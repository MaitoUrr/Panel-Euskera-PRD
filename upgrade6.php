<?php
// Ejecutar UNA VEZ y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

function col_exists($pdo, $table, $col){
    return count($pdo->query("SHOW COLUMNS FROM $table LIKE '$col'")->fetchAll()) > 0;
}

try {
    $pdo = get_db();
    if (!col_exists($pdo, 'compras', 'producto_id')) {
        $pdo->exec("ALTER TABLE compras ADD COLUMN producto_id INT NULL AFTER insumo_nombre");
        echo "Columna producto_id agregada a compras.\n";
    }
    if (!col_exists($pdo, 'compras', 'producto_nombre')) {
        $pdo->exec("ALTER TABLE compras ADD COLUMN producto_nombre VARCHAR(150) DEFAULT '' AFTER producto_id");
        echo "Columna producto_nombre agregada a compras.\n";
    }
    echo "\nMigración completa. Borrá este archivo (upgrade6.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
