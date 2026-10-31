<?php
// Ejecutar UNA VEZ y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

function col_exists($pdo, $table, $col){
    return count($pdo->query("SHOW COLUMNS FROM $table LIKE '$col'")->fetchAll()) > 0;
}

try {
    $pdo = get_db();
    $nuevas = [
        'direccion' => "VARCHAR(200) DEFAULT ''",
        'localidad' => "VARCHAR(100) DEFAULT ''",
        'provincia' => "VARCHAR(100) DEFAULT ''",
        'cp' => "VARCHAR(15) DEFAULT ''",
    ];
    foreach ($nuevas as $col => $def) {
        if (!col_exists($pdo, 'proveedores', $col)) {
            $pdo->exec("ALTER TABLE proveedores ADD COLUMN $col $def");
            echo "Columna $col agregada a proveedores.\n";
        } else {
            echo "La columna $col ya existía, no se tocó.\n";
        }
    }
    echo "\nMigración completa. Borrá este archivo (upgrade5.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
