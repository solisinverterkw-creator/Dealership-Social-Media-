<?php

/**
 * Finds the real header row and the right columns by NAME instead of assuming
 * fixed positions — real-world exports often have a title row before the
 * actual headers, an extra "Sr#" column, or slightly different wording.
 */
class SpreadsheetImportHelper
{
    /** Returns the 0-based row index of the first row containing a cell that matches any of $mustContainAny (case-insensitive substring). */
    public static function findHeaderRowIndex(array $rows, array $mustContainAny): int
    {
        foreach ($rows as $i => $row) {
            foreach ($row as $cell) {
                $cell = strtolower(trim((string)$cell));
                foreach ($mustContainAny as $needle) {
                    if ($cell !== '' && str_contains($cell, $needle)) {
                        return $i;
                    }
                }
            }
        }
        return 0;
    }

    /**
     * Returns the 0-based column index whose header cell contains any of
     * $keywords as a whole word, or null if none match. When a sheet has more
     * than one matching column (e.g. both a "REGIO" branch code and a
     * "Region" zone code), $preferLast picks the rightmost match instead of
     * the leftmost — useful when the more specific/meaningful column tends to
     * come after a shorter/coded one, as it does in these exports.
     */
    public static function findColumn(array $headerRow, array $keywords, bool $preferLast = false): ?int
    {
        $found = null;
        foreach ($headerRow as $i => $cell) {
            $cell = strtolower(trim((string)$cell));
            if ($cell === '') {
                continue;
            }
            if (self::matchesAnyKeyword($cell, $keywords)) {
                if (!$preferLast) {
                    return $i;
                }
                $found = $i;
            }
        }
        return $found;
    }

    /**
     * Whole-word match (not raw substring) — a plain str_contains() would let a
     * short keyword like "s no" (meant for "S.No"/"Sr No") false-positive match
     * inside an unrelated word like "...s Normal" (which literally contains the
     * letters "s no" as part of "s" + "normal"). \b anchors require an actual
     * word boundary on both sides, so it only matches when the keyword really
     * stands alone.
     */
    public static function matchesAnyKeyword(string $label, array $keywords): bool
    {
        $label = strtolower($label);
        foreach ($keywords as $kw) {
            // \b breaks down for a keyword ending in punctuation (e.g. "sr#" —
            // there's no word-boundary between "r" and "#" the way \b expects),
            // so this checks directly: not preceded/followed by a letter or
            // digit, rather than relying on \b's word/non-word transition.
            $pattern = '/(?<![a-z0-9])' . preg_quote(strtolower($kw), '/') . '(?![a-z0-9])/';
            if (preg_match($pattern, $label)) {
                return true;
            }
        }
        return false;
    }

    /** Left-to-right forward-fill: a merged group-header cell only has a value in
     * its leftmost column in the raw sheet — every column it visually spans is
     * blank — so blanks are filled with the last non-blank value seen. Resets
     * right after $anchorCol (the "Dealer" column) so that label doesn't leak
     * into the product columns that follow it. */
    private static function forwardFillRow(array $row, int $maxCols, int $anchorCol = -1): array
    {
        $filled = [];
        $last = '';
        for ($c = 0; $c < $maxCols; $c++) {
            $v = trim((string)($row[$c] ?? ''));
            if ($v !== '') {
                $last = $v;
            }
            $filled[$c] = $last;
            if ($c === $anchorCol) {
                $last = '';
            }
        }
        return $filled;
    }

    /**
     * Combines an arbitrary number of stacked header rows (a merged group-header
     * on top, one or more sub-header rows below it) into one leaf-level label
     * per column — e.g. "ALTO VXR" + "Normal" -> "ALTO VXR Normal". Keeps
     * consuming rows as long as $anchorCol (the column that identifies a real
     * data row — usually "Dealer") stays blank on the next row; stops the
     * moment that column has a value, since that's the first data row.
     *
     * @return array{header: array<int,string>, dataStartIndex: int}
     */
    public static function combineMultiRowHeader(array $rows, int $headerIndex, int $anchorCol): array
    {
        $maxCols = 0;
        foreach ($rows as $row) {
            $maxCols = max($maxCols, count($row));
        }

        $combined = self::forwardFillRow($rows[$headerIndex] ?? [], $maxCols, $anchorCol);
        $dataStartIndex = $headerIndex + 1;

        while ($dataStartIndex < count($rows)) {
            $row = $rows[$dataStartIndex];
            $isBlankRow = count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0;
            if ($isBlankRow) {
                break;
            }
            $anchorVal = trim((string)($row[$anchorCol] ?? ''));
            if ($anchorVal !== '') {
                break; // first real data row
            }

            $filledRow = self::forwardFillRow($row, $maxCols, $anchorCol);
            for ($c = 0; $c < $maxCols; $c++) {
                if ($filledRow[$c] !== '' && $filledRow[$c] !== $combined[$c]) {
                    $combined[$c] = trim($combined[$c] . ' ' . $filledRow[$c]);
                }
            }
            $dataStartIndex++;
        }

        return ['header' => $combined, 'dataStartIndex' => $dataStartIndex];
    }

