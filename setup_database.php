<?php

$host = 'localhost';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected successfully\n";
    
    // Διάβασμα του SQL script
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    
    // Εκτέλεση των εντολών
    $pdo->exec($sql);
    
    echo "Database and tables created successfully\n";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
} 