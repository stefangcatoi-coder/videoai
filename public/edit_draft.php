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
        .image-card { position: relative; min-height: 150px; background: #222; border-radius: 8px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .image-card img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; border: 1px solid var(--border-color); }
        .image-actions { margin-top: 0.5rem; display: flex; gap: 0.5rem; justify-content: center; }
        .btn-small { padding: 0.4rem 0.8rem; border: none; border-radius: 6px; font-size: 0.7rem; font-weight: bold; cursor: pointer; transition: opacity 0.2s; }
        .btn-change { background: var(--accent-purple); color: #121212; }
        .btn-upload { background: var(--accent-turquoise); color: #121212; }
        .btn-produce { width: 100%; padding: 1rem; border: none; border-radius: 12px; background: linear-gradient(90deg, #00b09b, #96c93d); color: #121212; font-weight: 800; cursor: pointer; margin-top: 2rem; }

        /* Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 10000; justify-content: center; align-items: center; }
        .modal-content { background: #1e1e1e; width: 90%; max-width: 800px; padding: 2rem; border-radius: 16px; position: relative; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .close-modal { color: #fff; font-size: 2rem; cursor: pointer; }
        .search-box { display: flex; gap: 1rem; margin-bottom: 1.5rem; }
        .search-box input { flex: 1; padding: 0.8rem; border-radius: 8px; border: 1px solid #333; background: #2c2c2c; color: #fff; }
        .stock-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; }
        .stock-item { cursor: pointer; border-radius: 8px; overflow: hidden; position: relative; border: 2px solid transparent; transition: border-color 0.2s; }
        .stock-item:hover { border-color: var(--accent-turquoise); }
        .stock-item img { width: 100%; height: 200px; object-fit: cover; }
        .stock-item .source { position: absolute; bottom: 5px; right: 5px; background: rgba(0,0,0,0.6); color: #fff; font-size: 0.6rem; padding: 2px 4px; border-radius: 3px; }

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
                    <label>Imagini Selectate (Stock)</label>
                    <div class="images-grid">
                        <div class="image-container">
                            <div class="image-card" id="card-1"><img src="<?php echo htmlspecialchars($video['image1']); ?>"></div>
                            <div class="image-actions">
                                <button type="button" class="btn-small btn-change" onclick="openStockModal(1)">🔄 Schimbă</button>
                                <button type="button" class="btn-small btn-upload" onclick="triggerUpload(1)">📤 Upload</button>
                            </div>
                        </div>
                        <div class="image-container">
                            <div class="image-card" id="card-2"><img src="<?php echo htmlspecialchars($video['image2']); ?>"></div>
                            <div class="image-actions">
                                <button type="button" class="btn-small btn-change" onclick="openStockModal(2)">🔄 Schimbă</button>
                                <button type="button" class="btn-small btn-upload" onclick="triggerUpload(2)">📤 Upload</button>
                            </div>
                        </div>
                        <div class="image-container">
                            <div class="image-card" id="card-3"><img src="<?php echo htmlspecialchars($video['image3']); ?>"></div>
                            <div class="image-actions">
                                <button type="button" class="btn-small btn-change" onclick="openStockModal(3)">🔄 Schimbă</button>
                                <button type="button" class="btn-small btn-upload" onclick="triggerUpload(3)">📤 Upload</button>
                            </div>
                        </div>
                    </div>
                    <input type="file" id="fileInput" style="display:none" accept="image/*" onchange="handleFileUpload(event)">
                    <button type="submit" name="produce" id="btnProduce" class="btn-produce">GENEREAZĂ VIDEO FINAL</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Modal Stock Selection -->
    <div id="stockModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Selectează Imagine</h2>
                <span class="close-modal" onclick="closeModal()">&times;</span>
            </div>
            <div class="search-box">
                <input type="text" id="stockSearch" placeholder="Caută alte imagini (engleză)...">
                <button type="button" class="btn-small btn-change" onclick="searchStock()">Caută</button>
            </div>
            <div id="stockGrid" class="stock-grid">
                <!-- Imagini dinamice aici -->
            </div>
        </div>
    </div>

    <script>
    let currentSlot = 1;
    const videoId = <?php echo (int)$video_id; ?>;

    function openStockModal(slot) {
        currentSlot = slot;
        document.getElementById('stockModal').style.display = 'flex';
        // Get keywords from hidden field or prompt
        const keywords = [
            "<?php echo addslashes($video['prompt1']); ?>",
            "<?php echo addslashes($video['prompt2']); ?>",
            "<?php echo addslashes($video['prompt3']); ?>"
        ];
        document.getElementById('stockSearch').value = keywords[slot-1] || '';
        searchStock();
    }

    function closeModal() {
        document.getElementById('stockModal').style.display = 'none';
    }

    async function searchStock() {
        const query = document.getElementById('stockSearch').value;
        const grid = document.getElementById('stockGrid');
        grid.innerHTML = '<div style="grid-column: 1/-1; text-align:center;">Se încarcă...</div>';

        try {
            const res = await fetch(`fetch_stock_images.php?query=${encodeURIComponent(query)}`);
            const data = await res.json();
            if (data.success) {
                grid.innerHTML = '';
                data.images.forEach(img => {
                    const div = document.createElement('div');
                    div.className = 'stock-item';
                    div.innerHTML = `<img src="${img.thumb}"><span class="source">${img.source}</span>`;
                    div.onclick = () => selectStockImage(img.url);
                    grid.appendChild(div);
                });
            } else {
                grid.innerHTML = `<div style="grid-column: 1/-1; color:red;">Eroare: ${data.error}</div>`;
            }
        } catch (e) {
            grid.innerHTML = '<div style="grid-column: 1/-1; color:red;">Eroare de rețea.</div>';
        }
    }

    async function selectStockImage(url) {
        closeModal();
        const card = document.getElementById('card-' + currentSlot);
        card.style.opacity = '0.5';

        const formData = new FormData();
        formData.append('video_id', videoId);
        formData.append('index', currentSlot);
        formData.append('url', url);

        try {
            const res = await fetch('save_selected_image.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                card.innerHTML = `<img src="${data.path}?t=${Date.now()}">`;
            } else {
                alert("Eroare: " + data.error);
            }
        } catch (e) {
            alert("Eroare de rețea.");
        } finally {
            card.style.opacity = '1';
        }
    }

    function triggerUpload(slot) {
        currentSlot = slot;
        document.getElementById('fileInput').click();
    }

    async function handleFileUpload(event) {
        const file = event.target.files[0];
        if (!file) return;

        const card = document.getElementById('card-' + currentSlot);
        card.style.opacity = '0.5';

        const formData = new FormData();
        formData.append('video_id', videoId);
        formData.append('index', currentSlot);
        formData.append('image', file);

        try {
            const res = await fetch('upload_custom_image.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                card.innerHTML = `<img src="${data.path}?t=${Date.now()}">`;
            } else {
                alert("Eroare la upload: " + data.error);
            }
        } catch (e) {
            alert("Eroare de rețea la upload.");
        } finally {
            card.style.opacity = '1';
            event.target.value = ''; // Reset input
        }
    }
    </script>
</body>
</html>
