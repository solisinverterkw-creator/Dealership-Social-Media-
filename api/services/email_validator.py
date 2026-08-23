import re
import socket
import urllib.parse
import requests
import dns.resolver

class EmailValidator:
    def __init__(self):
        self.role_based_prefixes = [
            'info', 'admin', 'administrator', 'support', 'sales', 'contact', 'help',
            'noreply', 'no-reply', 'donotreply', 'webmaster', 'postmaster', 'hostmaster',
            'abuse', 'marketing', 'hr', 'careers', 'jobs', 'billing', 'accounts',
            'enquiry', 'enquiries', 'inquiries', 'feedback', 'office', 'team', 'service',
            'root', 'security', 'privacy', 'legal', 'press', 'media', 'newsletter',
        ]
        self.mx_cache = {}
        self.disposable_cache = {}
        self.smtp_consecutive_unreachable = 0
        self.smtp_disabled_for_batch = False
        self.known_smtp_unverifiable_domains = [
            'gmail.com', 'googlemail.com',
            'yahoo.com', 'ymail.com', 'rocketmail.com',
            'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
            'icloud.com', 'me.com', 'mac.com',
            'aol.com',
        ]

    def validate(self, email: str, include_smtp: bool = False) -> dict:
        email = email.strip()
        result = {
            'email': email,
            'format_valid': False,
            'mx_valid': False,
            'is_disposable': None,
            'is_role_based': False,
            'smtp_valid': None,
            'domain': None,
            'overall_valid': False,
            'summary': ''
        }

        # Check format
        email_regex = r'^([a-zA-Z0-9_.+-]+)@([a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+)$'
        if not re.match(email_regex, email):
            result['summary'] = 'Invalid Email Format.'
            return result

        result['format_valid'] = True
        local_part, domain = email.split('@', 1)
        domain = domain.lower()
        result['domain'] = domain

        # Check MX record
        if domain not in self.mx_cache:
            try:
                answers = dns.resolver.resolve(domain, 'MX')
                self.mx_cache[domain] = len(answers) > 0
            except Exception:
                try:
                    answers = dns.resolver.resolve(domain, 'A')
                    self.mx_cache[domain] = len(answers) > 0
                except Exception:
                    self.mx_cache[domain] = False

        result['mx_valid'] = self.mx_cache[domain]

        # Check Role-based
        result['is_role_based'] = local_part.lower() in self.role_based_prefixes

        # Check Disposable
        if domain not in self.disposable_cache:
            self.disposable_cache[domain] = self.check_disposable(domain)
        result['is_disposable'] = self.disposable_cache[domain]

        # Check SMTP (if enabled and MX is valid)
        if include_smtp and result['mx_valid']:
            result['smtp_valid'] = self.check_smtp(email, domain)

        result['overall_valid'] = (
            result['format_valid']
            and result['mx_valid']
            and result['is_disposable'] is not True
            and result['smtp_valid'] is not False
            and not result['is_role_based']
        )

        reasons = []
        if not result['mx_valid']:
            reasons.append('Domain Has No Valid Mail Server (MX Record) — Likely A Typo Or Fake Domain.')
        if result['is_disposable'] is True:
            reasons.append('Disposable/Temporary Email Service Detected.')
        if result['is_disposable'] is None:
            reasons.append('Disposable Check Unavailable (DeBounce API Unreachable) — Not Counted Against Validity.')
        if result['is_role_based']:
            reasons.append('Role-Based Address (e.g. info@, admin@) — Not Tied To One Specific Person.')
        
        if include_smtp and result['mx_valid']:
            if result['smtp_valid'] is False:
                reasons.append('Mail Server Rejected This Address (SMTP RCPT TO Refused) — Mailbox Likely Does Not Exist.')
            elif result['smtp_valid'] is None:
                if domain in self.known_smtp_unverifiable_domains:
                    reasons.append('SMTP Check Skipped — This Provider Blocks Automated Mailbox Verification By Policy. MX + Disposable + Role-Based Checks Still Apply.')
                else:
                    reasons.append('SMTP Check Unavailable (Mail Server Did Not Respond) — Not Counted Against Validity.')

        result['summary'] = ' '.join(reasons) if reasons else 'Valid.'
        return result

    def is_smtp_disabled_for_batch(self) -> bool:
        return self.smtp_disabled_for_batch

    def check_disposable(self, domain: str) -> bool:
        url = f'https://disposable.debounce.io/?email={urllib.parse.quote(domain)}'
        try:
            res = requests.get(url, timeout=10)
            if res.status_code == 200:
                data = res.json()
                disp = data.get('disposable')
                return disp == 'true' or disp is True
        except Exception:
            pass
        return None

    def check_smtp(self, email: str, domain: str) -> bool:
        if self.smtp_disabled_for_batch:
            return None
        if domain in self.known_smtp_unverifiable_domains:
            return None

        # Get MX hosts
        try:
            answers = dns.resolver.resolve(domain, 'MX')
            mx_hosts = [str(rdata.exchange).rstrip('.') for rdata in sorted(answers, key=lambda r: r.preference)]
        except Exception:
            mx_hosts = [domain]

        if not mx_hosts:
            return None

        host = mx_hosts[0]
        timeout_seconds = 4
        conclusive = False
        result_val = None

        try:
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(timeout_seconds)
            sock.connect((host, 25))

            greeting = sock.recv(512).decode('utf-8', errors='ignore')
            if greeting and greeting.strip().startswith('220'):
                sock.sendall(b"HELO emailcheck.local\r\n")
                sock.recv(512)

                sock.sendall(b"MAIL FROM:<verify@emailcheck.local>\r\n")
                sock.recv(512)

                sock.sendall(f"RCPT TO:<{email}>\r\n".encode('utf-8'))
                rcpt_response = sock.recv(512).decode('utf-8', errors='ignore')

                sock.sendall(b"QUIT\r\n")

                if rcpt_response:
                    conclusive = True
                    code_str = rcpt_response.strip()[:3]
                    try:
                        code = int(code_str)
                        result_val = (code == 250 or code == 251)
                    except ValueError:
                        pass
            sock.close()
        except Exception:
            pass

        if conclusive:
            self.smtp_consecutive_unreachable = 0
            return result_val

        self.smtp_consecutive_unreachable += 1
        if self.smtp_consecutive_unreachable >= 2:
            self.smtp_disabled_for_batch = True

        return None
