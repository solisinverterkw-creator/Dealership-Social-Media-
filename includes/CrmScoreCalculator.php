<?php

/**
 * Turns a parameter's raw uploaded numbers (crm_raw_data.raw_json) into a
 * "Points Obtained" value (crm_scores.points_obtained), per crm_parameters.calc_key.
 * Filled in one calc_key at a time as each parameter's scoring logic is
 * confirmed — a calc_key with no case here yet just isn't calculable, so
 * recalculation leaves that parameter's existing crm_scores rows untouched.
 */
class CrmScoreCalculator
{
    /**
     * @param array<string,mixed> $raw Whatever columns were in the uploaded raw-data sheet, keyed by header text (summed across all source rows for that dealership).
     * @param array<string,mixed> $dealership The dealerships row — only needed by calc_keys that use a dealership-level setting rather than (or in addition to) the raw sheet, e.g. Digital Enquiry Targets' per-dealership target.
     */
    public static function calculate(?string $calcKey, array $raw, float $maxPoints, array $dealership = []): ?float
    {
        if ($calcKey === null) {
            return null;
        }

        return match ($calcKey) {
            'detailing_of_enquiry' => self::detailingOfEnquiry($raw, $maxPoints),
            'timely_followup' => self::timedResponseBands($raw, $maxPoints),
            'number_of_followups' => self::numberOfFollowups($raw, $maxPoints),
            'voip_calling' => self::voipCalling($raw, $maxPoints),
            // Same criteria shape/bands as Timely Follow-Up ("Within 20
            // min~20 | +20~15 | +40~10 | +60~5 | +80~0") and the same raw
            // "Average of MIN" sheet format — just a separately-uploaded file.
            'first_response_time' => self::timedResponseBands($raw, $maxPoints),
            'manager_assigning_time' => self::managerAssigningTimeBands($raw, $maxPoints),
            'digital_enquiry_targets' => self::digitalEnquiryTargets($raw, $maxPoints, $dealership),
            'stage_won_conversion' => self::stageWonConversion($raw, $maxPoints, $dealership),
            'fronx_test_drive_monthly' => self::fronxTestDriveMonthly($raw),
            'pipeline_tracking' => self::pipelineTracking($raw, $maxPoints),
            default => null,
        };
    }

    /**
     * Banded on the WORST (maximum, not average) "Business Days Difference"
     * across this dealership's enquiries this month — crm_parameters.php's
     * import tracks that max per dealership directly (not summed), stored as
     * "Max Business Days Difference". Bands: <=1 day = full marks, 2-3 days
     * = 10/15, >3 days = 0 — scaled proportionally if max_points is edited.
     */
    private static function pipelineTracking(array $raw, float $maxPoints): ?float
    {
        $maxDays = self::findRawValue($raw, 'business days');
        if ($maxDays === null) {
            return null;
        }

        $fraction = match (true) {
            $maxDays <= 1 => 1.0,
            $maxDays <= 3 => 0.6667,
            default => 0.0,
        };

        return round($fraction * $maxPoints, 2);
    }

    /**
     * (Completed Test Drives / 104) * 100 — direct achievement %, no Max
     * Points/scoring (same "direct result" treatment as Digital Enquiry
     * Conversion). Unlike that parameter, the target here (104/month) is a
     * FIXED company-wide number from the criteria text, not per-dealership,
     * so there's no dealerships.* lookup — just count rows whose "Status"
     * column is exactly "Completed".
     */
    private static function fronxTestDriveMonthly(array $raw): ?float
    {
        $completed = self::findRawValue($raw, 'completed')
            ?? self::findRawValue($raw, 'achiev')
            ?? self::findRawValue($raw, 'actual');
        if ($completed === null) {
            return null;
        }

        return round(($completed / 104) * 100, 2);
    }

    /**
     * (Digital Enquiries / dealerships.digital_enquiry_target) * 100. Unlike
     * every other parameter, the target isn't in the monthly raw sheet — it's
     * dealership-specific and reviewed every ~6 months, so it's set once via
     * Edit Dealership instead. "Digital Enquiries" is a count of this
     * dealership's enquiries whose "Enquiry Source" was Facebook or
     * Instagram — crm_parameters.php's import already counts that (per raw
     * row, not summed) into a "Digital Enquiries (Facebook + Instagram
     * Source)" field when the raw sheet has an "Enquiry Source" column; a
     * plain "Achieved"/"Actual" column (no per-enquiry source breakdown) is
     * also accepted as a fallback.
     */
    private static function digitalEnquiryTargets(array $raw, float $maxPoints, array $dealership): ?float
    {
        $target = isset($dealership['digital_enquiry_target']) ? (float)$dealership['digital_enquiry_target'] : 0.0;
        if ($target <= 0) {
            return null; // no target set for this dealership yet — see Edit Dealership
        }

        $achieved = self::findRawValue($raw, 'digital')
            ?? self::findRawValue($raw, 'achiev')
            ?? self::findRawValue($raw, 'actual')
            ?? self::findFirstRawValue($raw, ['row count']);
        if ($achieved === null) {
            return null;
        }

        return self::achievementBands($achieved, $target, $maxPoints);
    }

