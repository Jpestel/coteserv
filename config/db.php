<?php
// config/db.php
// Connexion à la base de données via PDO

// Paramètres de connexion
define('DB_HOST', 'localhost');
define('DB_PORT', '8888');
define('DB_NAME', 'coteservdb');
define('DB_USER', 'root');
define('DB_PASS', 'root');

try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // En cas d'erreur, arrêter l'exécution et afficher un message (à adapter en prod)
    die('Erreur de connexion à la base de données : ' . $e->getMessage());
}
