/**
 * index.php - Κύριο σημείο εισόδου του API για το παιχνίδι Qwirkle
 * 
 * Διαχειρίζεται:
 * - Αυθεντικοποίηση παικτών
 * - Δημιουργία και συμμετοχή σε παιχνίδια
 * - Εκτέλεση κινήσεων
 * - Διαχείριση σφαλμάτων και καταγραφή
 */

<?php
// Ρύθμιση αναφοράς σφαλμάτων για καλύτερο debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Ορισμός σχετικών διαδρομών για τα βασικά directories
$root_dir = dirname(__DIR__);  // Ανεβαίνουμε ένα επίπεδο από το /api
$lib_dir = $root_dir . '/lib';
$logs_dir = $root_dir . '/logs';

// Δημιουργία των απαραίτητων directories αν δεν υπάρχουν
foreach ([$lib_dir, $logs_dir] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

// Καταγραφή πληροφοριών debugging στο αρχείο καταγραφής
$debug_info = [
    'Time' => date('Y-m-d H:i:s'),
    'PHP Version' => PHP_VERSION,
    'Server Software' => $_SERVER['SERVER_SOFTWARE'],
    'Request Method' => $_SERVER['REQUEST_METHOD'],
    'Request URI' => $_SERVER['REQUEST_URI'],
    'Script Path' => __FILE__,
    'Document Root' => $_SERVER['DOCUMENT_ROOT'],
    'Paths' => [
        'root_dir' => $root_dir,
        'lib_dir' => $lib_dir,
        'logs_dir' => $logs_dir
    ]
];

file_put_contents($logs_dir . '/game.log', print_r($debug_info, true));

// Ορισμός διαδρομών για τα βασικά αρχεία της εφαρμογής
$game_file = $lib_dir . '/Game.php';
$player_file = $lib_dir . '/Player.php';
$logger_file = $lib_dir . '/Logger.php';

// Έλεγχος ύπαρξης και δικαιωμάτων ανάγνωσης των απαραίτητων αρχείων
$files_status = "Κατάσταση αρχείων:\n";
foreach ([$game_file, $player_file, $logger_file] as $file) {
    $files_status .= basename($file) . ': ' . 
        (file_exists($file) ? 'υπάρχει' : 'λείπει') . ', ' .
        (is_readable($file) ? 'αναγνώσιμο' : 'μη αναγνώσιμο') . "\n";
}
file_put_contents($logs_dir . '/game.log', $files_status, FILE_APPEND);

// Φόρτωση των απαραίτητων κλάσεων
require_once $game_file;
require_once $player_file;
require_once $logger_file;

use App\Game;
use App\Player;
use App\Logger;

// Ορισμός του τύπου απάντησης ως JSON
header('Content-Type: application/json');

try {
    // Αρχικοποίηση του συστήματος καταγραφής
    $logger = Logger::getInstance();
    $logger->info("Αίτημα API: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);

    // Ανάλυση της διεύθυνσης URI
    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $uri = explode('/', trim($uri, '/'));

    $logger->info("Τμήματα URI: " . json_encode($uri));

    // Έλεγχος αυθεντικοποίησης
    $headers = getallheaders();

    if (isset($headers['Authorization'])) {
        $auth = explode(' ', $headers['Authorization']);
        
        if ($auth[0] === 'Basic') {
            $decoded = base64_decode($auth[1]);
            
            // Διαχείριση πιθανών προβλημάτων κωδικοποίησης
            if (strpos($decoded, ':') === false) {
                $username = $decoded;  // Δεν υπάρχει κωδικός στο string
            } else {
                $credentials = explode(':', $decoded);
                $username = $credentials[0];
            }
            
            // Καθαρισμός του ονόματος χρήστη
            $username = preg_replace('/[^a-zA-Z0-9_]/', '', $username);
            
            $logger->info("Αυθεντικοποίηση χρήστη: $username");
            $player = Player::authenticate($username);
            
            if (!$player) {
                $logger->error("Αποτυχία αυθεντικοποίησης για τον χρήστη: $username");
                http_response_code(401);
                echo json_encode(['error' => 'Αποτυχία αυθεντικοποίησης']);
                exit;
            }
            $logger->info("Επιτυχής αυθεντικοποίηση παίκτη: " . json_encode($player));
        }
    }

    if (!isset($player)) {
        $logger->error("Αποτυχία αυθεντικοποίησης - δεν παρέχθηκαν διαπιστευτήρια");
        http_response_code(401);
        echo json_encode(['error' => 'Απαιτείται αυθεντικοποίηση']);
        exit;
    }

    switch($method) {
        case 'POST':
            // Διαχείριση αιτήματος για κίνηση
            if (isset($uri[2]) && $uri[2] === 'games' && isset($uri[3]) && isset($uri[4]) && $uri[4] === 'moves') {
                $game_id = intval($uri[3]);
                
                try {
                    $game = new Game($game_id);
                    $data = json_decode(file_get_contents('php://input'), true);
                    
                    if (!isset($data['move_type']) || !isset($data['move_data'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Μη έγκυρα δεδομένα κίνησης']);
                        exit;
                    }
                    
                    $result = $game->makeMove($player['player_id'], $data['move_type'], $data['move_data']);
                    
                    if (isset($result['error'])) {
                        http_response_code(400);
                    }
                    echo json_encode($result);
                    
                } catch (\Throwable $e) {
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Αποτυχία εκτέλεσης κίνησης',
                        'message' => $e->getMessage()
                    ]);
                }
            }
            // Διαχείριση αιτήματος για συμμετοχή σε παιχνίδι
            else if (isset($uri[2]) && $uri[2] === 'games' && isset($uri[3]) && isset($uri[4]) && $uri[4] === 'join') {
                $game_id = intval($uri[3]);
                
                try {
                    $game = new Game($game_id);
                    
                    if ($game->join($player['player_id'])) {
                        $state = $game->getGameState($player['player_id']);
                        $response = [
                            'status' => 'success',
                            'game_state' => $state
                        ];
                        echo json_encode($response);
                    } else {
                        http_response_code(400);
                        echo json_encode(['error' => 'Αποτυχία συμμετοχής στο παιχνίδι']);
                    }
                } catch (\Throwable $e) {
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Αποτυχία συμμετοχής στο παιχνίδι',
                        'message' => $e->getMessage()
                    ]);
                }
            }
            // Διαχείριση αιτήματος για δημιουργία νέου παιχνιδιού
            else if (isset($uri[2]) && $uri[2] === 'games') {
                $logger->info("Έναρξη δημιουργίας παιχνιδιού για τον παίκτη: " . $player['player_id']);
                try {
                    $game = Game::create($player['player_id']);
                    
                    if ($game) {
                        $state = $game->getGameState($player['player_id']);
                        $response = [
                            'status' => 'success',
                            'game_id' => $game->getId(),
                            'game_state' => $state
                        ];
                        $logger->info("Επιτυχής δημιουργία παιχνιδιού: " . json_encode($response));
                        echo json_encode($response);
                    } else {
                        $logger->error("Η δημιουργία παιχνιδιού επέστρεψε null");
                        http_response_code(400);
                        echo json_encode(['error' => 'Αποτυχία δημιουργίας παιχνιδιού']);
                    }
                } catch (\Throwable $e) {
                    $logger->error("Σφάλμα κατά τη δημιουργία παιχνιδιού: " . $e->getMessage());
                    $logger->error("Αρχείο: " . $e->getFile() . " Γραμμή: " . $e->getLine());
                    $logger->error("Stack trace: " . $e->getTraceAsString());
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Αποτυχία δημιουργίας παιχνιδιού',
                        'message' => $e->getMessage()
                    ]);
                }
            }
            break;
            
        case 'GET':
            // Διαχείριση αιτήματος για λήψη κατάστασης παιχνιδιού
            if (isset($uri[2]) && $uri[2] === 'games' && isset($uri[3])) {
                $game_id = intval($uri[3]);
                
                try {
                    $game = new Game($game_id);
                    $state = $game->getGameState($player['player_id']);
                    echo json_encode($state);
                } catch (\Throwable $e) {
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Αποτυχία λήψης κατάστασης παιχνιδιού',
                        'message' => $e->getMessage()
                    ]);
                }
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Μη υποστηριζόμενη μέθοδος']);
            break;
    }
    
} catch (\Throwable $e) {
    $logger->error("Κρίσιμο σφάλμα: " . $e->getMessage());
    $logger->error("Αρχείο: " . $e->getFile() . " Γραμμή: " . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'error' => 'Εσωτερικό σφάλμα διακομιστή',
        'message' => $e->getMessage()
    ]);
}
?> 