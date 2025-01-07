<?php
/**
 * Game.php
 * 
 * Κεντρική κλάση διαχείρισης του παιχνιδιού Qwirkle.
 * Υλοποιεί όλη τη λογική του παιχνιδιού, συμπεριλαμβανομένων:
 * - Δημιουργία και αρχικοποίηση παιχνιδιού
 * - Διαχείριση παικτών και σειράς παιξίματος
 * - Έλεγχος εγκυρότητας κινήσεων
 * - Υπολογισμός σκορ
 * - Διαχείριση κατάστασης παιχνιδιού
 * - Πρόταση έγκυρων κινήσεων
 * - Αναγνώριση καταστάσεων deadlock
 */

namespace App;

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Board.php';

class Game {
    /** @var int ID του παιχνιδιού στη βάση δεδομένων */
    private $id;
    
    /** @var string Κατάσταση παιχνιδιού (initialized/active/completed) */
    private $status;
    
    /** @var int ID του πρώτου παίκτη */
    private $player1_id;
    
    /** @var int ID του δεύτερου παίκτη */
    private $player2_id;
    
    /** @var int ID του παίκτη που έχει σειρά */
    private $current_player_id;
    
    /** @var mysqli Σύνδεση με τη βάση δεδομένων */
    private $db;
    
    /** @var Board Αντικείμενο διαχείρισης του ταμπλό */
    private $board;

    /**
     * Επιστρέφει το ID του παιχνιδιού
     * @return int
     */
    public function getId() {
        return $this->id;
    }

    /**
     * Constructor - Φορτώνει ένα υπάρχον παιχνίδι από τη βάση
     * @param int $id ID του παιχνιδιού
     */
    public function __construct($id) {
        $this->id = $id;
        $this->db = DB::getInstance()->getConnection();
        $this->board = new Board($id);
        $this->load();
    }

