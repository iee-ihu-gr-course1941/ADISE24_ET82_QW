<?php
/**
 * Database Connection Handler
 * 
 * Υλοποίηση σύνδεσης με τη βάση δεδομένων χρησιμοποιώντας το
 * Singleton design pattern για διασφάλιση μοναδικής σύνδεσης.
 * 
 * @package Qwirkle
 * @subpackage Database
 * @author Όνομα Επώνυμο
 */
class DB {
    /** @var DB|null Το μοναδικό instance της κλάσης */
    private static $instance = null;
    
    /** @var mysqli Το αντικείμενο σύνδεσης με τη MySQL */
    private $mysqli;
    
    /**
     * Constructor
     * 
     * Αρχικοποιεί τη σύνδεση με τη βάση δεδομένων.
     * Ορίζεται ως private για την υλοποίηση του Singleton pattern.
     */
    private function __construct() {
        $this->mysqli = new mysqli(
            'localhost',
            'root',
            '',
            'qwirkle_game'
        );
        
        if ($this->mysqli->connect_errno) {
            throw new Exception('Αποτυχία σύνδεσης με MySQL: ' . $this->mysqli->connect_error);
        }
        
        $this->mysqli->set_charset('utf8mb4');
    }
    
    /**
     * Επιστρέφει το μοναδικό instance της κλάσης
     * 
     * @return DB Το instance της κλάσης
     */
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Επιστρέφει το αντικείμενο σύνδεσης mysqli
     * 
     * @return mysqli Το αντικείμενο σύνδεσης
     */
    public function getConnection() {
        return $this->mysqli;
    }
}
?> 