<?php
require_once 'lib/DB.php';
require_once 'lib/Logger.php';

use App\DB;
use App\Logger;

try {
    $db = DB::getInstance()->getConnection();
    
    // Απενεργοποίηση ελέγχων ξένων κλειδιών
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    
    // Πίνακες για καθαρισμό
    $tables = [
        'players',
        'games',
        'game_tiles',
        'game_moves',
        'game_scores'
    ];
    
    // Καθαρισμός κάθε πίνακα
    foreach ($tables as $table) {
        $db->query("TRUNCATE TABLE $table");
        echo "Καθαρίστηκε ο πίνακας: $table\n";
        Logger::getInstance()->info("Cleared table: $table");
    }
    
    // Επανενεργοποίηση ελέγχων ξένων κλειδιών
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    
    echo "\nΗ βάση δεδομένων καθαρίστηκε επιτυχώς!\n";
    Logger::getInstance()->info("Database reset completed successfully");
    
} catch(Exception $e) {
    echo "Σφάλμα: " . $e->getMessage() . "\n";
    Logger::getInstance()->error("Error resetting database: " . $e->getMessage());
} 