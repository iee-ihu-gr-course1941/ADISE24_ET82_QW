# Qwirkle Game - Web API Implementation

## Περιγραφή Project
Υλοποίηση του επιτραπέζιου παιχνιδιού Qwirkle ως Web API σε PHP/MySQL. Το παιχνίδι επιτρέπει σε δύο παίκτες να παίξουν μέσω HTTP requests (curl/wget) χωρίς γραφικό περιβάλλον. Η εφαρμογή υλοποιεί πλήρως τους κανόνες του παιχνιδιού, συμπεριλαμβανομένου του ελέγχου εγκυρότητας κινήσεων, της αναγνώρισης σειράς παιξίματος και του εντοπισμού καταστάσεων deadlock.

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

## Άεχνική Υλοποίηση

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
Το σύστημα αναλύει και προτείνει έγκυρες κινήσεις με την εξής σειρά προτεραιότητας:

1. **Κινήσεις που ολοκληρώνουν Qwirkle**
   - Εντοπισμός γραμμών με 5 πλακίδια
   - Έλεγχος για πλακίδια στο χέρι που συμπληρώνουν τη γραμμή

2. **Κινήσεις που επεκτείνουν υπάρχουσες γραμμές**
   - Αναζήτηση συμβατών πλακιδίων για κάθε άκρο γραμμής
   - Υπολογισμός πόντων για κάθε πιθανή επέκταση

3. **Κινήσεις που δημιουργούν νέες γραμμές**
   - Εύρεση σημείων σύνδεσης με υπάρχοντα πλακίδια
   - Υπολογισμός πιθανών συνδυασμών από τα διαθέσιμα πλακίδια

4. **Ανταλλαγή πλακιδίων**
   - Προτείνεται όταν δεν υπάρχουν καλές επιλογές τοποθέτησης
   - Προτεραιότητα στην ανταλλαγή πλακιδίων που δεν ταιριάζουν με τα υπόλοιπα

## Εγκατάσταση & Χρήση

