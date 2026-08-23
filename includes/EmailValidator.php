<?php
class EmailValidator
{
    /**
     * Common role/generic mailbox prefixes — these belong to a department or
     * function, not a specific person, so they're flagged separately from a
     * genuine personal/business contact address.
     */
    private array $roleBasedPrefixes = [
        'info', 'admin', 'administrator', 'support', 'sales', 'contact', 'help',
        'noreply', 'no-reply', 'donotreply', 'webmaster', 'postmaster', 'hostmaster',
        'abuse', 'marketing', 'hr', 'careers', 'jobs', 'billing', 'accounts',
        'enquiry', 'enquiries', 'inquiries', 'feedback', 'office', 'team', 'service',
        'root', 'security', 'privacy', 'legal', 'press', 'media', 'newsletter',
    ];

    /**
     * MX and disposable status are both properties of the DOMAIN, not the
     * individual mailbox — cached per domain within a batch so a file with
     * hundreds of @gmail.com (or any repeated domain) addresses only does the
     * DNS lookup + DeBounce call once per unique domain, not once per email.
     */
    private array $mxCache = [];
    private array $disposableCache = [];

    /**
     * Circuit breaker for SMTP: port 25 being blocked is almost always a
     * network-wide condition (firewall/ISP/host), not a per-domain thing — so
     * after 2 total connection failures (couldn't even reach any MX host,
     * across 2 different domains), stop attempting SMTP for the rest of this
     * batch instead of paying the full timeout on every remaining email.
     */
    private int $smtpConsecutiveUnreachable = 0;
    private bool $smtpDisabledForBatch = false;

    /**
     * Big freemail providers deliberately accept the TCP connection but then
     * never send the SMTP greeting for automated-looking clients — a known
     * anti-enumeration defense, not a blocked port (confirmed live: connect
     * succeeds in ~0.2s, then the socket just sits silent until timeout).
     * No amount of retrying or a longer timeout fixes this — it's
     * intentional on their end. Skipping the handshake for these domains
     * avoids burning the circuit breaker on an unwinnable check, so SMTP
     * checking keeps working for the smaller/business domains in the same
     * batch that actually do respond.
     */
    private array $knownSmtpUnverifiableDomains = [
        'gmail.com', 'googlemail.com',
        'yahoo.com', 'ymail.com', 'rocketmail.com',
        'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
        'icloud.com', 'me.com', 'mac.com',
        'aol.com',
    ];

    /**
     * Runs all checks and returns each result plus an overall verdict.
     * Format/role checks are local (instant); MX is a DNS lookup; disposable
     * calls DeBounce's free public API (no key required); SMTP (if enabled) is
     * a direct handshake with the mailbox's real mail server — QUIT is sent
     * before DATA, so no email is ever actually sent.
     * $includeSmtp is opt-in and off by default: it's the slowest check (a live
     * socket connection per email) and outbound port 25 is blocked on many
     * hosts, so it's not worth paying that cost unless explicitly requested.
     */
    public function validate(string $email, bool $includeSmtp = false): array
    {
        $email = trim($email);
        $result = [
            'email' => $email,
            'format_valid' => false,
            'mx_valid' => false,
            'is_disposable' => null,   // null = couldn't be determined (API unreachable)
            'is_role_based' => false,
            'smtp_valid' => null,      // null = not checked, or port 25 unreachable from this server
            'domain' => null,
        ];

        $result['format_valid'] = (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
        if (!$result['format_valid']) {
            $result['overall_valid'] = false;
            $result['summary'] = 'Invalid Email Format.';
            return $result;
        }

        [$localPart, $domain] = explode('@', $email, 2);
        $domain = strtolower($domain);
        $result['domain'] = $domain;

        if (!isset($this->mxCache[$domain])) {
            $this->mxCache[$domain] = checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A');
        }
        $result['mx_valid'] = $this->mxCache[$domain];

        $result['is_role_based'] = in_array(strtolower($localPart), $this->roleBasedPrefixes, true);

        if (!array_key_exists($domain, $this->disposableCache)) {
            $this->disposableCache[$domain] = $this->checkDisposable($domain);
        }
        $result['is_disposable'] = $this->disposableCache[$domain];

        if ($includeSmtp && $result['mx_valid']) {
            $result['smtp_valid'] = $this->checkSmtp($email, $domain);
        }

        $result['overall_valid'] = $result['format_valid']
            && $result['mx_valid']
            && $result['is_disposable'] !== true
            && $result['smtp_valid'] !== false
            && !$result['is_role_based'];
            // Role-based (info@, admin@) counts as Invalid overall — a shared
            // department inbox isn't a specific person, so it's not useful
            // for lead/contact data even though the mailbox itself is real.

        $reasons = [];
        if (!$result['mx_valid']) {
            $reasons[] = 'Domain Has No Valid Mail Server (MX Record) — Likely A Typo Or Fake Domain.';
        }
        if ($result['is_disposable'] === true) {
            $reasons[] = 'Disposable/Temporary Email Service Detected.';
        }
        if ($result['is_disposable'] === null) {
            $reasons[] = 'Disposable Check Unavailable (DeBounce API Unreachable) — Not Counted Against Validity.';
        }
        if ($result['is_role_based']) {
            $reasons[] = 'Role-Based Address (e.g. info@, admin@) — Not Tied To One Specific Person.';
        }
        if ($includeSmtp && $result['mx_valid']) {
            if ($result['smtp_valid'] === false) {
                $reasons[] = 'Mail Server Rejected This Address (SMTP RCPT TO Refused) — Mailbox Likely Does Not Exist.';
            } elseif ($result['smtp_valid'] === null) {
                if (in_array($domain, $this->knownSmtpUnverifiableDomains, true)) {
                    $reasons[] = 'SMTP Check Skipped — This Provider Blocks Automated Mailbox Verification By Policy. MX + Disposable + Role-Based Checks Still Apply.';
                } else {
                    $reasons[] = 'SMTP Check Unavailable (Mail Server Did Not Respond) — Not Counted Against Validity.';
                }
            }
        }
        $result['summary'] = empty($reasons) ? 'Valid.' : implode(' ', $reasons);

        return $result;
    }

    /** True once the circuit breaker has kicked in and stopped attempting SMTP for the rest of this batch. */
    public function isSmtpDisabledForBatch(): bool
    {
        return $this->smtpDisabledForBatch;
    }

    private function checkDisposable(string $domain): ?bool
    {
        $url = 'https://disposable.debounce.io/?email=' . urlencode($domain);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }
        $data = json_decode($response, true);
        if (!isset($data['disposable'])) {
            return null;
        }
        return $data['disposable'] === 'true' || $data['disposable'] === true;
    }

