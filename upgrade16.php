<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

function col_exists($pdo, $table, $col){
    return count($pdo->query("SHOW COLUMNS FROM $table LIKE '$col'")->fetchAll()) > 0;
}

try {
    $pdo = get_db();
    if (!col_exists($pdo,'insumos','precio_referencia')) {
        $pdo->exec("ALTER TABLE insumos ADD COLUMN precio_referencia DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER stock_seguridad");
        echo "Columna precio_referencia agregada a insumos.\n";
    }
    echo "\nMigración completa. Borrá este archivo (upgrade16.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
