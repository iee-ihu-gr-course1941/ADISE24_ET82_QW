<?php
namespace App;

/**
 * Κλάση διαχείρισης σύνδεσης με τη βάση δεδομένων
 * Χρησιμοποιεί το πρότυπο Singleton για να διασφαλίσει μία μοναδική σύνδεση
 */
class DB {
    /** @var DB|null Το μοναδικό στιγμιότυπο της κλάσης */
    private static $instance = null;
    
    /** @var \mysqli Η σύνδεση με τη βάση δεδομένων */
    private $connection;
    
    /**
     * Ιδιωτικός κατασκευαστής - δημιουργεί τη σύνδεση με τη βάση
     * @throws \Exception σε περίπτωση αποτυχίας σύνδεσης
     */
    private function __construct() {
        $this->connection = new \mysqli('localhost', 'root', '', 'qwirkle_game');
        if ($this->connection->connect_error) {
            throw new \Exception("Connection failed: " . $this->connection->connect_error);
        }
        Logger::getInstance()->info("Database connection established successfully");
    }
    
    /**
     * Επιστρέφει το μοναδικό στιγμιότυπο της κλάσης
     * @return DB το στιγμιότυπο της κλάσης
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Επιστρέφει τη σύνδεση με τη βάση δεδομένων
     * @return \mysqli το αντικείμενο σύνδεσης
     */
    public function getConnection() {
        return $this->connection;
    }
} 