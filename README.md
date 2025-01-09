# Qwirkle Game - Web API Implementation

## Περιγραφή Project
Υλοποίηση του επιτραπέζιου παιχνιδιού Qwirkle ως Web API σε PHP/MySQL. Το παιχνίδι επιτρέπει σε δύο παίκτες να παίξουν μέσω HTTP requests (curl/wget) χωρίς γραφικό περιβάλλον. 
Η εφαρμογή υλοποιεί πλήρως τους κανόνες του παιχνιδιού, συμπεριλαμβανομένου του ελέγχου εγκυρότητας κινήσεων, της αναγνώρισης σειράς παιξίματος και του εντοπισμού καταστάσεων deadlock.

## Κανόνες Παιχνιδιού

### Βασικοί Κανόνες
1. **Πλακίδια**
   - 108 πλακίδια συνολικά (6 χρώματα × 6 σχήματα × 3 αντίγραφα)
   - Κάθε παίκτης ξεκινά με 6 πλακίδια
   - Μετά από κάθε κίνηση, ο παίκτης τραβάει νέα πλακίδια μέχρι να έχει πάλι 6

2. **Έγκυρες Κινήσεις**
   - Η πρώτη κίνηση πρέπει να γίνει στο κέντρο του ταμπλό (0,0)
   - Κάθε επόμενη κίνηση πρέπει να συνδέεται με υπάρχοντα πλακίδια
   - Τα πλακίδια σε μια γραμμή πρέπει να έχουν:
     * Ίδιο χρώμα και διαφορετικά σχήματα, ή
     * Ίδιο σχήμα και διαφορετικά χρώματα
   - Μέγιστο μήκος γραμμής: 6 πλακίδια (Qwirkle)

3. **Τύποι Κινήσεων**
   - **Τοποθέτηση (place)**: Τοποθέτηση 1+ πλακιδιών σε ευθεία γραμμή
   - **Ανταλλαγή (exchange)**: Ανταλλαγή 1+ πλακιδιών με νέα από το deck
   - **Πάσο (pass)**: Παράλειψη σειράς όταν δεν υπάρχει έγκυρη κίνηση

### Υπολογισμός Σκορ
1. **Βασικοί Πόντοι**
   - 1 πόντος για κάθε πλακίδιο στη γραμμή που επηρεάζεται
   - Ένα πλακίδιο μπορεί να μετρήσει για 2 γραμμές (οριζόντια και κάθετα)

2. **Qwirkle Bonus**
   - +6 πόντοι για κάθε ολοκληρωμένη γραμμή 6 πλακιδιών (Qwirkle)
   - Μπορούν να επιτευχθούν πολλαπλά Qwirkle σε μία κίνηση

3. **Παράδειγμα Σκοραρίσματος**
   ```
   Αρχική κατάσταση:
   red_circle red_star red_diamond
   
   Κίνηση: Προσθήκη red_square στο τέλος
   Πόντοι: 4 (όλα τα πλακίδια στη γραμμή)
   ```

### Τερματισμός Παιχνιδιού
Το παιχνίδι τελειώνει όταν:
1. Ένας παίκτης τοποθετήσει όλα του τα πλακίδια (+6 bonus πόντοι)
2. Δεν υπάρχουν άλλα πλακίδια στο deck και κανείς δεν μπορεί να κάνει κίνηση

## Τεχνική Υλοποίηση

### Τεχνολογίες
- PHP 7.4+
- MySQL 5.7+
- Apache 2.4+ (XAMPP)
- JSON για επικοινωνία API

### Αρχιτεκτονική

#### Δομή Project
```
qwirkle/
├── api/                # REST API endpoints
│   └── index.php      # Κύριος router
├── lib/               # Core classes
│   ├── Board.php      # Διαχείριση ταμπλό
│   ├── Game.php       # Λογική παιχνιδιού
│   ├── Player.php     # Διαχείριση παικτών
│   ├── DB.php         # Database singleton
│   ├── logger.php     # Καταγραφή συμβάντων
│   ├── config.php     # Βασικές ρυθμίσεις
│   ├── config.dev.php # Ρυθμίσεις ανάπτυξης
│   └── dbconnect.php  # Σύνδεση με βάση
└── schema.sql         # Database schema
```

#### Διαχείριση Ταμπλό (Board.php)
Το ταμπλό υλοποιείται ως ένας δισδιάστατος πίνακας με τις εξής λειτουργίες:

1. **Αναπαράσταση**
   ```php
   $board[y][x] = 'red_circle';  // Συντεταγμένες (x,y)
   ```

2. **Βασικές Λειτουργίες**
   - `placeTile($x, $y, $tile)`: Τοποθέτηση πλακιδίου
   - `isValidMove($x, $y, $tile)`: Έλεγχος εγκυρότητας κίνησης
   - `getLine($x, $y, $direction)`: Εύρεση γραμμής (οριζόντια/κάθετη)
   - `calculatePoints($x, $y, $tile)`: Υπολογισμός πόντων

