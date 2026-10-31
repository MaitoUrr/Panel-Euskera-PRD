<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();
    $pdo->exec("ALTER TABLE produccion_consumos MODIFY lote_insumo_id INT NULL");
    echo "produccion_consumos.lote_insumo_id ahora permite NULL (para consumos sin lote de compra específico).\n";
    echo "\nMigración completa. Borrá este archivo (upgrade19.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
