<?php
// app/generate_assets.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/deapi.php';

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

    $prompts = json_decode($video['assets_json'], true) ?: [];
    $localImages = [];

    // 2. Helper function to generate and download image via DeAPI.ai
    function downloadImage($prompt, $videoId, $index) {
        $apiKey = trim(DEAPI_API_KEY);
        $url = DEAPI_API_URL;

        $payload = [
            "prompt" => $prompt,
            "model" => "Flux.1-schnell",
            "width" => 1920,
            "height" => 1080,
            "seed" => rand(1, 99999999)
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey", "Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) throw new Exception("DeAPI Error $httpCode: $response");

        $result = json_decode($response, true);
        $requestId = $result['request_id'] ?? $result['id'] ?? null;
        $imgUrl = '';

        if (!$requestId) {
            $imgUrl = $result['data'][0]['url'] ?? $result['url'] ?? $result['output'][0] ?? '';
        } else {
            // Polling
            $statusUrl = DEAPI_STATUS_URL . $requestId;
            $maxAttempts = 40;
            $attempts = 0;
            while ($attempts < $maxAttempts) {
                sleep(3);
                $attempts++;
                $ch = curl_init($statusUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey"]);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                $statusRes = curl_exec($ch);
                $statusHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($statusHttp === 200) {
                    $statusData = json_decode($statusRes, true);
                    $status = $statusData['status'] ?? '';
                    if ($status === 'completed' || $status === 'succeeded' || isset($statusData['output']) || isset($statusData['url'])) {
                         $imgUrl = $statusData['output'][0] ?? $statusData['url'] ?? ($statusData['data'][0]['url'] ?? '');
                         if ($imgUrl) break;
                    } elseif ($status === 'failed') throw new Exception("DeAPI Generation Failed.");
                }
            }
        }

        if (empty($imgUrl)) throw new Exception("DeAPI Timeout/No URL.");

        // Download
        $imgData = @file_get_contents($imgUrl);
        if ($imgData === false) throw new Exception("Download failed from $imgUrl");

        $filename = "img_" . $videoId . "_" . $index . "_" . time() . ".jpg";
        $relative_path = "uploads/images/" . $filename;
        $absolute_path = __DIR__ . "/../public/" . $relative_path;

        if (!is_dir(dirname($absolute_path))) mkdir(dirname($absolute_path), 0775, true);
        file_put_contents($absolute_path, $imgData);
        return $relative_path;
    }

    // 3. Generate 10 images
    foreach ($prompts as $i => $prompt) {
        if ($i >= 10) break;
        try {
            $localImages[] = downloadImage($prompt, $video_id, $i + 1);
        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/../storage/assets_error.log', "Error on image $i: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    // 4. Update Database
    $stmt_upd = $pdo->prepare("UPDATE videos SET status = 'draft', image1 = ?, image2 = ?, image3 = ?, assets_json = ? WHERE id = ?");
    $stmt_upd->execute([
        $localImages[0] ?? '',
        $localImages[1] ?? '',
        $localImages[2] ?? '',
        json_encode($localImages),
        $video_id
    ]);

} catch (Exception $e) {
    file_put_contents(__DIR__ . '/../storage/assets_error.log', "Global Error: " . $e->getMessage() . "\n", FILE_APPEND);
}
