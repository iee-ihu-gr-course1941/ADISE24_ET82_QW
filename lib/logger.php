<?php
namespace App;

/**
 * Κλάση καταγραφής συμβάντων του παιχνιδιού
 * Χρησιμοποιεί το πρότυπο Singleton για να διασφαλίσει μία μοναδική καταγραφή
 */
class Logger {
    /** @var Logger|null Το μοναδικό στιγμιότυπο της κλάσης */
    private static $instance = null;
    
    /** @var string Η διαδρομή του αρχείου καταγραφής */
    private $logFile;
    
    /**
     * Ιδιωτικός κατασκευαστής - δημιουργεί τον φάκελο και το αρχείο καταγραφής
     */
    private function __construct() {
        $logDir = __DIR__ . '/../logs';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $this->logFile = $logDir . '/game.log';
    }
    
    /**
     * Επιστρέφει το μοναδικό στιγμιότυπο της κλάσης
     * @return Logger το στιγμιότυπο της κλάσης
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Καταγράφει ένα μήνυμα με συγκεκριμένο επίπεδο σημαντικότητας
     * @param string $level το επίπεδο του μηνύματος (INFO, ERROR, DEBUG)
     * @param string $message το μήνυμα προς καταγραφή
     */
    public function log($level, $message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
    
    /**
     * Καταγράφει ένα πληροφοριακό μήνυμα
     * @param string $message το μήνυμα προς καταγραφή
     */
    public function info($message) {
        $this->log('INFO', $message);
    }
    
    /**
     * Καταγράφει ένα μήνυμα σφάλματος
     * @param string $message το μήνυμα προς καταγραφή
     */
    public function error($message) {
        $this->log('ERROR', $message);
    }
    
    /**
     * Καταγράφει ένα μήνυμα αποσφαλμάτωσης
     * @param string $message το μήνυμα προς καταγραφή
     */
    public function debug($message) {
        $this->log('DEBUG', $message);
    }
}
?> 