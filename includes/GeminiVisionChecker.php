<?php
require_once __DIR__ . '/../config.php';

class GeminiVisionChecker
{
    // Tried in order, same API key — each Gemini model has its own separate
    // free-tier daily request quota, so when one is exhausted (HTTP 429 /
    // "quota exceeded"), the next one down the list still has requests left
    // instead of the whole feature going down for the day. Same fallback
    // chain as VisitReportAnalyzer.
    private array $models = ['gemini-flash-latest'];

    private function encodeImage(string $path, int $maxDim = 720): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $data = null;
        $mime = 'image/jpeg';

        if (function_exists('imagecreatefromstring')) {
            $raw = @file_get_contents($path);
            $src = $raw ? @imagecreatefromstring($raw) : null;
            if ($src) {
                $w = imagesx($src);
                $h = imagesy($src);
                if ($w > $maxDim || $h > $maxDim) {
                    if ($w >= $h) {
                        $newW = $maxDim;
                        $newH = (int)round(($h / $w) * $maxDim);
                    } else {
                        $newH = $maxDim;
                        $newW = (int)round(($w / $h) * $maxDim);
                    }
                    $dst = imagecreatetruecolor($newW, $newH);
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
                    imagedestroy($src);
                    ob_start();
                    imagejpeg($dst, null, 82);
                    $data = ob_get_clean();
                    imagedestroy($dst);
                    $mime = 'image/jpeg';
                } else {
                    $data = $raw;
                }
            }
        }

