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
        'voiceover_path TEXT'
    ];

    foreach ($columns as $column) {
        $pdo->exec("ALTER TABLE videos ADD COLUMN $column");
        echo "Added column: $column\n";
    }

    echo "Database updated successfully.\n";
} catch (PDOException $e) {
    // If columns already exist, it will throw an error, which we can ignore or handle
    echo "Info: " . $e->getMessage() . "\n";
}
