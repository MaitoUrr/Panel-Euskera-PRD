<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

function col_exists($pdo, $table, $col){
    return count($pdo->query("SHOW COLUMNS FROM $table LIKE '$col'")->fetchAll()) > 0;
}

try {
    $pdo = get_db();
    $pdo->exec("ALTER TABLE lotes_produccion MODIFY estado ENUM('en_coccion','fermentando','madurando','terminado') NOT NULL DEFAULT 'en_coccion'");
    echo "Estado de lotes_produccion ampliado con 'madurando'.\n";
    if (!col_exists($pdo,'lotes_produccion','fecha_maduracion')) {
        $pdo->exec("ALTER TABLE lotes_produccion ADD COLUMN fecha_maduracion DATE NULL AFTER fecha_fermentacion");
        echo "Columna fecha_maduracion agregada.\n";
    }
    echo "\nMigración completa. Borrá este archivo (upgrade15.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
