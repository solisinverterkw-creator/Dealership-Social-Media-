<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/GoogleReviewLookup.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID missing']);
    exit;
}
if (!Auth::canAccessDealership((int)$id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}
if (!Auth::can('refresh')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You Do Not Have Permission To Refresh Data.']);
    exit;
}

$db = Database::getConnection();
$stmt = $db->prepare("SELECT google_search, google_review_count, google_rating FROM dealerships WHERE id = :id");
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    echo json_encode(['success' => false, 'message' => 'Dealership Not Found']);
    exit;
}

if (empty($d['google_search'])) {
    echo json_encode(['success' => true, 'skipped' => true, 'google_review_count' => (int)$d['google_review_count'], 'google_rating' => $d['google_rating']]);
    exit;
}

$gr = (new GoogleReviewLookup())->searchAndGetReviews($d['google_search']);
if (!$gr['success']) {
    echo json_encode(['success' => false, 'message' => $gr['message'], 'google_review_count' => (int)$d['google_review_count'], 'google_rating' => $d['google_rating']]);
    exit;
}

$db->prepare("UPDATE dealerships SET google_review_count = :count, google_rating = :rating, last_refreshed = NOW() WHERE id = :id")
   ->execute(['count' => $gr['review_count'], 'rating' => $gr['rating'], 'id' => $id]);

echo json_encode(['success' => true, 'google_review_count' => $gr['review_count'], 'google_rating' => $gr['rating']]);