    /**
     * (Won Enquiries / dealerships.digital_enquiry_conversion_target) * 100 —
     * "Won Enquiries" is a count of this dealership's rows whose "Stage"
     * column was exactly "Won" (not "Pre WON" or anything else), from the
     * same "one row per enquiry" raw sheet as Digital Enquiry Targets; the
     * conversion target is also dealership-specific, set via Edit Dealership,
     * reviewed every ~6 months rather than monthly.
     */
    private static function stageWonConversion(array $raw, float $maxPoints, array $dealership): ?float
    {
        $target = isset($dealership['digital_enquiry_conversion_target']) ? (float)$dealership['digital_enquiry_conversion_target'] : 0.0;
        if ($target <= 0) {
            return null; // no conversion target set for this dealership yet — see Edit Dealership
        }

        $achieved = self::findRawValue($raw, 'won')
            ?? self::findRawValue($raw, 'achiev')
            ?? self::findRawValue($raw, 'actual');
        if ($achieved === null) {
            return null;
        }

        // No scoring/Max Points for this parameter — just the direct
        // achievement percentage (achieved / target * 100), uncapped so
        // over-achievement is visible as-is. crm_report.php/visit_report.php
        // display this as "X%" and leave it out of the Total CRM Points sum.
        return round(($achieved / $target) * 100, 2);
    }

    /** Shared >100/90/80/70/60% achievement bands used by Digital Enquiry Targets. */
    private static function achievementBands(float $achieved, float $target, float $maxPoints): float
    {
        $percentage = ($achieved / $target) * 100;
        $fraction = match (true) {
            $percentage > 100 => 1.0,
            $percentage >= 90 => 0.8333,
            $percentage >= 80 => 0.6667,
            $percentage >= 70 => 0.5,
            $percentage >= 60 => 0.3333,
            default => 0.0,
        };

        return round($fraction * $maxPoints, 2);
    }

    /**
     * (Total Follow Up Calls / Total Enquiries) * 100 — a dealership can
     * follow up more than once per enquiry, so this can exceed 100%; >250%
     * is full marks. Same raw sheet as VoIP Calling (Total Enquiries, Total
     * Follow Up Calls, Total Number Of Calls Through VoIP columns) — the
     * sheet's own ready-made "%"/"Ratio" columns are ignored and recomputed
     * here instead, since raw-data import sums counts across a dealership's
     * branch rows but summing pre-computed percentages would be meaningless.
     */
    private static function numberOfFollowups(array $raw, float $maxPoints): ?float
    {
        $enquiries = self::findRawValueAll($raw, ['total', 'enquir']);
        $followUps = self::findRawValueAll($raw, ['total', 'follow']);
        if ($enquiries === null || $followUps === null || $enquiries <= 0) {
            return null;
        }

        $percentage = ($followUps / $enquiries) * 100;
        $fraction = match (true) {
            $percentage > 250 => 1.0,
            $percentage >= 200 => 0.75,
            $percentage >= 170 => 0.6,
            $percentage >= 150 => 0.45,
            $percentage >= 96 => 0.3,
            $percentage >= 50 => 0.15,
            default => 0.0,
        };

        return round($fraction * $maxPoints, 2);
    }

    /**
     * (Total Calls Through VoIP / Total Follow Up Calls) * 100 — ratio is
     * against follow-up calls, not total enquiries. Same raw sheet as
     * Number Of Follow-Ups.
     */
    private static function voipCalling(array $raw, float $maxPoints): ?float
    {
        $voipCalls = self::findRawValueAll($raw, ['total', 'voip']);
        $followUps = self::findRawValueAll($raw, ['total', 'follow']);
        if ($voipCalls === null || $followUps === null || $followUps <= 0) {
            return null;
        }

        $percentage = ($voipCalls / $followUps) * 100;
        $fraction = match (true) {
            $percentage > 90 => 1.0,
            $percentage >= 85 => 0.8,
            $percentage >= 80 => 0.6,
            $percentage >= 75 => 0.4,
            default => 0.0,
        };

        return round($fraction * $maxPoints, 2);
    }

