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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['produce'])) {
    $new_title = $_POST['title'] ?? $video['title'];
    $new_script = $_POST['script'] ?? $video['script'];
    $new_description = $_POST['description'] ?? $video['description'];
    $new_tags = $_POST['tags'] ?? $video['tags'];

    try {
        $pdo->beginTransaction();

        $stmt_user = $pdo->prepare("SELECT monthly_limit, videos_used FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $user_data = $stmt_user->fetch();

        if ($user_data['videos_used'] >= $user_data['monthly_limit']) {
            throw new Exception("Limită de video-uri atinsă.");
        }

        // Update with status 'processing'
        $stmt_update = $pdo->prepare("UPDATE videos SET title = ?, script = ?, description = ?, tags = ?, status = 'processing' WHERE id = ?");
        $stmt_update->execute([$new_title, $new_script, $new_description, $new_tags, $video_id]);

        $stmt_inc = $pdo->prepare("UPDATE users SET videos_used = videos_used + 1 WHERE id = ?");
        $stmt_inc->execute([$user_id]);

        $pdo->commit();

        // Trigger Async Pipeline
        $cmd = "php " . __DIR__ . "/../app/process_video.php " . $video_id . " > /dev/null 2>&1 &";
        exec($cmd);

        header("Location: dashboard.php?success=Producția a început! Te vom anunța când este gata.");
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
    <title>Studio Creație - Video AI</title>
    <style>
        :root { --bg-dark: #121212; --card-bg: #1e1e1e; --accent-purple: #bb86fc; --accent-turquoise: #03dac6; --text-main: #e0e0e0; }
        body { background-color: var(--bg-dark); color: var(--text-main); font-family: 'Segoe UI', sans-serif; margin: 0; display: flex; }
        .main-content { margin-left: 250px; padding: 2rem; width: 100%; display: flex; justify-content: center; }
        .container { width: 100%; max-width: 900px; }
        .studio-card { background-color: var(--card-bg); padding: 2rem; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; margin-bottom: 0.5rem; color: var(--accent-purple); font-weight: 600; font-size: 0.8rem; }
        input[type="text"], textarea { width: 100%; padding: 0.8rem; border-radius: 8px; border: 1px solid #333; background: #2c2c2c; color: #fff; box-sizing: border-box; }
        textarea { min-height: 100px; }
        .images-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-top: 1rem; }
        .image-card img { width: 100%; border-radius: 8px; border: 1px solid #333; }
        .btn-produce { width: 100%; padding: 1rem; border: none; border-radius: 12px; background: linear-gradient(90deg, #00b09b, #96c93d); color: #121212; font-weight: 800; cursor: pointer; margin-top: 2rem; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/header.php'; ?>
    <div class="main-content">
        <div class="container">
            <h1>Studio Creație Video</h1>
            <?php if ($error): ?><div style="color:#ff5252;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="POST">
                <div class="studio-card">
                    <div class="form-group"><label>Titlu Video</label><input type="text" name="title" value="<?php echo htmlspecialchars($video['title']); ?>" required></div>
                    <div class="form-group"><label>Script (Voce AI)</label><textarea name="script" required><?php echo htmlspecialchars($video['script']); ?></textarea></div>
                    <div class="form-group"><label>Descriere SEO</label><textarea name="description"><?php echo htmlspecialchars($video['description']); ?></textarea></div>
                    <div class="form-group"><label>Etichete</label><input type="text" name="tags" value="<?php echo htmlspecialchars($video['tags']); ?>"></div>
                    <label>Imagini Generate</label>
                    <div class="images-grid">
                        <div class="image-card"><img src="<?php echo htmlspecialchars($video['image1'] ?: ''); ?>"></div>
                        <div class="image-card"><img src="<?php echo htmlspecialchars($video['image2'] ?: ''); ?>"></div>
                        <div class="image-card"><img src="<?php echo htmlspecialchars($video['image3'] ?: ''); ?>"></div>
                    </div>
                    <button type="submit" name="produce" class="btn-produce">LANSEAZĂ PRODUCȚIA ASINCRONĂ</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
