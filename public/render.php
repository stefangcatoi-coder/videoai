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
    $audio = __DIR__ . "/" . $video['voiceover_path'];
    $images = json_decode($video['assets_json'], true) ?: [$video['image1'], $video['image2'], $video['image3']];
    $ass_path = "/dev/shm/video_" . $video_id . "/final.ass";

    // Verify files exist
    if (!file_exists($audio)) {
        echo "<p style='color: red;'>Eroare: Fișierul audio lipsește.</p>";
        exit;
    }

    // 3. Calculate Audio Duration
    $ffprobe_cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($audio);
    $audio_duration = (float)shell_exec($ffprobe_cmd);
    if (!$audio_duration) $audio_duration = 60.0;

    $num_images = count($images);
    $img_duration = $audio_duration / $num_images;
    $zoompan_d = round($img_duration * 25);

    $output_filename = "video_" . $video_id . "_" . time() . ".mp4";
    $output_path = __DIR__ . "/uploads/videos/" . $output_filename;
    $relative_video_path = "uploads/videos/" . $output_filename;

    if (!is_dir(__DIR__ . "/uploads/videos/")) {
        mkdir(__DIR__ . "/uploads/videos/", 0775, true);
    }

    // 4. FFmpeg Command (Landscape 1920x1080)
    $ffmpeg = "ffmpeg";
    $input_images = "";
    $filter_complex = "";
    foreach ($images as $i => $img) {
        $abs_img = __DIR__ . "/" . $img;
        $input_images .= "-loop 1 -t " . $img_duration . " -i " . escapeshellarg($abs_img) . " ";
        $filter_complex .= "[$i:v]scale=1920:-1,crop=1920:1080,zoompan=z='min(zoom+0.001,1.5)':d=$zoompan_d:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=1920x1080[v$i]; ";
    }
    
    for ($i = 0; $i < $num_images; $i++) $filter_complex .= "[v$i]";
    $filter_complex .= "concat=n=$num_images:v=1:a=0[vcat]; ";

    // Add Subtitles
    if (file_exists($ass_path)) {
        // FFmpeg subtitles filter requires special path escaping
        $escaped_ass = str_replace(":", "\\:", $ass_path);
        $filter_complex .= "[vcat]subtitles=" . escapeshellarg($escaped_ass) . "[v]";
    } else {
        $filter_complex .= "[vcat]copy[v]";
    }

    $ffmpeg_cmd = "$ffmpeg -y $input_images -i " . escapeshellarg($audio) . " " .
        "-filter_complex \"$filter_complex\" " .
        "-map \"[v]\" -map $num_images:a -c:v libx264 -pix_fmt yuv420p -preset fast -c:a aac -b:a 192k -shortest " . escapeshellarg($output_path) . " 2>&1";

    exec($ffmpeg_cmd, $output, $return_var);

    if ($return_var !== 0) {
        if (!is_dir(__DIR__ . '/../storage')) mkdir(__DIR__ . '/../storage', 0775, true);
        file_put_contents(__DIR__ . '/../storage/debug_ffmpeg.log', "CMD: $ffmpeg_cmd\n\nOUTPUT:\n" . implode("\n", $output));
        echo "<p style='color: red;'>Eroare FFmpeg. Verifică storage/debug_ffmpeg.log pentru detalii.</p>";
        exit;
    }

    // 5. Update Database
    $stmt = $pdo->prepare("UPDATE videos SET status = 'done', video_path = ? WHERE id = ?");
    $stmt->execute([$relative_video_path, $video_id]);

    // 6. Success - Redirect using JS since headers already sent
    echo "<script>window.location.href = 'dashboard.php?success=Video-ul tău este gata! Îl poți vedea acum.';</script>";
    ?>
</body>
</html>
