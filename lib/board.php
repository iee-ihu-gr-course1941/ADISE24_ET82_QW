<?php
/**
 * Board.php
 * 
 * Κλάση διαχείρισης του ταμπλό του παιχνιδιού Qwirkle.
 * Υλοποιεί τη λογική για:
 * - Φόρτωση και αποθήκευση της κατάστασης του ταμπλό
 * - Τοποθέτηση και έλεγχο εγκυρότητας πλακιδίων
 * - Εύρεση πιθανών θέσεων για νέα πλακίδια
 * - Υπολογισμό γραμμών και συνδυασμών
 */
namespace App;

class Board {
    /** @var int ID του παιχνιδιού στο οποίο ανήκει το ταμπλό */
    private $id;
    
    /** @var array Δισδιάστατος πίνακας που αναπαριστά το ταμπλό */
    private $board;
    
    /** @var mysqli Σύνδεση με τη βάση δεδομένων */
    private $db;
    
    /**
     * Constructor - Δημιουργεί νέο αντικείμενο ταμπλό για συγκεκριμένο παιχνίδι
     * @param int $game_id ID του παιχνιδιού
     */
    public function __construct($game_id) {
        $this->id = $game_id;
        $this->db = DB::getInstance()->getConnection();
        Logger::getInstance()->info("Loading board for game $game_id");
        $this->load();
        Logger::getInstance()->info("Board loaded: " . json_encode($this->board));
    }
    
