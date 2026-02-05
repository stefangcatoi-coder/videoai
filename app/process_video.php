<?php
// app/process_video.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/speechify.php';

$video_id = $argv[1] ?? 0;
if (!$video_id) die("No video ID provided.\n");

try {
    // 1. Fetch video data
    $stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ?");
    $stmt->execute([$video_id]);
    $video = $stmt->fetch();
    if (!$video) die("Video not found.\n");

    $segments = json_decode($video['segments_json'], true) ?: [$video['script']];

    $colorMap = [
        'yellow' => '&H00FFFF', 'white' => '&HFFFFFF', 'cyan' => '&HFFFF00',
        'lime' => '&H00FF00', 'orange' => '&H00A5FF', 'light blue' => '&HFFCC00'
    ];
    $assColor = $colorMap[$video['subtitle_color']] ?? '&HFFFFFF';

    $totalDuration = 0.0;
    $allAssDialogue = "";
    $combinedAudioBinary = "";

    // 2. Sequential in-memory processing
    foreach ($segments as $index => $segmentText) {
        $cleanText = trim(preg_replace('/\[SEGMENT \d+\]/', '', $segmentText));

        // a. TTS via Speechify -> memory
        $apiKey = SPEECHIFY_API_KEY;
        $url = SPEECHIFY_API_URL;
        $payload = [
            "input" => $cleanText, "voice_id" => "george", "language" => "ro-RO",
            "audio_format" => "mp3", "model" => "simba-multilingual"
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
        $audio_binary = base64_decode($result['audio_data'] ?? '');

        // b. Whisper Transcription -> memory via Python Bridge
        $descriptorspec = [
           0 => ["pipe", "r"], // stdin
           1 => ["pipe", "w"], // stdout
           2 => ["pipe", "w"]  // stderr
        ];
        $process = proc_open("python3 " . __DIR__ . "/whisper_worker.py", $descriptorspec, $pipes);
        if (is_resource($process)) {
            fwrite($pipes[0], $audio_binary);
            fclose($pipes[0]);
            $json_output = stream_get_contents($pipes[1]);
            $stderr_output = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $return_value = proc_close($process);
            if ($return_value !== 0) throw new Exception("Whisper bridge failed: $stderr_output");
        } else {
            throw new Exception("Could not start whisper worker.");
        }

        $transcript = json_decode($json_output, true);

        // c. Generate ASS Dialogue lines -> memory
        if (isset($transcript['segments'])) {
            foreach ($transcript['segments'] as $s) {
                if (isset($s['words'])) {
                    foreach ($s['words'] as $w) {
                        $start = formatAssTime($w['start'] + $totalDuration);
                        $end = formatAssTime($w['end'] + $totalDuration);
                        $text = trim($w['word']);
                        $allAssDialogue .= "Dialogue: 0,$start,$end,Default,,0,0,0,,$text\n";
                    }
                }
            }
        }

        // d. Calculate segment duration via ffprobe (using pipe)
        $descriptorspec = [
           0 => ["pipe", "r"],
           1 => ["pipe", "w"],
           2 => ["pipe", "w"]
        ];
        $process = proc_open("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 pipe:0", $descriptorspec, $pipes);
        fwrite($pipes[0], $audio_binary);
        fclose($pipes[0]);
        $seg_duration = (float)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $totalDuration += $seg_duration;
        $combinedAudioBinary .= $audio_binary;
    }

    // 3. Save combined final audio (Product, not temp file)
    $final_audio_filename = "voiceover_" . $video_id . "_" . time() . ".mp3";
    $final_audio_path = __DIR__ . "/../public/uploads/audio/" . $final_audio_filename;
    if (!is_dir(dirname($final_audio_path))) mkdir(dirname($final_audio_path), 0775, true);
    file_put_contents($final_audio_path, $combinedAudioBinary);

    // 4. Construct Final ASS -> memory
    $ass_header = "[Script Info]\nScriptType: v4.00+\nPlayResX: 1920\nPlayResY: 1080\n\n";
    $ass_header .= "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
    $ass_header .= "Style: Default,Sans,70,$assColor,&H000000,&H000000,&H000000,1,0,0,0,100,100,0,0,1,4,0,2,10,10,100,1\n\n";
    $ass_header .= "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
    $final_ass_content = $ass_header . $allAssDialogue;

    // 5. Final Rendering Stage (Landscape 16:9)
    $images = json_decode($video['assets_json'], true) ?: [$video['image1'], $video['image2'], $video['image3']];
    $num_images = count($images);
    $img_duration = $totalDuration / $num_images;
    $zoompan_d = round($img_duration * 25);

    $output_filename = "video_" . $video_id . "_" . time() . ".mp4";
    $output_path = __DIR__ . "/../public/uploads/videos/" . $output_filename;
    $relative_video_path = "uploads/videos/" . $output_filename;
    if (!is_dir(dirname($output_path))) mkdir(dirname($output_path), 0775, true);

    $input_images = "";
    $filter_complex = "";
    foreach ($images as $i => $img) {
        $abs_img = __DIR__ . "/../public/" . $img;
        $input_images .= "-loop 1 -t " . $img_duration . " -i " . escapeshellarg($abs_img) . " ";
        $filter_complex .= "[$i:v]scale=1920:-1,crop=1920:1080,zoompan=z='min(zoom+0.001,1.5)':d=$zoompan_d:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=1920x1080[v$i]; ";
    }
    for ($i = 0; $i < $num_images; $i++) $filter_complex .= "[v$i]";
    $filter_complex .= "concat=n=$num_images:v=1:a=0[vcat]; ";

    // Pass subtitles via pipe:3 and specify it in filter
    $filter_complex .= "[vcat]subtitles=/dev/fd/3[v]";

    $ffmpeg_cmd = "ffmpeg -y $input_images -i " . escapeshellarg($final_audio_path) . " " .
        "-filter_complex \"$filter_complex\" " .
        "-map \"[v]\" -map $num_images:a -c:v libx264 -pix_fmt yuv420p -preset fast -c:a aac -b:a 192k -shortest " . escapeshellarg($output_path);

    $descriptorspec = [
       0 => ["pipe", "r"], // stdin
       1 => ["pipe", "w"], // stdout
       2 => ["pipe", "w"], // stderr
       3 => ["pipe", "r"]  // extra pipe for subtitles
    ];
    $process = proc_open($ffmpeg_cmd, $descriptorspec, $pipes);
    if (is_resource($process)) {
        // Write subtitles to pipe 3
        fwrite($pipes[3], $final_ass_content);
        fclose($pipes[3]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $ret = proc_close($process);
        if ($ret !== 0) throw new Exception("FFmpeg failed: $stderr");
    }

    // 6. Update Database
    $stmt = $pdo->prepare("UPDATE videos SET status = 'ready_for_render', voiceover_path = ?, video_path = ? WHERE id = ?");
    $stmt->execute(["uploads/audio/" . $final_audio_filename, $relative_video_path, $video_id]);

} catch (Exception $e) {
    file_put_contents(__DIR__ . '/../storage/process_error.log', "Global Error: " . $e->getMessage() . "\n", FILE_APPEND);
}

function formatAssTime($seconds) {
    $h = floor($seconds / 3600);
    $m = floor(((int)$seconds % 3600) / 60);
    $s = $seconds - ($h * 3600) - ($m * 60);
    return sprintf("%01d:%02d:%05.2f", $h, $m, $s);
}
