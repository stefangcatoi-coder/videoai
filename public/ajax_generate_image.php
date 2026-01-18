<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(300); // 5 minutes for one image

// /var/www/video-ai/public/ajax_generate_image.php

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/deapi.php';

header('Content-Type: application/json');

$video_id = $_GET['video_id'] ?? 0;
$index = $_GET['index'] ?? 0;
$user_id = $_SESSION['user_id'];

if (!$video_id || !in_array($index, [1, 2, 3])) {
    echo json_encode(['error' => 'Parametri invalizi.']);
    exit;
}

// Fetch prompt and verify ownership
$stmt = $pdo->prepare("SELECT prompt1, prompt2, prompt3 FROM videos WHERE id = ? AND user_id = ?");
$stmt->execute([$video_id, $user_id]);
$video = $stmt->fetch();

if (!$video) {
    echo json_encode(['error' => 'Video inexistent sau acces interzis.']);
    exit;
}

$promptKey = "prompt" . $index;
$prompt = $video[$promptKey] ?? '';

if (empty($prompt)) {
    echo json_encode(['error' => 'Prompt lipsă pentru această imagine.']);
    exit;
}

try {
    $imgUrl = generateImage($prompt);
    $localPath = downloadImage($imgUrl, $video_id, $index);

    // Update DB
    $column = "image" . $index;
    $stmt_upd = $pdo->prepare("UPDATE videos SET $column = ? WHERE id = ?");
    $stmt_upd->execute([$localPath, $video_id]);

    echo json_encode(['success' => true, 'path' => $localPath]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

function generateImage($prompt) {
    $apiKey = trim(DEAPI_API_KEY);
    $url = trim(DEAPI_API_URL);

    $payload = [
        "prompt" => $prompt,
        "model" => "Flux1schnell",
        "width" => 1080,
        "height" => 1920,
        "seed" => rand(1, 99999999),
        "steps" => 4
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        file_put_contents(__DIR__ . '/../storage/debug_deapi.log', "HTTP $httpCode (Initiate): " . $response . "\n", FILE_APPEND);
        throw new Exception("Eroare DeAPI (HTTP $httpCode).");
    }

    $result = json_decode($response, true);
    $requestId = $result['request_id'] ?? $result['id'] ?? $result['data']['id'] ?? $result['data']['request_id'] ?? $result['task_id'] ?? null;

    if (!$requestId) {
        $imgUrl = $result['data'][0]['url'] ?? $result['url'] ?? $result['output'][0] ?? $result['data']['url'] ?? '';
        if (empty($imgUrl)) {
            file_put_contents(__DIR__ . '/../storage/debug_deapi.log', "HTTP 200 (Invalid Format): " . $response . "\n", FILE_APPEND);
            throw new Exception("DeAPI nu a returnat un request_id sau un URL valid.");
        }
        return $imgUrl;
    } else {
        $statusUrl = trim(DEAPI_STATUS_URL) . $requestId;
        $maxAttempts = 50;
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

                if ($status === 'completed' || $status === 'succeeded' || isset($statusData['output']) || isset($statusData['url']) || isset($statusData['data']['url'])) {
                     $imgUrl = $statusData['output'][0] ?? $statusData['url'] ?? ($statusData['data'][0]['url'] ?? $statusData['data']['url'] ?? '');
                     if ($imgUrl) return $imgUrl;
                } elseif ($status === 'failed') {
                    throw new Exception("Generarea imaginii a eșuat la DeAPI.");
                }
            }
        }
    }
    throw new Exception("Timeout la generarea imaginii.");
}

function downloadImage($imgUrl, $videoId, $index) {
    $imgData = @file_get_contents($imgUrl);
    if ($imgData === false) {
        throw new Exception("Nu am putut descărca imaginea.");
    }

    $filename = "img_" . $videoId . "_" . $index . "_" . time() . ".jpg";
    $relative_path = "uploads/images/" . $filename;
    $absolute_path = __DIR__ . "/" . $relative_path;

    $dir = dirname($absolute_path);
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    file_put_contents($absolute_path, $imgData);
    return $relative_path;
}
?>
