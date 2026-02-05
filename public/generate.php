<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(600); // 10 minutes for API calls

// /var/www/video-ai/public/generate.php

session_start();

// Security Middleware
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/gemini.php';

$user_id = $_SESSION['user_id'];
$error = '';

// Fetch user data to check limits
$stmt = $pdo->prepare("SELECT monthly_limit, videos_used FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$can_generate = ($user['videos_used'] < $user['monthly_limit']);

// Processing Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_generate) {
    $idea = trim($_POST['idea'] ?? '');

    if (!empty($idea)) {
        if (strlen($idea) > 500) {
            $error = "Ideea este prea lungă (maxim 500 caractere).";
        } else {
            try {
                // 1. Prepare Prompt for Gemini (SEO Optimized for 10-15 mins)
                $prompt = "Generate a professional SEO-optimized video plan for: \"$idea\".
                Requirements:
                - Duration: 10-15 minutes long-form YouTube video.
                - Script length: 1500-2200 words.
                - Tone: professional, clear, engaging.
                - Language: Romanian (except image_prompts).
                - Image prompts: 10 cinematic landscape (16:9) descriptions in English.
                - NO emojis, NO stage directions.
                - DO NOT mention images or stock photos in the script.

                Response MUST be a pure JSON object with:
                - title: Catchy title with Hooks.
                - script: The full script (1500-2200 words).
                - description: SEO description with Intro, 3 bullet points, and CTA.
                - tags: 15-20 relevant tags.
                - image_prompts: Array of 10 detailed visual prompts in English for 16:9 landscape.

                Example format:
                {
                  \"title\": \"...\",
                  \"script\": \"...\",
                  \"description\": \"...\",
                  \"tags\": \"...\",
                  \"image_prompts\": [\"prompt 1\", ..., \"prompt 10\"]
                }";

                // 2. Call Gemini API
                $url = GEMINI_API_URL . "?key=" . trim(GEMINI_API_KEY);
                $payload = [
                    "contents" => [["parts" => [["text" => $prompt]]]]
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120); // High timeout for long content

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($httpCode !== 200) {
                    $logMsg = "HTTP Code: $httpCode\nCurl Error: $curlError\nResponse: $response\n";
                    file_put_contents(__DIR__ . '/../storage/debug_api.log', $logMsg);
                    throw new Exception("Eroare API Gemini (HTTP $httpCode). Detalii în storage/debug_api.log.");
                }

                $result = json_decode($response, true);
                $aiResponseText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                if (preg_match('/\{.*\}/s', $aiResponseText, $matches)) {
                    $aiResponseText = $matches[0];
                }

                $aiData = json_decode($aiResponseText, true);

                if (!$aiData || !isset($aiData['script'])) {
                    throw new Exception("AI-ul nu a returnat un format JSON valid.");
                }

                // 3. Process Logic
                // a. Subtitle Color
                $colors = ['yellow', 'white', 'cyan', 'lime', 'orange', 'light blue'];
                $randomColor = $colors[array_rand($colors)];

                // b. Segmentation Logic (600-900 words)
                $fullScript = $aiData['script'] ?? '';
                $words = explode(' ', $fullScript);
                $segments = [];
                $currentSegment = [];
                $wordCount = 0;
                $segmentIndex = 1;

                foreach ($words as $word) {
                    $currentSegment[] = $word;
                    $wordCount++;
                    if ($wordCount >= 750) {
                        $segments[] = "[SEGMENT $segmentIndex]\n" . implode(' ', $currentSegment);
                        $currentSegment = [];
                        $wordCount = 0;
                        $segmentIndex++;
                    }
                }
                if (!empty($currentSegment)) {
                    $segments[] = "[SEGMENT $segmentIndex]\n" . implode(' ', $currentSegment);
                }
                $segmentsJson = json_encode($segments);

                // 4. Save to Database (Initial state)
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO videos (user_id, title, status, script, description, tags, video_type, segments_json, subtitle_color, assets_json) VALUES (?, ?, 'generating_assets', ?, ?, ?, 'landscape', ?, ?, ?)");
                $stmt->execute([
                    $user_id,
                    $aiData['title'] ?? $idea,
                    $fullScript,
                    $aiData['description'] ?? '',
                    $aiData['tags'] ?? '',
                    $segmentsJson,
                    $randomColor,
                    json_encode($aiData['image_prompts'] ?? []) // Store prompts temporarily in assets_json
                ]);

                $video_id = $pdo->lastInsertId();
                $pdo->commit();

                // 5. Trigger Async Assets Worker
                $cmd = "php " . __DIR__ . "/../app/generate_assets.php " . $video_id . " > /dev/null 2>&1 &";
                exec($cmd);

                header("Location: dashboard.php?success=Planul a fost generat! Acum descărcăm imaginile în fundal.");
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
    } else {
        $error = "Vă rugăm să introduceți ideea video-ului.";
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generează Video - Video AI</title>
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; margin: 0; display: flex; }
        .main-content { margin-left: 250px; padding: 2rem; width: 100%; display: flex; justify-content: center; }
        .container { width: 100%; max-width: 600px; }
        h1 { color: #ffffff; margin-bottom: 2rem; }
        .card { background-color: #1e1e1e; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5); }
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; margin-bottom: 0.5rem; font-weight: bold; color: #bb86fc; }
        input { width: 100%; padding: 0.75rem; border-radius: 4px; border: 1px solid #333; background-color: #2c2c2c; color: #fff; box-sizing: border-box; font-size: 1rem; }
        .btn-generate { width: 100%; padding: 1rem; border: none; border-radius: 4px; background-color: #03dac6; color: #121212; font-weight: bold; font-size: 1.1rem; cursor: pointer; transition: background-color 0.3s; }
        .btn-generate:hover { background-color: #01b0a1; }
        .error { color: #cf6679; background-color: rgba(207, 102, 121, 0.1); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem; text-align: center; }
        .loading-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 1000; justify-content: center; align-items: center; flex-direction: column; }
        .spinner { border: 4px solid #333; border-top: 4px solid #03dac6; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin-bottom: 1rem; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/header.php'; ?>
    <div class="main-content">
        <div class="container">
            <h1>Generează Video Nou</h1>
            <?php if (!$can_generate): ?>
                <div class="error">Limită atinsă! Ai folosit <?php echo $user['videos_used']; ?> din <?php echo $user['monthly_limit']; ?> video-uri.</div>
            <?php else: ?>
                <div class="card">
                    <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                    <form method="POST" id="genForm">
                        <div class="form-group">
                            <label for="idea">Ideea Video-ului</label>
                            <input type="text" name="idea" id="idea" placeholder="Ex: Cum să gătești paste" maxlength="500" required>
                        </div>
                        <button type="submit" class="btn-generate">Generează Plan și Imagini AI</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div id="loading" class="loading-overlay">
        <div class="spinner"></div>
        <p>Gemini lucrează... Te rugăm să aștepți (aprox. 1 minut).</p>
    </div>
    <script>
        document.getElementById('genForm').addEventListener('submit', function() {
            document.getElementById('loading').style.display = 'flex';
        });
    </script>
</body>
</html>
