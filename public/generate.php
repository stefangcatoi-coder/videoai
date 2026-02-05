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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_generate) {
    $idea = trim($_POST['idea'] ?? '');
    $video_type = $_POST['video_type'] ?? 'short';
    $language = $_POST['language'] ?? 'ro';

    if (!empty($idea)) {
        try {
            // 1. Prepare Prompt for Gemini
            $langName = ($language === 'en') ? 'English' : 'Romanian';
            $orientation = ($video_type === 'short') ? 'portrait (9:16)' : 'landscape (16:9)';
            $scriptReq = ($video_type === 'short')
                ? "a script of 50-60 words"
                : "a natural, engaging script of approximately 1500-2000 words (matching a 10-15 minute narration)";
            $numKeywords = ($video_type === 'short') ? 3 : 20;

            $prompt = "Generate a professional video plan for the idea: \"$idea\".
            Target format: $video_type ($orientation).
            Language: $langName.

            Return ONLY a valid JSON object without markdown formatting.
            The JSON MUST include:
            - title: Captivating, SEO-friendly title in $langName.
            - script: $scriptReq in $langName. No emojis, no stage directions.
            - description: 2-3 paragraphs SEO optimized description in $langName.
            - tags: 15-20 comma-separated keywords in $langName.
            - keywords: An array of $numKeywords visual search keywords in ENGLISH (even if the script is Romanian).
              Keywords must be realistic for stock photos (no AI generation style).
              If format is long-form/landscape, keywords MUST favor 'landscape', 'wide shot', '16:9' style content.
              Avoid words like 'portrait', 'vertical', or 'close-up'.

            Format example:
            {
              \"title\": \"...\",
              \"script\": \"...\",
              \"description\": \"...\",
              \"tags\": \"...\",
              \"keywords\": [\"keyword 1\", \"keyword 2\", ...]
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);

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
            if (preg_match('/\{.*\}/s', $aiResponseText, $matches)) { $aiResponseText = $matches[0]; }
            $aiData = json_decode($aiResponseText, true);

            if (!$aiData || !isset($aiData['script'])) {
                throw new Exception("AI-ul nu a returnat un format JSON valid.");
            }

            // 3. Process Logic
            // a. Subtitle Color
            $allowed_colors = ['yellow', 'white', 'cyan', 'lime', 'orange', 'light blue'];
            $subtitle_color = $allowed_colors[array_rand($allowed_colors)];

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

            // 4. Save to Database
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO videos (user_id, title, status, script, description, tags, video_type, language, subtitle_color, segments_json, assets_json) VALUES (?, ?, 'generating_assets', ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([
                $user_id,
                $aiData['title'] ?? $idea,
                $fullScript,
                $aiData['description'] ?? '',
                $aiData['tags'] ?? '',
                $video_type,
                $language,
                $subtitle_color,
                $segmentsJson,
                json_encode($aiData['keywords'] ?? []) // Store keywords temporarily in assets_json
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
        input, select { width: 100%; padding: 0.75rem; border-radius: 4px; border: 1px solid #333; background-color: #2c2c2c; color: #fff; box-sizing: border-box; font-size: 1rem; }
        .btn-generate { width: 100%; padding: 1rem; border: none; border-radius: 4px; background-color: #03dac6; color: #121212; font-weight: bold; font-size: 1.1rem; cursor: pointer; transition: background-color 0.3s; }
        .btn-generate:hover { background-color: #01b0a1; }
        .error { color: #cf6679; background-color: rgba(207, 102, 121, 0.1); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem; text-align: center; }
        .loading-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 1000; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px;}
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
                            <input type="text" name="idea" id="idea" placeholder="Ex: Istoria Imperiului Roman" maxlength="500" required>
                        </div>
                        <div class="form-group">
                            <label for="video_type">Format Video</label>
                            <select name="video_type" id="video_type">
                                <option value="short">Short (Vertical 9:16)</option>
                                <option value="long">Long-form (Landscape 16:9)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="language">Limba</label>
                            <select name="language" id="language">
                                <option value="ro">Română</option>
                                <option value="en">English</option>
                            </select>
                        </div>
                        <button type="submit" class="btn-generate">Generează Plan și Imagini AI</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div id="loading" class="loading-overlay">
        <div class="spinner"></div>
        <p>Gemini lucrează la planul tău... <br>Acest proces poate dura aproximativ 1 minut.</p>
    </div>
    <script>
        document.getElementById('genForm').addEventListener('submit', function() {
            document.getElementById('loading').style.display = 'flex';
        });
    </script>
</body>
</html>
