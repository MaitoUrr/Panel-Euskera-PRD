<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS ajustes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero_secuencial INT NOT NULL,
        numero VARCHAR(20) NOT NULL UNIQUE,
        fecha DATE NOT NULL,
        concepto VARCHAR(150) NOT NULL,
        usuario VARCHAR(50) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ajuste_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ajuste_id INT NOT NULL,
        item_tipo ENUM('producto','insumo') NOT NULL,
        item_id INT NOT NULL,
        item_nombre VARCHAR(150) NOT NULL,
        cantidad DECIMAL(10,3) NOT NULL,
        saldo_resultante DECIMAL(10,3) NOT NULL,
        FOREIGN KEY (ajuste_id) REFERENCES ajustes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "Tablas ajustes y ajuste_items creadas.\n";
    echo "\nMigración completa. Borrá este archivo (upgrade18.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