    /**
     * Pro-rata: (sum of "...Filled" / sum of "...View") * 100 gives the
     * percentage of CRM fields completed across this dealership's enquiries
     * this month; points scale linearly against that percentage, capped at
     * max points (e.g. 80% filled on a 25-max parameter = 20 points).
     */
    private static function detailingOfEnquiry(array $raw, float $maxPoints): ?float
    {
        $filled = self::findRawValue($raw, 'fill');
        $view = self::findRawValue($raw, 'view');
        if ($filled === null || $view === null || $view <= 0) {
            return null;
        }

        $percentage = min(100, ($filled / $view) * 100);
        return round(($percentage / 100) * $maxPoints, 2);
    }

    /**
     * Banded scoring against a 20-minute standard (Timely Follow-Up): the raw
     * sheet already gives one pre-averaged "Average of MIN" value per
     * dealership (a pivot-style export, not per-enquiry rows), so no
     * summing/dividing is needed here — just band lookup. Bands are
     * <=20min=100% of max, <=40min=75%, <=60min=50%, <=80min=25%, >80min=0%,
     * matching the criteria "Within time~20 | +20 min~15 | +40~10 | +60~5 |
     * +80~0" scaled proportionally so it still works if max_points is edited.
     */
    private static function timedResponseBands(array $raw, float $maxPoints): ?float
    {
        $avgMinutes = self::findRawValue($raw, 'min');
        if ($avgMinutes === null) {
            return null;
        }

        $fraction = match (true) {
            $avgMinutes <= 20 => 1.0,
            $avgMinutes <= 40 => 0.75,
            $avgMinutes <= 60 => 0.5,
            $avgMinutes <= 80 => 0.25,
            default => 0.0,
        };

        return round($fraction * $maxPoints, 2);
    }

    /**
     * Sales Manager Enquiry Assigning Time — same "Average of MIN" raw sheet
     * as Timely Follow-Up/First Response Time, but its criteria only has 4
     * bands with no zero-point floor ("Within 20 min~20 | +20~15 | +40~10 |
     * +60~5" — anything over 40min stays at the 5pt/25% band, never drops to 0).
     */
    private static function managerAssigningTimeBands(array $raw, float $maxPoints): ?float
    {
        $avgMinutes = self::findRawValue($raw, 'min');
        if ($avgMinutes === null) {
            return null;
        }

        $fraction = match (true) {
            $avgMinutes <= 20 => 1.0,
            $avgMinutes <= 40 => 0.75,
            $avgMinutes <= 60 => 0.5,
            default => 0.25,
        };

        return round($fraction * $maxPoints, 2);
    }

    /** Case-insensitive "which raw column has this word in its header" lookup — raw headers are admin-typed, not fixed, so exact-key matching is too brittle. */
    private static function findRawValue(array $raw, string $needle): ?float
    {
        foreach ($raw as $key => $value) {
            if (stripos((string)$key, $needle) !== false) {
                return (float)$value;
            }
        }
        return null;
    }

    /** Fallback when a raw sheet has exactly one real data column besides Dealer Name and "Row Count" (auto-added by the importer) — used when the column's exact label can't be predicted (e.g. it's admin-typed and might be "Achieved", "Actual", or anything else). */
    private static function findFirstRawValue(array $raw, array $excludeKeysLower): ?float
    {
        foreach ($raw as $key => $value) {
            if (in_array(strtolower((string)$key), $excludeKeysLower, true)) {
                continue;
            }
            return (float)$value;
        }
        return null;
    }

    /**
     * Like findRawValue(), but requires every word in $mustContainAll to be
     * present — needed when a sheet has both a raw count column ("Total
     * Follow Up Calls") and a derived percentage column that mentions the
     * same word ("Follow-up % against Enquiries"); requiring "total" as one
     * of the needles picks the count column, not the percentage one.
     */
    private static function findRawValueAll(array $raw, array $mustContainAll): ?float
    {
        foreach ($raw as $key => $value) {
            $keyLower = strtolower((string)$key);
            $matchesAll = true;
            foreach ($mustContainAll as $needle) {
                if (stripos($keyLower, $needle) === false) {
                    $matchesAll = false;
                    break;
                }
            }
            if ($matchesAll) {
                return (float)$value;
            }
        }
        return null;
    }
}
