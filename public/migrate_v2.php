<?php
// /var/www/video-ai/public/migrate_v2.php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo->exec("ALTER TABLE videos ADD COLUMN prompt1 TEXT");
    $pdo->exec("ALTER TABLE videos ADD COLUMN prompt2 TEXT");
    $pdo->exec("ALTER TABLE videos ADD COLUMN prompt3 TEXT");
    echo "<h1>Migration Successful</h1><p>Added prompt columns to videos table.</p>";
} catch (PDOException $e) {
    echo "<h1>Migration Info</h1><p>" . $e->getMessage() . "</p>";
}
unlink(__FILE__); // Self-delete for security
?>
