<?php
// public/render.php
// This file now just redirects to the dashboard or view page
// as the rendering is handled asynchronously by app/process_video.php.

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$video_id = $_GET['id'] ?? 0;
header("Location: view.php?id=" . $video_id);
exit;
