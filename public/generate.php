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
    $title = $_POST['title'] ?? '';
    $prompt = $_POST['prompt'] ?? '';

    if (!empty($title) && !empty($prompt)) {
        try {
            $pdo->beginTransaction();

            // Insert new video
            $stmt = $pdo->prepare("INSERT INTO videos (user_id, title, status) VALUES (?, ?, 'pending')");
            $stmt->execute([$user_id, $title]);

            // Increment videos_used
            $stmt = $pdo->prepare("UPDATE users SET videos_used = videos_used + 1 WHERE id = ?");
            $stmt->execute([$user_id]);

            $pdo->commit();

            header("Location: dashboard.php?success=Video-ul tău se procesează!");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "A apărut o eroare la salvare: " . $e->getMessage();
        }
    } else {
        $error = "Vă rugăm să completați toate câmpurile.";
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

        /* Sidebar (Same as Dashboard) */
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

        input, textarea {
            width: 100%;
            padding: 0.75rem;
            border-radius: 4px;
            border: 1px solid #333;
            background-color: #2c2c2c;
            color: #fff;
            box-sizing: border-box;
            font-size: 1rem;
        }

        textarea {
            height: 150px;
            resize: vertical;
        }

        input:focus, textarea:focus {
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

        .btn-generate:disabled {
            background-color: #555;
            color: #888;
            cursor: not-allowed;
        }

        .error {
            color: #cf6679;
            background-color: rgba(207, 102, 121, 0.1);
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .info-box {
            background-color: #2c2c2c;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            text-align: center;
            border-left: 4px solid #bb86fc;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Video AI</h2>
        <a href="dashboard.php" class="nav-link">Dashboard</a>
        <a href="generate.php" class="nav-link active">Creează Video</a>
        <a href="logout.php" class="nav-link logout-link">Logout</a>
    </div>

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

                    <div class="info-box">
                        Credite disponibile: <?php echo ($user['monthly_limit'] - $user['videos_used']); ?>
                    </div>

                    <form method="POST">
                        <div class="form-group">
                            <label for="title">Titlu Video</label>
                            <input type="text" name="title" id="title" placeholder="Ex: Cum să gătești paste" required>
                        </div>
                        <div class="form-group">
                            <label for="prompt">Script / Prompt Video</label>
                            <textarea name="prompt" id="prompt" placeholder="Descrie în detaliu ce vrei să conțină video-ul tău..." required></textarea>
                        </div>
                        <button type="submit" class="btn-generate">Generează Video</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
