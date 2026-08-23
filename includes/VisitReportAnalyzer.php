<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SpreadsheetImportHelper.php';

/**
 * Text-only Gemini call (no images) that reads a dealership's pulled-together
 * sales, stock, and social-media numbers and writes a short professional-English
 * write-up of weak areas — used at the end of the Visit Report, in place of a
 * DSM manually typing up observations.
 */
class VisitReportAnalyzer
{
    // Tried in order, same API key — each Gemini model has its own separate
    // free-tier daily request quota, so when one is exhausted (HTTP 429 /
    // "quota exceeded"), the next one down the list still has requests left
    // instead of the whole feature going down for the day.
    private array $models = ['gemini-3-flash-preview', 'gemini-3.1-flash-lite', 'gemini-flash-lite-latest', 'gemini-flash-latest'];

    /**
     * $context: ['dealership_name', 'sales' => [...], 'sales_target',
     * 'sales_grand_total', 'stock' => [...], 'ageing' => [...], 'crm' => [...
     * with 'calc_key'], 'security_amount', 'social' => [...with targets...]]
     */
    public function analyzeWeakAreas(array $context): array
    {
        $promptLines = [
            "You are a District Sales Manager (DSM) writing the \"Weak Areas\" section of a dealership visit report for \"{$context['dealership_name']}\".",
            "Write in clear, professional English, as short paragraphs or bullet points a manager would put in an official report. Be specific and reference actual numbers throughout. If a section looks healthy, say so briefly instead of forcing a complaint.",
            "Cover the sections below in EXACTLY this order, using the specific guidance given for each:",
            "",
            "1. SALES TARGET vs ACHIEVEMENT — Compare the Grand Total Achieved against the Sales Target. If the target was NOT met, check the STOCK list below: if stock is available for the underperforming/fast-moving models, explicitly recommend that the dealership submit payment against that available stock to help achieve the monthly sales target. If the target was met or exceeded, state that briefly and move on.",
            "2. AGEING — For any vehicle aged 60+ days, urge the dealership to submit payment for those specific vehicles (name the model/chassis) to clear the ageing stock and free up tied-up capital.",
            "3. CRM & DEALERSHIP INFRASTRUCTURE — Identify weak areas among the CRM scorecard parameters below (any parameter below its max points) and recommend specific improvement actions.",
            "4. TEST DRIVE & DIGITAL CONVERSION TARGETS — Identify weak areas in the Test Drive and Digital Enquiry Conversion percentages below and recommend specific improvement actions.",
            "5. SOCIAL MEDIA & REVIEWS — Identify weak areas (followers/reviews below target, low posting frequency, poor rating, etc.) and recommend specific improvement actions.",
            "",
            "Do not mention \"PA\" or \"PB\" anywhere in your response.",
            "",
            "SALES (this month):",
        ];

        $promptLines[] = "Sales Target: " . ($context['sales_target'] !== null ? number_format($context['sales_target']) : "not set");
        $promptLines[] = "Grand Total Achieved: " . ($context['sales_grand_total'] !== null ? number_format($context['sales_grand_total']) : "not set");
        $promptLines[] = "";

        if (empty($context['sales'])) {
            $promptLines[] = "No product-wise sales data on record for this month.";
        } else {
            foreach ($context['sales'] as $s) {
                $label = SpreadsheetImportHelper::friendlyProductLabel($s['product_name']);
                $promptLines[] = "- {$label}: {$s['quantity']} units sold";
            }
        }

        $promptLines[] = "";
        $promptLines[] = "STOCK (current):";
        if (empty($context['stock'])) {
            $promptLines[] = "No stock data on record.";
        } else {
            foreach ($context['stock'] as $s) {
                $promptLines[] = "- {$s['product_name']}: {$s['quantity']} units in stock";
            }
        }
        if (!empty($context['security_amount'])) {
            $promptLines[] = "Security amount held: " . number_format((float)$context['security_amount'], 2);
        }

        $promptLines[] = "";
        $promptLines[] = "AGEING (vehicles in stock 60+ days, measured against this month's last date):";
        if (empty($context['ageing'])) {
            $promptLines[] = "No vehicles aged 60+ days — nothing stuck.";
        } else {
            foreach ($context['ageing'] as $a) {
                $promptLines[] = "- {$a['product_name']} (Chassis {$a['chassis_number']}): {$a['days_aged']} days aged";
            }
        }

        // "Direct result %" parameters (Max Points = 0 — Test Drive/Digital
        // Conversion targets) are called out in their own section 4 above,
        // separate from the normally-scored CRM parameters in section 3.
        $crm = $context['crm'] ?? [];
        $generalCrm = array_filter($crm, fn($c) => (float)$c['max_points'] !== 0.0);
        $directResultCrm = array_filter($crm, fn($c) => (float)$c['max_points'] === 0.0);

        $promptLines[] = "";
        $promptLines[] = "CRM & DEALERSHIP INFRASTRUCTURE SCORECARD (this month, points obtained vs max points):";
        $generalCrmHasData = !empty(array_filter($generalCrm, fn($c) => $c['points_obtained'] !== null));
        if (!$generalCrmHasData) {
            $promptLines[] = "No CRM scorecard data on record for this month.";
        } else {
            foreach ($generalCrm as $c) {
                if ($c['points_obtained'] === null) {
                    continue;
                }
                $promptLines[] = "- {$c['parameter_name']}: {$c['points_obtained']} / {$c['max_points']} points" . ($c['points_obtained'] < $c['max_points'] ? " (below max)" : "");
            }
        }

        $promptLines[] = "";
        $promptLines[] = "TEST DRIVE & DIGITAL CONVERSION TARGETS (this month, % achievement):";
        $directResultHasData = !empty(array_filter($directResultCrm, fn($c) => $c['points_obtained'] !== null));
        if (!$directResultHasData) {
            $promptLines[] = "No test drive/digital conversion data on record for this month.";
        } else {
            foreach ($directResultCrm as $c) {
                if ($c['points_obtained'] === null) {
                    continue;
                }
                $promptLines[] = "- {$c['parameter_name']}: {$c['points_obtained']}% achieved" . ($c['points_obtained'] < 100 ? " (below target)" : "");
            }
        }

        $promptLines[] = "";
        $promptLines[] = "SOCIAL MEDIA & REVIEWS:";
        $social = $context['social'] ?? [];
        $promptLines[] = "- Facebook Followers: " . number_format((int)($social['fb_followers'] ?? 0)) . (($social['fb_target'] ?? 0) > 0 ? " (Target: " . number_format($social['fb_target']) . ")" : "");
        $promptLines[] = "- Instagram Followers: " . number_format((int)($social['ig_followers'] ?? 0)) . (($social['ig_target'] ?? 0) > 0 ? " (Target: " . number_format($social['ig_target']) . ")" : "");
        $promptLines[] = "- YouTube Subscribers: " . number_format((int)($social['yt_subscribers'] ?? 0)) . (($social['yt_target'] ?? 0) > 0 ? " (Target: " . number_format($social['yt_target']) . ")" : "");
        $promptLines[] = "- Facebook Posts/Week: " . number_format((int)($social['fb_posts_week'] ?? 0));
        $promptLines[] = "- Instagram Posts/Week: " . number_format((int)($social['ig_posts_week'] ?? 0));
        $promptLines[] = "- Google Reviews: " . number_format((int)($social['google_review_count'] ?? 0)) . " (Rating: " . ($social['google_rating'] ?? 0) . "/5)" . (($social['google_review_target'] ?? 0) > 0 ? " (Target: " . number_format($social['google_review_target']) . ")" : "");

        $promptLines[] = "";
        $promptLines[] = 'Respond with ONLY this JSON, no other text: {"weak_areas": ["point 1", "point 2", "..."], "summary": "one short overall paragraph"}';
        $promptLines[] = 'weak_areas should list the points IN THE ORDER of the 5 sections above (sales/stock, ageing, CRM, test drive & digital conversion, social media). If nothing significant is wrong, weak_areas should be an empty array and summary should say performance looks healthy overall.';

        $body = json_encode([
            'contents' => [['parts' => [['text' => implode("\n", $promptLines)]]]],
            'generationConfig' => ['response_mime_type' => 'application/json'],
        ]);

        $httpCode = 0;
        $response = null;
        $lastMessage = '';
        $backoffSeconds = [5, 10, 20];

        foreach ($this->models as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

            // Quota-exceeded (429) means THIS model has no requests left for
            // the day — retrying it won't help, so move straight to the next
            // model instead of burning the backoff loop on a dead end.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 || $httpCode === 429) {
                    break;
                }
                sleep($backoffSeconds[$attempt] ?? 20);
            }

            if ($httpCode === 200) {
                break;
            }

            $data = json_decode($response, true);
            $lastMessage = $data['error']['message']
                ?? ($httpCode === 0 ? 'Could Not Reach Gemini (Connection Failed Or Timed Out).' : "HTTP {$httpCode}");
        }

        if ($httpCode !== 200) {
            return ['success' => false, 'message' => $lastMessage . ' (All Models Exhausted.)'];
        }

        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$text) {
            return ['success' => false, 'message' => 'No response from Gemini.'];
        }

        $result = json_decode($text, true);
        if (!is_array($result) || !isset($result['weak_areas'])) {
            return ['success' => false, 'message' => 'Could not parse Gemini response: ' . mb_substr($text, 0, 200)];
        }

        return [
            'success' => true,
            'weak_areas' => $result['weak_areas'],
            'summary' => $result['summary'] ?? '',
        ];
    }
}
