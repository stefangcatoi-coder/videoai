<?php
// app/generate_assets.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/images_api.php';

$video_id = $argv[1] ?? 0;

if (!$video_id) {
    die("No video ID provided.\n");
}

try {
    // 1. Fetch video data
    $stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ?");
    $stmt->execute([$video_id]);
    $video = $stmt->fetch();

    if (!$video) die("Video not found.\n");

    $keywords = json_decode($video['assets_json'], true) ?: [];
    $assets = [];
    $orientation = ($video['video_type'] === 'short') ? 'portrait' : 'landscape';

    // 2. Helper function to get image from Unsplash or Pexels
    function getAutoImage($keyword, $index, $orientation = 'portrait') {
        $localPath = '';
        $foundUrl = '';

        // 1. Try Unsplash
        $unsplashKey = trim(UNSPLASH_ACCESS_KEY);
        $url = "https://api.unsplash.com/search/photos?query=" . urlencode($keyword) . "&orientation=$orientation&per_page=1";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Client-ID $unsplashKey"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($res, true);
            if (!empty($data['results'][0]['urls']['regular'])) {
                $foundUrl = $data['results'][0]['urls']['regular'];
            }
        }

        // 2. Fallback to Pexels
        if (!$foundUrl) {
            $pexelsKey = trim(PEXELS_API_KEY);
            $url = "https://api.pexels.com/v1/search?query=" . urlencode($keyword) . "&orientation=$orientation&per_page=1";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $pexelsKey"]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($res, true);
                if (!empty($data['photos'][0]['src']['large2x'])) {
                    $foundUrl = $data['photos'][0]['src']['large2x'];
                }
            }
        }

        // 3. Download and Save
        if ($foundUrl) {
            $imgData = @file_get_contents($foundUrl);
            if ($imgData) {
                $filename = "stock_" . time() . "_" . $index . "_" . rand(1000, 9999) . ".jpg";
                $relative_path = "uploads/images/" . $filename;
                $absolute_path = __DIR__ . "/../public/" . $relative_path;

                if (!is_dir(dirname($absolute_path))) {
                    mkdir(dirname($absolute_path), 0777, true);
                }

                file_put_contents($absolute_path, $imgData);
                $localPath = $relative_path;
            }
        }

        // 4. Final Fallback
        if (!$localPath) {
            $placeholderSize = ($orientation === 'portrait') ? '1080x1920' : '1920x1080';
            $localPath = "https://via.placeholder.com/$placeholderSize.png/222222/FFFFFF?text=Imagine+Indisponibila";
        }

        return $localPath;
    }

    // 3. Download Images
    foreach ($keywords as $idx => $kw) {
        $path = getAutoImage($kw, $idx + 1, $orientation);
        $assets[] = ['path' => $path, 'keyword' => $kw];
    }

    // 4. Update Database
    $stmt_upd = $pdo->prepare("UPDATE videos SET status = 'draft', image1 = ?, image2 = ?, image3 = ?, prompt1 = ?, prompt2 = ?, prompt3 = ?, assets_json = ? WHERE id = ?");
    $stmt_upd->execute([
        $assets[0]['path'] ?? '',
        $assets[1]['path'] ?? '',
        $assets[2]['path'] ?? '',
        $assets[0]['keyword'] ?? '',
        $assets[1]['keyword'] ?? '',
        $assets[2]['keyword'] ?? '',
        json_encode($assets),
        $video_id
    ]);

} catch (Exception $e) {
    file_put_contents(__DIR__ . '/../storage/assets_error.log', "Global Error: " . $e->getMessage() . "\n", FILE_APPEND);
}
