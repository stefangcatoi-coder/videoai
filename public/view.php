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

        /* Sidebar */
        .sidebar {
            width: 250px;
            background-color: #1e1e1e;
            height: 100vh;
            position: fixed;
            padding: 2rem 1rem;
            box-shadow: 2px 0 5px rgba(0,0,0,0.5);
        }

        .sidebar h2 {
            color: #bb86fc;
            margin-bottom: 2rem;
            text-align: center;
        }

        .nav-link {
            display: block;
            padding: 0.75rem 1rem;
            color: #e0e0e0;
            text-decoration: none;
            border-radius: 4px;
            margin-bottom: 0.5rem;
            transition: background 0.3s;
        }

        .nav-link:hover {
            background-color: #2c2c2c;
            color: #bb86fc;
        }

        .logout-link {
            color: #cf6679;
            margin-top: 2rem;
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
    <div class="sidebar">
        <h2>Video AI</h2>
        <a href="dashboard.php" class="nav-link">Dashboard</a>
        <a href="generate.php" class="nav-link">Creează Video</a>
        <a href="logout.php" class="nav-link logout-link">Logout</a>
    </div>

    <div class="main-content">
        <div class="container">
            <div class="header-box">
                <h1><?php echo htmlspecialchars($video['title']); ?></h1>
                <a href="dashboard.php" class="btn btn-back">← Înapoi la Dashboard</a>
            </div>

            <div class="card">
                <?php if ($video['status'] === 'pending'): ?>
                    <div class="pending-box">
                        <div class="loader"></div>
                        <div class="status-msg">Video-ul tău este încă în cuptorul AI. Revino în câteva momente!</div>
                        <a href="view.php?id=<?php echo $video['id']; ?>" class="btn btn-refresh">Refresh Page</a>
                    </div>
                <?php elseif ($video['status'] === 'done'): ?>
                    <div class="status-msg" style="color: #03dac6;">Video-ul a fost generat cu succes!</div>
                    <div class="video-container">
                        <video width="100%" controls>
                            <source src="https://www.w3schools.com/html/mov_bbb.mp4" type="video/mp4">
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
