<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// /var/www/video-ai/public/ajax_generate_image.php

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/deapi.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$video_id = $_GET['video_id'] ?? 0;
$index = $_GET['index'] ?? 0;
$user_id = $_SESSION['user_id'];

try {
    if ($action === 'initiate') {
        if (!$video_id || !in_array($index, [1, 2, 3])) {
            throw new Exception("Parametri invalizi pentru inițiere.");
        }

        $stmt = $pdo->prepare("SELECT prompt1, prompt2, prompt3 FROM videos WHERE id = ? AND user_id = ?");
        $stmt->execute([$video_id, $user_id]);
        $video = $stmt->fetch();

        if (!$video) throw new Exception("Video inexistent.");

        $promptKey = "prompt" . $index;
        $prompt = $video[$promptKey] ?? '';
        if (empty($prompt)) throw new Exception("Prompt lipsă.");

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
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey", "Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 429) {
            echo json_encode(['success' => false, 'isRateLimited' => true, 'error' => 'Rate limit la inițiere.']);
            exit;
        }

        if ($httpCode !== 200) {
            file_put_contents(__DIR__ . '/../storage/debug_deapi.log', "Action Initiate Error ($httpCode): " . $response . "\n", FILE_APPEND);
            throw new Exception("Eroare DeAPI (HTTP $httpCode).");
        }

        $result = json_decode($response, true);
        $requestId = $result['request_id'] ?? $result['id'] ?? $result['data']['id'] ?? $result['data']['request_id'] ?? $result['task_id'] ?? null;
        $imgUrl = $result['data'][0]['url'] ?? $result['url'] ?? $result['output'][0] ?? $result['data']['url'] ?? '';

        echo json_encode(['success' => true, 'requestId' => $requestId, 'imgUrl' => $imgUrl]);

    } elseif ($action === 'poll') {
        $requestId = $_GET['requestId'] ?? '';
        if (empty($requestId)) throw new Exception("RequestId lipsă.");

        $apiKey = trim(DEAPI_API_KEY);
        $statusUrl = trim(DEAPI_STATUS_URL) . $requestId;

        $ch = curl_init($statusUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $statusRes = curl_exec($ch);
        $statusHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusHttp === 429) {
            echo json_encode(['success' => false, 'isRateLimited' => true, 'error' => 'Too Many Requests']);
            exit;
        }

        if ($statusHttp !== 200) {
            file_put_contents(__DIR__ . '/../storage/debug_deapi.log', "Action Poll Error ($statusHttp) for ID $requestId: " . $statusRes . "\n", FILE_APPEND);
            throw new Exception("Eroare verificare status ($statusHttp).");
        }

        $statusData = json_decode($statusRes, true);
        $status = $statusData['status'] ?? '';
        $imgUrl = $statusData['output'][0] ?? $statusData['url'] ?? ($statusData['data'][0]['url'] ?? $statusData['data']['url'] ?? '');

        $isCompleted = ($status === 'completed' || $status === 'succeeded' || !empty($imgUrl));
        $isFailed = ($status === 'failed');

        echo json_encode([
            'success' => true,
            'status' => $status,
            'completed' => $isCompleted,
            'failed' => $isFailed,
            'imgUrl' => $imgUrl
        ]);

    } elseif ($action === 'save') {
        $imgUrl = $_GET['imgUrl'] ?? '';
        if (!$video_id || !$index || empty($imgUrl)) {
            throw new Exception("Parametri invalizi pentru salvare.");
        }

        $imgData = @file_get_contents($imgUrl);
        if ($imgData === false) {
            throw new Exception("Nu am putut descărca imaginea de la URL-ul furnizat.");
        }

        $filename = "img_" . $video_id . "_" . $index . "_" . time() . ".jpg";
        $relative_path = "uploads/images/" . $filename;
        $absolute_path = __DIR__ . "/" . $relative_path;

        $dir = dirname($absolute_path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        file_put_contents($absolute_path, $imgData);

        // Update DB
        $column = "image" . (int)$index;
        $stmt_upd = $pdo->prepare("UPDATE videos SET $column = ? WHERE id = ? AND user_id = ?");
        $stmt_upd->execute([$relative_path, $video_id, $user_id]);

        echo json_encode(['success' => true, 'path' => $relative_path]);
    } else {
        throw new Exception("Acțiune invalidă.");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
