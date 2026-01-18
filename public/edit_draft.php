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

// Handle Confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    try {
        $pdo->beginTransaction();

        // Check limits again just in case
        $stmt_user = $pdo->prepare("SELECT monthly_limit, videos_used FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $user_data = $stmt_user->fetch();

        if ($user_data['videos_used'] >= $user_data['monthly_limit']) {
            throw new Exception("Limită de video-uri atinsă.");
        }

        // Update status to pending_production
        $stmt = $pdo->prepare("UPDATE videos SET status = 'pending_production' WHERE id = ?");
        $stmt->execute([$video_id]);

        // Increment usage
        $stmt = $pdo->prepare("UPDATE users SET videos_used = videos_used + 1 WHERE id = ?");
        $stmt->execute([$user_id]);

        $pdo->commit();

        header("Location: dashboard.php?success=Video-ul tău este în producție!");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Eroare: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editare Draft - Video AI</title>
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
            max-width: 800px;
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
            margin-bottom: 2rem;
        }

        .field {
            margin-bottom: 1.5rem;
        }

        .label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
            color: #bb86fc;
            font-size: 0.9rem;
            text-transform: uppercase;
        }

        .value {
            background-color: #2c2c2c;
            padding: 1rem;
            border-radius: 4px;
            border: 1px solid #333;
            line-height: 1.6;
        }

        .images-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }

        .images-grid img {
            width: 100%;
            border-radius: 4px;
            border: 1px solid #333;
        }

        .btn-confirm {
            width: 100%;
            padding: 1.2rem;
            border: none;
            border-radius: 4px;
            background-color: #03dac6;
            color: #121212;
            font-weight: bold;
            font-size: 1.2rem;
            cursor: pointer;
            transition: transform 0.2s, background-color 0.3s;
        }

        .btn-confirm:hover {
            background-color: #01b0a1;
            transform: scale(1.01);
        }

        .error {
            color: #cf6679;
            background-color: rgba(207, 102, 121, 0.1);
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .tag {
            background-color: #333;
            color: #bb86fc;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/header.php'; ?>

    <div class="main-content">
        <div class="container">
            <h1>Revizuire Draft Video</h1>

            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="field">
                    <span class="label">Titlu</span>
                    <div class="value"><?php echo htmlspecialchars($video['title']); ?></div>
                </div>

                <div class="field">
                    <span class="label">Descriere</span>
                    <div class="value"><?php echo nl2br(htmlspecialchars($video['description'])); ?></div>
                </div>

                <div class="field">
                    <span class="label">Script</span>
                    <div class="value"><?php echo nl2br(htmlspecialchars($video['script'])); ?></div>
                </div>

                <div class="field">
                    <span class="label">Etichete</span>
                    <div class="tags">
                        <?php
                        $tags = explode(',', $video['tags']);
                        foreach ($tags as $tag):
                        ?>
                            <span class="tag"><?php echo htmlspecialchars(trim($tag)); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="field">
                    <span class="label">Imagini Generate</span>
                    <div class="images-grid">
                        <img src="<?php echo htmlspecialchars($video['image1']); ?>" alt="Imagine 1">
                        <img src="<?php echo htmlspecialchars($video['image2']); ?>" alt="Imagine 2">
                        <img src="<?php echo htmlspecialchars($video['image3']); ?>" alt="Imagine 3">
                    </div>
                </div>
            </div>

            <form method="POST">
                <button type="submit" name="confirm" class="btn-confirm">CONFIRMĂ ȘI GENEREAZĂ VIDEO FINAL</button>
            </form>
        </div>
    </div>
</body>
</html>
