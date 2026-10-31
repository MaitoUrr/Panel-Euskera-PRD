<?php
// Ejecutar UNA VEZ y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS remito_barriles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        remito_id INT NOT NULL,
        barril_id VARCHAR(20) NOT NULL,
        FOREIGN KEY (remito_id) REFERENCES remitos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabla remito_barriles creada.\n";
    echo "Migración completa. Borrá este archivo (upgrade4.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
