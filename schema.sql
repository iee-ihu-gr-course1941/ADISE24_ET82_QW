-- Δημιουργία βάσης δεδομένων
CREATE DATABASE IF NOT EXISTS qwirkle_game DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE qwirkle_game;

-- Πίνακας παικτών
CREATE TABLE IF NOT EXISTS players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    token VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Πίνακας παιχνιδιών
CREATE TABLE IF NOT EXISTS games (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player1_id INT NOT NULL,
    player2_id INT,
    current_player_id INT,
    status ENUM('initialized', 'active', 'completed') DEFAULT 'initialized',
    winner_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (player1_id) REFERENCES players(id),
    FOREIGN KEY (player2_id) REFERENCES players(id),
    FOREIGN KEY (current_player_id) REFERENCES players(id),
    FOREIGN KEY (winner_id) REFERENCES players(id)
);

-- Πίνακας κινήσεων
CREATE TABLE IF NOT EXISTS game_moves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    player_id INT NOT NULL,
    move_type ENUM('place', 'exchange', 'pass') NOT NULL,
    move_data JSON,
    move_number INT NOT NULL,  -- Αυτό είναι σημαντικό για τη σειρά των κινήσεων
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (player_id) REFERENCES players(id),
    INDEX idx_game_moves (game_id, move_number),
    INDEX idx_game_sequence (game_id, move_number DESC)
);

-- Πίνακας πλακιδιών παιχνιδιού
CREATE TABLE IF NOT EXISTS game_tiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    tile VARCHAR(20) NOT NULL,  -- π.χ. 'red_circle'
    status ENUM('deck', 'hand', 'board') DEFAULT 'deck',
    drawn_by INT,
    x INT,
    y INT,
    placed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (drawn_by) REFERENCES players(id),
    UNIQUE KEY unique_board_position (game_id, x, y),
    INDEX idx_game_tile (game_id, tile, status)
);

-- Πίνακας σκορ
CREATE TABLE IF NOT EXISTS game_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    player_id INT NOT NULL,
    score INT DEFAULT 0,
    last_move_score INT DEFAULT 0,
    moves_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (player_id) REFERENCES players(id),
    UNIQUE KEY unique_game_player (game_id, player_id)
);