<?php
// =============================================
// config.php — Connexion MySQL
// =============================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'songo');
define('DB_USER', 'root');      // Utilisateur XAMPP par défaut
define('DB_PASS', '');          // Mot de passe vide par défaut sur XAMPP

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Erreur de connexion à la base de données.']));
        }
    }
    return $pdo;
}