    /**
     * Φορτώνει την κατάσταση του ταμπλό από τη βάση δεδομένων
     * Δημιουργεί έναν δισδιάστατο πίνακα με τα πλακίδια στις θέσεις τους
     */
    private function load() {
        $this->board = [];
        
        $stmt = $this->db->prepare('
            SELECT x, y, tile 
            FROM game_tiles 
            WHERE game_id = ? AND status = "board"
            ORDER BY y, x
        ');
        $stmt->bind_param('i', $this->id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while($row = $result->fetch_assoc()) {
            if(!isset($this->board[$row['y']])) {
                $this->board[$row['y']] = [];
            }
            $this->board[$row['y']][$row['x']] = $row['tile'];
        }
    }
    
    /**
     * Τοποθετεί ένα πλακίδιο στο ταμπλό
     * - Ελέγχει αν η θέση είναι διαθέσιμη
     * - Ενημερώνει τη βάση δεδομένων
     * - Ανανεώνει την τοπική κατάσταση του ταμπλό
     * 
     * @param int $x Συντεταγμένη Χ
     * @param int $y Συντεταγμένη Υ
     * @param string $tile Το πλακίδιο προς τοποθέτηση (μορφή: χρώμα_σχήμα)
     * @param int $player_id ID του παίκτη που κάνει την κίνηση
     * @return bool true αν η τοποθέτηση ήταν επιτυχής, false αλλιώς
     */
    public function placeTile($x, $y, $tile, $player_id) {
        Logger::getInstance()->info("Placing tile $tile at ($x, $y) for player $player_id");
        
        // Έλεγχος αν η θέση είναι ήδη κατειλημμένη
        $check_stmt = $this->db->prepare('
            SELECT COUNT(*) as count
            FROM game_tiles 
            WHERE game_id = ? AND x = ? AND y = ? AND status = "board"
        ');
        $check_stmt->bind_param('iii', $this->id, $x, $y);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['count'] > 0) {
            Logger::getInstance()->error("Position ($x, $y) is already occupied");
            return false;
        }
        
        $stmt = $this->db->prepare('
            UPDATE game_tiles 
            SET status = "board", x = ?, y = ? 
            WHERE game_id = ? AND tile = ? AND drawn_by = ? AND status = "hand"
        ');
        $stmt->bind_param('iiisi', $x, $y, $this->id, $tile, $player_id);
        
        if($stmt->execute()) {
            if($stmt->affected_rows > 0) {
                Logger::getInstance()->info("Successfully placed tile");
                if(!isset($this->board[$y])) {
                    $this->board[$y] = [];
                }
                $this->board[$y][$x] = $tile;
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Ελέγχει αν μια κίνηση είναι έγκυρη
     * Μια κίνηση είναι έγκυρη αν:
     * - Η θέση είναι ελεύθερη
     * - Το πλακίδιο συνδέεται με υπάρχοντα πλακίδια
     * - Τα γειτονικά πλακίδια έχουν ίδιο χρώμα ή σχήμα
     * - Δεν υπάρχουν διπλότυπα στην ίδια γραμμή
     * 
     * @param int $x Συντεταγμένη Χ
     * @param int $y Συντεταγμένη Υ
     * @param string $tile Το πλακίδιο προς έλεγχο
     * @return bool true αν η κίνηση είναι έγκυρη, false αλλιώς
     */
    public function isValidMove($x, $y, $tile) {
        $this->load();

        Logger::getInstance()->info("=== Starting isValidMove check ===");
        Logger::getInstance()->info("Checking move: $tile at ($x, $y)");
        Logger::getInstance()->info("Current board state: " . json_encode($this->board));
        
        // If board is empty, first move must be at center (0,0)
        if(empty($this->board)) {
            Logger::getInstance()->info("Empty board - checking if move is at center (0,0)");
            if($x !== 0 || $y !== 0) {
                throw new \Exception("Η πρώτη κίνηση πρέπει να γίνει στο κέντρο του ταμπλό (0,0)");
            }
            return true;
        }

        // Check if cell is occupied
        if(isset($this->board[$y][$x])) {
            Logger::getInstance()->info("Cell ($x, $y) is already occupied with tile: " . $this->board[$y][$x]);
            throw new \Exception("Η θέση ($x, $y) είναι ήδη κατειλημμένη");
        }

        // Check for adjacent tiles
        $adjacent = false;
        $directions = [[-1,0], [1,0], [0,-1], [0,1]];
        
        // Get all adjacent tiles
        $horizontal_adjacent = [];
        $vertical_adjacent = [];
        foreach($directions as $dir) {
            $check_x = $x + $dir[0];
            $check_y = $y + $dir[1];
            
            if(isset($this->board[$check_y][$check_x])) {
                $adjacent = true;
                if($dir[0] !== 0) { // Horizontal neighbor
                    Logger::getInstance()->info("Found horizontal neighbor at ($check_x, $check_y): " . $this->board[$check_y][$check_x]);
                    $horizontal_adjacent[] = $this->board[$check_y][$check_x];
                } else { // Vertical neighbor
                    Logger::getInstance()->info("Found vertical neighbor at ($check_x, $check_y): " . $this->board[$check_y][$check_x]);
                    $vertical_adjacent[] = $this->board[$check_y][$check_x];
                }
            }
        }
        
        if(!$adjacent) {
            Logger::getInstance()->info("No adjacent tiles found");
            throw new \Exception("Το πλακίδιο πρέπει να τοποθετηθεί δίπλα σε υπάρχον πλακίδιο");
        }
        
        // Check if the tile matches with adjacent tiles in each line
        list($tile_color, $tile_shape) = explode('_', $tile);
        Logger::getInstance()->info("New tile properties - Color: $tile_color, Shape: $tile_shape");
        
        // Temporary tile placement for line checks
        if(!isset($this->board[$y])) {
            $this->board[$y] = [];
        }
        $this->board[$y][$x] = $tile;
        
        // Check if the lines are valid
        $horizontal_line = $this->getLine($x, $y, 'horizontal');
        $vertical_line = $this->getLine($x, $y, 'vertical');
        
        Logger::getInstance()->info("Horizontal line found: " . implode(", ", $horizontal_line));
        Logger::getInstance()->info("Vertical line found: " . implode(", ", $vertical_line));
        
        // Remove temporary tile
        unset($this->board[$y][$x]);
        if(empty($this->board[$y])) {
            unset($this->board[$y]);
        }
        
        // Check line validity
        if(!empty($horizontal_line)) {
            Logger::getInstance()->info("Checking horizontal line: " . implode(", ", $horizontal_line));
            try {
                $this->isValidTileForLine($tile, $horizontal_line);
                Logger::getInstance()->info("Horizontal line check passed");
            } catch(\Exception $e) {
                Logger::getInstance()->info("Horizontal line check failed: " . $e->getMessage());
                throw new \Exception("Σφάλμα στην οριζόντια γραμμή: " . $e->getMessage());
            }
        }
        
        if(!empty($vertical_line)) {
            Logger::getInstance()->info("Checking vertical line: " . implode(", ", $vertical_line));
            try {
                $this->isValidTileForLine($tile, $vertical_line);
                Logger::getInstance()->info("Vertical line check passed");
            } catch(\Exception $e) {
                Logger::getInstance()->info("Vertical line check failed: " . $e->getMessage());
                throw new \Exception("Σφάλμα στην κάθετη γραμμή: " . $e->getMessage());
            }
        }
        
        Logger::getInstance()->info("=== Move validation successful ===");
        return true;
    }
    
    private function isValidLine($x, $y, $tile) {
        // Έλεγχος οριζόντιας γραμμής
        $horizontal = $this->getLine($x, $y, 'horizontal');
        if(!$this->isValidTileForLine($tile, $horizontal)) {
            Logger::getInstance()->info("Invalid horizontal line");
            return false;
        }
        
        // Έλεγχος κάθετης γραμμής
        $vertical = $this->getLine($x, $y, 'vertical');
        if(!$this->isValidTileForLine($tile, $vertical)) {
            Logger::getInstance()->info("Invalid vertical line");
            return false;
        }
        
        return true;
    }
    
    /**
     * Επιστρέφει τα πλακίδια σε μια γραμμή (οριζόντια ή κάθετη)
     * @param int $x Συντεταγμένη Χ του κεντρικού σημείου
     * @param int $y Συντεταγμένη Υ του κεντρικού σημείου
     * @param string $direction Κατεύθυνση ('horizontal' ή 'vertical')
     * @return array Πίνακας με τα πλακίδια της γραμμής
     */
    public function getLine($x, $y, $direction) {
        Logger::getInstance()->info("=== Getting line for position ($x, $y) in direction: $direction ===");
        $line = [];
        
        if($direction === 'horizontal') {
            // Προς τα αριστερά
            Logger::getInstance()->info("Checking tiles to the left");
            $check_x = $x - 1;
            while(isset($this->board[$y][$check_x])) {
                Logger::getInstance()->info("Found tile at ($check_x, $y): " . $this->board[$y][$check_x]);
                $line[] = $this->board[$y][$check_x];
                $check_x--;
            }
            
            // Αντιστροφή για σωστή σειρά
            $line = array_reverse($line);
            Logger::getInstance()->info("Left tiles (reversed): " . implode(", ", $line));
            
            // Προς τα δεξιά
            Logger::getInstance()->info("Checking tiles to the right");
            $check_x = $x + 1;
            while(isset($this->board[$y][$check_x])) {
                Logger::getInstance()->info("Found tile at ($check_x, $y): " . $this->board[$y][$check_x]);
                $line[] = $this->board[$y][$check_x];
                $check_x++;
            }
        } else {
            // Προς τα πάνω
            Logger::getInstance()->info("Checking tiles upward");
            $check_y = $y - 1;
            while(isset($this->board[$check_y][$x])) {
                Logger::getInstance()->info("Found tile at ($x, $check_y): " . $this->board[$check_y][$x]);
                $line[] = $this->board[$check_y][$x];
                $check_y--;
            }
            
            // Αντιστροφή για σωστή σειρά
            $line = array_reverse($line);
            Logger::getInstance()->info("Upward tiles (reversed): " . implode(", ", $line));
            
            // Προς τα κάτω
            Logger::getInstance()->info("Checking tiles downward");
            $check_y = $y + 1;
            while(isset($this->board[$check_y][$x])) {
                Logger::getInstance()->info("Found tile at ($x, $check_y): " . $this->board[$check_y][$x]);
                $line[] = $this->board[$check_y][$x];
                $check_y++;
            }
        }
        
        Logger::getInstance()->info("Final line: " . implode(", ", $line));
        return $line;
    }
    
    private function isValidTileForLine($tile, $line) {
        if(empty($line)) {
            Logger::getInstance()->info("[isValidTileForLine] Line is empty, returning true");
            return true;
        }
        
        Logger::getInstance()->info("[isValidTileForLine] Starting validation for tile $tile");
        Logger::getInstance()->info("[isValidTileForLine] Line contents: " . implode(", ", $line));
        
        // Διαχωρισμός χρώματος και σχήματος του νέου πλακιδίου
        list($tile_color, $tile_shape) = explode('_', $tile);
        Logger::getInstance()->info("[isValidTileForLine] New tile - Color: $tile_color, Shape: $tile_shape");
        
        // Έλεγχος μέγιστου μήκους γραμμής (συμπεριλαμβανομένου του νέου πλακιδίου)
        if(count($line) >= 6) {
            Logger::getInstance()->info("[isValidTileForLine] Line length check failed: " . count($line) . " tiles");
            throw new \Exception("Η γραμμή έχει ήδη το μέγιστο μήκος (6 πλακίδια)");
        }

        // Έλεγχος για μοναδικό πλακίδι στη γραμμή
        if(count($line) === 1) {
            list($first_color, $first_shape) = explode('_', $line[0]);
            Logger::getInstance()->info("[isValidTileForLine] Single tile check - First tile - Color: $first_color, Shape: $first_shape");
            
            if($tile_color !== $first_color && $tile_shape !== $first_shape) {
                Logger::getInstance()->info("[isValidTileForLine] Single tile match failed - neither color nor shape matches");
                throw new \Exception("Το πλακίδιο πρέπει να έχει το ίδιο χρώμα ($first_color) ή το ίδιο σχήμα ($first_shape) με το υπάρχον πλακίδιο");
            }

            // Έλεγχος για διπλότυπα
            if($tile_color === $first_color && $tile_shape === $first_shape) {
                throw new \Exception("Δεν επιτρέπεται η τοποθέτηση του ίδιου ακριβώς πλακιδίου");
            }

            Logger::getInstance()->info("[isValidTileForLine] Single tile check passed");
            return true;
        }

        // Ανάλυση των υπαρχόντων πλακιδίων στη γραμμή
        $colors = [];
        $shapes = [];
        foreach($line as $existing_tile) {
            list($color, $shape) = explode('_', $existing_tile);
            $colors[$color] = isset($colors[$color]) ? $colors[$color] + 1 : 1;
            $shapes[$shape] = isset($shapes[$shape]) ? $shapes[$shape] + 1 : 1;
            Logger::getInstance()->info("[isValidTileForLine] Analyzing tile: $existing_tile - Color: $color, Shape: $shape");
        }

        // Προσδιορισμός του τύπου της γραμμής
        $unique_colors = count($colors);
        $unique_shapes = count($shapes);

        // Έλεγχος για γραμμή χρώματος
        if($unique_colors === 1) {
            $line_color = array_key_first($colors);
            Logger::getInstance()->info("[isValidTileForLine] Color line detected - Color: $line_color");
            
            if($tile_color !== $line_color) {
                throw new \Exception("Η γραμμή είναι χρώματος $line_color - το πλακίδιο πρέπει να έχει το ίδιο χρώμα");
            }
            if(isset($shapes[$tile_shape])) {
                throw new \Exception("Στη γραμμή χρώματος $line_color υπάρχει ήδη πλακίδιο με σχήμα $tile_shape");
            }
            return true;
        }

        // Έλεγχος για γραμμή σχήματος
        if($unique_shapes === 1) {
            $line_shape = array_key_first($shapes);
            Logger::getInstance()->info("[isValidTileForLine] Shape line detected - Shape: $line_shape");
            
            if($tile_shape !== $line_shape) {
                throw new \Exception("Η γραμμή είναι σχήματος $line_shape - το πλακίδιο πρέπει να έχει το ίδιο σχήμα");
            }
            if(isset($colors[$tile_color])) {
                throw new \Exception("Στη γραμμή σχήματος $line_shape υπάρχει ήδη πλακίδιο με χρώμα $tile_color");
            }
            return true;
        }

        // Αν φτάσουμε εδώ, η γραμμή δεν είναι έγκυρη
        throw new \Exception("Η γραμμή πρέπει να έχει είτε το ίδιο χρώμα με διαφορετικά σχήματα, είτε το ίδιο σχήμα με διαφορετικά χρώματα");
    }
    
    /**
     * Επιστρέφει την τρέχουσα κατάσταση του ταμπλό
     * @return array Δισδιάστατος πίνακας με τα πλακίδια
     */
    public function getBoard() {
        return $this->board;
    }
    
    /**
     * Ελέγχει αν το ταμπλό είναι άδειο
     * @return bool true αν το ταμπλό είναι άδειο, false αλλιώς
     */
    public function isEmpty() {
        return empty($this->board);
    }
    
    /**
     * Επιστρέφει όλες τις πιθανές θέσεις για τοποθέτηση νέων πλακιδιών
     * Μια θέση είναι πιθανή αν:
     * - Είναι κενή
     * - Γειτνιάζει με υπάρχον πλακίδιο
     * 
     * @return array Πίνακας με τις συντεταγμένες των πιθανών θέσεων
     */
    public function getPossiblePositions() {
        if ($this->isEmpty()) {
            return [['x' => 0, 'y' => 0]];
        }

        $positions = [];
        $min_x = PHP_INT_MAX;
        $max_x = PHP_INT_MIN;
        $min_y = PHP_INT_MAX;
        $max_y = PHP_INT_MIN;

        // Βρίσκουμε τα όρια του ταμπλό
        foreach ($this->board as $y => $row) {
            foreach ($row as $x => $tile) {
                $min_x = min($min_x, $x);
                $max_x = max($max_x, $x);
                $min_y = min($min_y, $y);
                $max_y = max($max_y, $y);
            }
        }

        // Ελέγχουμε όλες τις γειτονικές θέσεις των υπαρχόντων πλακιδίων
        $checked = [];
        foreach ($this->board as $y => $row) {
            foreach ($row as $x => $tile) {
                $directions = [[-1,0], [1,0], [0,-1], [0,1]];
                foreach ($directions as $dir) {
                    $check_x = $x + $dir[0];
                    $check_y = $y + $dir[1];
                    
                    // Αποφυγή θέσεων πολύ μακριά από το υπάρχον ταμπλό
                    if ($check_x < $min_x - 1 || $check_x > $max_x + 1 ||
                        $check_y < $min_y - 1 || $check_y > $max_y + 1) {
                        continue;
                    }

                    $key = "$check_x,$check_y";
                    if (!isset($checked[$key]) && !isset($this->board[$check_y][$check_x])) {
                        $positions[] = ['x' => $check_x, 'y' => $check_y];
                        $checked[$key] = true;
                    }
                }
            }
        }

        return $positions;
    }
    
    public function getTileAt($x, $y) {
        return $this->board[$y][$x] ?? null;
    }
    
    public function getAdjacentTiles($x, $y) {
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
    
    public function isValidPlacement($x, $y, $tile) {
        // If board is empty, first move must be at center (0,0)
        if(empty($this->board)) {
            return ($x === 0 && $y === 0);
        }

        // Check if cell is occupied
        if(isset($this->board[$y][$x])) {
            return false;
        }

        // Check for adjacent tiles
        $adjacent = false;
        $directions = [[-1,0], [1,0], [0,-1], [0,1]];
        
        // Get all adjacent tiles
        $horizontal_adjacent = [];
        $vertical_adjacent = [];
        foreach($directions as $dir) {
            $check_x = $x + $dir[0];
            $check_y = $y + $dir[1];
            
            if(isset($this->board[$check_y][$check_x])) {
                $adjacent = true;
                if($dir[0] !== 0) { // Horizontal neighbor
                    $horizontal_adjacent[] = $this->board[$check_y][$check_x];
                } else { // Vertical neighbor
                    $vertical_adjacent[] = $this->board[$check_y][$check_x];
                }
            }
        }
        
        if(!$adjacent) {
            return false;
        }

        // Διαχωρισμός χρώματος και σχήματος του νέου πλακιδίου
        list($tile_color, $tile_shape) = explode('_', $tile);
        
        // Προσωρινή τοποθέτηση για έλεγχο γραμμών
        if(!isset($this->board[$y])) {
            $this->board[$y] = [];
        }
        $this->board[$y][$x] = $tile;
        
        // Έλεγχος γραμμών
        $valid = true;
        
        // Έλεγχος οριζόντιας γραμμής
        if (!empty($horizontal_adjacent)) {
            $horizontal_line = $this->getLine($x, $y, 'horizontal');
            try {
                $this->isValidTileForLine($tile, $horizontal_line);
            } catch (\Exception $e) {
                $valid = false;
            }
        }
        
        // Έλεγχος κάθετης γραμμής
        if ($valid && !empty($vertical_adjacent)) {
            $vertical_line = $this->getLine($x, $y, 'vertical');
            try {
                $this->isValidTileForLine($tile, $vertical_line);
            } catch (\Exception $e) {
                $valid = false;
            }
        }
        
        // Αφαίρεση προσωρινού πλακιδίου
        unset($this->board[$y][$x]);
        if(empty($this->board[$y])) {
            unset($this->board[$y]);
        }
        
        return $valid;
    }

    public function isValidMoveSet($tiles) {
        $this->load();
        
        Logger::getInstance()->info("=== Starting isValidMoveSet check ===");
        Logger::getInstance()->info("Checking tiles: " . json_encode($tiles));

        // Έλεγχος για πρώτη κίνηση
        if(empty($this->board)) {
            Logger::getInstance()->info("Empty board - checking if first move is at center (0,0)");
            foreach($tiles as $tile_data) {
                if($tile_data['x'] !== 0 || $tile_data['y'] !== 0) {
                    throw new \Exception("Η πρώτη κίνηση πρέπει να γίνει στο κέντρο του ταμπλό (0,0)");
                }
            }
            Logger::getInstance()->info("First move validation successful");
            return true;
        }

        // Οι παρακάτω έλεγχοι εκτελούνται μόνο αν δεν είναι η πρώτη κίνηση
        // 1. Έλεγχος αν όλα τα πλακίδια είναι σε μία γραμμή
        if (!$this->areTilesInLine($tiles)) {
            Logger::getInstance()->info("Tiles are not in a line");
            throw new \Exception("Τα πλακίδια πρέπει να τοποθετηθούν σε μία γραμμή");
        }
        Logger::getInstance()->info("Tiles are in a line");

        // 2. Έλεγχος αν έχουν κοινό χαρακτηριστικό
        if (!$this->haveTilesCommonProperty($tiles)) {
            Logger::getInstance()->info("Tiles don't have a common property");
            throw new \Exception("Τα πλακίδια πρέπει να έχουν ένα κοινό χαρακτηριστικό (χρώμα ή σχήμα)");
        }
        Logger::getInstance()->info("Tiles have a common property");

        // 3. Έλεγχος αν τουλάχιστον ένα πλακίδι συνδέεται με υπάρχον πλακίδι
        $has_adjacent = false;
        foreach ($tiles as $tile_data) {
            $directions = [[-1,0], [1,0], [0,-1], [0,1]];
            foreach ($directions as $dir) {
                $check_x = $tile_data['x'] + $dir[0];
                $check_y = $tile_data['y'] + $dir[1];
                if (isset($this->board[$check_y][$check_x])) {
                    $has_adjacent = true;
                    break 2;
                }
            }
        }

        if (!$has_adjacent && !empty($this->board)) {
            Logger::getInstance()->info("No tile is adjacent to existing tiles");
            throw new \Exception("Τουλάχιστον ένα πλακίδι πρέπει να συνδέεται με υπάρχον πλακίδιο");
        }

        // 4. Προσωρινή τοποθέτηση και έλεγχος
        $temp_board = $this->board;
        try {
            // Τοποθέτηση όλων των πλακιδίων
            foreach ($tiles as $tile_data) {
                if (!isset($this->board[$tile_data['y']])) {
                    $this->board[$tile_data['y']] = [];
                }
                if (isset($this->board[$tile_data['y']][$tile_data['x']])) {
                    throw new \Exception("Η θέση είναι ήδη κατειλημμένη");
                }
                $this->board[$tile_data['y']][$tile_data['x']] = $tile_data['tile'];
            }

            // Έλεγχος γραμμών για κάθε πλακίδι
            foreach ($tiles as $tile_data) {
                $x = $tile_data['x'];
                $y = $tile_data['y'];
                
                // Έλεγχος οριζόντιας γραμμής
                $horizontal_line = $this->getLine($x, $y, 'horizontal');
                if (count($horizontal_line) > 6) {
                    throw new \Exception("Η οριζόντια γραμμή δεν μπορεί να έχει περισσότερα από 6 πλακίδια");
                }

                // Έλεγχος κάθετης γραμμής
                $vertical_line = $this->getLine($x, $y, 'vertical');
                if (count($vertical_line) > 6) {
                    throw new \Exception("Η κάθετη γραμμή δεν μπορεί να έχει περισσότερα από 6 πλακίδια");
                }

                // Έλεγχος αν το πλακίδιο ταιριάζει με τις υπάρχουσες γραμμές
                if (!empty($horizontal_line)) {
                    try {
                        $this->isValidTileForLine($tile_data['tile'], $horizontal_line);
                    } catch (\Exception $e) {
                        throw new \Exception("Σφάλμα στην οριζόντια γραμμή: " . $e->getMessage());
                    }
                }
                
                if (!empty($vertical_line)) {
                    try {
                        $this->isValidTileForLine($tile_data['tile'], $vertical_line);
                    } catch (\Exception $e) {
                        throw new \Exception("Σφάλμα στην κάθετη γραμμή: " . $e->getMessage());
                    }
                }
            }

        } catch (\Exception $e) {
            $this->board = $temp_board;
            throw $e;
        }

        // 5. Επαναφορά του board
        $this->board = $temp_board;
        Logger::getInstance()->info("=== MoveSet validation successful ===");
        return true;
    }

    private function areTilesInLine($tiles) {
        if (count($tiles) === 1) return true;
        
        $same_x = true;
        $same_y = true;
        $first_x = $tiles[0]['x'];
        $first_y = $tiles[0]['y'];
        
        // Έλεγχος αν είναι σε συνεχόμενη γραμμή
        $positions = [];
        foreach ($tiles as $tile) {
            if ($tile['x'] !== $first_x) $same_x = false;
            if ($tile['y'] !== $first_y) $same_y = false;
            $positions[] = $same_x ? $tile['y'] : $tile['x'];
        }
        
        // Έλεγχος για κενά στη γραμμή
        sort($positions);
        for ($i = 1; $i < count($positions); $i++) {
            if ($positions[$i] - $positions[$i-1] !== 1) {
                return false;
            }
        }
        
        return $same_x || $same_y;
    }

    private function haveTilesCommonProperty($tiles) {
        if (count($tiles) === 1) return true;
        
        $first_tile = $tiles[0]['tile'];
        list($first_color, $first_shape) = explode('_', $first_tile);
        
        $same_color = true;
        $same_shape = true;
        $used_tiles = [];
        
        foreach ($tiles as $tile) {
            list($color, $shape) = explode('_', $tile['tile']);
            if ($color !== $first_color) $same_color = false;
            if ($shape !== $first_shape) $same_shape = false;
            
            // Έλεγχος για διπλότυπα (ακριβώς το ίδιο πλακίδιο)
            if (isset($used_tiles[$tile['tile']])) {
                throw new \Exception("Δεν επιτρέπονται διπλότυπα πλακίδια στην ίδια γραμμή");
            }
            $used_tiles[$tile['tile']] = true;
        }
        
        return $same_color || $same_shape;
    }
}
?> 