<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// /var/www/video-ai/public/generate.php

set_time_limit(240);
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

/**
 * Helper function to generate image using DeAPI.ai Flux.1 Schnell
 */
function generateImage($imgPrompt) {
    if (empty(DEAPI_API_KEY) || DEAPI_API_KEY === 'YOUR_DEAPI_API_KEY_HERE') {
        // Fallback to picsum if key is not set (useful for testing without wasting credits or if key missing)
        return "https://picsum.photos/1024/1920?random=" . rand(1, 10000);
    }

    $payload = [
        "prompt" => $imgPrompt,
        "model" => "Flux1schnell",
        "width" => 1024, // Multiple of 128
        "height" => 1920, // Multiple of 128
        "steps" => 4,
        "guidance" => 0,
        "seed" => rand(1, 1000000),
        "loras" => []
    ];

    $ch = curl_init(DEAPI_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . trim(DEAPI_API_KEY),
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        // Log error and fallback
        error_log("DeAPI Error (HTTP $httpCode): " . $response);
        return "https://picsum.photos/1024/1920?random=" . rand(1, 10000);
    }

    $resData = json_decode($response, true);
    $requestId = $resData['request_id'] ?? null;

    if (!$requestId) {
        return "https://picsum.photos/1024/1920?random=" . rand(1, 10000);
    }

    // Polling for the image
    $maxAttempts = 30;
    $imageUrl = null;
    for ($i = 0; $i < $maxAttempts; $i++) {
        sleep(3);
        $ch = curl_init(DEAPI_STATUS_URL . $requestId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . trim(DEAPI_API_KEY)
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $statusRes = curl_exec($ch);
        curl_close($ch);

        $statusData = json_decode($statusRes, true);
        if (($statusData['status'] ?? '') === 'done') {
            $imageUrl = $statusData['result']['output'][0] ?? null;
            break;
        } elseif (($statusData['status'] ?? '') === 'error') {
            error_log("DeAPI Job Error: " . json_encode($statusData));
            break;
        }
    }

    if ($imageUrl) {
        // Download and save locally
        $imgContent = file_get_contents($imageUrl);
        if ($imgContent) {
            $filename = 'img_' . time() . '_' . uniqid() . '.png';
            $uploadDir = __DIR__ . '/uploads/images/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            file_put_contents($uploadDir . $filename, $imgContent);
            return 'uploads/images/' . $filename;
        }
    }

    return "https://picsum.photos/1024/1920?random=" . rand(1, 10000);
}

// Processing Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_generate) {
    $idea = $_POST['idea'] ?? '';

    if (!empty($idea)) {
        try {
            // 1. Prepare Improved Prompt for Gemini
            $prompt = "Generează un plan video profesional pentru ideea: \"$idea\".
            Răspunsul tău TREBUIE să fie un obiect JSON pur, fără marcaje markdown sau alte explicații, strict în limba română (cu excepția tag-urilor și a image_prompts care trebuie să fie în engleză), cu următoarele câmpuri:
            - title: Un titlu captivant care să atragă atenția.
            - script: Un text de exact 50-60 de cuvinte, structurat pentru un clip de 30 de secunde, cu un hook puternic la început și un îndemn la acțiune la sfârșit.
            - description: O descriere optimizată pentru social media (SEO).
            - tags: O listă cu 5 etichete relevante separate prin virgulă.
            - image_prompts: Un array cu 3 descrieri vizuale detaliate și profesionale (ÎN ENGLEZĂ) pentru un generator de imagini AI (Flux.1). Descrierile trebuie să includă detalii despre stil (cinematic, hyper-realistic, 8k), iluminare (dramatic lighting, soft glow), compoziție (close-up, wide shot) și subiectul principal, astfel încât să fie perfect aliniate cu scriptul.

            Exemplu format cerut:
            {
              \"title\": \"Titlu Pro\",
              \"script\": \"Vrei să înveți cum să...\",
              \"description\": \"Descoperă secretele...\",
              \"tags\": \"ai, tehnologie, viitor, invatare, video\",
              \"image_prompts\": [
                \"Cinematic close-up of a high-tech robotic hand drawing on a transparent glass screen, vibrant blue neon lights, hyper-realistic, 8k resolution\",
                \"Wide shot of a futuristic city with flying vehicles and lush green rooftops during sunset, golden hour lighting, detailed architecture\",
                \"Close-up of a person eyes reflecting a digital interface with complex data visualizations, sharp focus, dramatic lighting\"
              ]
            }";

            // 2. Call Gemini API
            $apiKey = GEMINI_API_KEY;
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;

            $payload = [
                "contents" => [
                    [
                        "parts" => [
                            ["text" => $prompt]
                        ]
                    ]
                ]
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
                throw new Exception("Eroare API Gemini (HTTP $httpCode). Verifică cheia API.");
            }

            $result = json_decode($response, true);
            $aiResponseText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (preg_match('/\{.*\}/s', $aiResponseText, $matches)) {
                $aiResponseText = $matches[0];
            }

            $aiData = json_decode($aiResponseText, true);

            if (!$aiData || !isset($aiData['script'])) {
                throw new Exception("AI-ul nu a returnat un format JSON valid sau datele lipsesc.");
            }

            // 3. Generate Images using DeAPI
            $imagePrompts = $aiData['image_prompts'] ?? [];
            $images = [];
            for ($i = 0; $i < 3; $i++) {
                $prompt_text = $imagePrompts[$i] ?? "Futuristic background, cinematic, 8k";
                $images[] = generateImage($prompt_text);
            }

            // 4. Save to Database
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO videos (user_id, title, status, script, description, tags, image1, image2, image3) VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, ?)");

            $title = $aiData['title'] ?? $idea;
            $script = $aiData['script'] ?? '';
            $description = $aiData['description'] ?? '';
            $tags = $aiData['tags'] ?? '';

            $stmt->execute([
                $user_id,
                $title,
                $script,
                $description,
                $tags,
                $images[0],
                $images[1],
                $images[2]
            ]);

            $video_id = $pdo->lastInsertId();
            $pdo->commit();

            header("Location: edit_draft.php?id=" . $video_id);
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
        body {
            background-color: #121212;
            color: #e0e0e0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            display: flex;
        }

        .main-content {
            margin-left: 250px;
            padding: 2rem;
            width: 100%;
            display: flex;
            justify-content: center;
        }

        .container {
            width: 100%;
            max-width: 600px;
        }

        h1 {
            color: #ffffff;
            margin-bottom: 2rem;
        }

        .card {
            background-color: #1e1e1e;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
            color: #bb86fc;
        }

        input {
            width: 100%;
            padding: 0.75rem;
            border-radius: 4px;
            border: 1px solid #333;
            background-color: #2c2c2c;
            color: #fff;
            box-sizing: border-box;
            font-size: 1rem;
        }

        input:focus {
            outline: none;
            border-color: #bb86fc;
        }

        .btn-generate {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 4px;
            background-color: #03dac6;
            color: #121212;
            font-weight: bold;
            font-size: 1.1rem;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .btn-generate:hover {
            background-color: #01b0a1;
        }

        .error {
            color: #cf6679;
            background-color: rgba(207, 102, 121, 0.1);
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .spinner {
            border: 4px solid #333;
            border-top: 4px solid #03dac6;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin-bottom: 1rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/header.php'; ?>

    <div class="main-content">
        <div class="container">
            <h1>Generează Video Nou</h1>

            <?php if (!$can_generate): ?>
                <div class="error">
                    <strong>Limită atinsă!</strong><br>
                    Ai folosit <?php echo $user['videos_used']; ?> din <?php echo $user['monthly_limit']; ?> video-uri.
                    Te rugăm să faci upgrade pentru a genera mai multe.
                </div>
            <?php else: ?>
                <div class="card">
                    <?php if ($error): ?>
                        <div class="error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST" id="genForm">
                        <div class="form-group">
                            <label for="idea">Ideea Video-ului</label>
                            <input type="text" name="idea" id="idea" placeholder="Ex: Cum să gătești paste" required>
                        </div>
                        <button type="submit" class="btn-generate">Generează Plan (Gemini + DeAPI)</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="loading" class="loading-overlay">
        <div class="spinner"></div>
        <p>Gemini AI & DeAPI lucrează... <br>Acest proces poate dura până la 1 minut.</p>
    </div>

    <script>
        document.getElementById('genForm').addEventListener('submit', function() {
            document.getElementById('loading').style.display = 'flex';
        });
    </script>
</body>
</html>
