<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// /var/www/video-ai/public/edit_draft.php

session_start();

// Security Middleware
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/speechify.php';

$user_id = $_SESSION['user_id'];
$video_id = $_GET['id'] ?? 0;

// Fetch draft details and verify ownership
$stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ? AND user_id = ?");
$stmt->execute([$video_id, $user_id]);
$video = $stmt->fetch();

if (!$video || $video['status'] !== 'draft') {
    header("Location: dashboard.php");
    exit;
}

$error = '';

// Handle Production Request (Speechify Integration)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['produce'])) {
    $new_title = $_POST['title'] ?? $video['title'];
    $new_script = $_POST['script'] ?? $video['script'];
    $new_description = $_POST['description'] ?? $video['description'];
    $new_tags = $_POST['tags'] ?? $video['tags'];

    try {
        $pdo->beginTransaction();

        // 1. Check user limits
        $stmt_user = $pdo->prepare("SELECT monthly_limit, videos_used FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $user_data = $stmt_user->fetch();

        if ($user_data['videos_used'] >= $user_data['monthly_limit']) {
            throw new Exception("Limită de video-uri atinsă.");
        }

        // 2. Save changes locally
        $stmt_update = $pdo->prepare("UPDATE videos SET title = ?, script = ?, description = ?, tags = ? WHERE id = ?");
        $stmt_update->execute([$new_title, $new_script, $new_description, $new_tags, $video_id]);

        // 3. Call Speechify API for Voiceover
        $apiKey = SPEECHIFY_API_KEY;
        $url = SPEECHIFY_API_URL;

        $payload = [
            "input" => $new_script,
            "voice_id" => "george", 
            "language" => "ro-RO",
            "audio_format" => "mp3",
            "model" => "simba-multilingual"
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
            file_put_contents(__DIR__ . '/../storage/debug_speechify.log', "HTTP $httpCode: " . $response . "\n", FILE_APPEND);
            throw new Exception("Eroare Speechify API (HTTP $httpCode).");
        }

        $result = json_decode($response, true);
        $audio_base64 = $result['audio_data'] ?? '';

        if (empty($audio_base64)) {
            throw new Exception("Speechify nu a returnat date audio.");
        }

        // 4. Save Audio File
        $audio_content = base64_decode($audio_base64);
        $filename = "voiceover_" . $video_id . "_" . time() . ".mp3";
        $upload_dir = __DIR__ . "/uploads/audio/";
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true);
        
        $file_path = $upload_dir . $filename;
        file_put_contents($file_path, $audio_content);
        $relative_audio_path = "uploads/audio/" . $filename;

        // 5. Update Database Status to ready_for_render
        $stmt_final = $pdo->prepare("UPDATE videos SET status = 'ready_for_render', voiceover_path = ? WHERE id = ?");
        $stmt_final->execute([$relative_audio_path, $video_id]);

        // 6. Increment usage
        $stmt_inc = $pdo->prepare("UPDATE users SET videos_used = videos_used + 1 WHERE id = ?");
        $stmt_inc->execute([$user_id]);

        $pdo->commit();

        // 7. Redirect to render.php
        header("Location: render.php?id=" . $video_id);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Studio Creație - Video AI</title>
    <style>
        :root {
            --bg-dark: #121212; --card-bg: #1e1e1e; --input-bg: #2c2c2c;
            --accent-purple: #bb86fc; --accent-turquoise: #03dac6;
            --text-main: #e0e0e0; --text-dim: #b0b0b0; --border-color: #333;
        }
        body { background-color: var(--bg-dark); color: var(--text-main); font-family: 'Segoe UI', sans-serif; margin: 0; display: flex; }
        .main-content { margin-left: 250px; padding: 2rem; width: 100%; display: flex; justify-content: center; }
        .container { width: 100%; max-width: 900px; }
        h1 { background: linear-gradient(45deg, var(--accent-purple), var(--accent-turquoise)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .studio-card { background-color: var(--card-bg); padding: 2rem; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; margin-bottom: 0.5rem; color: var(--accent-purple); font-weight: 600; text-transform: uppercase; font-size: 0.8rem; }
        input[type="text"], textarea { width: 100%; padding: 0.8rem; border-radius: 8px; border: 1px solid var(--border-color); background: var(--input-bg); color: #fff; box-sizing: border-box; }
        textarea { min-height: 100px; }
        .images-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-top: 1rem; }
        .image-card img { width: 100%; border-radius: 8px; border: 1px solid var(--border-color); }
        .btn-produce { width: 100%; padding: 1rem; border: none; border-radius: 12px; background: linear-gradient(90deg, #00b09b, #96c93d); color: #121212; font-weight: 800; cursor: pointer; margin-top: 2rem; }
        .loader-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 9999; flex-direction: column; justify-content: center; align-items: center; }
        .spinner { width: 50px; height: 50px; border: 5px solid rgba(255,255,255,0.1); border-top: 5px solid var(--accent-turquoise); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/header.php'; ?>
    <div id="loader" class="loader-overlay"><div class="spinner"></div><div style="color:#fff; margin-top:1rem;">Generăm Vocea...</div></div>
    <div class="main-content">
        <div class="container">
            <h1>Studio Creație Video</h1>
            <?php if ($error): ?><div style="color:#ff5252;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="POST" onsubmit="document.getElementById('loader').style.display='flex'">
                <div class="studio-card">
                    <div class="form-group"><label>Titlu Video</label><input type="text" name="title" value="<?php echo htmlspecialchars($video['title']); ?>" required></div>
                    <div class="form-group"><label>Script (Voce AI)</label><textarea name="script" required><?php echo htmlspecialchars($video['script']); ?></textarea></div>
                    <div class="form-group"><label>Descriere SEO</label><textarea name="description"><?php echo htmlspecialchars($video['description']); ?></textarea></div>
                    <div class="form-group"><label>Etichete</label><input type="text" name="tags" value="<?php echo htmlspecialchars($video['tags']); ?>"></div>
                    <label>Imagini Generate (DeAPI)</label>
                    <div class="images-grid">
                        <div class="image-card"><img src="<?php echo htmlspecialchars($video['image1']); ?>"></div>
                        <div class="image-card"><img src="<?php echo htmlspecialchars($video['image2']); ?>"></div>
                        <div class="image-card"><img src="<?php echo htmlspecialchars($video['image3']); ?>"></div>
                    </div>
                    <button type="submit" name="produce" class="btn-produce">GENEREAZĂ VIDEO FINAL</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