    /**
     * Normalizes a dealership name for matching against our own clean DB
     * names — real-world exports append things like "(SMC-PVT)",
     * "(SMC-PRIVATE)L", or a trailing ".", drop the "Suzuki" brand prefix
     * ("United Motors" vs our "SUZUKI UNITED MOTORS"), or space city names
     * differently ("Mian Channu" vs our "MIANCHANNU"). Stripping the
     * parenthetical suffix/punctuation/prefix and then removing ALL
     * whitespace (not just collapsing it) makes every one of those variants
     * converge on the same key.
     */
    public static function normalizeDealershipName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\s*\(.*$/', '', $name) ?? $name;
        $name = rtrim($name, " .\t\n\r\0\x0B");
        $name = preg_replace('/^suzuki\s+/', '', trim($name)) ?? $name;
        $name = preg_replace('/\s+/', '', $name) ?? $name;
        return trim($name);
    }

    /**
     * Matches a raw dealer name (from an uploaded sheet) to a known
     * dealership id — exact normalized match first, falling back to a small
     * Levenshtein-distance tolerance for genuine misspellings that
     * normalizeDealershipName() can't fix on its own (e.g. a missing letter:
     * "Mianchanu" vs our "Mianchannu" — a spacing/punctuation difference
     * would already match, but a dropped letter is a real typo). Only
     * applies the fuzzy fallback when exactly one dealership is within
     * tolerance, so two genuinely different, similarly-spelled dealers never
     * get confused for each other.
     *
     * @param array<string,int> $dealershipsByNormalizedName normalizeDealershipName(name) => id
     */
    public static function findDealershipMatch(array $dealershipsByNormalizedName, string $rawName): ?int
    {
        $normalized = self::normalizeDealershipName($rawName);
        if ($normalized === '') {
            return null;
        }
        if (isset($dealershipsByNormalizedName[$normalized])) {
            return $dealershipsByNormalizedName[$normalized];
        }

        $bestId = null;
        $bestDistance = null;
        $ambiguous = false;

        foreach ($dealershipsByNormalizedName as $knownNormalized => $id) {
            // Skip names too different in length to plausibly be a typo of
            // each other — keeps this fast and avoids far-fetched matches.
            if (abs(strlen($knownNormalized) - strlen($normalized)) > 2) {
                continue;
            }
            $distance = levenshtein($normalized, $knownNormalized);
            $tolerance = strlen($knownNormalized) >= 12 ? 2 : 1;
            if ($distance > $tolerance) {
                continue;
            }
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestId = $id;
                $ambiguous = false;
            } elseif ($distance === $bestDistance) {
                $ambiguous = true;
            }
        }

