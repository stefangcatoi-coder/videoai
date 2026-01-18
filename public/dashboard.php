<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// /var/www/video-ai/public/dashboard.php

session_start();

// Security Middleware: Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

$user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $pdo->prepare("SELECT email, plan, monthly_limit, videos_used FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    // Should not happen if session is valid
    session_destroy();
    header("Location: login.php");
    exit;
}

// Fetch videos for this user
$stmt = $pdo->prepare("SELECT * FROM videos WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$videos = $stmt->fetchAll();

// Logic for Create Video button
$limit_reached = ($user['videos_used'] >= $user['monthly_limit']);
$progress_percent = ($user['monthly_limit'] > 0) ? ($user['videos_used'] / $user['monthly_limit']) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Video AI</title>
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

        .nav-link:hover, .nav-link.active {
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
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .user-info {
            text-align: right;
        }

        .btn-create {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            transition: opacity 0.3s;
        }

        .btn-green {
            background-color: #03dac6;
            color: #121212;
        }

        .btn-green:hover {
            background-color: #01b0a1;
        }

        .btn-disabled {
            background-color: #555;
            color: #888;
            cursor: not-allowed;
        }

        .upgrade-msg {
            display: block;
            margin-top: 0.5rem;
            color: #cf6679;
            font-size: 0.85rem;
        }

        /* Stats Card */
        .card {
            background-color: #1e1e1e;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }

        .progress-container {
            background-color: #333;
            border-radius: 10px;
            height: 12px;
            width: 100%;
            margin: 1rem 0;
            overflow: hidden;
        }

        .progress-bar {
            background-color: #bb86fc;
            height: 100%;
            transition: width 0.5s ease-in-out;
        }

        /* Table Styles */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        th, td {
            text-align: left;
            padding: 1rem;
            border-bottom: 1px solid #333;
        }

        th {
            color: #bb86fc;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 1px;
        }

        .empty-msg {
            text-align: center;
            padding: 3rem;
            color: #888;
        }

        /* Status Labels */
        .status-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-pending {
            background-color: rgba(255, 152, 0, 0.2);
            color: #ff9800;
            border: 1px solid #ff9800;
        }

        .status-done {
            background-color: rgba(76, 175, 80, 0.2);
            color: #4caf50;
            border: 1px solid #4caf50;
        }

        .ai-working {
            display: block;
            font-size: 0.7rem;
            margin-top: 4px;
            font-style: italic;
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Video AI</h2>
        <a href="dashboard.php" class="nav-link active">Dashboard</a>
        <a href="generate.php" class="nav-link">Creează Video</a>
        <a href="logout.php" class="nav-link logout-link">Logout</a>
    </div>

    <div class="main-content">
        <?php if (isset($_GET['success'])): ?>
            <div style="background-color: rgba(3, 218, 198, 0.1); color: #03dac6; padding: 1rem; border-radius: 4px; margin-bottom: 2rem; border: 1px solid #03dac6; text-align: center;">
                <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>

        <div class="header">
            <h1>Dashboard</h1>
            <div style="display: flex; align-items: center; gap: 1.5rem;">
                <?php if (!$limit_reached): ?>
                    <a href="generate.php" class="btn-create btn-green">+ Video Nou</a>
                <?php endif; ?>
                <div class="user-info">
                    <strong><?php echo htmlspecialchars($user['email']); ?></strong><br>
                    <span>Plan: <?php echo htmlspecialchars($user['plan']); ?></span>
                </div>
            </div>
        </div>

        <div class="card">
            <h3>Limită lunară</h3>
            <p><?php echo $user['videos_used']; ?> / <?php echo $user['monthly_limit']; ?> video-uri utilizate</p>
            <div class="progress-container">
                <div class="progress-bar" style="width: <?php echo min(100, $progress_percent); ?>%;"></div>
            </div>

            <?php if ($limit_reached): ?>
                <a href="#" class="btn-create btn-disabled">Creează Video</a>
                <span class="upgrade-msg">Ai atins limita. Upgrade Plan pentru a continua.</span>
            <?php else: ?>
                <a href="generate.php" class="btn-create btn-green">Creează Video</a>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Video-urile tale</h3>
            <?php if (empty($videos)): ?>
                <div class="empty-msg">Nu ai niciun video încă.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Titlu</th>
                            <th>Creat la</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($videos as $video): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($video['title']); ?></strong></td>
                                <td><?php echo date('d.m.Y H:i', strtotime($video['created_at'])); ?></td>
                                <td>
                                    <?php if ($video['status'] === 'pending'): ?>
                                        <span class="status-badge status-pending">În așteptare</span>
                                        <span class="ai-working">AI-ul lucrează...</span>
                                    <?php elseif ($video['status'] === 'done'): ?>
                                        <span class="status-badge status-done">Finalizat</span>
                                    <?php else: ?>
                                        <span class="status-badge"><?php echo htmlspecialchars($video['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