        if ($data === null) {
            $data = file_get_contents($path);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };
        }

        return [
            'inline_data' => [
                'mime_type' => $mime,
                'data' => base64_encode($data),
            ],
        ];
    }

    /**
     * Step 1: Identifies the exact car model in the submitted image and checks
     * if it matches any of the approved vehicle models configured in Brand Assets.
     */
    private function identifyVehicle(string $submittedImagePath, array $vehicleModels): array
    {
        $modelNames = array_column($vehicleModels, 'name');
        if (empty($modelNames)) {
            return ['status' => 'no_models'];
        }

        $promptLines = [
            "You are an expert car model identifier.",
            "Analyze the submitted post image and identify the exact car model shown (e.g. 'Suzuki Swift', 'Suzuki Alto', 'Suzuki Cultus', 'Suzuki Every', 'Suzuki Fronx', etc.).",
            "Compare the detected car model against this list of APPROVED vehicle models configured in Brand Assets:",
            "Approved Models: " . implode(', ', $modelNames),
            "",
            "Respond with ONLY this JSON, no other text:",
            '{"detected_car_model": "detected model name", "is_in_approved_list": true|false, "matched_approved_name": "exact name from approved list or null"}'
        ];

        $parts = [];
        $parts[] = ['text' => implode("\n", $promptLines)];

        $submittedEncoded = $this->encodeImage($submittedImagePath);
        if (!$submittedEncoded) {
            return ['status' => 'error'];
        }
        $parts[] = $submittedEncoded;

        $body = json_encode([
            'contents' => [['parts' => $parts]],
            'generationConfig' => ['response_mime_type' => 'application/json'],
        ]);

        $httpCode = 0;
        $response = null;

        foreach ($this->models as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

            for ($attempt = 0; $attempt < 2; $attempt++) {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 || $httpCode === 429) {
                    break;
                }
                sleep(2);
            }

            if ($httpCode === 200) {
                break;
            }
        }

        if ($httpCode !== 200 || !$response) {
            return ['status' => 'error'];
        }

        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$text) {
            return ['status' => 'error'];
        }

        $result = json_decode($text, true);
        if (!is_array($result)) {
            return ['status' => 'error'];
        }

        $detectedModel = $result['detected_car_model'] ?? 'Unknown Vehicle';
        $matchedName   = $result['matched_approved_name'] ?? null;
        $isInApproved  = !empty($result['is_in_approved_list']);

        // Double check against DB model names
        foreach ($vehicleModels as $v) {
            if (strcasecmp($v['name'], $matchedName) === 0 || strcasecmp($v['name'], $detectedModel) === 0) {
                return [
                    'status'         => 'matched',
                    'detected_model' => $detectedModel,
                    'vehicle'        => $v
                ];
            }
        }

        return [
            'status'         => 'unapproved_model',
            'detected_model' => $detectedModel
        ];
    }

    /**
     * Sends the submitted post image + selected target vehicle reference assets
     * (vehicle photos, logo variants, tagline) to Gemini in one call and asks it to
     * check every rule at once — vehicle model & parts match, logo subtext, contrast, tagline.
     */
    public function checkCompliance(string $submittedImagePath, string $caption, array $targetVehicle, $identity): array
    {
        $identity = $identity ?: null; // PDO::fetch() returns false (not null) when brand_identity has no row yet
        
        $vehicleModels = [$targetVehicle];
        
        // Pre-encode reference images and build exact prompt descriptions
        $imageParts = [];
        $referenceDescriptions = [];
        $refIndex = 1;
        $vehicleImageCount = 0;

        foreach ($vehicleModels as $v) {
            $images = $v['images'] ?? (!empty($v['reference_image']) ? [$v['reference_image']] : []);
            foreach ($images as $imagePath) {
                $encoded = $this->encodeImage(__DIR__ . '/../' . $imagePath);
                if ($encoded) {
                    $imageParts[] = $encoded;
                    $referenceDescriptions[] = "Reference image {$refIndex}: approved target vehicle — {$v['name']}, color {$v['color']} (one of " . count($images) . " reference photos of this vehicle).";
                    $refIndex++;
                    $vehicleImageCount++;
                }
            }
        }

        if ($vehicleImageCount === 0) {
            return ['success' => false, 'message' => "No reference photos found for '{$targetVehicle['name']}' on server. Please upload reference photos in Brand Assets."];
        }

        if (!empty($identity['logo_light_path'])) {
            $encoded = $this->encodeImage(__DIR__ . '/../' . $identity['logo_light_path']);
            if ($encoded) {
                $imageParts[] = $encoded;
                $referenceDescriptions[] = "Reference image {$refIndex}: LIGHT logo variant (for dark/blue backgrounds).";
                $refIndex++;
            }
        }
        if (!empty($identity['logo_dark_path'])) {
            $encoded = $this->encodeImage(__DIR__ . '/../' . $identity['logo_dark_path']);
            if ($encoded) {
                $imageParts[] = $encoded;
                $referenceDescriptions[] = "Reference image {$refIndex}: DARK logo variant (for white/light backgrounds).";
                $refIndex++;
            }
        }
        if (!empty($identity['logo_white_bg_path'])) {
            $encoded = $this->encodeImage(__DIR__ . '/../' . $identity['logo_white_bg_path']);
            if ($encoded) {
                $imageParts[] = $encoded;
                $referenceDescriptions[] = "Reference image {$refIndex}: RED & BLUE (full-color) logo variant (for white backgrounds specifically).";
                $refIndex++;
            }
        }

        $submittedEncoded = $this->encodeImage($submittedImagePath, 720);
        if (!$submittedEncoded) {
            return ['success' => false, 'message' => 'Submitted post image could not be read.'];
        }

        $ruleNum = 1;
        $promptLines = [
            "You are a strict brand-compliance auditor for a car dealership's social media post.",
            "Check the SUBMITTED POST IMAGE (the last image below) against ALL of these rules with 100% precision, using the earlier reference images as ground truth:",
            "",
        ];
        
        $targetName = $targetVehicle['name'];
        $promptLines[] = ($ruleNum++) . ". MANDATORY VEHICLE MODEL MATCH ({$targetName}):\n" .
            "   Look at the car body shape, front grille, headlights, and ANY text overlay/badge on the submitted graphic (such as 'FRONX', 'ALTO', 'CULTUS', 'EVERY', 'SWIFT').\n" .
            "   The user explicitly selected '{$targetName}' in the form as the target car model for this post.\n" .
            "   IF THE SUBMITTED GRAPHIC SHOWS A DIFFERENT CAR MODEL OR TEXT BADGE (for example: if the graphic features a Fronx SUV, Alto, Cultus, or Every when '{$targetName}' is selected), YOU MUST REJECT IMMEDIATELY with reason 'Vehicle Model Mismatch: Graphic shows a different car model, but {$targetName} was selected'.";

        $promptLines[] = ($ruleNum++) . ". DOOR HANDLES STRICT CHROME AUDIT:\n" .
            "   Zoom in on the front and rear door handles of the car in the graphic.\n" .
            "   Standard approved {$targetName} specification requires CHROME / METALLIC SILVER door handles.\n" .
            "   CRITICAL INSTRUCTION: If the door handles on the car are WHITE, PAINTED, BODY-COLOR, or if you cannot see a distinct metallic chrome shine on the handles, YOU MUST REJECT IMMEDIATELY with reason 'Vehicle Spec Violation: Door handles are painted white body-color, whereas approved {$targetName} specification requires Chrome handles'. DO NOT APPROVE WHITE OR BODY-COLOR PAINTED DOOR HANDLES!";

        $hasLogo = !empty($identity['logo_light_path']) || !empty($identity['logo_dark_path']) || !empty($identity['logo_white_bg_path']);
        if ($hasLogo) {
            $promptLines[] = ($ruleNum++) . ". LOGO VARIANT AUDIT:\n" .
                "   LOGO BACKGROUND CONTRAST: Examine the background patch directly behind the Suzuki logo. If the background is DARK or BLUE, the WHITE/LIGHT logo variant is required (which is valid and correct). If the background is WHITE, the RED & BLUE or DARK logo variant is required. Only reject if wrong variant is used (e.g. white logo on white background, or dark logo on black background).";
        }
        if (!empty($identity['tagline'])) {
            $promptLines[] = ($ruleNum++) . ". TAGLINE: The post caption should include or closely reflect this tagline: \"{$identity['tagline']}\". Missing it = REJECT.";
        }
        $brandColors = array_filter([$identity['primary_color'] ?? null, $identity['secondary_color'] ?? null]);
        if (!empty($brandColors)) {
            $colorList = implode(' and ', $brandColors);
            $promptLines[] = ($ruleNum++) . ". BRAND COLOR: The submitted image's graphic design elements (background, text overlays, banners, branding accents — NOT the vehicle's own paint color) should prominently use the brand color(s): {$colorList}. If the design clearly ignores these brand colors in favor of unrelated ones, REJECT.";
        }
        if (!empty($identity['website_url'])) {
            $promptLines[] = ($ruleNum++) . ". WEBSITE: The post caption should include or closely reflect this website: \"{$identity['website_url']}\". Missing it = REJECT.";
        }
        $promptLines[] = ($ruleNum++) . ". WORDING RELEVANCE: If the submitted image has any overlay text/wording baked into the graphic (headline, banner text, promotional copy — not the separate caption), it must make clear, sensible sense together with what the image actually shows (the vehicle, background, mood, occasion). REJECT if the wording is generic filler, unrelated to the image, nonsensical, or contradicts what's visually shown. When rejecting for this reason, also put one concrete example of better wording that WOULD fit this image in the \"suggestion\" field.";

        $promptLines[] = "";
        $promptLines[] = "Submitted post caption: \"" . ($caption !== '' ? $caption : '(no caption)') . "\"";
        $promptLines[] = "";
        $promptLines[] = "Reference images attached below, in order:";
        foreach ($referenceDescriptions as $desc) {
            $promptLines[] = $desc;
        }
        $promptLines[] = "";
        $promptLines[] = "Last image attached below is the SUBMITTED POST IMAGE to judge.";
        $promptLines[] = "";
        $promptLines[] = "COMPREHENSIVE AUDIT INSTRUCTION: Do NOT stop after finding the first violation. You MUST evaluate ALL rules (Vehicle Model & Parts Audit, Logo Accuracy & Subtext, Tagline, Brand Colors, and Wording Relevance). If multiple violations exist (e.g. BOTH a logo subtext issue AND a vehicle door handle finish mismatch), YOU MUST INCLUDE ALL VIOLATIONS in the 'reasons' array so the user receives a complete report!";
        $promptLines[] = 'Respond with ONLY this JSON, no other text: {"approved": true|false, "reasons": ["violation 1", "violation 2", "..."], "suggestion": "better wording example, or null"}';
        $promptLines[] = 'If approved with zero issues across all rules, reasons should be an empty array and suggestion null.';

        $parts = [];
        $parts[] = ['text' => implode("\n", $promptLines)];
        foreach ($imageParts as $imgPart) {
            $parts[] = $imgPart;
        }
        $parts[] = $submittedEncoded;

        $body = json_encode([
            'contents' => [['parts' => $parts]],
            'generationConfig' => ['response_mime_type' => 'application/json'],
        ]);

        // A model can also return a transient 503 "high demand" error, or the
        // connection can drop outright under sustained overload (curl reports
        // HTTP 0) — retry a few times with backoff before moving to the next
        // model. 429 (quota exceeded) means THIS model has no requests left
        // for the day, so move straight to the next model instead of burning
        // the backoff loop on a dead end.
        $httpCode = 0;
        $response = null;
        $lastMessage = '';
        $backoffSeconds = [5, 10, 20];

        foreach ($this->models as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200) {
                break;
            }

            $data = json_decode($response, true);
            $lastMessage = $data['error']['message']
                ?? (!empty($curlError) ? "cURL Error: {$curlError}" : ($httpCode === 0 ? 'Gemini API Connection Timed Out.' : "HTTP {$httpCode}"));
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
        if (!is_array($result) || !isset($result['approved'])) {
            return ['success' => false, 'message' => 'Could not parse Gemini response: ' . mb_substr($text, 0, 200)];
        }

        return [
            'success' => true,
            'approved' => (bool)$result['approved'],
            'reasons' => $result['reasons'] ?? [],
            'suggestion' => $result['suggestion'] ?? null,
        ];
    }
}
