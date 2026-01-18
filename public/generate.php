<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
    $idea = $_POST['idea'] ?? '';

    if (!empty($idea)) {
        try {
            // 1. Prepare Prompt for Gemini
            $prompt = "Generează un plan detaliat pentru un video pornind de la ideea: \"$idea\".
            Răspunsul tău TREBUIE să fie un obiect JSON valid, strict în limba română (cu excepția tag-urilor dacă e cazul), cu următoarele câmpuri:
            - title: Un titlu captivant.
            - script: Un text de aproximativ 60 de cuvinte care va fi folosit ca voce de fundal.
            - description: O descriere scurtă pentru YouTube/Social Media.
            - tags: O listă de cuvinte cheie separate prin virgulă.
            - image_prompts: Un array cu exact 3 descrieri vizuale (în engleză) pentru un generator de imagini AI, care să ilustreze scriptul.

            Returnează DOAR codul JSON, fără alte explicații.";

            // 2. Call Gemini API
            $apiKey = GEMINI_API_KEY;
            $url = GEMINI_API_URL . "?key=" . $apiKey;

            $payload = [
                "contents" => [
                    [
                        "parts" => [
                            ["text" => $prompt]
                        ]
                    ]
                ],
                "generationConfig" => [
                    "responseMimeType" => "application/json"
                ]
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Eroare API Gemini (HTTP $httpCode). Verifică cheia API în config/gemini.php.");
            }

            $result = json_decode($response, true);
            $aiResponseText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $aiData = json_decode($aiResponseText, true);

            if (!$aiData) {
                throw new Exception("AI-ul nu a returnat un format JSON valid.");
            }

            // 3. Save to Database
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO videos (user_id, title, status, script, description, tags, image1, image2, image3) VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, ?)");

            $title = $aiData['title'] ?? $idea;
            $script = $aiData['script'] ?? '';
            $description = $aiData['description'] ?? '';
            $tags = $aiData['tags'] ?? '';

            // Placeholder images as requested
            $img1 = "https://picsum.photos/800/450?random=" . rand(1, 1000);
            $img2 = "https://picsum.photos/800/450?random=" . rand(1, 1000);
            $img3 = "https://picsum.photos/800/450?random=" . rand(1, 1000);

            $stmt->execute([
                $user_id,
                $title,
                $script,
                $description,
                $tags,
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

        /* Main Content */
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
                        <button type="submit" class="btn-generate">Generează Plan (Gemini AI)</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="loading" class="loading-overlay">
        <div class="spinner"></div>
        <p>Gemini AI gândește... Te rugăm să aștepți.</p>
    </div>

    <script>
        document.getElementById('genForm').addEventListener('submit', function() {
            document.getElementById('loading').style.display = 'flex';
        });
    </script>
</body>
</html>
