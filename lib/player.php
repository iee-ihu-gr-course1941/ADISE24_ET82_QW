<?php
namespace App;

/**
 * Κλάση διαχείρισης παικτών
 * Χειρίζεται την ταυτοποίηση και δημιουργία παικτών στο σύστημα
 */
class Player {
    /** @var \mysqli Η σύνδεση με τη βάση δεδομένων */
    private static $db;
    
    /**
     * Ταυτοποιεί έναν παίκτη με βάση το όνομα χρήστη
     * Αν ο παίκτης δεν υπάρχει, δημιουργείται νέος λογαριασμός
     * 
     * @param string $username το όνομα χρήστη του παίκτη
     * @return array τα στοιχεία του παίκτη (player_id, username)
     */
    public static function authenticate($username) {
        self::$db = DB::getInstance()->getConnection();
        
        // Έλεγχος αν υπάρχει ήδη ο παίκτης
        $stmt = self::$db->prepare('SELECT * FROM players WHERE username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if($row = $result->fetch_assoc()) {
            return [
                'player_id' => $row['id'],
                'username' => $row['username']
            ];
        }
        
        // Αν ο παίκτης δεν υπάρχει, δημιουργία νέου
        $stmt = self::$db->prepare('INSERT INTO players (username) VALUES (?)');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        
        return [
            'player_id' => self::$db->insert_id,
            'username' => $username
        ];
    }
}
?> 