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

// Redirect drafts
if ($video['status'] === 'draft') {
    header("Location: edit_draft.php?id=" . $video_id);
    exit;
}

$placeholder = 'Nu a fost generat încă';

?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Producție: <?php echo htmlspecialchars($video['title'] ?: 'Video'); ?> - Video AI</title>
    <style>
        :root {
            --bg-dark: #121212; --card-bg: #1e1e1e; --accent-purple: #bb86fc;
            --accent-turquoise: #03dac6; --text-main: #e0e0e0; --border-color: #333;
        }
        body { background-color: var(--bg-dark); color: var(--text-main); font-family: 'Segoe UI', sans-serif; margin: 0; display: flex; }
        .main-content { margin-left: 250px; padding: 2rem; width: calc(100% - 250px); box-sizing: border-box; }

        .view-grid { display: grid; grid-template-columns: 400px 1fr; gap: 2rem; align-items: start; }

        /* Left Column: Player */
        .player-card { background: #000; border-radius: 16px; border: 1px solid var(--border-color); overflow: hidden; position: sticky; top: 2rem; }
        .video-container { aspect-ratio: 9/16; width: 100%; display: flex; align-items: center; justify-content: center; }
        video { width: 100%; height: 100%; object-fit: contain; }

        /* Right Column: Metadata */
        .resources-panel { display: flex; flex-direction: column; gap: 1.5rem; }
        .meta-card { background: var(--card-bg); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); }
        .meta-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; }
        .meta-header h3 { margin: 0; color: var(--accent-purple); font-size: 0.9rem; text-transform: uppercase; }

        .field-group { position: relative; }
        textarea { width: 100%; background: #121212; border: 1px solid #333; color: #fff; padding: 1rem; border-radius: 8px; resize: none; font-size: 0.95rem; line-height: 1.5; }
        .copy-btn { padding: 0.4rem 0.8rem; background: #333; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 0.75rem; transition: background 0.2s; }
        .copy-btn:hover { background: var(--accent-turquoise); color: #000; }

        .audio-player { width: 100%; margin-top: 0.5rem; }

        /* Actions */
        .action-btns { display: flex; gap: 1rem; margin-top: 1rem; }
        .btn-action { flex: 1; padding: 1rem; border-radius: 8px; font-weight: bold; text-align: center; text-decoration: none; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn-download { background: linear-gradient(90deg, #00b09b, #96c93d); color: #000; }
        .badge { padding: 0.3rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; }
        .badge-done { background: rgba(3, 218, 198, 0.1); color: var(--accent-turquoise); border: 1px solid var(--accent-turquoise); }

        .btn-done {
            background-color: var(--accent-turquoise);
            color: #121212;
            padding: 0.5rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            transition: opacity 0.3s;
        }
        .btn-done:hover { opacity: 0.8; }

        @media (max-width: 1000px) {
            .view-grid { grid-template-columns: 1fr; }
            .player-card { position: relative; max-width: 400px; margin: 0 auto; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/header.php'; ?>

    <div class="main-content">
        <div class="view-grid">
            <!-- Columna Stanga: Video -->
            <div class="player-card">
                <div class="video-container">
                    <?php if ($video['status'] === 'done'): ?>
                        <video controls>
                            <source src="<?php echo htmlspecialchars($video['video_path']); ?>" type="video/mp4">
                            Browser-ul tău nu suportă tag-ul video.
                        </video>
                    <?php else: ?>
                        <div style="padding: 2rem; text-align: center;">
                            <div class="badge">Status: <?php echo $video['status']; ?></div>
                            <p>Video-ul este în procesare...</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="padding: 1rem; background: #1a1a1a;">
                    <div class="action-btns">
                        <?php if ($video['status'] === 'done'): ?>
                            <a href="<?php echo htmlspecialchars($video['video_path']); ?>" download class="btn-action btn-download">
                                📥 Descarcă MP4
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Columna Dreapta: Metadata & Resurse -->
            <div class="resources-panel">
                <div style="display:flex; justify-content: space-between; align-items: center;">
                    <h1>Detalii Video</h1>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <span class="badge badge-done"><?php echo $video['status']; ?></span>
                        <a href="dashboard.php" class="btn-done">Gata</a>
                    </div>
                </div>

                <div class="meta-card">
                    <div class="meta-header">
                        <h3>Titlu Video</h3>
                        <button class="copy-btn" onclick="copyToClipboard('titleField', this)">Copy</button>
                    </div>
                    <div class="field-group">
                        <textarea id="titleField" rows="2" readonly><?php echo htmlspecialchars($video['title'] ?: $placeholder); ?></textarea>
                    </div>
                </div>

                <div class="meta-card">
                    <div class="meta-header">
                        <h3>Script (Voce AI)</h3>
                        <button class="copy-btn" onclick="copyToClipboard('scriptField', this)">Copy</button>
                    </div>
                    <div class="field-group">
                        <textarea id="scriptField" rows="6" readonly><?php echo htmlspecialchars($video['script'] ?: $placeholder); ?></textarea>
                    </div>
                </div>

                <div class="meta-card">
                    <div class="meta-header">
                        <h3>Descriere SEO</h3>
                        <button class="copy-btn" onclick="copyToClipboard('descField', this)">Copy</button>
                    </div>
                    <div class="field-group">
                        <textarea id="descField" rows="6" readonly><?php echo htmlspecialchars($video['description'] ?: $placeholder); ?></textarea>
                    </div>
                </div>

                <div class="meta-card">
                    <div class="meta-header">
                        <h3>Hashtags / Etichete</h3>
                        <button class="copy-btn" onclick="copyToClipboard('tagsField', this)">Copy</button>
                    </div>
                    <div class="field-group">
                        <textarea id="tagsField" rows="3" readonly><?php echo htmlspecialchars($video['tags'] ?: $placeholder); ?></textarea>
                    </div>
                </div>

                <div class="meta-card">
                    <div class="meta-header">
                        <h3>Fișier Voiceover (MP3)</h3>
                    </div>
                    <?php if ($video['voiceover_path']): ?>
                        <audio controls class="audio-player">
                            <source src="<?php echo htmlspecialchars($video['voiceover_path']); ?>" type="audio/mpeg">
                        </audio>
                    <?php else: ?>
                        <p style="color: #666;"><?php echo $placeholder; ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    function copyToClipboard(id, btn) {
        const text = document.getElementById(id).value;
        navigator.clipboard.writeText(text).then(() => {
            const originalText = btn.innerText;
            btn.innerText = 'Copiat!';
            btn.style.background = '#03dac6';
            btn.style.color = '#000';
            setTimeout(() => {
                btn.innerText = originalText;
                btn.style.background = '#333';
                btn.style.color = '#fff';
            }, 2000);
        });
    }
    </script>
</body>
</html>
