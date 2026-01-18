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
$success_msg = '';

// Handle Production Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['produce'])) {
    $new_title = $_POST['title'] ?? $video['title'];
    $new_script = $_POST['script'] ?? $video['script'];
    $new_description = $_POST['description'] ?? $video['description'];
    $new_tags = $_POST['tags'] ?? $video['tags'];

    try {
        $pdo->beginTransaction();

        // Check limits again
        $stmt_user = $pdo->prepare("SELECT monthly_limit, videos_used FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $user_data = $stmt_user->fetch();

        if ($user_data['videos_used'] >= $user_data['monthly_limit']) {
            throw new Exception("Limită de video-uri atinsă. Te rugăm să faci upgrade.");
        }

        // 1. Save changes and update status to 'pending'
        $stmt = $pdo->prepare("UPDATE videos SET title = ?, script = ?, description = ?, tags = ?, status = 'pending' WHERE id = ?");
        $stmt->execute([$new_title, $new_script, $new_description, $new_tags, $video_id]);

        // 2. Increment usage
        $stmt = $pdo->prepare("UPDATE users SET videos_used = videos_used + 1 WHERE id = ?");
        $stmt->execute([$user_id]);

        $pdo->commit();

        // Redirect to dashboard with success message as requested in Dashboard logic
        header("Location: dashboard.php?success=Video-ul tău a fost trimis la procesare! Verifică Dashboard-ul în câteva minute.");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Eroare: " . $e->getMessage();
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
            --bg-dark: #121212;
            --card-bg: #1e1e1e;
            --input-bg: #2c2c2c;
            --accent-purple: #bb86fc;
            --accent-turquoise: #03dac6;
            --text-main: #e0e0e0;
            --text-dim: #b0b0b0;
            --border-color: #333;
        }

        body {
            background-color: var(--bg-dark);
            color: var(--text-main);
            font-family: 'Inter', 'Segoe UI', Roboto, sans-serif;
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
            max-width: 900px;
        }

        h1 {
            color: #ffffff;
            font-size: 2rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(45deg, var(--accent-purple), var(--accent-turquoise));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        p.subtitle {
            color: var(--text-dim);
            margin-bottom: 2rem;
        }

        .studio-card {
            background-color: var(--card-bg);
            padding: 2.5rem;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            border: 1px solid var(--border-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--accent-purple);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        input[type="text"], textarea {
            width: 100%;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: var(--input-bg);
            color: #fff;
            font-size: 1rem;
            box-sizing: border-box;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        input[type="text"]:focus, textarea:focus {
            outline: none;
            border-color: var(--accent-turquoise);
            box-shadow: 0 0 0 2px rgba(3, 218, 198, 0.2);
        }

        textarea {
            resize: vertical;
            min-height: 120px;
            line-height: 1.5;
        }

        .script-area {
            min-height: 180px;
        }

        .images-section {
            margin: 2.5rem 0;
        }

        .images-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .image-card {
            background-color: var(--input-bg);
            padding: 0.5rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            transition: transform 0.3s;
        }

        .image-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-purple);
        }

        .image-card img {
            width: 100%;
            height: auto;
            border-radius: 8px;
            display: block;
        }

        .btn-produce {
            width: 100%;
            padding: 1.25rem;
            border: none;
            border-radius: 12px;
            background: linear-gradient(90deg, #00b09b, #96c93d); /* Gradient verde/albastru-ish as requested */
            color: #121212;
            font-weight: 800;
            font-size: 1.2rem;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 2px;
            box-shadow: 0 4px 15px rgba(0, 176, 155, 0.4);
            transition: all 0.3s ease;
            margin-top: 2rem;
        }

        .btn-produce:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 176, 155, 0.6);
            filter: brightness(1.1);
        }

        .btn-produce:active {
            transform: translateY(0);
        }

        .error {
            color: #ff5252;
            background-color: rgba(255, 82, 82, 0.1);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            text-align: center;
            border: 1px solid rgba(255, 82, 82, 0.2);
        }

        /* Sidebar adjustment if header.php is used */
        <?php if (file_exists(__DIR__ . '/../views/header.php')): ?>
        /* Assuming sidebar is 250px wide in header.php */
        <?php endif; ?>
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/header.php'; ?>

    <div class="main-content">
        <div class="container">
            <h1>Studio Creație Video</h1>
            <p class="subtitle">Rafinează-ți conținutul generat de AI înainte de producția finală.</p>

            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="studio-card">
                    <div class="form-group">
                        <label for="title">Titlu Video</label>
                        <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($video['title']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="script">Script Video (Voce AI)</label>
                        <textarea name="script" id="script" class="script-area" required><?php echo htmlspecialchars($video['script']); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="description">Descriere Social Media</label>
                        <textarea name="description" id="description"><?php echo htmlspecialchars($video['description']); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="tags">Etichete (Tags)</label>
                        <input type="text" name="tags" id="tags" value="<?php echo htmlspecialchars($video['tags']); ?>">
                    </div>

                    <div class="images-section">
                        <label>Storyboard Vizual</label>
                        <div class="images-grid">
                            <div class="image-card">
                                <img src="<?php echo htmlspecialchars($video['image1']); ?>" alt="Scena 1">
                            </div>
                            <div class="image-card">
                                <img src="<?php echo htmlspecialchars($video['image2']); ?>" alt="Scena 2">
                            </div>
                            <div class="image-card">
                                <img src="<?php echo htmlspecialchars($video['image3']); ?>" alt="Scena 3">
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="produce" class="btn-produce">PRODUCE VIDEO (30 SEC)</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