    /**
     * SMTP handshake ("Method 1"): connects to the mailbox's real mail server,
     * says HELO / MAIL FROM / RCPT TO, reads whether the server accepts that
     * specific recipient, then QUITs — DATA (the actual message) is never
     * sent, so no email goes out. Returns null (not false) if no MX host could
     * be reached at all, since that's almost always port 25 being blocked by
     * the network/host, not proof the mailbox is invalid.
     */
    private function checkSmtp(string $email, string $domain): ?bool
    {
        if ($this->smtpDisabledForBatch) {
            return null; // already confirmed SMTP isn't giving usable answers on this network — don't waste time retrying
        }

        if (in_array($domain, $this->knownSmtpUnverifiableDomains, true)) {
            // Doesn't count toward the circuit breaker — this isn't a network
            // problem, so it says nothing about whether SMTP will work for
            // other (non-freemail) domains later in the same batch.
            return null;
        }

        $mxHosts = [];
        $mxWeights = [];
        if (getmxrr($domain, $mxHosts, $mxWeights)) {
            array_multisort($mxWeights, $mxHosts);
        } else {
            $mxHosts = [$domain]; // some domains accept mail directly on their A record
        }

        // A real, reachable SMTP server almost always greets and responds
        // within a few seconds; anything slower is more likely blocked/
        // filtered than genuinely slow. Only 1 host is tried — on a network
        // where SMTP works at all, the primary MX is virtually always
        // reachable; on a network where it doesn't, trying more hosts just
        // burns more time. Freemail giants (Gmail/Yahoo/Outlook/etc.) are
        // skipped above, so this timeout only applies to smaller/business
        // mail servers that are far more likely to actually answer.
        $timeoutSeconds = 4;
        $host = $mxHosts[0];

        $conclusive = false;
        $result = null;

        $conn = @fsockopen($host, 25, $errno, $errstr, $timeoutSeconds);
        if ($conn) {
            stream_set_timeout($conn, $timeoutSeconds);
            $greeting = fgets($conn, 512);
            if ($greeting && str_starts_with(trim($greeting), '220')) {
                fwrite($conn, "HELO emailcheck.local\r\n");
                fgets($conn, 512);
                fwrite($conn, "MAIL FROM:<verify@emailcheck.local>\r\n");
                fgets($conn, 512);
                fwrite($conn, "RCPT TO:<{$email}>\r\n");
                $rcptResponse = fgets($conn, 512);
                fwrite($conn, "QUIT\r\n"); // never send DATA — no email is ever sent
                if ($rcptResponse) {
                    $conclusive = true;
                    $code = (int)substr(trim($rcptResponse), 0, 3);
                    $result = $code === 250 || $code === 251;
                }
            }
            fclose($conn);
        }

        // Any conclusive answer (accept or reject) proves SMTP genuinely works
        // on this network — reset the breaker so later domains still get checked.
        if ($conclusive) {
            $this->smtpConsecutiveUnreachable = 0;
            return $result;
        }

        $this->smtpConsecutiveUnreachable++;
        if ($this->smtpConsecutiveUnreachable >= 2) {
            $this->smtpDisabledForBatch = true; // 2 domains in a row with no usable answer — not worth the time cost per-email anymore
        }

        return null;
    }
}
