<?php
// /finance/includes/db.php
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . FIN_DB_HOST . ';dbname=' . FIN_DB_NAME . ';charset=utf8mb4',
        FIN_DB_USER,
        FIN_DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    // Fallback to local XAMPP root user if primary user credentials fail on localhost
    if (in_array(FIN_DB_HOST, ['localhost', '127.0.0.1'], true)) {
        try {
            $pdo = new PDO(
                'mysql:host=' . FIN_DB_HOST . ';dbname=' . FIN_DB_NAME . ';charset=utf8mb4',
                'root',
                '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e2) {
            die('Database connection failed: ' . $e->getMessage());
        }
    } else {
        die('Database connection failed: ' . $e->getMessage());
    }
}
