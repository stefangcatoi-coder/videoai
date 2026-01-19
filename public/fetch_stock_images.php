<?php
// /var/www/video-ai/public/fetch_stock_images.php
session_start();
require_once __DIR__ . '/../config/images_api.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$query = $_GET['query'] ?? 'nature';
$images = [];

// Helper to fetch from Unsplash
function fetchUnsplash($q, $count) {
    $key = trim(UNSPLASH_ACCESS_KEY);
    if ($key === 'YOUR_UNSPLASH_ACCESS_KEY' || empty($key)) return [];

    $url = "https://api.unsplash.com/search/photos?query=" . urlencode($q) . "&orientation=portrait&per_page=" . $count;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Client-ID $key"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return [];
    $data = json_decode($res, true);
    $results = [];
    foreach ($data['results'] ?? [] as $item) {
        $results[] = [
            'url' => $item['urls']['regular'],
            'thumb' => $item['urls']['small'],
            'source' => 'Unsplash'
        ];
    }
    return $results;
}

// Helper to fetch from Pexels
function fetchPexels($q, $count) {
    $key = trim(PEXELS_API_KEY);
    if ($key === 'YOUR_PEXELS_API_KEY' || empty($key)) return [];

    $url = "https://api.pexels.com/v1/search?query=" . urlencode($q) . "&orientation=portrait&per_page=" . $count;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $key"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return [];
    $data = json_decode($res, true);
    $results = [];
    foreach ($data['photos'] ?? [] as $item) {
        $results[] = [
            'url' => $item['src']['large2x'],
            'thumb' => $item['src']['small'],
            'source' => 'Pexels'
        ];
    }
    return $results;
}

// Logic: try 5 + 5
$unsplashImages = fetchUnsplash($query, 5);
$pexelsImages = fetchPexels($query, 5);

// Fallback logic
if (count($unsplashImages) < 5 && count($pexelsImages) >= 5) {
    $needed = 10 - count($unsplashImages);
    $pexelsImages = fetchPexels($query, $needed);
} elseif (count($pexelsImages) < 5 && count($unsplashImages) >= 5) {
    $needed = 10 - count($pexelsImages);
    $unsplashImages = fetchUnsplash($query, $needed);
}

$images = array_merge($unsplashImages, $pexelsImages);

echo json_encode(['success' => true, 'images' => array_slice($images, 0, 10)]);
