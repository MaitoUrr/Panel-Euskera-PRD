<?php
// Ejecutar UNA VEZ y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();
    $pdo->exec("ALTER TABLE productos MODIFY stock DECIMAL(10,2) NOT NULL DEFAULT 0");
    echo "Columna stock de productos pasada a decimal (ya no se pierden los litros fraccionados).\n";
    echo "\nMigración completa. Borrá este archivo (upgrade8.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
