<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(1200); // 20 minutes for long rendering

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
    <p>Adăugăm subtitrări dinamice și procesăm imaginile stock. Acest proces poate dura câteva minute pentru video-uri lungi.</p>

    <?php
    if (ob_get_level()) ob_end_flush();
    flush();

    // 2. Paths and Config
    function getRealFfPath($path) {
        if (empty($path)) return "";
        if (strpos($path, "http") === 0) return $path;
        return __DIR__ . "/" . $path;
    }

    $audio = getRealFfPath($video['voiceover_path']);
    $is_vertical = ($video['video_type'] === 'short');
    $width = $is_vertical ? 1080 : 1920;
    $height = $is_vertical ? 1920 : 1080;
    $whisper_lang = ($video['language'] === 'en') ? 'English' : 'Romanian';

    $assets = json_decode($video['assets_json'], true) ?: [];
    if (empty($assets)) {
        $assets = [
            ['path' => $video['image1']],
            ['path' => $video['image2']],
            ['path' => $video['image3']]
        ];
    }
    // Clean empty paths
    $assets = array_filter($assets, function($a) { return !empty($a['path']); });

    if (empty($assets) || !file_exists($audio)) {
        echo "<p style='color: red;'>Eroare: Unele fișiere media lipsesc.</p>";
        exit;
    }

    // 3. Timing Calculation
    $ffprobe_cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($audio);
    $audio_duration = (float)shell_exec($ffprobe_cmd);
    if (!$audio_duration || $audio_duration <= 0) $audio_duration = 30.0;

    $num_images = count($assets);
    $img_duration = $audio_duration / $num_images;

    // 4. Word-Level Subtitles with Whisper
    $tempDir = __DIR__ . "/uploads/temp_" . $video_id . "_" . time() . "/";
    if (!is_dir($tempDir)) mkdir($tempDir, 0775, true);

    $audioBasename = pathinfo($audio, PATHINFO_FILENAME);
    $jsonOutput = $tempDir . $audioBasename . ".json";
    $assFile = $tempDir . "subtitles.ass";

    $whisper_bin = (shell_exec("which whisper") !== null) ? "whisper" : "/usr/local/bin/whisper";
    $whisper_cmd = "$whisper_bin " . escapeshellarg($audio) . " --model base --language " . escapeshellarg($whisper_lang) . " --word_timestamps True --output_format json --output_dir " . escapeshellarg($tempDir) . " 2>&1";

    $w_handle = popen("$whisper_cmd", 'r');
    $w_out_str = "";
    while (!feof($w_handle)) {
        $line = fgets($w_handle);
        $w_out_str .= $line;
        echo "<!-- Transcribing... -->";
        flush();
    }
    $w_ret = pclose($w_handle);

    function formatAssTime($seconds) {
        $h = floor($seconds / 3600);
        $m = floor(($seconds / 60) % 60);
        $s = floor($seconds % 60);
        $cs = round(($seconds - floor($seconds)) * 100);
        if ($cs >= 100) { $cs = 0; $s++; }
        return sprintf("%d:%02d:%02d.%02d", $h, $m, $s, $cs);
    }

    $color_map = [
        'yellow' => '&H0000FFFF',
        'white' => '&H00FFFFFF',
        'cyan' => '&H00FFFF00',
        'lime' => '&H0000FF00',
        'orange' => '&H0000A5FF',
        'light_blue' => '&H00FFCC66'
    ];
    $primary_color = $color_map[$video['subtitle_color']] ?? '&H0000FFFF';

    if ($w_ret === 0 && file_exists($jsonOutput)) {
        $data = json_decode(file_get_contents($jsonOutput), true);

        $assHeader = "[Script Info]\nScriptType: v4.00+\nPlayResX: $width\nPlayResY: $height\n\n";
        $assHeader .= "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        // Alignment=2 (Bottom Center), MarginV=50/100
        $marginV = $is_vertical ? 100 : 50;
        $fontSize = $is_vertical ? 72 : 48;
        $assHeader .= "Style: Default,Sans,$fontSize,$primary_color,&H0000FFFF,&H00000000,&H00000000,-1,0,0,0,100,100,0,0,1,2,2,2,10,10,$marginV,1\n\n";
        $assHeader .= "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        $events = "";
        foreach ($data['segments'] as $segment) {
            if (!isset($segment['words'])) continue;
            $words = $segment['words'];
            foreach ($words as $idx => $wordData) {
                $start = formatAssTime($wordData['start']);
                $end = formatAssTime($wordData['end']);
                $cleanWord = trim($wordData['word']);
                $events .= "Dialogue: 0,$start,$end,Default,,0,0,0,," . $cleanWord . "\n";
            }
        }
        file_put_contents($assFile, $assHeader . $events);
        $useAss = true;
    } else {
        file_put_contents(__DIR__ . '/../storage/debug_whisper.log', " Whisper failed with code $w_ret for video $video_id. Output: $w_out_str\n", FILE_APPEND);
        $useAss = false;
    }

    // 5. Build Filter Complex
    $zoompan_d = round($img_duration * 25);
    $target_w = $width * 2;
    $target_h = $height * 2;
    $preScale = "scale=$target_w:$target_h:force_original_aspect_ratio=increase,crop=$target_w:$target_h,setsar=1";
    $zoomLogic = "zoompan=z='min(zoom+0.0015,1.5)':d=$zoompan_d:s={$width}x{$height}:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':fps=25";

    $inputs = "";
    $filter = "";
    foreach ($assets as $idx => $asset) {
        $imgPath = getRealFfPath($asset['path']);
        $inputs .= "-loop 1 -t $img_duration -i " . escapeshellarg($imgPath) . " ";
        $filter .= "[$idx:v]$preScale,$zoomLogic,trim=duration=$img_duration,setpts=PTS-STARTPTS[v$idx]; ";
    }

    $concatNodes = "";
    for ($i=0; $i<$num_images; $i++) { $concatNodes .= "[v$i]"; }
    $filter .= $concatNodes . "concat=n=$num_images:v=1:a=0[vbase]";

    if ($useAss && file_exists($assFile)) {
        $escapedAssPath = str_replace(['\\', ':', "'"], ['\\\\', '\\:', "'\\''"], $assFile);
        $filter .= "; [vbase]subtitles='" . $escapedAssPath . "'[vfinal]";
        $lastLabel = "vfinal";
    } else {
        $lastLabel = "vbase";
    }

    $output_filename = "video_" . $video_id . "_" . time() . ".mp4";
    $output_path = __DIR__ . "/uploads/videos/" . $output_filename;
    $relative_video_path = "uploads/videos/" . $output_filename;

    if (!is_dir(__DIR__ . "/uploads/videos/")) mkdir(__DIR__ . "/uploads/videos/", 0775, true);

    $ffmpeg_cmd = "ffmpeg -y $inputs -i " . escapeshellarg($audio) . " " .
        "-filter_complex " . escapeshellarg($filter) . " " .
        "-map \"[$lastLabel]\" -map $num_images:a -c:v libx264 -pix_fmt yuv420p -preset faster -crf 23 -c:a aac -b:a 192k -shortest " . escapeshellarg($output_path);

    // Use popen to keep connection alive during long rendering
    $handle = popen("$ffmpeg_cmd 2>&1", 'r');
    $full_output = "";
    while (!feof($handle)) {
        $line = fgets($handle);
        $full_output .= $line;
        // Keep-alive every few lines of output
        echo "<!-- Rendering... -->";
        flush();
    }
    pclose($handle);

    if (!file_exists($output_path) || filesize($output_path) < 1000) {
        file_put_contents(__DIR__ . '/../storage/debug_render.log', "CMD: $ffmpeg_cmd\n\nOUTPUT:\n$full_output\nFailed to generate video.\n", FILE_APPEND);
        echo "<p style='color: red;'>Eroare FFmpeg. Verifică storage/debug_render.log.</p>";
        exit;
    }

    // 6. Cleanup
    foreach ($assets as $asset) {
        $p = getRealFfPath($asset['path']);
        if (strpos($p, 'http') !== 0 && file_exists($p)) @unlink($p);
    }
    @unlink($jsonOutput);
    @unlink($assFile);
    @rmdir($tempDir);

    // 7. Update Database
    $stmt = $pdo->prepare("UPDATE videos SET status = 'done', video_path = ? WHERE id = ?");
    $stmt->execute([$relative_video_path, $video_id]);

    echo "<script>window.location.href = 'dashboard.php?success=Video-ul este gata!';</script>";
    ?>
</body>
</html>
