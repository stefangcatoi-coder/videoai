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
    $img1 = __DIR__ . "/" . $video['image1'];
    $img2 = __DIR__ . "/" . $video['image2'];
    $img3 = __DIR__ . "/" . $video['image3'];
    $audio = __DIR__ . "/" . $video['voiceover_path'];
    $fontPath = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf";

    if (!file_exists($img1) || !file_exists($img2) || !file_exists($img3) || !file_exists($audio)) {
        echo "<p style='color: red;'>Eroare: Unele fișiere media lipsesc.</p>";
        exit;
    }

    // 3. Timing Calculation
    $ffprobe_cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($audio);
    $audio_duration = (float)shell_exec($ffprobe_cmd);
    if (!$audio_duration || $audio_duration <= 0) $audio_duration = 20.0;

    $img_duration = $audio_duration / 3;

    // 4. Subtitle Processing
    function getPhrases($text) {
        $words = explode(' ', $text);
        $phrases = [];
        $current = [];
        foreach ($words as $word) {
            $current[] = $word;
            if (count($current) >= 4 || preg_match('/[.!?]$/', $word)) {
                $phrases[] = trim(implode(' ', $current));
                $current = [];
            }
        }
        if (!empty($current)) $phrases[] = trim(implode(' ', $current));
        return $phrases;
    }

    $scriptText = $video['script'] ?? '';
    $phrases = getPhrases($scriptText);
    $numPhrases = count($phrases);
    $phraseDuration = ($numPhrases > 0) ? $audio_duration / $numPhrases : 0;

    // FFmpeg Text Escaping
    function escapeFf($t) {
        $t = str_replace(["\\", "'", ":"], ["\\\\", "'\\''", "\\:"], $t);
        return $t;
    }

    // 5. Build Filter Complex
    // Slideshow part
    // Folosim scale=w=-1:h=1920,crop=1080:1920 conform specificațiilor
    $filter = "[0:v]scale=w=-1:h=1920,crop=1080:1920,setsar=1,trim=duration=$img_duration,setpts=PTS-STARTPTS[v1]; ";
    $filter .= "[1:v]scale=w=-1:h=1920,crop=1080:1920,setsar=1,trim=duration=$img_duration,setpts=PTS-STARTPTS[v2]; ";
    $filter .= "[2:v]scale=w=-1:h=1920,crop=1080:1920,setsar=1,trim=duration=$img_duration,setpts=PTS-STARTPTS[v3]; ";
    $filter .= "[v1][v2][v3]concat=n=3:v=1:a=0[vbase]; ";

    // Subtitles part
    $lastLabel = "vbase";
    for ($i = 0; $i < $numPhrases; $i++) {
        $start = $i * $phraseDuration;
        $end = ($i + 1) * $phraseDuration;
        $nextLabel = "vsub" . $i;
        $text = escapeFf($phrases[$i]);

        // Current phrase (Yellow, Centered)
        $filter .= "[$lastLabel]drawtext=fontfile='$fontPath':text='$text':fontcolor=yellow:fontsize=64:x=(w-text_w)/2:y=(h-text_h)/2:enable='between(t,$start,$end)'";

        // Previous phrase (White, Above)
        if ($i > 0) {
            $prevText = escapeFf($phrases[$i-1]);
            $filter .= ",drawtext=fontfile='$fontPath':text='$prevText':fontcolor=white@0.4:fontsize=50:x=(w-text_w)/2:y=(h-text_h)/2-100:enable='between(t,$start,$end)'";
        }

        // Next phrase (White, Below)
        if ($i < $numPhrases - 1) {
            $nextText = escapeFf($phrases[$i+1]);
            $filter .= ",drawtext=fontfile='$fontPath':text='$nextText':fontcolor=white@0.4:fontsize=50:x=(w-text_w)/2:y=(h-text_h)/2+100:enable='between(t,$start,$end)'";
        }

        $filter .= "[$nextLabel]; ";
        $lastLabel = $nextLabel;
    }

    // Final output label
    $finalV = substr($lastLabel, 0);

    $output_filename = "video_" . $video_id . "_" . time() . ".mp4";
    $output_path = __DIR__ . "/uploads/videos/" . $output_filename;
    $relative_video_path = "uploads/videos/" . $output_filename;

    if (!is_dir(__DIR__ . "/uploads/videos/")) mkdir(__DIR__ . "/uploads/videos/", 0775, true);

    $ffmpeg_cmd = "ffmpeg -y " .
        "-loop 1 -t $img_duration -i " . escapeshellarg($img1) . " " .
        "-loop 1 -t $img_duration -i " . escapeshellarg($img2) . " " .
        "-loop 1 -t $img_duration -i " . escapeshellarg($img3) . " " .
        "-i " . escapeshellarg($audio) . " " .
        "-filter_complex \"$filter\" " .
        "-map \"[$lastLabel]\" -map 3:a -c:v libx264 -pix_fmt yuv420p -preset faster -crf 23 -c:a aac -b:a 192k -shortest " . escapeshellarg($output_path) . " 2>&1";

    exec($ffmpeg_cmd, $output, $return_var);

    if ($return_var !== 0) {
        file_put_contents(__DIR__ . '/../storage/debug_ffmpeg.log', "CMD: $ffmpeg_cmd\n\nOUTPUT:\n" . implode("\n", $output));
        echo "<p style='color: red;'>Eroare FFmpeg. Verifică storage/debug_ffmpeg.log.</p>";
        exit;
    }

    // 6. Update Database
    $stmt = $pdo->prepare("UPDATE videos SET status = 'done', video_path = ? WHERE id = ?");
    $stmt->execute([$relative_video_path, $video_id]);

    echo "<script>window.location.href = 'dashboard.php?success=Video-ul cu subtitrări este gata!';</script>";
    ?>
</body>
</html>