        return $ambiguous ? null : $bestId;
    }

    /**
     * Sorts product/variant column names by a caller-given priority list
     * (e.g. "Alto VXR" before "Alto VXR AGS") rather than plain alphabetical.
     * A pattern matches a product name if every one of its words appears in
     * the product name IN ORDER, even with other words/codes between them —
     * "Alto VXR AGS" matches "SUZUKI ALTO AET306 VXR AGS M1 658 CC" despite
     * the "AET306"/"M1"/"658 CC" codes sitting in between. When more than one
     * pattern matches the same product, the MOST SPECIFIC one wins (most
     * words matched — "Alto VXR AGS" over the shorter "Alto VXR" for a VXR
     * AGS product), not just the first one listed. Anything matching no
     * pattern sorts after everything that does, alphabetically among itself.
     */
    public static function sortProductColumnsByPriority(array $productNames, array $priorityPatterns): array
    {
        $rank = [];
        foreach ($productNames as $product) {
            $rank[$product] = self::findBestMatchingPatternIndex($product, $priorityPatterns) ?? (count($priorityPatterns) + 1);
        }

        $sorted = $productNames;
        usort($sorted, function ($a, $b) use ($rank) {
            if ($rank[$a] !== $rank[$b]) {
                return $rank[$a] <=> $rank[$b];
            }
            return strcasecmp($a, $b);
        });

        return $sorted;
    }

    /**
     * Shortens a long, code-heavy product name (e.g. "SUZUKI ALTO AET306 VXR
     * AGS M1 658 CC") down to just the human name from $priorityPatterns
     * ("Alto VXR AGS") for compact display — table columns, print — instead
     * of the raw sheet text. Falls back to the original name unchanged when
     * nothing in the pattern list matches it.
     */
    public static function shortenProductLabel(string $productName, array $priorityPatterns): string
    {
        $index = self::findBestMatchingPatternIndex($productName, $priorityPatterns);
        return $index !== null ? $priorityPatterns[$index] : $productName;
    }

    /**
     * Shared by sortProductColumnsByPriority() and shortenProductLabel() — see
     * sortProductColumnsByPriority()'s docblock for the matching rules.
     * Each token is matched as a whole word (not a raw substring) — a plain
     * strpos() for "GL" would incorrectly match inside "GLX" (GLX contains
     * "GL" as its first two letters), wrongly treating a GLX product as a GL
     * match.
     */
    private static function findBestMatchingPatternIndex(string $product, array $priorityPatterns): ?int
    {
        $productLower = strtolower($product);
        $bestPatternIndex = null;
        $bestTokenCount = 0;

        foreach ($priorityPatterns as $patternIndex => $pattern) {
            $tokens = preg_split('/\s+/', strtolower(trim($pattern)));
            $pos = 0;
            $matched = true;
            foreach ($tokens as $token) {
                $regex = '/(?<![a-z0-9])' . preg_quote($token, '/') . '(?![a-z0-9])/';
                if (!preg_match($regex, $productLower, $m, PREG_OFFSET_CAPTURE, $pos)) {
                    $matched = false;
                    break;
                }
                $pos = $m[0][1] + strlen($m[0][0]);
            }
            if ($matched && count($tokens) > $bestTokenCount) {
                $bestTokenCount = count($tokens);
                $bestPatternIndex = $patternIndex;
            }
        }

        return $bestPatternIndex;
    }

    /**
     * Parses a date cell that could be a few different shapes depending on
     * how the source system exported it: a plain "20260713"-style YYYYMMDD
     * string (seen in these dealership exports), an Excel serial day number
     * (if the cell came through as raw numeric rather than pre-formatted
     * text), or an ordinary date string. Returns Y-m-d, or null if none of
     * those interpretations produce a sane date.
     */
    public static function parseFlexibleDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{8}$/', $value)) {
            $dt = DateTime::createFromFormat('Ymd', $value);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        // Excel's date epoch is 1899-12-30; a bare serial number in a
        // plausible date range (roughly 1970-2100) is almost certainly an
        // unformatted Excel date rather than some other kind of code.
        if (preg_match('/^\d{4,6}$/', $value)) {
            $serial = (int)$value;
            if ($serial >= 25569 && $serial <= 73050) {
                $dt = (new DateTime('1899-12-30'))->modify("+{$serial} days");
                return $dt->format('Y-m-d');
            }
        }

        // Slash-separated numeric dates (e.g. "31/07/2026") are day-first in
        // these exports — PHP's strtotime() assumes American m/d/y for
        // slash-separated dates, which silently fails (returns false) as
        // soon as the day exceeds 12, so this is parsed explicitly instead
        // of falling through to strtotime() below.
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value)) {
            $dt = DateTime::createFromFormat('d/m/Y', $value);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    /**
     * "PA"/"PB" are dealership-specific business shorthand — Pending
     * Allocation (payment already submitted to the company, not yet
     * invoiced) and Pending Booking (booked, not yet invoiced) — spelled out
     * for display without touching the underlying stored column name (which
     * still needs to match exactly what the sheet calls it).
     */
    public static function friendlyProductLabel(string $name): string
    {
        $key = strtolower(trim($name));
        if ($key === 'pa') {
            return 'PA (Pending Allocation)';
        }
        if ($key === 'pb') {
            return 'PB (Pending Booking)';
        }
        return $name;
    }
}
