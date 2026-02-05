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

$assets = json_decode($video['assets_json'], true) ?: [];
if (empty($assets)) {
    $assets = [
        ['path' => $video['image1'], 'keyword' => $video['prompt1']],
        ['path' => $video['image2'], 'keyword' => $video['prompt2']],
        ['path' => $video['image3'], 'keyword' => $video['prompt3']]
    ];
}

$error = '';

// Handle Production Request (Speechify Integration)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['produce'])) {
    set_time_limit(1200); // 20 minutes for long voice generation
    $new_title = $_POST['title'] ?? $video['title'];
    $new_script = $_POST['script'] ?? $video['script'];
    $new_description = $_POST['description'] ?? $video['description'];
    $new_tags = $_POST['tags'] ?? $video['tags'];

    try {
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

        // --- Start of Keep-Alive Page ---
        ?>
        <!DOCTYPE html>
        <html lang="ro">
        <head>
            <meta charset="UTF-8">
            <title>Generare Voce - Video AI</title>
            <style>
                body { background-color: #121212; color: #fff; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; flex-direction: column; text-align: center; }
                .loader { border: 5px solid #333; border-top: 5px solid #03dac6; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin-bottom: 20px; }
                @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
                h2 { background: linear-gradient(45deg, #bb86fc, #03dac6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
            </style>
        </head>
        <body>
            <div class="loader"></div>
            <h2>Generăm Vocea AI...</h2>
            <p>Procesăm script-ul tău în bucăți de înaltă calitate. <br>Acest lucru previne erorile de timeout pentru script-uri lungi.</p>
            <?php
            if (ob_get_level()) ob_end_flush();
            flush();
            // --- End of Keep-Alive Page ---

        $pdo->beginTransaction();

        // 3. Call Speechify API for Voiceover (with Chunking for long scripts)
        $apiKey = trim(SPEECHIFY_API_KEY);
        // Handle "Bearer " prefix if accidentally included in config
        if (strpos($apiKey, 'Bearer ') === 0) {
            $apiKey = substr($apiKey, 7);
        }
        $url = SPEECHIFY_API_URL;

        // Clean script of problematic characters and ensure it's not too long for one go
        $clean_script = str_replace(["\r", "\n"], " ", $new_script);
        // Remove characters that might break JSON or the API
        $clean_script = preg_replace('/[^\p{L}\p{N}\s\p{P}]/u', '', $clean_script);
        $clean_script = preg_replace('/\s+/', ' ', $clean_script);
        $clean_script = trim($clean_script);

        if (empty($clean_script)) {
            throw new Exception("Scriptul este gol după curățare.");
        }

        // Helper for splitting text into chunks (multibyte safe)
        $chunks = [];
        $tempText = $clean_script;
        $maxLength = 3000; // Safer limit for various languages and diacritics

        while (mb_strlen($tempText, 'UTF-8') > $maxLength) {
            $chunkPart = mb_substr($tempText, 0, $maxLength, 'UTF-8');
            $cutPos = mb_strrpos($chunkPart, '.', 0, 'UTF-8');
            if ($cutPos === false) $cutPos = mb_strrpos($chunkPart, ' ', 0, 'UTF-8');
            if ($cutPos === false) $cutPos = $maxLength;

            $chunks[] = mb_substr($tempText, 0, $cutPos + 1, 'UTF-8');
            $tempText = trim(mb_substr($tempText, $cutPos + 1, NULL, 'UTF-8'));
        }
        if (!empty($tempText)) $chunks[] = $tempText;

        $audio_content = "";
        foreach ($chunks as $idx => $chunk) {
            echo "<!-- Processing chunk " . ($idx+1) . " -->";
            flush();

            $payload = [
                "input" => $chunk,
                "voice_id" => "george",
                "language" => ($video['language'] === 'en' ? "en-US" : "ro-RO"),
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
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);

            $response = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $errorDetail = $response ?: $err;
                file_put_contents(__DIR__ . '/../storage/debug_speechify.log', "HTTP $httpCode Payload: " . json_encode($payload) . " Response: " . $errorDetail . "\n", FILE_APPEND);

                // If 400, it might be the voice/language combo
                if ($httpCode === 400) {
                    throw new Exception("Eroare Speechify API (400 - Bad Request). Verifică dacă vocea 'george' este disponibilă pentru limba selectată sau dacă textul conține caractere nepermise.");
                }
                throw new Exception("Eroare Speechify API (HTTP $httpCode). " . $errorDetail);
            }

            $result = json_decode($response, true);
            $audio_base64 = $result['audio_data'] ?? '';

            if (empty($audio_base64)) {
                throw new Exception("Speechify nu a returnat date audio pentru un chunk.");
            }
            $audio_content .= base64_decode($audio_base64);
        }

        // 4. Save Audio File
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

        // 7. Redirect to render.php via JS (since we already sent HTML)
        echo "<script>window.location.href = 'render.php?id=" . $video_id . "';</script>";
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
        .image-container { background: #222; padding: 10px; border-radius: 8px; }
        .image-card { aspect-ratio: <?php echo ($video['video_type'] === 'short' ? '9/16' : '16/9'); ?>; overflow: hidden; border-radius: 8px; margin-bottom: 10px; }
        .image-card img { width: 100%; height: 100%; object-fit: cover; }
        .btn-small { padding: 5px 10px; font-size: 0.7rem; cursor: pointer; border: none; border-radius: 4px; font-weight: bold; width: 100%; margin-bottom: 5px; }
        .btn-change { background: var(--accent-purple); color: #000; }
        .btn-upload { background: var(--accent-turquoise); color: #000; }
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
        .stock-item img { width: 100%; height: 150px; object-fit: cover; }
        .stock-item .source { position: absolute; bottom: 5px; right: 5px; background: rgba(0,0,0,0.6); color: #fff; font-size: 0.6rem; padding: 2px 4px; border-radius: 3px; }

        .loader-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 9999; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 20px;}
        .spinner { width: 50px; height: 50px; border: 5px solid rgba(255,255,255,0.1); border-top: 5px solid var(--accent-turquoise); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/header.php'; ?>
    <div id="loader" class="loader-overlay"><div class="spinner"></div><div style="color:#fff; margin-top:1rem;">Generăm Vocea... <br>Acesta este primul pas, urmat de producția video.</div></div>
    <div class="main-content">
        <div class="container">
            <h1>Studio Creație Video (<?php echo strtoupper($video['video_type']); ?>)</h1>
            <?php if ($error): ?><div style="color:#ff5252;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="POST" onsubmit="document.getElementById('loader').style.display='flex'">
                <div class="studio-card">
                    <div class="form-group"><label>Titlu Video</label><input type="text" name="title" value="<?php echo htmlspecialchars($video['title']); ?>" required></div>
                    <div class="form-group"><label>Script (Voce AI)</label><textarea name="script" required><?php echo htmlspecialchars($video['script']); ?></textarea></div>
                    <div class="form-group"><label>Descriere SEO</label><textarea name="description"><?php echo htmlspecialchars($video['description']); ?></textarea></div>
                    <div class="form-group"><label>Etichete</label><input type="text" name="tags" value="<?php echo htmlspecialchars($video['tags']); ?>"></div>
                    <label>Imagini Selectate (Stock)</label>
                    <div class="images-grid">
                        <?php foreach ($assets as $i => $asset): ?>
                        <div class="image-container">
                            <div class="image-card" id="card-<?php echo $i+1; ?>"><img src="<?php echo htmlspecialchars($asset['path']); ?>"></div>
                            <div class="image-actions">
                                <button type="button" class="btn-small btn-change" onclick="openStockModal(<?php echo $i+1; ?>, '<?php echo addslashes($asset['keyword']); ?>')">🔄 Schimbă</button>
                                <button type="button" class="btn-small btn-upload" onclick="triggerUpload(<?php echo $i+1; ?>)">📤 Upload</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
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
                <button type="button" class="btn-small btn-change" onclick="searchStock()" style="width: auto;">Caută</button>
            </div>
            <div id="stockGrid" class="stock-grid">
                <!-- Imagini dinamice aici -->
            </div>
        </div>
    </div>

    <script>
    let currentSlot = 1;
    const videoId = <?php echo (int)$video_id; ?>;
    const orientation = '<?php echo ($video['video_type'] === 'short' ? 'portrait' : 'landscape'); ?>';

    function openStockModal(slot, keyword) {
        currentSlot = slot;
        document.getElementById('stockModal').style.display = 'flex';
        document.getElementById('stockSearch').value = keyword || '';
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
            const res = await fetch(`fetch_stock_images.php?query=${encodeURIComponent(query)}&orientation=${orientation}`);
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
        formData.append('video_type', '<?php echo $video['video_type']; ?>');

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