3. **Έλεγχοι Εγκυρότητας**
   ```php
   // Παράδειγμα ελέγχου γραμμής
   foreach ($line as $tile) {
       if ($tile_color === $color) {
           if (in_array($tile_shape, $shapes)) {
               return false; // Διπλό σχήμα
           }
           $shapes[] = $tile_shape;
       }
   }
   ```

#### Διαχείριση Παιχνιδιού (Game.php)
Η κλάση Game διαχειρίζεται πλήρως την κατάσταση του παιχνιδιού:

1. **Κατάσταση Παιχνιδιού**
   ```php
   class Game {
       private $status;        // initialized/active/completed
       private $current_player;
       private $board;
       private $players_tiles;
       private $deck;
   }
   ```

2. **Κύριες Λειτουργίες**
   - `makeMove($player_id, $move_type, $move_data)`: Εκτέλεση κίνησης
   - `dealTiles($player_id, $count)`: Μοίρασμα πλακιδίων
   - `exchangeTiles($player_id, $tiles)`: Ανταλλαγή πλακιδίων
   - `suggestMoves($player_id)`: Πρόταση κινήσεων

3. **Παράδειγμα Ροής Κίνησης**
   ```php
   // 1. Έλεγχος σειράς παίκτη
   if ($this->current_player !== $player_id) {
       throw new Exception("Δεν είναι η σειρά σας");
   }

   // 2. Έλεγχος εγκυρότητας κίνησης
   foreach ($tiles as $tile_data) {
       if (!$this->board->isValidMove(
           $tile_data['x'], 
           $tile_data['y'], 
           $tile_data['tile']
       )) {
           throw new Exception("Μη έγκυρη κίνηση");
       }
   }

   // 3. Εκτέλεση κίνησης και υπολογισμός πόντων
   $points = 0;
   foreach ($tiles as $tile_data) {
       $points += $this->board->placeTile(
           $tile_data['x'],
           $tile_data['y'],
           $tile_data['tile']
       );
   }

   // 4. Ενημέρωση κατάστασης παιχνιδιού
   $this->updateGameState($points);
   ```

#### Διαχείριση Βάσης Δεδομένων
Η κατάσταση του παιχνιδιού διατηρείται σε πραγματικό χρόνο στη βάση:

1. **Πίνακες**
   - **players**: Στοιχεία παικτών και authentication
   - **games**: Τρέχουσα κατάσταση παιχνιδιών
   - **game_moves**: Ιστορικό κινήσεων
   - **game_tiles**: Θέσεις πλακιδίων στο ταμπλό
   - **game_scores**: Βαθμολογία παικτών

2. **Παράδειγμα Δομής**
   ```sql
   CREATE TABLE game_tiles (
       id INT AUTO_INCREMENT,
       game_id INT,
       tile VARCHAR(20),
       x INT,
       y INT,
       status ENUM('deck', 'hand', 'board'),
       drawn_by INT,
       PRIMARY KEY (id)
   );
   ```

3. **Συναλλαγές (Transactions)**
   ```php
   // Παράδειγμα ατομικής συναλλαγής για κίνηση
   $this->db->begin_transaction();
   try {
       // 1. Ενημέρωση θέσης πλακιδίων
       // 2. Καταγραφή κίνησης
       // 3. Ενημέρωση σκορ
       $this->db->commit();
   } catch (Exception $e) {
       $this->db->rollback();
       throw $e;
   }
   ```


### Αλγόριθμος Πρότασης Κινήσεων
Ο αλγόριθμος αναλύει την τρέχουσα κατάσταση του παιχνιδιού και προτείνει τις βέλτιστες κινήσεις:

1. **Ανάλυση Ταμπλό**
   ```php
   // Εύρεση όλων των άκρων γραμμών
   foreach ($this->board as $y => $row) {
       foreach ($row as $x => $tile) {
           if ($this->isLineEnd($x, $y)) {
               $endpoints[] = ['x' => $x, 'y' => $y];
           }
       }
   }
   ```

2. **Εύρεση Συμβατών Πλακιδίων**
   ```php
   // Για κάθε άκρο γραμμής
   foreach ($endpoints as $point) {
       $line = $this->getLine($point['x'], $point['y']);
       $compatibleTiles = $this->findCompatibleTiles($line);
       
       foreach ($player_tiles as $tile) {
           if (in_array($tile, $compatibleTiles)) {
               $moves[] = [
                   'x' => $point['x'],
                   'y' => $point['y'],
                   'tile' => $tile
               ];
           }
       }
   }
   ```

3. **Αξιολόγηση Κινήσεων**
   ```php
   foreach ($moves as &$move) {
       // Υπολογισμός πόντων
       $points = $this->calculateMovePoints($move);
       
       // Έλεγχος για Qwirkle
       if ($this->wouldCreateQwirkle($move)) {
           $points += 6;
       }
       
       $move['points'] = $points;
   }
   
   // Ταξινόμηση κινήσεων βάσει πόντων
   usort($moves, function($a, $b) {
       return $b['points'] - $a['points'];
   });
   ```

