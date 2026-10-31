<?php
// Ejecutar UNA VEZ y borrar después.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();
    $cols = $pdo->query("SHOW COLUMNS FROM clientes LIKE 'cp'")->fetchAll();
    if (count($cols) === 0) {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN cp VARCHAR(15) DEFAULT '' AFTER provincia");
        echo "Columna 'cp' agregada a clientes.\n";
    } else {
        echo "La columna 'cp' ya existía, no se tocó.\n";
    }
    echo "Listo. Borrá este archivo (add_cp.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
