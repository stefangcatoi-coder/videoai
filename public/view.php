<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// /var/www/video-ai/public/view.php

session_start();

// Security Middleware
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

$user_id = $_SESSION['user_id'];
$video_id = $_GET['id'] ?? 0;

// Fetch video details and verify ownership
$stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ? AND user_id = ?");
$stmt->execute([$video_id, $user_id]);
$video = $stmt->fetch();

if (!$video) {
    die("Acces Refuzat sau Video Inexistent.");
}

// Redirect drafts to edit_draft.php
if ($video['status'] === 'draft') {
    header("Location: edit_draft.php?id=" . $video_id);
    exit;
}

?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vizualizare Video - Video AI</title>
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
            max-width: 800px;
        }

        .header-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        h1 {
            color: #ffffff;
            margin: 0;
        }

        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            transition: opacity 0.3s;
            cursor: pointer;
            border: none;
        }

        .btn-back {
            background-color: #333;
            color: #e0e0e0;
        }

        .btn-refresh {
            background-color: #bb86fc;
            color: #121212;
            margin-top: 1rem;
        }

        .card {
            background-color: #1e1e1e;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
            text-align: center;
        }

        .status-msg {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
        }

        .video-container {
            margin-top: 2rem;
            background: #000;
            border-radius: 8px;
            overflow: hidden;
            aspect-ratio: 16 / 9;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #333;
        }

        .pending-box {
            padding: 3rem;
        }

        .loader {
            border: 4px solid #333;
            border-top: 4px solid #bb86fc;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1.5rem;
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
            <div class="header-box">
                <h1><?php echo htmlspecialchars($video['title']); ?></h1>
                <a href="dashboard.php" class="btn btn-back">← Înapoi la Dashboard</a>
            </div>

            <div class="card">
                <?php if ($video['status'] === 'pending' || $video['status'] === 'pending_production'): ?>
                    <div class="pending-box">
                        <div class="loader"></div>
                        <div class="status-msg">
                            <?php if ($video['status'] === 'pending'): ?>
                                Video-ul tău este încă în cuptorul AI. Revino în câteva momente!
                            <?php else: ?>
                                Slideshow-ul se generează acum. Aproape gata!
                            <?php endif; ?>
                        </div>
                        <a href="view.php?id=<?php echo $video['id']; ?>" class="btn btn-refresh">Refresh Page</a>
                    </div>
                <?php elseif ($video['status'] === 'done'): ?>
                    <div class="status-msg" style="color: #03dac6;">Video-ul a fost generat cu succes!</div>
                    <div class="video-container" style="aspect-ratio: 9/16; max-width: 400px; margin: 0 auto;">
                        <video width="100%" height="100%" controls>
                            <source src="<?php echo htmlspecialchars($video['video_path']); ?>" type="video/mp4">
                            Browser-ul tău nu suportă tag-ul video.
                        </video>
                    </div>
                <?php else: ?>
                    <div class="status-msg">Status: <?php echo htmlspecialchars($video['status']); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
