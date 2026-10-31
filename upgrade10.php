<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

function col_exists($pdo, $table, $col){
    return count($pdo->query("SHOW COLUMNS FROM $table LIKE '$col'")->fetchAll()) > 0;
}

try {
    $pdo = get_db();

    // ---- normalizar la unidad de insumos a los 3 valores estándar ----
    $pdo->exec("UPDATE insumos SET unidad='kilogramos' WHERE LOWER(unidad) IN ('kg','kilo','kilos','kilogramo','kilogramos')");
    $pdo->exec("UPDATE insumos SET unidad='litros' WHERE LOWER(unidad) IN ('l','lt','lts','litro','litros')");
    $pdo->exec("UPDATE insumos SET unidad='unidades' WHERE unidad NOT IN ('kilogramos','litros')");
    $pdo->exec("ALTER TABLE insumos MODIFY unidad ENUM('kilogramos','litros','unidades') NOT NULL DEFAULT 'unidades'");
    echo "Unidad de insumos estandarizada (kilogramos / litros / unidades).\n";

    // ---- productos: agregar kilogramos como opción y sumar stock mínimo/seguridad ----
    $pdo->exec("ALTER TABLE productos MODIFY unidad_medida ENUM('litros','unidades','kilogramos') NOT NULL DEFAULT 'unidades'");
    if (!col_exists($pdo,'productos','stock_minimo')) {
        $pdo->exec("ALTER TABLE productos ADD COLUMN stock_minimo DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER stock");
        echo "Columna stock_minimo agregada a productos.\n";
    }
    if (!col_exists($pdo,'productos','stock_seguridad')) {
        $pdo->exec("ALTER TABLE productos ADD COLUMN stock_seguridad DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER stock_minimo");
        echo "Columna stock_seguridad agregada a productos.\n";
    }

    echo "\nMigración completa. Borrá este archivo (upgrade10.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