### Εγκατάσταση
1. **Προαπαιτούμενα**
   - Εγκατάσταση XAMPP από [apachefriends.org](https://www.apachefriends.org/)
   - Ρύθμιση Apache να τρέχει στη θύρα 88 από την επιλογή config (Apache http.conf),αλλάζοντας το port σε 88 και σώζοντας το αρχείο.
   - Ενεργοποίηση υπηρεσιών Apache και MySQL

2. **Εγκατάσταση Εφαρμογής**
   ```bash
   # Αντιγραφή αρχείων στο htdocs
   cd C:\xampp\htdocs\qwirkle
   git clone https://github.com/your-username/qwirkle.git
   
   # Δημιουργία βάσης δεδομένων
   mysql -u root -p < qwirkle/schema.sql
   ```

   # Phpmyadmin - Εισαγωγή στοιχείων στη βάση
   Πηγαίνουμε στην οθόνη του phpmyadmin και εισάγουμε τα στοιχεία στη βάση 
   από το αρχείο qwirkle/schema.sql ,από την επιλογή εισαγωγής στοιχείων στη βάση.

### Web API Documentation

#### Authentication
Απλή αυθεντικοποίηση με username (χωρίς password):
```http
Authorization: Basic <base64_encoded_username>
```

Response (Error - Χωρίς Authentication):
```json
{
    "error": "Απαιτείται αυθεντικοποίηση"
}
```

Response (Error - Λάθος Authentication):
```json
{
    "error": "Αποτυχία αυθεντικοποίησης"
}
```

#### Endpoints

1. **Δημιουργία Παιχνιδιού**
```http
POST /qwirkle/api/games
```
Response (Success):
```json
{
    "player_id": 123,
    "game_state": {
        "status": "initialized",
        "tiles": ["red_circle", "blue_star", "green_diamond"]
    }
}
```

2. **Είσοδος στο Παιχνίδι**
```http
POST /qwirkle/api/games/{game_id}/join

Response (Success):
```json
{
    "game_state": {
        "status": "active",
        "tiles": ["red_circle", "blue_star", "green_diamond"]
    }
}
```

3. **Εκτέλεση Κίνησης**
```http
POST /qwirkle/api/games/{game_id}/moves

body:
```json
{
    "move_type": "place",
    "move_data": {
        "tiles": [
            {
                "x": -1,
                "y": 0,
                "tile": "orange_star"
            }
        ]
    }
}
```
Response (Success):
```json
{
    "status": "success"
}
```

4. **Κατάσταση Παιχνιδιού**
```http
GET /qwirkle/api/games/{game_id}
```
Response (Success) παράδειγμα για τον player2:
```json
{
    "status": "success",
    "game_state": {
        "game_id": 1,
        "status": "active",
        "current_player": 2,
        "player1": 1,
        "player2": 2,
        "your_hand": [
            "red_star",
            "yellow_circle",
            "green_star",
            "green_clover",
            "blue_square",
            "purple_clover"
        ],
        "board": [
            {
                "x": 0,
                "y": 0,
                "tile": "orange_circle"
            },
            {
                "x": -1,
                "y": 0,
                "tile": "orange_star"
            },
            {
                "x": -1,
                "y": 1,
                "tile": "orange_clover"
            }
        ],
        "scores": {
            "1": 7
        },
        "remaining_tiles": 93,
        "is_your_turn": false,
        "available_moves": {
            "has_moves": true,
            "moves": [
                {
                    "move_type": "place",
                    "move_data": {
                        "tiles": [
                            {
                                "x": -2,
                                "y": 1,
                                "tile": "green_clover"
                            },
                            {
                                "x": -2,
                                "y": 2,
                                "tile": "purple_clover"
                            }
                        ]
                    },
                    "expected_points": 3,
```

#### Γενικά Σφάλματα

1. **Μη εξουσιοδοτημένη πρόσβαση (401)**
```json
{
    "error": "Απαιτείται αυθεντικοποίηση"
}
```

2. **Μη υποστηριζόμενη μέθοδος (405)**
```json
{
    "error": "Μη υποστηριζόμενη μέθοδος"
}
```

3. **Εσωτερικό σφάλμα διακομιστή (500)**
```json
{
    "error": "Εσωτερικό σφάλμα διακομιστή"
}
```

### Χρήση με Postman

#### Ρύθμιση Περιβάλλοντος
1. **Δημιουργία Environment**
   - Όνομα: `Qwirkle Local`
   - Variables:
     * `base_url`: `http://localhost:88/qwirkle/api`
     * `username`: `<το όνομα παίκτη>`
     * `game_id`: `<θα συμπληρωθεί αυτόματα>`

2. **Ρύθμιση Authorization**
   - Type: `Basic Auth`
   - Username: `{{username}}`
   - Password: (άδειο)

3. **Collection Setup**
   - Δημιουργία νέου collection "Qwirkle"
   - Import των endpoints από το παρακάτω curl format

#### Tests στο Postman
```javascript
// Test για επιτυχή δημιουργία παιχνιδιού
pm.test("Επιτυχής δημιουργία παιχνιδιού", function () {
    pm.response.to.have.status(200);
    var jsonData = pm.response.json();
    pm.expect(jsonData.status).to.eql("success");
    pm.environment.set("game_id", jsonData.game_id);
});

// Test για έγκυρη κίνηση
pm.test("Έγκυρη κίνηση", function () {
    pm.response.to.have.status(200);
    var jsonData = pm.response.json();
    pm.expect(jsonData.status).to.eql("success");
});
```

### Παραδείγματα Χρήσης με curl

1. **Δημιουργία παιχνιδιού**:
```bash
curl -X POST \
     -H "Authorization: Basic $(echo -n 'player1' | base64)" \
     http://localhost:88/qwirkle/api/games
```

2. **Είσοδος στο παιχνίδι**:
```bash
curl -X POST \
     -H "Authorization: Basic $(echo -n 'player2' | base64)" \
     http://localhost:88/qwirkle/api/games/1/join
```

3. **Εκτέλεση κίνησης**:
```bash
curl -X POST \
     -H "Authorization: Basic $(echo -n 'player1' | base64)" \
     -H "Content-Type: application/json" \
     -d '{"type":"place","tiles":[{"tile":"red_circle","x":0,"y":0}]}' \
     http://localhost:88/qwirkle/api/games/1/moves
```

## Άδεια Χρήσης
MIT License

### Web API Implementation
Το API έχει σχεδιαστεί με γνώμονα την απλότητα και την αξιοπιστία:

1. **Αρχιτεκτονική REST**
   - Stateless επικοινωνία
   - Χρήση HTTP methods (GET, POST) για διαφορετικές λειτουργίες
   - JSON για ανταλλαγή δεδομένων

2. **Ασφάλεια**
   - Basic Authentication με username
   - Έλεγχος σειράς παικτών
   - Επικύρωση όλων των requests

3. **Διαχείριση Σφαλμάτων**
   - Συνεπή HTTP status codes
   - Αναλυτικά μηνύματα λάθους
   - Καταγραφή σφαλμάτων σε log files

4. **Caching & Performance**
   - Αποθήκευση game state στη βάση
   - Βελτιστοποιημένα queries
   - Prepared statements για ασφάλεια

5. **Επεκτασιμότητα**
   - Modular σχεδιασμός
   - Εύκολη προσθήκη νέων endpoints
   - Υποστήριξη versioning

### Λειτουργίες Εφαρμογής

1. **Διαχείριση Παιχνιδιού**
   - Δημιουργία/είσοδος σε παιχνίδι
   - Αυτόματη διανομή πλακιδίων
   - Παρακολούθηση σειράς παικτών
   - Υπολογισμός σκορ

2. **Έλεγχος Κινήσεων**
   - Επικύρωση θέσης πλακιδίων
   - Έλεγχος συνδεσιμότητας
   - Αναγνώριση Qwirkle
   - Υπολογισμός πόντων

3. **Βοηθητικές Λειτουργίες**
   - Πρόταση έγκυρων κινήσεων
   - Ανίχνευση τέλους παιχνιδιού
   - Ιστορικό κινήσεων
   - Στατιστικά παιχνιδιού

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

### Χρήση με Postman

#### Παραδείγματα Requests

1. **Δημιουργία Παιχνιδιού**
```http
POST http://localhost:88/qwirkle/api/games
Authorization: Basic cGxheWVyMQ==
```

2. **Είσοδος στο Παιχνίδι**
```http
POST http://localhost:88/qwirkle/api/games/1/join
Authorization: Basic cGxheWVyMg==
```

3. **Τοποθέτηση Πλακιδίου**
```http
POST http://localhost:88/qwirkle/api/games/1/moves
Authorization: Basic cGxheWVyMQ==
Content-Type: application/json

{
    "move_type": "place",
    "move_data": {
        "tiles": [
            {
                "tile": "red_circle",
                "x": 0,
                "y": 0
            }
        ]
    }
}
```

4. **Ανταλλαγή Πλακιδίων**
```http
POST http://localhost:88/qwirkle/api/games/1/moves
Authorization: Basic cGxheWVyMQ==
Content-Type: application/json

{
    "move_type": "exchange",
    "move_data": {
        "tiles": ["red_circle", "blue_star"]
    }
}
```

5. **Κατάσταση Παιχνιδιού**
```http
GET http://localhost:88/qwirkle/api/games/1
Authorization: Basic cGxheWVyMQ==
```