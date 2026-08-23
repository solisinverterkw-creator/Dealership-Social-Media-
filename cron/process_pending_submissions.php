<?php
/**
 * Cron: Process Pending Post Submissions via Gemini Vision
 *
 * Picks up all submissions that are still in 'pending' status (and have not
 * been checked yet) and runs the Gemini brand-compliance check on each one,
 * then updates the status to 'approved' or 'rejected'.
 *
 * Designed to run every 1–2 minutes via cron-job.org (or any HTTP pinger):
 *   URL: https://rosp.whf.bz/cron/process_pending_submissions.php?key=CRON_SECRET_KEY
 *
 * Also safe to trigger manually from your browser while logged in as super admin.
 *
 * Why this exists: GoogieHost (free shared hosting) enforces a ~30s PHP
 * execution timeout on web requests. The Gemini API call with multiple
 * reference images can take 60-90s, causing the page to return a
 * "Request Timeout" error and leaving the submission stuck in 'pending'.
 * By doing the heavy Gemini work here in a cron context (also ~30s limited,
 * but we process one submission at a time with a minimal payload thanks to
 * the two-step vehicle identification approach) the main UI responds instantly.
 */

header('Content-Type: text/plain');

// Allow either cron key auth OR super-admin session auth
require_once __DIR__ . '/../config.php';

$cronKeyOk    = isset($_GET['key']) && hash_equals(CRON_SECRET_KEY, $_GET['key']);
$sessionAuthOk = false;
if (!$cronKeyOk) {
    require_once __DIR__ . '/../includes/Auth.php';
    $sessionAuthOk = Auth::isSuperAdmin();
}
if (!$cronKeyOk && !$sessionAuthOk) {
    http_response_code(403);
    echo "Forbidden. Pass ?key=CRON_SECRET_KEY or be logged in as super admin.\n";
    exit;
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/GeminiVisionChecker.php';
set_time_limit(0);

$db = Database::getConnection();

// Fetch pending submissions that have not been checked yet (checked_at IS NULL).
// Limit to 5 at a time so we don't exceed the server's wall-clock time.
$pending = $db->query("
    SELECT ps.*, d.name AS dealership_name
    FROM post_submissions ps
    JOIN dealerships d ON d.id = ps.dealership_id
    WHERE ps.status = 'pending' AND ps.checked_at IS NULL
    ORDER BY ps.submitted_at ASC
    LIMIT 5
")->fetchAll();

if (empty($pending)) {
    echo "No pending submissions to process.\n";
    exit;
}

echo "Found " . count($pending) . " pending submission(s).\n\n";

// Load vehicle reference data once (shared across all submissions this run)
$vehicles = $db->query("SELECT * FROM vehicle_models")->fetchAll();
foreach ($vehicles as &$v) {
    $imgStmt = $db->prepare("SELECT image_path FROM vehicle_model_images WHERE vehicle_model_id = :vid ORDER BY id");
    $imgStmt->execute(['vid' => $v['id']]);
    $v['images'] = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
}
unset($v);
$identity = $db->query("SELECT * FROM brand_identity WHERE id = 1")->fetch();

if (empty($vehicles)) {
    echo "No vehicle reference models configured — cannot check compliance.\n";
    exit;
}

$checker = new GeminiVisionChecker();

foreach ($pending as $sub) {
    $id        = (int)$sub['id'];
    $imagePath = __DIR__ . '/../' . $sub['image_path'];

    echo "Processing submission ID {$id} ({$sub['dealership_name']})... ";

    if (!file_exists($imagePath)) {
        $db->prepare("UPDATE post_submissions SET status = 'rejected', reasons = :r, checked_at = NOW() WHERE id = :id")
           ->execute(['r' => 'Submitted image file not found on server.', 'id' => $id]);
        echo "SKIPPED (image missing)\n";
        continue;
    }

    $result = $checker->checkCompliance($imagePath, $sub['caption'] ?? '', $vehicles, $identity);

    if (!$result['success']) {
        // Gemini call itself failed — mark as rejected with the error reason
        $db->prepare("UPDATE post_submissions SET status = 'rejected', reasons = :r, checked_at = NOW() WHERE id = :id")
           ->execute(['r' => 'Gemini check failed: ' . $result['message'], 'id' => $id]);
        echo "FAILED ({$result['message']})\n";
        continue;
    }

    $status  = $result['approved'] ? 'approved' : 'rejected';
    $reasons = $result['reasons'] ?? [];
    if (!empty($result['suggestion'])) {
        $reasons[] = '💡 Suggested Wording: ' . $result['suggestion'];
    }
    $reasonsText = implode(' | ', $reasons);

    $db->prepare("UPDATE post_submissions SET status = :status, reasons = :reasons, checked_at = NOW() WHERE id = :id")
       ->execute(['status' => $status, 'reasons' => $reasonsText, 'id' => $id]);

    echo strtoupper($status) . "\n";
    if ($reasonsText) {
        echo "   Reasons: {$reasonsText}\n";
    }
}

echo "\nDone.\n";
