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
    $idea = $_POST['idea'] ?? '';

    if (!empty($idea)) {
        try {
            $pdo->beginTransaction();

            // Insert new video as a Draft with placeholders
            $stmt = $pdo->prepare("INSERT INTO videos (user_id, title, status, script, description, tags, image1, image2, image3) VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, ?)");

            $placeholder_script = "Acesta este un script generat automat pentru: " . $idea;
            $placeholder_desc = "Descriere generată pentru: " . $idea;
            $placeholder_tags = "video, ai, " . strtolower(str_replace(' ', ', ', $idea));
            $img1 = "https://placehold.co/600x400?text=Imagine+1";
            $img2 = "https://placehold.co/600x400?text=Imagine+2";
            $img3 = "https://placehold.co/600x400?text=Imagine+3";

            $stmt->execute([
                $user_id,
                $idea,
                $placeholder_script,
                $placeholder_desc,
                $placeholder_tags,
                $img1,
                $img2,
                $img3
            ]);

            $video_id = $pdo->lastInsertId();

            // We don't increment videos_used yet, only when they confirm the final generation?
            // Actually, usually draft creation doesn't consume credits, but the user didn't specify.
            // In the previous flow, generation incremented it.
            // I'll leave it for the "Confirm" step in edit_draft.php to be more user-friendly.

            $pdo->commit();

            header("Location: edit_draft.php?id=" . $video_id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "A apărut o eroare la salvare: " . $e->getMessage();
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
    <title>Planifică Video - Video AI</title>
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

                    <form method="POST">
                        <div class="form-group">
                            <label for="idea">Ideea Video-ului</label>
                            <input type="text" name="idea" id="idea" placeholder="Ex: Cum să gătești paste" required>
                        </div>
                        <button type="submit" class="btn-generate">Planifică Video</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
