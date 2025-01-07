/**
 * Βασικές ρυθμίσεις για το παιχνίδι Qwirkle
 */
<?php
// Ρυθμίσεις βάσης δεδομένων
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'qwirkle_game');

// Δυναμικός υπολογισμός του API URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:88';
define('API_URL', $protocol . '://' . $host . '/qwirkle/api');

// Ρυθμίσεις παιχνιδιού
define('GAME_INITIAL_TILES', 6);
define('GAME_MAX_LINE_LENGTH', 6);
define('GAME_QWIRKLE_BONUS', 6);
define('GAME_DEFAULT_TURN_TIME', 300);

// Διαθέσιμα χρώματα και σχήματα
$COLORS = ['red', 'orange', 'yellow', 'green', 'blue', 'purple'];
$SHAPES = ['circle', 'cross', 'diamond', 'square', 'star', 'clover'];
?> 