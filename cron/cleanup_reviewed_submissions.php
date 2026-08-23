<?php
require_once __DIR__ . '/../config.php';
$isCli = php_sapi_name() === 'cli';
$hasValidKey = isset($_GET['key']) && hash_equals(CRON_SECRET_KEY, $_GET['key']);
if (!$isCli && !$hasValidKey) {
    http_response_code(403);
    exit('CLI only, or provide a valid ?key=.');
}

require_once __DIR__ . '/../includes/Database.php';

$db = Database::getConnection();

// 15 days after the compliance check ran (checked_at), not after submission —
// a post can sit pending for a while before a super admin/checker gets to it.
// Pending submissions are left alone — only a final approved/rejected verdict starts the clock.
$stmt = $db->query("
    SELECT id, image_path FROM post_submissions
    WHERE status IN ('approved', 'rejected') AND checked_at IS NOT NULL AND checked_at <= NOW() - INTERVAL 15 DAY
");
$rows = $stmt->fetchAll();

echo "[" . date('Y-m-d H:i:s') . "] Reviewed-submission cleanup — " . count($rows) . " row(s) older than 15 days.\n";

$delete = $db->prepare("DELETE FROM post_submissions WHERE id = :id");
foreach ($rows as $row) {
    $fullPath = __DIR__ . '/../' . $row['image_path'];
    if (is_file($fullPath)) {
        unlink($fullPath);
    }
    $delete->execute(['id' => $row['id']]);
    echo "  Deleted submission #{$row['id']} ({$row['image_path']})\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Reviewed-submission cleanup done.\n";
