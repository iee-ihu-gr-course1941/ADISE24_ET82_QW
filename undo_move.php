<?php
require_once 'lib/DB.php';
require_once 'lib/Logger.php';

use App\DB;
use App\Logger;

try {
    $db = DB::getInstance()->getConnection();
    
    // Αναίρεση της κίνησης
    $stmt = $db->prepare('
        UPDATE game_tiles 
        SET status = "hand", x = NULL, y = NULL 
        WHERE game_id = ? AND tile = ? AND x = ? AND y = ? AND status = "board"
    ');
    
    $game_id = 1;
    $tile = 'yellow_cross';
    $x = 2;
    $y = -1;
    
    $stmt->bind_param('isii', $game_id, $tile, $x, $y);
    
    if($stmt->execute()) {
        if($stmt->affected_rows > 0) {
            echo "Η κίνηση αναιρέθηκε επιτυχώς\n";
            Logger::getInstance()->info("Move undone successfully: yellow_cross at (2,-1)");
        } else {
            echo "Δεν βρέθηκε η κίνηση για αναίρεση\n";
            Logger::getInstance()->info("No move found to undo");
        }
    } else {
        throw new Exception("Σφάλμα κατά την αναίρεση της κίνησης: " . $stmt->error);
    }
    
} catch(Exception $e) {
    echo "Σφάλμα: " . $e->getMessage() . "\n";
    Logger::getInstance()->error("Error undoing move: " . $e->getMessage());
} 