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
    $pdo->beginTransaction();

    // Legacy support for image1, image2, image3
    if ($index <= 3) {
        $column = "image" . (int)$index;
        $stmt = $pdo->prepare("UPDATE videos SET $column = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$relative_path, $video_id, $_SESSION['user_id']]);
    }

    // New assets_json support
    $stmt_assets = $pdo->prepare("SELECT assets_json FROM videos WHERE id = ? AND user_id = ?");
    $stmt_assets->execute([$video_id, $_SESSION['user_id']]);
    $video = $stmt_assets->fetch();

    if ($video) {
        $assets = json_decode($video['assets_json'], true) ?: [];
        if (isset($assets[$index - 1])) {
            $assets[$index - 1]['path'] = $relative_path;
            $stmt_upd = $pdo->prepare("UPDATE videos SET assets_json = ? WHERE id = ?");
            $stmt_upd->execute([json_encode($assets), $video_id]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'path' => $relative_path]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
