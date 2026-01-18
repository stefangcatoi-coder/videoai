<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(120); // 2 minutes for API calls and downloads

// /var/www/video-ai/public/generate.php

session_start();

// Security Middleware
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/gemini.php';
require_once __DIR__ . '/../config/deapi.php';

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

// Helper function to generate and download image via DeAPI.ai
function generateAndDownloadImage($prompt, $videoId, $index) {
    $apiKey = trim(DEAPI_API_KEY);
    $url = DEAPI_API_URL;

    $payload = [
        "prompt" => $prompt,
        "model" => "flux", 
        "width" => 1080,
        "height" => 1920
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
        // Fallback or Log
        file_put_contents(__DIR__ . '/../storage/debug_deapi.log', "HTTP $httpCode: " . $response . "\n", FILE_APPEND);
        throw new Exception("Eroare DeAPI (HTTP $httpCode). Verifică storage/debug_deapi.log.");
    }

    $result = json_decode($response, true);
    // Assuming 'data', 'url', or 'output' contains the image URL
    $imgUrl = $result['data'][0]['url'] ?? $result['url'] ?? $result['output'][0] ?? '';

    if (empty($imgUrl)) {
        throw new Exception("DeAPI nu a returnat un URL valid pentru imagine.");
    }

    // Download local
    $imgData = file_get_contents($imgUrl);
    if ($imgData === false) {
        throw new Exception("Nu am putut descărca imaginea de la URL: " . $imgUrl);
    }

    $filename = "img_" . $videoId . "_" . $index . "_" . time() . ".jpg";
    $relative_path = "uploads/images/" . $filename;
    $absolute_path = __DIR__ . "/" . $relative_path;
    
    $dir = dirname($absolute_path);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    
    file_put_contents($absolute_path, $imgData);
    
    return $relative_path;
}

// Processing Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_generate) {
    $idea = trim($_POST['idea'] ?? '');

    if (!empty($idea)) {
        if (strlen($idea) > 500) {
            $error = "Ideea este prea lungă (maxim 500 caractere).";
        } else {
            try {
                // 1. Prepare Prompt for Gemini (SEO Optimized)
                $prompt = "Generează un plan video profesional și optimizat SEO pentru ideea: \"$idea\".
                Răspunsul tău TREBUIE să fie un obiect JSON pur, FĂRĂ MARCAJE MARKDOWN (fără ```json), fără nicio altă explicație în plus, strict în limba română (cu excepția image_prompts), cu următoarele câmpuri:

                - title: Un titlu captivant care să includă cuvinte cheie de tip 'Hook' (cârlig) pentru a atrage click-uri.
                - script: Un text de exact 50-60 de cuvinte, optimizat pentru retenție: începe cu o întrebare intrigantă, oferă informație utilă la mijloc și încheie cu un îndemn clar de abonare.
                - description: O descriere optimizată SEO care să respecte structura: o introducere captivantă, 3 puncte cheie (bullet points) despre subiect și un Call to Action (CTA) final.
                - tags: O listă de 15-20 de etichete relevante, separate prin virgulă, incluzând atât cuvinte cheie generale, cât și 'long-tail keywords' specifice.
                - image_prompts: Un array cu 3 descrieri vizuale scurte, EXCLUSIV ÎN LIMBA ENGLEZĂ, pentru un generator de imagini AI.

                Exemplu format cerut (strict JSON):
                {
                  \"title\": \"[HOOK] Titlu Optimizat\",
                  \"script\": \"Vrei să afli cum...? [Informație]. Abonează-te pentru mai multe!\",
                  \"description\": \"Intro... \n• Punct 1 \n• Punct 2 \n• Punct 3 \n\n Acționează acum!\",
                  \"tags\": \"cuvânt1, cuvânt specific, long tail keyword...\",
                  \"image_prompts\": [\"visual prompt 1\", \"visual prompt 2\", \"visual prompt 3\"]
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
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    file_put_contents(__DIR__ . '/../storage/debug_api.log', $response);
                    throw new Exception("Eroare API Gemini (HTTP $httpCode).");
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

                // 3. Save to Database (Initial Draft)
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO videos (user_id, title, status, script, description, tags) VALUES (?, ?, 'draft', ?, ?, ?)");
                $stmt->execute([
                    $user_id,
                    $aiData['title'] ?? $idea,
                    $aiData['script'] ?? '',
                    $aiData['description'] ?? '',
                    $aiData['tags'] ?? ''
                ]);

                $video_id = $pdo->lastInsertId();

                // 4. Generate and Save Images
                $prompts = $aiData['image_prompts'] ?? ["Image related to $idea", "Another scene for $idea", "Final scene for $idea"];
                $localImg1 = generateAndDownloadImage($prompts[0], $video_id, 1);
                $localImg2 = generateAndDownloadImage($prompts[1], $video_id, 2);
                $localImg3 = generateAndDownloadImage($prompts[2], $video_id, 3);

                // Update with image paths
                $stmt_upd = $pdo->prepare("UPDATE videos SET image1 = ?, image2 = ?, image3 = ? WHERE id = ?");
                $stmt_upd->execute([$localImg1, $localImg2, $localImg3, $video_id]);

                $pdo->commit();

                header("Location: edit_draft.php?id=" . $video_id);
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
        <p>Gemini și DeAPI lucrează... Te rugăm să aștepți (aprox. 30s).</p>
    </div>
    <script>
        document.getElementById('genForm').addEventListener('submit', function() {
            document.getElementById('loading').style.display = 'flex';
        });
    </script>
</body>
</html>
