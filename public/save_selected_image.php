<?php
// /var/www/video-ai/public/save_selected_image.php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$video_id = $_POST['video_id'] ?? 0;
$index = $_POST['index'] ?? 0;
$imgUrl = $_POST['url'] ?? '';

if (!$video_id || !$index || !$imgUrl) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

// Download image locally
$imgData = @file_get_contents($imgUrl);
if (!$imgData) {
    echo json_encode(['success' => false, 'error' => 'Failed to download image']);
    exit;
}

$filename = "selected_" . time() . "_" . $index . "_" . rand(1000, 9999) . ".jpg";
$relative_path = "uploads/images/" . $filename;
$absolute_path = __DIR__ . "/" . $relative_path;

if (!is_dir(dirname($absolute_path))) {
    mkdir(dirname($absolute_path), 0777, true);
}

file_put_contents($absolute_path, $imgData);

// Update DB
try {
    $column = "image" . (int)$index;
    $stmt = $pdo->prepare("UPDATE videos SET $column = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$relative_path, $video_id, $_SESSION['user_id']]);

    echo json_encode(['success' => true, 'path' => $relative_path]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
