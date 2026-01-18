<?php
// update_db.php
require_once __DIR__ . '/config/database.php';

try {
    $columns = [
        'script TEXT',
        'description TEXT',
        'tags TEXT',
        'image1 TEXT',
        'image2 TEXT',
        'image3 TEXT',
        'voiceover_path TEXT',
        'video_path TEXT',
        'prompt1 TEXT',
        'prompt2 TEXT',
        'prompt3 TEXT'
    ];

    foreach ($columns as $column) {
        try {
            $pdo->exec("ALTER TABLE videos ADD COLUMN $column");
            echo "Added column: $column\n";
        } catch (PDOException $e) {
            echo "Column $column already exists or error: " . $e->getMessage() . "\n";
        }
    }

    echo "Database update process finished.\n";
} catch (PDOException $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
}
