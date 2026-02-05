<?php
// app/process_video.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/speechify.php';

$video_id = $argv[1] ?? 0;

if (!$video_id) {
    die("No video ID provided.\n");
}

try {
    // 1. Fetch video data
    $stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ?");
    $stmt->execute([$video_id]);
    $video = $stmt->fetch();

    if (!$video) die("Video not found.\n");

    $segments = json_decode($video['segments_json'], true);
    if (empty($segments)) {
        // Fallback: use the whole script as one segment
        $segments = [$video['script']];
    }

    $shm_dir = "/dev/shm/video_" . $video_id;
    if (!is_dir($shm_dir)) mkdir($shm_dir, 0777, true);

    $audio_files = [];
    $ass_files = [];

    $colors = ['yellow', 'white', 'cyan', 'lime', 'orange', 'lightblue'];
    $randomColorName = $colors[array_rand($colors)];
    $colorMap = [
        'yellow' => '&H00FFFF',
        'white' => '&HFFFFFF',
        'cyan' => '&HFFFF00',
        'lime' => '&H00FF00',
        'orange' => '&H00A5FF',
        'lightblue' => '&HFFCC00'
    ];
    $assColor = $colorMap[$randomColorName];

    $totalDuration = 0.0;

    foreach ($segments as $index => $segmentText) {
        $segmentIndex = $index + 1;
        // Clean segment label if exists
        $cleanText = preg_replace('/\[SEGMENT \d+\]/', '', $segmentText);
        $cleanText = trim($cleanText);

        // a. TTS via Speechify
        $apiKey = SPEECHIFY_API_KEY;
        $url = SPEECHIFY_API_URL;
        $payload = [
            "input" => $cleanText,
            "voice_id" => "george",
            "language" => "ro-RO",
            "audio_format" => "mp3",
            "model" => "simba-multilingual"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey", "Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) throw new Exception("Speechify Error $httpCode: $response");

        $result = json_decode($response, true);
        $audio_content = base64_decode($result['audio_data'] ?? '');

        $audio_path = "$shm_dir/seg_$segmentIndex.mp3";
        file_put_contents($audio_path, $audio_content);
        $audio_files[] = $audio_path;

        // b. Transcription via Whisper
        $whisper_cmd = "whisper " . escapeshellarg($audio_path) . " --model base --word_timestamps True --output_format json --output_dir " . escapeshellarg($shm_dir) . " --threads 4";
        exec($whisper_cmd);

        $json_path = "$shm_dir/seg_$segmentIndex.json";
        if (!file_exists($json_path)) throw new Exception("Whisper failed to generate JSON for segment $segmentIndex");

        $transcript = json_decode(file_get_contents($json_path), true);

        // c. Generate ASS Subtitles for this segment
        $ass_content = "[Script Info]\nScriptType: v4.00+\nPlayResX: 1920\nPlayResY: 1080\n\n";
        $ass_content .= "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $ass_content .= "Style: Default,Sans,60,$assColor,&H000000,&H000000,&H000000,1,0,0,0,100,100,0,0,1,3,0,2,10,10,100,1\n\n";
        $ass_content .= "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        if (isset($transcript['segments'])) {
            foreach ($transcript['segments'] as $s) {
                if (isset($s['words'])) {
                    foreach ($s['words'] as $w) {
                        $start = formatAssTime($w['start'] + $totalDuration);
                        $end = formatAssTime($w['end'] + $totalDuration);
                        $text = trim($w['word']);
                        $ass_content .= "Dialogue: 0,$start,$end,Default,,0,0,0,,$text\n";
                    }
                }
            }
        }

        $ass_path = "$shm_dir/seg_$segmentIndex.ass";
        file_put_contents($ass_path, $ass_content);
        $ass_files[] = $ass_path;

        // Update total duration
        $duration_cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($audio_path);
        $seg_duration = (float)shell_exec($duration_cmd);
        $totalDuration += $seg_duration;
    }

    // Combine Audios
    $final_audio_filename = "voiceover_" . $video_id . "_" . time() . ".mp3";
    $final_audio_path = __DIR__ . "/../public/uploads/audio/" . $final_audio_filename;

    $concat_cmd = "ffmpeg -y ";
    foreach ($audio_files as $f) $concat_cmd .= "-i " . escapeshellarg($f) . " ";
    $concat_cmd .= "-filter_complex \"concat=n=" . count($audio_files) . ":v=0:a=1\" " . escapeshellarg($final_audio_path);
    exec($concat_cmd);

    // Combine ASS files
    $final_ass_path = "$shm_dir/final.ass";
    $final_ass_content = "[Script Info]\nScriptType: v4.00+\nPlayResX: 1920\nPlayResY: 1080\n\n";
    $final_ass_content .= "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
    $final_ass_content .= "Style: Default,Sans,70,$assColor,&H000000,&H000000,&H000000,1,0,0,0,100,100,0,0,1,4,0,2,10,10,100,1\n\n";
    $final_ass_content .= "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

    foreach ($ass_files as $f) {
        $lines = file($f);
        foreach ($lines as $line) {
            if (strpos($line, "Dialogue:") === 0) {
                $final_ass_content .= $line;
            }
        }
    }
    file_put_contents($final_ass_path, $final_ass_content);

    $stmt = $pdo->prepare("UPDATE videos SET status = 'ready_for_render', voiceover_path = ?, subtitle_color = ? WHERE id = ?");
    $stmt->execute(["uploads/audio/" . $final_audio_filename, $randomColorName, $video_id]);

} catch (Exception $e) {
    file_put_contents(__DIR__ . '/../storage/process_error.log', $e->getMessage());
}

function formatAssTime($seconds) {
    $h = floor($seconds / 3600);
    $m = floor(((int)$seconds % 3600) / 60);
    $s = $seconds - ($h * 3600) - ($m * 60);
    return sprintf("%01d:%02d:%05.2f", $h, $m, $s);
}
