<?php
require_once 'lib/DB.php';
require_once 'lib/Logger.php';

$db = App\DB::getInstance()->getConnection();

// Ενημέρωση κατάστασης παιχνιδιού
$stmt = $db->prepare('UPDATE games SET status = "completed" WHERE id = 1');
$stmt->execute();

// Εύρεση του νικητή
$stmt = $db->prepare('
    SELECT player_id, score 
    FROM game_scores 
    WHERE game_id = 1 
    ORDER BY score DESC 
    LIMIT 1
');
$stmt->execute();
$winner = $stmt->get_result()->fetch_assoc();

echo "Game status updated. Winner is player {$winner['player_id']} with score {$winner['score']}.\n"; 