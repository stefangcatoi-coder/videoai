<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(600); // 10 minutes for rendering

// /var/www/video-ai/public/render.php

session_start();

// Security Middleware
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

$user_id = $_SESSION['user_id'];
$video_id = $_GET['id'] ?? 0;

// 1. Fetch video data and verify
$stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ? AND user_id = ?");
$stmt->execute([$video_id, $user_id]);
$video = $stmt->fetch();

if (!$video || $video['status'] !== 'ready_for_render') {
    header("Location: dashboard.php");
    exit;
}

?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Producție Video - Video AI</title>
    <style>
        body { background-color: #121212; color: #fff; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; flex-direction: column; }
        .loader { border: 5px solid #333; border-top: 5px solid #03dac6; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin-bottom: 20px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        h2 { background: linear-gradient(45deg, #bb86fc, #03dac6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    </style>
</head>
<body>
    <div class="loader"></div>
    <h2>Generăm Video-ul Final...</h2>
    <p>Acest proces poate dura până la 1 minut. Te rugăm să nu închizi pagina.</p>

    <?php
    // Flush the output to the browser so the user sees the loader
    if (ob_get_level()) ob_end_flush();
    flush();

    // 2. Paths
    $img1 = __DIR__ . "/" . $video['image1'];
    $img2 = __DIR__ . "/" . $video['image2'];
    $img3 = __DIR__ . "/" . $video['image3'];
    $audio = __DIR__ . "/" . $video['voiceover_path'];

    // Verify files exist
    if (!file_exists($img1) || !file_exists($img2) || !file_exists($img3) || !file_exists($audio)) {
        echo "<p style='color: red;'>Eroare: Unele fișiere media lipsesc de pe disc.</p>";
        exit;
    }

    $output_filename = "video_" . $video_id . "_" . time() . ".mp4";
    $output_path = __DIR__ . "/uploads/videos/" . $output_filename;
    $relative_video_path = "uploads/videos/" . $output_filename;

    if (!is_dir(__DIR__ . "/uploads/videos/")) {
        mkdir(__DIR__ . "/uploads/videos/", 0775, true);
    }

    // 3. FFmpeg Command
    // We try to find ffmpeg in common paths if it's not in PATH
    $ffmpeg = "ffmpeg";

    $ffmpeg_cmd = "$ffmpeg -y " .
        "-loop 1 -t 10 -i " . escapeshellarg($img1) . " " .
        "-loop 1 -t 10 -i " . escapeshellarg($img2) . " " .
        "-loop 1 -t 10 -i " . escapeshellarg($img3) . " " .
        "-i " . escapeshellarg($audio) . " " .
        "-filter_complex \"" .
        "[0:v]scale=w=1920:h=-1,crop=1080:1920,zoompan=z='min(zoom+0.001,1.5)':d=250:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=1080x1920[v1]; " .
        "[1:v]scale=w=1920:h=-1,crop=1080:1920,zoompan=z='min(zoom+0.001,1.5)':d=250:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=1080x1920[v2]; " .
        "[2:v]scale=w=1920:h=-1,crop=1080:1920,zoompan=z='min(zoom+0.001,1.5)':d=250:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=1080x1920[v3]; " .
        "[v1][v2][v3]concat=n=3:v=1:a=0[v]\" " .
        "-map \"[v]\" -map 3:a -c:v libx264 -pix_fmt yuv420p -c:a aac -b:a 192k -shortest " . escapeshellarg($output_path) . " 2>&1";

    exec($ffmpeg_cmd, $output, $return_var);

    if ($return_var !== 0) {
        if (!is_dir(__DIR__ . '/../storage')) mkdir(__DIR__ . '/../storage', 0775, true);
        file_put_contents(__DIR__ . '/../storage/debug_ffmpeg.log', "CMD: $ffmpeg_cmd\n\nOUTPUT:\n" . implode("\n", $output));
        echo "<p style='color: red;'>Eroare FFmpeg. Verifică storage/debug_ffmpeg.log pentru detalii.</p>";
        exit;
    }

    // 4. Update Database
    $stmt = $pdo->prepare("UPDATE videos SET status = 'done', video_path = ? WHERE id = ?");
    $stmt->execute([$relative_video_path, $video_id]);

    // 5. Success - Redirect using JS since headers already sent
    echo "<script>window.location.href = 'dashboard.php?success=Video-ul tău este gata! Îl poți vedea acum.';</script>";
    ?>
</body>
</html>
