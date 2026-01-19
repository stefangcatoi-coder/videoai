<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(600); // 10 minutes for API calls and downloads

// /var/www/video-ai/public/generate.php

session_start();

// Security Middleware
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/gemini.php';
require_once __DIR__ . '/../config/images_api.php';

$user_id = $_SESSION['user_id'];
$error = '';

// Helper function to get image from Unsplash or Pexels
function getAutoImage($keyword, $index) {
    $localPath = '';
    $foundUrl = '';

    // 1. Try Unsplash
    $unsplashKey = trim(UNSPLASH_ACCESS_KEY);
    $url = "https://api.unsplash.com/search/photos?query=" . urlencode($keyword) . "&orientation=portrait&per_page=1";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Client-ID $unsplashKey"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
        $url = "https://api.pexels.com/v1/search?query=" . urlencode($keyword) . "&orientation=portrait&per_page=1";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $pexelsKey"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
            $absolute_path = __DIR__ . "/" . $relative_path;

            if (!is_dir(dirname($absolute_path))) {
                mkdir(dirname($absolute_path), 0777, true);
            }

            file_put_contents($absolute_path, $imgData);
            $localPath = $relative_path;
        }
    }

    // 4. Final Fallback (Grey Placeholder)
    if (!$localPath) {
        $localPath = "https://via.placeholder.com/1080x1920.png/222222/FFFFFF?text=Imagine+Indisponibila";
    }

    return $localPath;
}

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
                // 1. Prepare Prompt for Gemini (SEO Optimized)
                $prompt = "Generează un plan video profesional și optimizat SEO pentru ideea: \"$idea\".
                Răspunsul tău TREBUIE să fie un obiect JSON pur, FĂRĂ MARCAJE MARKDOWN (fără ```json), fără nicio altă explicație în plus, strict în limba română (cu excepția image_prompts), cu următoarele câmpuri:

                - title: Un titlu captivant care să includă cuvinte cheie de tip 'Hook' (cârlig) pentru a atrage click-uri.
                - script: Un text de exact 50-60 de cuvinte, optimizat pentru retenție: începe cu o întrebare intrigantă, oferă informație utilă la mijloc și încheie cu un îndemn clar de abonare.
                - description: O descriere optimizată SEO care să respecte structura: o introducere captivantă, 3 puncte cheie (bullet points) despre subiect și un Call to Action (CTA) final.
                - tags: O listă de 15-20 de etichete relevante, separate prin virgulă, incluzând atât cuvinte cheie generale, cât și 'long-tail keywords' specifice.
                - keywords: Un array cu 3 cuvinte cheie de căutare (Search Keywords) în limba engleză, specifice pentru fiecare scenă (ex: 'modern office skyscraper', 'crypto wallet phone', 'successful businessman smiling').

                Exemplu format cerut (strict JSON):
                {
                  \"title\": \"[HOOK] Titlu Optimizat\",
                  \"script\": \"Vrei să afli cum...? [Informație]. Abonează-te pentru mai multe!\",
                  \"description\": \"Intro... \n• Punct 1 \n• Punct 2 \n• Punct 3 \n\n Acționează acum!\",
                  \"tags\": \"cuvânt1, cuvânt specific, long tail keyword...\",
                  \"keywords\": [\"keyword 1\", \"keyword 2\", \"keyword 3\"]
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

                // 3. Fetch Stock Images
                $keywords = $aiData['keywords'] ?? [$idea, $idea, $idea];
                $img1 = getAutoImage($keywords[0] ?? $idea, 1);
                $img2 = getAutoImage($keywords[1] ?? $idea, 2);
                $img3 = getAutoImage($keywords[2] ?? $idea, 3);

                // 4. Save to Database (Initial Draft)
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO videos (user_id, title, status, script, description, tags, prompt1, prompt2, prompt3, image1, image2, image3) VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $user_id,
                    $aiData['title'] ?? $idea,
                    $aiData['script'] ?? '',
                    $aiData['description'] ?? '',
                    $aiData['tags'] ?? '',
                    $keywords[0] ?? '',
                    $keywords[1] ?? '',
                    $keywords[2] ?? '',
                    $img1,
                    $img2,
                    $img3
                ]);

                $video_id = $pdo->lastInsertId();
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
        <p>Gemini lucrează la planul tău... Te rugăm să aștepți.</p>
    </div>
    <script>
        document.getElementById('genForm').addEventListener('submit', function() {
            document.getElementById('loading').style.display = 'flex';
        });
    </script>
</body>
</html>