    /**
     * Φορτώνει την κατάσταση του παιχνιδιού από τη βάση
     */
    private function load() {
        $stmt = $this->db->prepare('
            SELECT * FROM games 
            WHERE id = ?
        ');
        $stmt->bind_param('i', $this->id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if($row = $result->fetch_assoc()) {
            $this->status = $row['status'];
            $this->player1_id = $row['player1_id'];
            $this->player2_id = $row['player2_id'];
            $this->current_player_id = $row['current_player_id'];
            Logger::getInstance()->info("Game loaded - Status: {$this->status}, Current player: {$this->current_player_id}");
        }
    }

    /**
     * Αρχικοποιεί τα πλακίδια ενός νέου παιχνιδιού
     * Δημιουργεί 3 αντίγραφα κάθε συνδυασμού χρώματος/σχήματος (108 πλακίδια συνολικά)
     * @param int $game_id ID του παιχνιδιού
     * @throws \Exception σε περίπτωση σφάλματος στη βάση
     */
    private static function initializeGameTiles($game_id) {
        $logger = Logger::getInstance();
        $logger->info("Initializing game tiles for game: $game_id");
        
        $db = DB::getInstance()->getConnection();
        $colors = ['red', 'orange', 'yellow', 'green', 'blue', 'purple'];
        $shapes = ['circle', 'cross', 'diamond', 'square', 'star', 'clover'];
        
        $stmt = $db->prepare('INSERT INTO game_tiles (game_id, tile, status) VALUES (?, ?, "deck")');
        if (!$stmt) {
            $logger->error("Failed to prepare statement: " . $db->error);
            throw new \Exception("Failed to prepare statement: " . $db->error);
        }
        
        $total_tiles = 0;
        // Κάθε συνδυασμός χρώματος/σχήματος υπάρχει 3 φορές
        foreach($colors as $color) {
            foreach($shapes as $shape) {
                for($i = 0; $i < 3; $i++) {
                    $tile = $color . '_' . $shape;
                    $stmt->bind_param('is', $game_id, $tile);
                    $result = $stmt->execute();
                    if (!$result) {
                        $logger->error("Failed to insert tile $tile: " . $stmt->error);
                        throw new \Exception("Failed to insert tile $tile: " . $stmt->error);
                    }
                    $total_tiles++;
                }
            }
        }
        
        $logger->info("Successfully initialized $total_tiles tiles for game $game_id");
    }

    /**
     * Μοιράζει τα αρχικά 6 πλακίδια σε έναν παίκτη
     * @param int $game_id ID του παιχνιδιού
     * @param int $player_id ID του παίκτη
     * @throws \Exception αν δεν υπάρχουν αρκετά πλακίδια ή σε σφάλμα βάσης
     */
    private static function dealInitialTiles($game_id, $player_id) {
        $logger = Logger::getInstance();
        $logger->info("Dealing initial tiles for game $game_id to player $player_id");
        
        $db = DB::getInstance()->getConnection();
        
        // Πρώτα ελέγχουμε πόσα πλακίδια υπάρχουν στο deck
        $stmt = $db->prepare('
            SELECT COUNT(*) as count 
            FROM game_tiles 
            WHERE game_id = ? AND status = "deck"
        ');
        if (!$stmt) {
            $logger->error("Failed to prepare count statement: " . $db->error);
            throw new \Exception("Failed to prepare count statement: " . $db->error);
        }
        
        $stmt->bind_param('i', $game_id);
        $result = $stmt->execute();
        if (!$result) {
            $logger->error("Failed to count deck tiles: " . $stmt->error);
            throw new \Exception("Failed to count deck tiles: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $deck_count = $row['count'];
        $logger->info("Found $deck_count tiles in deck");
        
        if ($deck_count < 6) {
            $logger->error("Not enough tiles in deck to deal initial hand");
            throw new \Exception("Not enough tiles in deck to deal initial hand");
        }
        
        // Τώρα μοιράζουμε τα πλακίδια
        $stmt = $db->prepare('
            UPDATE game_tiles 
            SET status = "hand", drawn_by = ? 
            WHERE game_id = ? AND status = "deck" 
            ORDER BY RAND() 
            LIMIT 6
        ');
        if (!$stmt) {
            $logger->error("Failed to prepare deal statement: " . $db->error);
            throw new \Exception("Failed to prepare deal statement: " . $db->error);
        }
        
        $stmt->bind_param('ii', $player_id, $game_id);
        $result = $stmt->execute();
        if (!$result) {
            $logger->error("Failed to deal tiles: " . $stmt->error);
            throw new \Exception("Failed to deal tiles: " . $stmt->error);
        }
        
        if ($stmt->affected_rows != 6) {
            $logger->error("Failed to deal 6 tiles, only dealt " . $stmt->affected_rows);
            throw new \Exception("Failed to deal 6 tiles, only dealt " . $stmt->affected_rows);
        }
        
        $logger->info("Successfully dealt 6 tiles to player $player_id");
    }

    /**
     * Δημιουργεί ένα νέο παιχνίδι
     * - Αρχικοποιεί το παιχνίδι στη βάση
     * - Δημιουργεί τα πλακίδια
     * - Μοιράζει τα αρχικά πλακίδια στον πρώτο παίκτη
     * - Αρχικοποιεί το σκορ
     * 
     * @param int $player_id ID του πρώτου παίκτη
     * @return Game το νέο παιχνίδι
     * @throws \Exception σε περίπτωση σφάλματος
     */
    public static function create($player_id) {
        $logger = Logger::getInstance();
        $logger->info("Starting game creation for player: $player_id");
        
        try {
            $db = DB::getInstance()->getConnection();
            $logger->info("Got database connection");
            
            $db->begin_transaction();
            $logger->info("Transaction started");
            
            // Δημιουργία παιχνιδιού
            $stmt = $db->prepare('
                INSERT INTO games (player1_id, current_player_id, status) 
                VALUES (?, ?, "initialized")
            ');
            if (!$stmt) {
                $logger->error("Failed to prepare game insert statement: " . $db->error);
                throw new \Exception("Failed to prepare game insert statement: " . $db->error);
            }
            $logger->info("Prepared game insert statement");
            
            $stmt->bind_param('ii', $player_id, $player_id);
            $logger->info("Bound parameters for game insert");
            
            $result = $stmt->execute();
            if (!$result) {
                $logger->error("Failed to insert game record: " . $stmt->error);
                throw new \Exception("Failed to insert game record: " . $stmt->error);
            }
            
            $game_id = $db->insert_id;
            $logger->info("Game record created with ID: $game_id");
            
            // Αρχικοποίηση πλακιδιών
            self::initializeGameTiles($game_id);
            $logger->info("Game tiles initialized");
            
            // Μοίρασμα αρχικών πλακιδιών
            self::dealInitialTiles($game_id, $player_id);
            $logger->info("Initial tiles dealt");
            
            // Δημιουργία αρχικού σκορ
            $stmt = $db->prepare('
                INSERT INTO game_scores (game_id, player_id, score) 
                VALUES (?, ?, 0)
            ');
            if (!$stmt) {
                $logger->error("Failed to prepare score insert statement: " . $db->error);
                throw new \Exception("Failed to prepare score insert statement: " . $db->error);
            }
            $logger->info("Prepared score insert statement");
            
            $stmt->bind_param('ii', $game_id, $player_id);
            $logger->info("Bound parameters for score insert");
            
            $result = $stmt->execute();
            if (!$result) {
                $logger->error("Failed to insert initial score: " . $stmt->error);
                throw new \Exception("Failed to insert initial score: " . $stmt->error);
            }
            $logger->info("Initial score created");
            
            $db->commit();
            $logger->info("Transaction committed");
            
            $game = new self($game_id);
            $logger->info("Game instance created successfully");
            
            // Επιστροφή του game state
            $state = $game->getGameState($player_id);
            $logger->info("Game state retrieved: " . json_encode($state));
            
            return $game;
            
        } catch (\Exception $e) {
            if (isset($db)) {
                $db->rollback();
                $logger->error("Transaction rolled back");
            }
            $logger->error("Failed to create game: " . $e->getMessage());
            $logger->error("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Προσθέτει τον δεύτερο παίκτη στο παιχνίδι
     * - Ενημερώνει την κατάσταση σε "active"
     * - Μοιράζει τα αρχικά πλακίδια στον δεύτερο παίκτη
     * 
     * @param int $player_id ID του δεύτερου παίκτη
     * @return bool true αν η είσοδος ήταν επιτυχής, false αλλιώς
     */
    public function join($player_id) {
        if($this->status !== 'initialized' || $this->player2_id !== null) {
            return false;
        }
        
        try {
            $this->db->begin_transaction();
            
            // Ενημέρωση παιχνιδιού
            $stmt = $this->db->prepare('
                UPDATE games 
                SET player2_id = ?, status = "active" 
                WHERE id = ?
            ');
            $stmt->bind_param('ii', $player_id, $this->id);
            $stmt->execute();
            
            // Μοίρασμα πλακιδιών στον δεύτερο παίκτη
            self::dealInitialTiles($this->id, $player_id);
            
            $this->db->commit();
            $this->load();  // Επαναφόρτωση κατάστασης
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollback();
            return false;
        }
    }

    /**
     * Εκτελεί μια κίνηση για έναν παίκτη
     * Υποστηρίζει τρεις τύπους κινήσεων:
     * - place: Τοποθέτηση πλακιδίων στο ταμπλό
     * - exchange: Ανταλλαγή πλακιδίων με νέα από το deck
     * - pass: Πέρασμα σειράς
     * 
     * @param int $player_id ID του παίκτη που κάνει την κίνηση
     * @param string $move_type Τύπος κίνησης (place/exchange/pass)
     * @param array $move_data Δεδομένα κίνησης (πλακίδια, συντεταγμένες κλπ)
     * @return array Αποτέλεσμα κίνησης με status (success/error) και προαιρετικό μήνυμα
     */
    public function makeMove($player_id, $move_type, $move_data = []) {
        Logger::getInstance()->info("Game loaded - Status: {$this->status}, Current player: {$this->current_player_id}");
        
        // Έλεγχος αν είναι η σειρά του παίκτη
        if($this->status !== 'active') {
            Logger::getInstance()->info("Game is not active");
            return ['status' => 'error', 'message' => 'Το παιχνίδι δεν είναι ενεργό ή δεν έχουν συνδεθεί και οι δύο παίκτες'];
        }
        
        if($this->current_player_id !== $player_id) {
            Logger::getInstance()->info("Not player's turn. Current: {$this->current_player_id}, Attempting: {$player_id}");
            return ['status' => 'error', 'message' => 'Δεν είναι η σειρά σας να παίξετε'];
        }

        try {
            $this->db->begin_transaction();

            // Καταγραφή της κίνησης
            $stmt = $this->db->prepare('INSERT INTO game_moves (game_id, player_id, move_type) VALUES (?, ?, ?)');
            $stmt->bind_param('iis', $this->id, $player_id, $move_type);
            if(!$stmt->execute()) {
                $this->db->rollback();
                return ['status' => 'error', 'message' => 'Σφάλμα κατά την καταγραφή της κίνησης'];
            }

            switch($move_type) {
                case 'place':
                    if(!isset($move_data['tiles']) || empty($move_data['tiles'])) {
                        $this->db->rollback();
                        return ['status' => 'error', 'message' => 'Δεν έχετε επιλέξει πλακίδια για τοποθέτηση'];
                    }

                    // Έλεγχος αν ο παίκτης έχει όλα τα πλακίδια
                    $player_tiles = $this->getPlayerTiles($player_id);
                    foreach($move_data['tiles'] as $tile_data) {
                        if(!isset($tile_data['x']) || !isset($tile_data['y']) || !isset($tile_data['tile'])) {
                            $this->db->rollback();
                            return ['status' => 'error', 'message' => 'Λείπουν πληροφορίες για την τοποθέτηση του πλακιδίου (x, y, ή τύπος)'];
                        }

                        if(!in_array($tile_data['tile'], $player_tiles)) {
                            $this->db->rollback();
                            return ['status' => 'error', 'message' => "Δεν έχετε το πλακίδιο {$tile_data['tile']} στο χέρι σας"];
                        }
                    }

                    try {
                        // Έλεγχος εγκυρότητας όλων των πλακιδίων μαζί
                        if(!$this->board->isValidMoveSet($move_data['tiles'])) {
                            $this->db->rollback();
                            return ['status' => 'error', 'message' => "Μη έγκυρη τοποθέτηση πλακιδίων"];
                        }
                    } catch (\Exception $e) {
                        $this->db->rollback();
                        return ['status' => 'error', 'message' => $e->getMessage()];
                    }

                    // Τοποθέτηση των πλακιδίων
                    foreach($move_data['tiles'] as $tile_data) {
                        if(!$this->board->placeTile($tile_data['x'], $tile_data['y'], $tile_data['tile'], $player_id)) {
                            $this->db->rollback();
                            return ['status' => 'error', 'message' => "Αποτυχία τοποθέτησης πλακιδίου {$tile_data['tile']} στη θέση ({$tile_data['x']}, {$tile_data['y']})"];
                        }
                    }

                    // Υπολογισμός πόντων
                    $score = $this->calculateScoreForMove($move_data['tiles']);
                    
                    // Ενημέρωση σκορ
                    $stmt = $this->db->prepare('
                        INSERT INTO game_scores (game_id, player_id, score) 
                        VALUES (?, ?, ?) 
                        ON DUPLICATE KEY UPDATE score = score + ?
                    ');
                    $stmt->bind_param('iiii', $this->id, $player_id, $score['total_points'], $score['total_points']);
                    if(!$stmt->execute()) {
                        $this->db->rollback();
                        return ['status' => 'error', 'message' => 'Σφάλμα κατά την ενημέρωση του σκορ'];
                    }

                    // Τράβηγμα νέων πλακιδίων
                    $stmt = $this->db->prepare('
                        UPDATE game_tiles 
                        SET status = "hand", drawn_by = ? 
                        WHERE game_id = ? AND status = "deck" 
                        ORDER BY RAND() 
                        LIMIT ?
                    ');
                    $num_tiles = count($move_data['tiles']);
                    $stmt->bind_param('iii', $player_id, $this->id, $num_tiles);
                    if(!$stmt->execute()) {
                        $this->db->rollback();
                        return ['status' => 'error', 'message' => 'Σφάλμα κατά το τράβηγμα νέων πλακιδίων'];
                    }
                    break;

                case 'exchange':
                    if(!isset($move_data['tiles']) || empty($move_data['tiles'])) {
                        $this->db->rollback();
                        return ['status' => 'error', 'message' => 'Δεν έχετε επιλέξει πλακίδια για ανταλλαγή'];
                    }

                    // Έλεγχος αν ο παίκτης έχει τα πλακίδια
                    $player_tiles = $this->getPlayerTiles($player_id);
                    foreach($move_data['tiles'] as $tile) {
                        if(!in_array($tile, $player_tiles)) {
                            $this->db->rollback();
                            return ['status' => 'error', 'message' => "Δεν έχετε το πλακίδιο $tile στο χέρι σας"];
                        }
                    }

                    // Επιστροφή των πλακιδίων στο deck
                    $stmt = $this->db->prepare('
                        UPDATE game_tiles 
                        SET status = "deck", drawn_by = NULL 
                        WHERE game_id = ? AND drawn_by = ? AND status = "hand" AND tile IN (' . str_repeat('?,', count($move_data['tiles']) - 1) . '?)
                    ');
                    $params = array_merge([$this->id, $player_id], $move_data['tiles']);
                    $types = 'ii' . str_repeat('s', count($move_data['tiles']));
                    $stmt->bind_param($types, ...$params);
                    if(!$stmt->execute()) {
                        $this->db->rollback();
                        return ['status' => 'error', 'message' => 'Σφάλμα κατά την επιστροφή των πλακιδίων'];
                    }

                    // Τράβηγμα νέων πλακιδίων
                    $stmt = $this->db->prepare('
                        UPDATE game_tiles 
                        SET status = "hand", drawn_by = ? 
                        WHERE game_id = ? AND status = "deck" 
                        ORDER BY RAND() 
                        LIMIT ?
                    ');
                    $num_tiles = count($move_data['tiles']);
                    $stmt->bind_param('iii', $player_id, $this->id, $num_tiles);
                    if(!$stmt->execute()) {
                        $this->db->rollback();
                        return ['status' => 'error', 'message' => 'Σφάλμα κατά το τράβηγμα νέων πλακιδίων'];
                    }
                    break;

                case 'pass':
                    Logger::getInstance()->info("Player {$player_id} passes their turn");
                    break;

                default:
                    $this->db->rollback();
                    return ['status' => 'error', 'message' => 'Μη έγκυρος τύπος κίνησης'];
            }

            // Αλλαγή σειράς
            $this->nextTurn();
            
            $this->db->commit();
            return ['status' => 'success'];
            
        } catch (\Exception $e) {
            $this->db->rollback();
            return ['status' => 'error', 'message' => 'Σφάλμα κατά την εκτέλεση της κίνησης: ' . $e->getMessage()];
        }
    }

    /**
     * Ελέγχει αν υπάρχουν διαθέσιμα πλακίδια στο deck
     * Χρησιμοποιείται για τον έλεγχο τερματισμού του παιχνιδιού
     * και για τον έλεγχο δυνατότητας ανταλλαγής πλακιδίων
     * 
     * @return bool true αν υπάρχουν διαθέσιμα πλακίδια, false αλλιώς
     */
    private function hasRemainingTiles() {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as remaining 
            FROM game_tiles 
            WHERE game_id = ? AND status = "deck"
        ');
        $stmt->bind_param('i', $this->id);
        $stmt->execute();
        $result = $stmt->get_result();
        $remaining = $result->fetch_assoc()['remaining'];
        Logger::getInstance()->info("Checking remaining tiles: $remaining");
        return $remaining > 0;
    }

    /**
     * Επιστρέφει τον αριθμό των πλακιδίων που έχει ένας παίκτης στο χέρι του
     * @param int $player_id ID του παίκτη
     * @return int Αριθμός πλακιδίων
     */
    private function getPlayerTilesCount($player_id) {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as tiles 
            FROM game_tiles 
            WHERE game_id = ? AND drawn_by = ? AND status = "hand"
        ');
        $stmt->bind_param('ii', $this->id, $player_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc()['tiles'];
    }

    /**
     * Ελέγχει αν ένας παίκτης έχει διαθέσιμες έγκυρες κινήσεις
     * Μια κίνηση θεωρείται έγκυρη αν:
     * - Το ταμπλό είναι άδειο (πρώτη κίνηση)
     * - Υπάρχει τουλάχιστον ένα πλακίδιο που μπορεί να τοποθετηθεί σε κάποια θέση
     * 
     * @param int $player_id ID του παίκτη
     * @return bool true αν υπάρχουν έγκυρες κινήσεις, false αλλιώς
     */
    private function hasValidMoves($player_id) {
        Logger::getInstance()->info("Checking valid moves for player $player_id");
        
        // Παίρνουμε τα πλακίδια του παίκτη
        $stmt = $this->db->prepare('
            SELECT tile 
            FROM game_tiles 
            WHERE game_id = ? AND drawn_by = ? AND status = "hand"
        ');
        $stmt->bind_param('ii', $this->id, $player_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $tiles = [];
        while ($row = $result->fetch_assoc()) {
            $tiles[] = $row['tile'];
        }

        Logger::getInstance()->info("Player tiles: " . implode(', ', $tiles));

        if (empty($tiles)) {
            Logger::getInstance()->info("Player has no tiles");
            return false;
        }

        // Αν το ταμπλό είναι άδειο, υπάρχει πάντα έγκυρη κίνηση
        if ($this->board->isEmpty()) {
            Logger::getInstance()->info("Board is empty - valid moves available");
            return true;
        }

        // Έλεγχος για πιθανές θέσεις
        $positions = $this->board->getPossiblePositions();
        Logger::getInstance()->info("Checking " . count($positions) . " possible positions");
        
        foreach ($positions as $pos) {
            foreach ($tiles as $tile) {
                if ($this->board->isValidMove($pos['x'], $pos['y'], $tile)) {
                    Logger::getInstance()->info("Found valid move: $tile at ({$pos['x']}, {$pos['y']})");
                    return true;
                }
            }
        }

        Logger::getInstance()->info("No valid moves available");
        return false;
    }

    /**
     * Ελέγχει αν το παιχνίδι βρίσκεται σε κατάσταση deadlock
     * Deadlock συμβαίνει όταν:
     * - Δεν υπάρχουν πλακίδια στο deck και ένας παίκτης δεν έχει πλακίδια
     * - Και οι δύο παίκτες έκαναν pass διαδοχικά
     * 
     * @return bool true αν υπάρχει deadlock, false αλλιώς
     */
    public function isDeadlocked() {
        Logger::getInstance()->info("=== Starting deadlock check ===");
        
        // Έλεγχος για τέλος παιχνιδιού λόγω έλλειψης πλακιδίων
        if (!$this->hasRemainingTiles()) {
            if ($this->getPlayerTilesCount($this->player1_id) == 0 || 
                $this->getPlayerTilesCount($this->player2_id) == 0) {
                Logger::getInstance()->info("Game over - no tiles remaining and at least one player has no tiles");
                return true;
            }
        }

        // Έλεγχος για συνεχόμενα passes
        $stmt = $this->db->prepare('
            SELECT move_type 
            FROM game_moves 
            WHERE game_id = ? 
            ORDER BY id DESC 
            LIMIT 2
        ');
        $stmt->bind_param('i', $this->id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $consecutive_passes = 0;
        while ($row = $result->fetch_assoc()) {
            if ($row['move_type'] === 'pass') {
                $consecutive_passes++;
            } else {
                break;
            }
        }

        Logger::getInstance()->info("Pass count: $consecutive_passes");
        
        // Αν και οι δύο παίκτες έκαναν pass, το παιχνίδι είναι σε deadlock
        if ($consecutive_passes >= 2) {
            Logger::getInstance()->info("Both players passed - game is deadlocked");
            return true;
        }

        return false;
    }

    /**
     * Ελέγχει αν το παιχνίδι έχει τελειώσει
     * Προσθέτει 6 bonus πόντους στον τελευταίο παίκτη αν το παιχνίδι τελείωσε με deadlock
     * 
     * @return bool true αν το παιχνίδι έχει τελειώσει, false αλλιώς
     */
    public function isGameOver() {
        Logger::getInstance()->info("Checking if game {$this->id} is over");
        
        if ($this->isDeadlocked()) {
            // Προσθήκη 6 πόντων bonus στον τελευταίο παίκτη που έπαιξε
            $stmt = $this->db->prepare('
                SELECT player_id 
                FROM game_moves 
                WHERE game_id = ? AND move_type != "pass" 
                ORDER BY id DESC 
                LIMIT 1
            ');
            $stmt->bind_param('i', $this->id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $this->updateScore($row['player_id'], 6); // Bonus πόντοι
            }
            
            return true;
        }
        
        return false;
    }

    /**
     * Επιστρέφει τον νικητή του παιχνιδιού
     * @return array|null Πίνακας με το ID του νικητή και το σκορ του, ή null αν το παιχνίδι δεν έχει τελειώσει
     */
    public function getWinner() {
        // Αν το παιχνίδι δεν έχει ολοκληρωθεί, δεν υπάρχει νικητής
        if ($this->status !== 'completed') {
            return null;
        }
        
        $stmt = $this->db->prepare('
            SELECT player_id, score 
            FROM game_scores 
            WHERE game_id = ? 
            ORDER BY score DESC 
            LIMIT 1
        ');
        $stmt->bind_param('i', $this->id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return [
                'player_id' => $row['player_id'],
                'score' => $row['score'] ?? 0  // Default σε 0 αν δεν υπάρχει σκορ
            ];
        }
        
        // Αν δεν υπάρχει σκορ, επέστρεψε τον πρώτο παίκτη με σκορ 0
        return [
            'player_id' => $this->player1_id,
            'score' => 0
        ];
    }

    /**
     * Υπολογίζει τους πόντους για ένα πλακίδιο σε συγκεκριμένη θέση
     * - 1 πόντος για κάθε πλακίδιο σε γραμμή (συμπεριλαμβανομένου του νέου)
     * - 6 bonus πόντοι για κάθε Qwirkle (γραμμή 6 πλακιδιών)
     * 
     * @param int $x Συντεταγμένη Χ
     * @param int $y Συντεταγμένη Υ
     * @return int Συνολικοί πόντοι
     */
    private function calculateScore($x, $y) {
        $score = 0;  // Ξεκινάμε από 0
        $qwirkle_bonus = 6;
        
        // Υπολογισμός οριζόντιας γραμμής
        $horizontal = $this->board->getLine($x, $y, 'horizontal');
        $h_length = count($horizontal) + 1;  // +1 για το νέο πλακίδι
        if ($h_length > 1) {
            $score += $h_length;  // Πόντοι για ΟΛΑ τα πλακίδια στη γραμμή
            if ($h_length == 6) {  // Qwirkle!
                $score += $qwirkle_bonus;
            }
        }
        
        // Υπολογισμός κάθετης γραμμής
        $vertical = $this->board->getLine($x, $y, 'vertical');
        $v_length = count($vertical) + 1;  // +1 για το νέο πλακίδι
        if ($v_length > 1) {
            $score += $v_length;  // Πόντοι για ΟΛΑ τα πλακίδια στη γραμμή
            if ($v_length == 6) {  // Qwirkle!
                $score += $qwirkle_bonus;
            }
        }
        
        // Αν το πλακίδιο είναι μόνο του (δεν συνδέεται με άλλα)
        if ($h_length == 1 && $v_length == 1) {
            $score = 1;
        }
        
        return $score;
    }

    /**
     * Ενημερώνει το σκορ ενός παίκτη
     * @param int $player_id ID του παίκτη
     * @param int $points Πόντοι που θα προστεθούν
     */
    private function updateScore($player_id, $points) {
        $stmt = $this->db->prepare('
            INSERT INTO game_scores (game_id, player_id, score) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE score = score + ?
        ');
        $stmt->bind_param('iiii', $this->id, $player_id, $points, $points);
        $stmt->execute();
    }

    /**
     * Επαναφορτώνει την κατάσταση του παιχνιδιού από τη βάση
     */
    public function refresh() {
        $this->load();
    }

    /**
     * Επιστρέφει τα πλακίδια που έχει ένας παίκτης στο χέρι του
     * @param int $player_id ID του παίκτη
     * @return array Πίνακας με τα πλακίδια
     */
    public function getPlayerTiles($player_id) {
        $stmt = $this->db->prepare('
            SELECT tile 
            FROM game_tiles 
            WHERE game_id = ? AND drawn_by = ? AND status = "hand"
        ');
        $stmt->bind_param('ii', $this->id, $player_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tiles = [];
        while ($row = $result->fetch_assoc()) {
            $tiles[] = $row['tile'];
        }
        return $tiles;
    }

    /**
     * Επιστρέφει το αντικείμενο του ταμπλό
     * @return Board
     */
    public function getBoard() {
        return $this->board;
    }

    /**
     * Επιστρέφει το σκορ ενός παίκτη
     * @param int $player_id ID του παίκτη
     * @return int Το τρέχον σκορ του παίκτη
     */
    public function getScore($player_id) {
        $stmt = $this->db->prepare('
            SELECT score 
            FROM game_scores 
            WHERE game_id = ? AND player_id = ?
        ');
        $stmt->bind_param('ii', $this->id, $player_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row ? $row['score'] : 0;
    }

    /**
     * Επιστρέφει την τρέχουσα κατάσταση του παιχνιδιού για έναν παίκτη
     * Περιλαμβάνει:
     * - Τα πλακίδια του παίκτη
     * - Την κατάσταση του ταμπλό
     * - Τα σκορ
     * - Τον τρέχοντα παίκτη
     * - Διαθέσιμες κινήσεις
     * 
     * @param int $player_id ID του παίκτη
     * @return array Η κατάσταση του παιχνιδιού
     */
    public function getGameState($player_id) {
        // Get player's tiles
        $stmt = $this->db->prepare('
            SELECT tile 
            FROM game_tiles 
            WHERE game_id = ? AND drawn_by = ? AND status = "hand"
        ');
        $stmt->bind_param('ii', $this->id, $player_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $hand = [];
        while ($row = $result->fetch_assoc()) {
            $hand[] = $row['tile'];
        }

        // Get board state
        $stmt = $this->db->prepare('
            SELECT x, y, tile 
            FROM game_tiles 
            WHERE game_id = ? AND status = "board"
        ');
        $stmt->bind_param('i', $this->id);
        $stmt->execute();
        $result = $stmt->get_result();
        $board = [];
        while ($row = $result->fetch_assoc()) {
            $board[] = [
                'x' => $row['x'],
                'y' => $row['y'],
                'tile' => $row['tile']
            ];
        }

        // Get scores
        $stmt = $this->db->prepare('
            SELECT player_id, score 
            FROM game_scores 
            WHERE game_id = ?
        ');
        $stmt->bind_param('i', $this->id);
        $stmt->execute();
        $result = $stmt->get_result();
        $scores = [];
        while ($row = $result->fetch_assoc()) {
            $scores[$row['player_id']] = $row['score'];
        }

        // Get remaining tiles count
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count 
            FROM game_tiles 
            WHERE game_id = ? AND status = "deck"
        ');
        $stmt->bind_param('i', $this->id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $remaining_tiles = $row['count'];

        Logger::getInstance()->debug("Remaining deck tiles: " . json_encode($this->getRemainingDeckTiles()));
        
        // Βασική κατάσταση παιχνιδιού
        $state = [
            'game_id' => $this->id,
            'status' => $this->status,
            'current_player' => $this->current_player_id,
            'player1' => $this->player1_id,
            'player2' => $this->player2_id,
            'your_hand' => $hand,
            'board' => $board,
            'scores' => $scores,
            'remaining_tiles' => $remaining_tiles,
            'is_your_turn' => $this->current_player_id === $player_id
        ];

        // Προθήκη πληροφοριών νικητή αν το παιχνίδι έχει ολοκληρωθεί
        if ($this->status === 'completed') {
            $winner = $this->getWinner();
            if ($winner) {
                $state['winner'] = $winner;
            }
        }

        // Προσπάθεια εύρεσης διαθέσιμων κινήσεων
        try {
            $state['available_moves'] = $this->findAvailableMoves($player_id);
        } catch (\Exception $e) {
            Logger::getInstance()->error("Error finding available moves: " . $e->getMessage());
            $state['available_moves'] = [
                'has_moves' => false,
                'moves' => ['pass'],
                'type' => 'pass',
                'error' => $e->getMessage()
            ];
        }

        return $state;
    }

    private function getRemainingDeckTiles() {
        $stmt = $this->db->prepare('
            SELECT tile 
            FROM game_tiles 
            WHERE game_id = ? AND status = "deck"
            ORDER BY tile
        ');
        $stmt->bind_param('i', $this->id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tiles = [];
        while($row = $result->fetch_assoc()) {
            $tiles[] = $row['tile'];
        }
        return $tiles;
    }

    private function nextTurn() {
        $next_player = ($this->current_player_id == $this->player1_id) ? $this->player2_id : $this->player1_id;
        $stmt = $this->db->prepare('UPDATE games SET current_player_id = ? WHERE id = ?');
        $stmt->bind_param('ii', $next_player, $this->id);
        if (!$stmt->execute()) {
            throw new \Exception('Failed to update current player');
        }
        $this->current_player_id = $next_player;
    }

    private function getAdjacentTiles($x, $y) {
        $adjacent_tiles = [];
        $directions = [[-1,0], [1,0], [0,-1], [0,1]];
        
        foreach($directions as $dir) {
            $check_x = $x + $dir[0];
            $check_y = $y + $dir[1];
            
            if(isset($this->board[$check_y][$check_x])) {
                $adjacent_tiles[] = $this->board[$check_y][$check_x];
            }
        }
        
        return $adjacent_tiles;
    }

    private function findAvailableMoves($player_id) {
        $logger = Logger::getInstance();
        $logger->info("Finding available moves for player $player_id");
        
        // Get player's tiles
        $player_tiles = $this->getPlayerTiles($player_id);
        if (empty($player_tiles)) {
            return [
                'has_moves' => false,
                'moves' => ['pass'],
                'type' => 'pass'
            ];
        }

        // Get possible positions
        $possible_positions = $this->board->getPossiblePositions();
        $valid_moves = [];
        $seen_moves = []; // Για αποφυγή διπλότυπων κινήσεων
        
        // First check single tile moves
        foreach ($player_tiles as $tile) {
            foreach ($possible_positions as $pos) {
                // Δημιουργία μοναδικού κλειδιού για τη κίνηση
                $move_key = $pos['x'] . ',' . $pos['y'] . ',' . $tile;
                
                if (isset($seen_moves[$move_key])) continue;
                $seen_moves[$move_key] = true;

                if ($this->board->isValidPlacement($pos['x'], $pos['y'], $tile)) {
                    $score = $this->calculateScoreForMove([
                        ['x' => $pos['x'], 'y' => $pos['y'], 'tile' => $tile]
                    ]);
                    
                    $valid_moves[] = [
                        'move_type' => 'place',
                        'move_data' => [
                            'tiles' => [[
                                'x' => $pos['x'],
                                'y' => $pos['y'],
                                'tile' => $tile
                            ]]
                        ],
                        'expected_points' => $score['total_points'],
                        'affected_lines' => $score['affected_lines']
                    ];
                }
            }
        }

        // Then check multiple tile combinations
        $combinations = $this->findTileCombinations($player_tiles);
        foreach ($combinations as $combination) {
            $positions = $this->findPositionsForTileCombination($combination);
            foreach ($positions as $tiles_placement) {
                // Check if this placement is unique
                $move_key = '';
                foreach ($tiles_placement as $tile_data) {
                    $move_key .= $tile_data['x'] . ',' . $tile_data['y'] . ',' . $tile_data['tile'] . ';';
                }
                
                if (isset($seen_moves[$move_key])) continue;
                $seen_moves[$move_key] = true;

                $score = $this->calculateScoreForMove($tiles_placement);
                
                $valid_moves[] = [
                    'move_type' => 'place',
                    'move_data' => [
                        'tiles' => $tiles_placement
                    ],
                    'expected_points' => $score['total_points'],
                    'affected_lines' => $score['affected_lines']
                ];
            }
        }

        // Sort moves by expected points (descending)
        usort($valid_moves, function($a, $b) {
            return $b['expected_points'] - $a['expected_points'];
        });

        if (!empty($valid_moves)) {
            return [
                'has_moves' => true,
                'moves' => $valid_moves,
                'type' => 'place'
            ];
        }

        // If no valid moves found, player can exchange tiles or pass
        return [
            'has_moves' => false,
            'moves' => ['exchange', 'pass'],
            'type' => 'other'
        ];
    }

    private function calculateScoreForMove($tiles_placement) {
        $total_points = 0;
        $affected_lines = [
            'horizontal' => [],
            'vertical' => []
        ];
        
        // Προσωρινή τοποθέτηση για υπολογισμό πόντων
        $temp_board = $this->board->getBoard();
        foreach ($tiles_placement as $tile_data) {
            if (!isset($temp_board[$tile_data['y']])) {
                $temp_board[$tile_data['y']] = [];
            }
            $temp_board[$tile_data['y']][$tile_data['x']] = $tile_data['tile'];
            
            // Get affected lines for this tile
            $horizontal = $this->board->getLine($tile_data['x'], $tile_data['y'], 'horizontal');
            $vertical = $this->board->getLine($tile_data['x'], $tile_data['y'], 'vertical');
            
            // Calculate points for horizontal line
            if (!empty($horizontal)) {
                $h_length = count($horizontal) + 1;
                $total_points += $h_length;
                if ($h_length === 6) { // Qwirkle!
                    $total_points += 6;
                }
                $affected_lines['horizontal'] = array_merge(
                    $affected_lines['horizontal'],
                    $horizontal
                );
            }
            
            // Calculate points for vertical line
            if (!empty($vertical)) {
                $v_length = count($vertical) + 1;
                $total_points += $v_length;
                if ($v_length === 6) { // Qwirkle!
                    $total_points += 6;
                }
                $affected_lines['vertical'] = array_merge(
                    $affected_lines['vertical'],
                    $vertical
                );
            }
            
            // If no lines affected, score 1 point for isolated tile
            if (empty($horizontal) && empty($vertical)) {
                $total_points += 1;
            }
        }
        
        // Remove duplicates from affected lines
        $affected_lines['horizontal'] = array_unique($affected_lines['horizontal']);
        $affected_lines['vertical'] = array_unique($affected_lines['vertical']);
        
        return [
            'total_points' => $total_points,
            'affected_lines' => $affected_lines
        ];
    }

    private function checkGameOver() {
        // Έλεγχος αν κάποιος παίκτης έχει χρησιμοποιήσει όλα τα πλακίδια του
        $player1_tiles = $this->getPlayerTiles($this->player1_id);
        $player2_tiles = $this->getPlayerTiles($this->player2_id);
        
        if ((empty($player1_tiles) || empty($player2_tiles)) && !$this->hasRemainingTiles()) {
            // Ο παίκτης που χρησιμοποίησε όλα τα πλακίδια παίρνει 6 bonus πόντους
            if (empty($player1_tiles)) {
                $this->addScore($this->player1_id, 6);
            } else {
                $this->addScore($this->player2_id, 6);
            }
            return true;
        }
        
        // Έλεγχος για πραγματικό deadlock
        if (!$this->hasRemainingTiles()) {
            // Έλεγχος αν υπάρχουν έγκυρες κινήσεις για κάθε παίκτη
            $player1_moves = $this->findAvailableMoves($this->player1_id);
            $player2_moves = $this->findAvailableMoves($this->player2_id);
            
            if (!$player1_moves['has_moves'] && !$player2_moves['has_moves']) {
                return true;
            }
        }
        
        return false;
    }

    private function addScore($player_id, $points) {
        $stmt = $this->db->prepare('
            UPDATE game_scores 
            SET score = score + ?, 
                last_move_score = ?,
                moves_count = moves_count + 1
            WHERE game_id = ? AND player_id = ?
        ');
        $stmt->bind_param('iiii', $points, $points, $this->id, $player_id);
        if (!$stmt->execute()) {
            throw new \Exception('Failed to update score');
        }
    }

    private function findTileCombinations($tiles) {
        $combinations = [];
        $n = count($tiles);
        
        // Για κάθε μέγεθος συνδυασμού (2 έως 6 πλακίδια)
        for ($len = 2; $len <= min(6, $n); $len++) {
            // Βρίσκουμε όλους τους συνδυασμούς μεγέθους $len
            for ($i = 0; $i < $n; $i++) {
                $combination = [$tiles[$i]];
                $this->findTileCombinationsRecursive($tiles, $len - 1, $i + 1, $combination, $combinations);
            }
        }
        
        return $combinations;
    }
    
    private function findTileCombinationsRecursive($tiles, $len, $start, $current, &$combinations) {
        if ($len == 0) {
            // Ελέγχουμε αν τα πλακίδια έχουν κοινό χαρακτηριστικό
            $first_tile = $current[0];
            list($first_color, $first_shape) = explode('_', $first_tile);
            
            $same_color = true;
            $same_shape = true;
            
            // Έλεγχος για διπλότυπα
            $duplicates = array_count_values($current);
            if (max($duplicates) > 1) {
                return; // Αγνοούμε συνδυασμούς με διπλότυπα
            }
            
            foreach ($current as $tile) {
                list($color, $shape) = explode('_', $tile);
                if ($color !== $first_color) $same_color = false;
                if ($shape !== $first_shape) $same_shape = false;
                if (!$same_color && !$same_shape) break;
            }
            
            // Προσθέτουμε μόνο τους συνδυασμούς που έχουν κοινό χαρακτηριστικό
            if ($same_color || $same_shape) {
                $combinations[] = $current;
            }
            return;
        }
        
        for ($i = $start; $i < count($tiles); $i++) {
            $new_combination = $current;
            $new_combination[] = $tiles[$i];
            $this->findTileCombinationsRecursive($tiles, $len - 1, $i + 1, $new_combination, $combinations);
        }
    }
    
    private function findPositionsForTileCombination($combination) {
        $positions = [];
        $board_positions = $this->board->getPossiblePositions();
        
        foreach ($board_positions as $pos) {
            // Δοκιμάζουμε οριζόντια τοποθέτηση (προς τα δεξιά)
            $horizontal_tiles = [];
            for ($i = 0; $i < count($combination); $i++) {
                $tile_data = [
                    'x' => $pos['x'] + $i,
                    'y' => $pos['y'],
                    'tile' => $combination[$i]
                ];
                $horizontal_tiles[] = $tile_data;
            }
            
            try {
                if ($this->board->isValidMoveSet($horizontal_tiles)) {
                    $positions[] = $horizontal_tiles;
                }
            } catch (\Exception $e) {
                // Αν η κίνηση δεν είναι έγκυρη, συνεχίζουμε
            }
            
            // Δοκιμάζουμε οριζόντια τοποθέτηση (προς τα αριστερά)
            $horizontal_tiles = [];
            for ($i = 0; $i < count($combination); $i++) {
                $tile_data = [
                    'x' => $pos['x'] - $i,
                    'y' => $pos['y'],
                    'tile' => $combination[$i]
                ];
                $horizontal_tiles[] = $tile_data;
            }
            
            try {
                if ($this->board->isValidMoveSet($horizontal_tiles)) {
                    $positions[] = $horizontal_tiles;
                }
            } catch (\Exception $e) {
                // Αν η κίνηση δεν είναι έγκυρη, συνεχίζουμε
            }
            
            // Δοκιμάζουμε κάθετη τοποθέτηση (προς τα κάτω)
            $vertical_tiles = [];
            for ($i = 0; $i < count($combination); $i++) {
                $tile_data = [
                    'x' => $pos['x'],
                    'y' => $pos['y'] + $i,
                    'tile' => $combination[$i]
                ];
                $vertical_tiles[] = $tile_data;
            }
            
            try {
                if ($this->board->isValidMoveSet($vertical_tiles)) {
                    $positions[] = $vertical_tiles;
                }
            } catch (\Exception $e) {
                // Αν η κίνηση δεν είναι έγκυρη, συνεχίζουμε
            }
            
            // Δοκιμάζουμε κάθετη τοποθέτηση (προς τα πάνω)
            $vertical_tiles = [];
            for ($i = 0; $i < count($combination); $i++) {
                $tile_data = [
                    'x' => $pos['x'],
                    'y' => $pos['y'] - $i,
                    'tile' => $combination[$i]
                ];
                $vertical_tiles[] = $tile_data;
            }
            
            try {
                if ($this->board->isValidMoveSet($vertical_tiles)) {
                    $positions[] = $vertical_tiles;
                }
            } catch (\Exception $e) {
                // Αν η κίνηση δεν είναι έγκυρη, συνεχίζουμε
            }
        }
        
        return $positions;
    }
} 