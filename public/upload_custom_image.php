<?php
// /var/www/video-ai/public/upload_custom_image.php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$video_id = $_POST['video_id'] ?? 0;
$index = $_POST['index'] ?? 0;
$file = $_FILES['image'] ?? null;
$video_type = $_POST['video_type'] ?? 'short';

if (!$video_id || !$index || !$file) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

$target_w = ($video_type === 'short') ? 1080 : 1920;
$target_h = ($video_type === 'short') ? 1920 : 1080;

// Create image from uploaded file
$src_img = null;
$type = @exif_imagetype($file['tmp_name']);
switch ($type) {
    case IMAGETYPE_JPEG: $src_img = @imagecreatefromjpeg($file['tmp_name']); break;
    case IMAGETYPE_PNG:  $src_img = @imagecreatefrompng($file['tmp_name']); break;
    case IMAGETYPE_WEBP: $src_img = @imagecreatefromwebp($file['tmp_name']); break;
}

if (!$src_img) {
    echo json_encode(['success' => false, 'error' => 'Invalid image format (JPG, PNG, WEBP only)']);
    exit;
}

$src_w = imagesx($src_img);
$src_h = imagesy($src_img);

// Center Crop Logic
$src_aspect = $src_w / $src_h;
$target_aspect = $target_w / $target_h;

if ($src_aspect > $target_aspect) {
    $new_h = $src_h;
    $new_w = $src_h * $target_aspect;
    $src_x = ($src_w - $new_w) / 2;
    $src_y = 0;
} else {
    $new_w = $src_w;
    $new_h = $src_w / $target_aspect;
    $src_x = 0;
    $src_y = ($src_h - $new_h) / 2;
}

$dst_img = imagecreatetruecolor($target_w, $target_h);
imagecopyresampled($dst_img, $src_img, 0, 0, $src_x, $src_y, $target_w, $target_h, $new_w, $new_h);

$filename = "custom_" . time() . "_" . $index . "_" . rand(1000, 9999) . ".jpg";
$relative_path = "uploads/images/" . $filename;
$absolute_path = __DIR__ . "/" . $relative_path;

if (!is_dir(dirname($absolute_path))) {
    mkdir(dirname($absolute_path), 0777, true);
}

imagejpeg($dst_img, $absolute_path, 90);
imagedestroy($src_img);
imagedestroy($dst_img);

// Update DB
try {
    $pdo->beginTransaction();

    if ($index <= 3) {
        $column = "image" . (int)$index;
        $stmt = $pdo->prepare("UPDATE videos SET $column = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$relative_path, $video_id, $_SESSION['user_id']]);
    }

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
