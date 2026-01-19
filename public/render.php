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

// Verificăm existența coloanelor esențiale în baza de date
try {
    $pdo->query("SELECT voiceover_path, video_path FROM videos LIMIT 1");
} catch (PDOException $e) {
    die("Eroare Bază de Date: Coloanele necesare (voiceover_path, video_path) lipsesc. Rulează update_db.php.");
}

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
    <p>Adăugăm subtitrări dinamice și procesăm imaginile. Te rugăm să aștepți.</p>

    <?php
    if (ob_get_level()) ob_end_flush();
    flush();

    // 2. Paths
    function getRealFfPath($path) {
        if (empty($path)) return "";
        if (strpos($path, "http") === 0) return $path;
        return __DIR__ . "/" . $path;
    }

    $img1 = getRealFfPath($video['image1']);
    $img2 = getRealFfPath($video['image2']);
    $img3 = getRealFfPath($video['image3']);
    $audio = getRealFfPath($video['voiceover_path']);
    $fontPath = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf";

    // Verificăm dacă fișierele locale există
    $checkFile = function($p) {
        if (empty($p)) return false;
        if (strpos($p, "http") === 0) return true; // Presupunem că URL-ul e valid
        return file_exists($p);
    };

    if (!$checkFile($img1) || !$checkFile($img2) || !$checkFile($img3) || !$checkFile($audio)) {
        echo "<p style='color: red;'>Eroare: Unele fișiere media lipsesc (sau URL invalid).</p>";
        exit;
    }

    // 3. Timing Calculation
    $ffprobe_cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($audio);
    $audio_duration = (float)shell_exec($ffprobe_cmd);
    if (!$audio_duration || $audio_duration <= 0) $audio_duration = 20.0;

    $img_duration = $audio_duration / 3;

    // 4. Word-Level Subtitles with Whisper
    $tempDir = __DIR__ . "/uploads/temp_" . $video_id . "_" . time() . "/";
    if (!is_dir($tempDir)) mkdir($tempDir, 0775, true);

    // Whisper creates a json file with the same name as audio but .json extension
    $audioBasename = pathinfo($audio, PATHINFO_FILENAME);
    $jsonOutput = $tempDir . $audioBasename . ".json";
    $assFile = $tempDir . "subtitles.ass";

    // Run Whisper for word-level timestamps
    $whisper_bin = (shell_exec("which whisper") !== null) ? "whisper" : "/usr/local/bin/whisper";

    // Check if bin exists, otherwise log it
    if ($whisper_bin !== "whisper" && !file_exists($whisper_bin)) {
        file_put_contents(__DIR__ . '/../storage/debug_whisper.log', "Error: Whisper binary not found. Please install it using 'pip install openai-whisper'.\n", FILE_APPEND);
    }

    $whisper_cmd = "$whisper_bin " . escapeshellarg($audio) . " --model base --language Romanian --word_timestamps True --output_format json --output_dir " . escapeshellarg($tempDir) . " 2>&1";
    exec($whisper_cmd, $w_out, $w_ret);

    function formatAssTime($seconds) {
        $h = floor($seconds / 3600);
        $m = floor(($seconds / 60) % 60);
        $s = floor($seconds % 60);
        $cs = round(($seconds - floor($seconds)) * 100);
        if ($cs >= 100) { $cs = 0; $s++; }
        return sprintf("%d:%02d:%02d.%02d", $h, $m, $s, $cs);
    }

    if ($w_ret === 0 && file_exists($jsonOutput)) {
        $data = json_decode(file_get_contents($jsonOutput), true);

        $assHeader = "[Script Info]\nScriptType: v4.00+\nPlayResX: 1080\nPlayResY: 1920\n\n";
        $assHeader .= "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $assHeader .= "Style: Default,Sans,72,&H00FFFFFF,&H0000FFFF,&H00000000,&H00000000,-1,0,0,0,100,100,0,0,1,2,2,5,10,10,10,1\n\n";
        $assHeader .= "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        $events = "";
        foreach ($data['segments'] as $segment) {
            if (!isset($segment['words'])) continue;

            $words = $segment['words'];
            foreach ($words as $idx => $wordData) {
                $start = formatAssTime($wordData['start']);
                $end = formatAssTime($wordData['end']);

                $lineText = "";
                foreach ($words as $i => $w) {
                    $cleanWord = trim($w['word']);
                    if ($i === $idx) {
                        $lineText .= "{\\1c&H00FFFF&}" . $cleanWord . "{\\1c&HFFFFFF&} ";
                    } else {
                        $lineText .= $cleanWord . " ";
                    }
                }
                $events .= "Dialogue: 0,$start,$end,Default,,0,0,0,," . trim($lineText) . "\n";
            }
        }
        file_put_contents($assFile, $assHeader . $events);
        $useAss = true;
    } else {
        // Log Whisper Error and disable ASS
        file_put_contents(__DIR__ . '/../storage/debug_whisper.log', " Whisper failed with code $w_ret: " . implode("\n", $w_out));
        $useAss = false;
    }

    // 5. Build Filter Complex
    // Slideshow part with Slow Zoom (Ken Burns) effect
    $zoompan_d = round($img_duration * 25); // frames at 25fps

    // Pre-scale and crop to 2160x3840 (double 1080x1920) for high-quality zoom
    $preScale = "scale=2160:3840:force_original_aspect_ratio=increase,crop=2160:3840,setsar=1";
    $zoomLogic = "zoompan=z='min(zoom+0.0015,1.5)':d=$zoompan_d:s=1080x1920:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':fps=25";

    $filter = "[0:v]$preScale,$zoomLogic,trim=duration=$img_duration,setpts=PTS-STARTPTS[v1]; ";
    $filter .= "[1:v]$preScale,$zoomLogic,trim=duration=$img_duration,setpts=PTS-STARTPTS[v2]; ";
    $filter .= "[2:v]$preScale,$zoomLogic,trim=duration=$img_duration,setpts=PTS-STARTPTS[v3]; ";
    $filter .= "[v1][v2][v3]concat=n=3:v=1:a=0[vbase]";

    if ($useAss) {
        if (!file_exists($assFile)) {
            file_put_contents(__DIR__ . '/../storage/debug_render.log', "Error: ASS file missing at $assFile\n", FILE_APPEND);
            $useAss = false;
            $lastLabel = "vbase";
        } else {
            // Pentru FFmpeg subtitles filter, calea trebuie să aibă backslash-uri dublate și coloane escapate
            $escapedAssPath = str_replace('\\', '\\\\', $assFile);
            $escapedAssPath = str_replace(':', '\\:', $escapedAssPath);
            $escapedAssPath = str_replace("'", "'\\''", $escapedAssPath);

            $filter .= "; [vbase]subtitles='" . $escapedAssPath . "'[vfinal]";
            $lastLabel = "vfinal";
        }
    } else {
        $lastLabel = "vbase";
    }

    $output_filename = "video_" . $video_id . "_" . time() . ".mp4";
    $output_path = __DIR__ . "/uploads/videos/" . $output_filename;
    $relative_video_path = "uploads/videos/" . $output_filename;

    if (!is_dir(__DIR__ . "/uploads/videos/")) mkdir(__DIR__ . "/uploads/videos/", 0775, true);

    $ffmpeg_cmd = "ffmpeg -y " .
        "-loop 1 -t $img_duration -i " . escapeshellarg($img1) . " " .
        "-loop 1 -t $img_duration -i " . escapeshellarg($img2) . " " .
        "-loop 1 -t $img_duration -i " . escapeshellarg($img3) . " " .
        "-i " . escapeshellarg($audio) . " " .
        "-filter_complex " . escapeshellarg($filter) . " " .
        "-map \"[$lastLabel]\" -map 3:a -c:v libx264 -pix_fmt yuv420p -preset faster -crf 23 -c:a aac -b:a 192k -shortest " . escapeshellarg($output_path);

    // Capturăm output-ul complet pentru debug conform cerinței
    $full_output = shell_exec("$ffmpeg_cmd 2>&1");
    file_put_contents(__DIR__ . '/../storage/debug_render.log', "CMD: $ffmpeg_cmd\n\nOUTPUT:\n" . $full_output);

    if (!file_exists($output_path) || filesize($output_path) < 1000) {
        echo "<p style='color: red;'>Eroare FFmpeg. Verifică storage/debug_render.log pentru detalii.</p>";
        exit;
    }

    // 6. Cleanup Logic (Post-Procesare)
    $filesToDelete = [$img1, $img2, $img3, $jsonOutput, $assFile];
    $deletedCount = 0;
    foreach ($filesToDelete as $f) {
        if (!empty($f) && strpos($f, 'http') !== 0 && file_exists($f)) {
            if (unlink($f)) $deletedCount++;
        }
    }

    // Logging cleanup status
    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    $freeSpace = round(@disk_free_space("/") / (1024 * 1024 * 1024), 2);
    $cleanupLog = "[" . date('Y-m-d H:i:s') . "] Video ID [$video_id] terminat. $deletedCount fișiere șterse, {$freeSpace}GB spațiu verificat\n";
    @file_put_contents($logDir . '/cleanup.log', $cleanupLog, FILE_APPEND);

    // 7. Update Database
    $stmt = $pdo->prepare("UPDATE videos SET status = 'done', video_path = ? WHERE id = ?");
    $stmt->execute([$relative_video_path, $video_id]);

    echo "<script>window.location.href = 'dashboard.php?success=Video-ul cu subtitrări este gata!';</script>";
    ?>
</body>
</html>
