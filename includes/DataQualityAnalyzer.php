<?php

/**
 * Statistical helpers for validating raw CRM enquiry data (fraud/quality
 * checks) — separate from CrmScoreCalculator, which turns already-trusted
 * numbers into scorecard points. This class asks "is the data itself real
 * and complete", not "what score does it produce".
 */
class DataQualityAnalyzer
{
    /** Obvious throwaway/example domains seen in fabricated test data. */
    private const FAKE_EMAIL_DOMAINS = ['test.com', 'example.com', 'test.test', 'asdf.com', 'abc.com', 'xyz.com', 'mail.com'];

    public static function isValidEmail(string $email): bool
    {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        return !in_array($domain, self::FAKE_EMAIL_DOMAINS, true);
    }

    /**
     * Accepts common Pakistani mobile formats: 03XXXXXXXXX, +923XXXXXXXXX,
     * 00923XXXXXXXXX, or the same with spaces/dashes — normalizes then checks
     * it's an 11-digit number starting with 03.
     */
    public static function isValidPakPhone(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '') {
            return false;
        }
        if (str_starts_with($digits, '0092')) {
            $digits = '0' . substr($digits, 4);
        } elseif (str_starts_with($digits, '92') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        }
        return (bool)preg_match('/^03\d{9}$/', $digits);
    }

    /**
     * Benford's Law: in most naturally-occurring numeric datasets, the
     * leading digit isn't uniformly distributed — "1" leads about 30% of the
     * time, "9" only about 4.6%. Fabricated/manipulated numbers tend to
     * deviate from this. Needs a reasonable sample (~50+) to be meaningful —
     * returns null below that instead of a misleading result.
     *
     * @param float[] $numbers
     * @return array{sample_size:int, chi_square:float, deviates:bool}|null
     */
    public static function benfordLawCheck(array $numbers): ?array
    {
        $leadingDigits = [];
        foreach ($numbers as $n) {
            $n = abs($n);
            if ($n < 1) {
                continue; // Benford's Law applies to the leading digit of numbers >= 1
            }
            $leadingDigits[] = (int)substr((string)(int)$n, 0, 1);
        }

        $sampleSize = count($leadingDigits);
        if ($sampleSize < 50) {
            return null;
        }

        $expected = [1 => 0.301, 2 => 0.176, 3 => 0.125, 4 => 0.097, 5 => 0.079, 6 => 0.067, 7 => 0.058, 8 => 0.051, 9 => 0.046];
        $observedCounts = array_fill_keys(range(1, 9), 0);
        foreach ($leadingDigits as $d) {
            if ($d >= 1 && $d <= 9) {
                $observedCounts[$d]++;
            }
        }

        $chiSquare = 0.0;
        foreach ($expected as $digit => $expectedProportion) {
            $expectedCount = $expectedProportion * $sampleSize;
            $observed = $observedCounts[$digit];
            $chiSquare += (($observed - $expectedCount) ** 2) / $expectedCount;
        }

        // Chi-square critical value at 8 degrees of freedom, p=0.05.
        $criticalValue = 15.51;

        return [
            'sample_size' => $sampleSize,
            'chi_square' => round($chiSquare, 2),
            'deviates' => $chiSquare > $criticalValue,
        ];
    }

    /**
     * Flags values that are statistically far from the group (|z-score| > 2,
     * i.e. more than 2 standard deviations from the mean) — e.g. a
     * dealership reporting 3x more enquiries than everyone else.
     *
     * @param array<int|string,float> $valuesByGroup
     * @return array<int|string,float> z-scores, keyed the same way, only for flagged (|z|>2) entries
     */
    public static function zScoreOutliers(array $valuesByGroup): array
    {
        $count = count($valuesByGroup);
        if ($count < 3) {
            return []; // not enough groups for a meaningful mean/stddev
        }

        $mean = array_sum($valuesByGroup) / $count;
        $variance = 0.0;
        foreach ($valuesByGroup as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $stdDev = sqrt($variance / $count);
        if ($stdDev == 0.0) {
            return [];
        }

        $outliers = [];
        foreach ($valuesByGroup as $key => $v) {
            $z = ($v - $mean) / $stdDev;
            if (abs($z) > 2) {
                $outliers[$key] = round($z, 2);
            }
        }
        return $outliers;
    }
}
