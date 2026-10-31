<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

function col_exists($pdo, $table, $col){
    $r = $pdo->query("SHOW COLUMNS FROM $table LIKE '$col'")->fetchAll();
    return count($r) > 0;
}

try {
    $pdo = get_db();

    // ---- productos: código, unidad de medida, tipo "granel" ----
    if (!col_exists($pdo,'productos','cod_prod')) {
        $pdo->exec("ALTER TABLE productos ADD COLUMN cod_prod VARCHAR(20) DEFAULT '' AFTER id");
        echo "Columna cod_prod agregada a productos.\n";
    }
    if (!col_exists($pdo,'productos','unidad_medida')) {
        $pdo->exec("ALTER TABLE productos ADD COLUMN unidad_medida ENUM('litros','unidades') NOT NULL DEFAULT 'unidades' AFTER tipo");
        echo "Columna unidad_medida agregada a productos.\n";
    }
    $pdo->exec("ALTER TABLE productos MODIFY tipo ENUM('granel','envase','recarga','merch') NOT NULL DEFAULT 'merch'");
    echo "Tipos de producto actualizados (ahora incluye 'granel').\n";

    // ---- barriles: descripción ----
    if (!col_exists($pdo,'barriles','descripcion')) {
        $pdo->exec("ALTER TABLE barriles ADD COLUMN descripcion VARCHAR(30) DEFAULT '' AFTER litros");
        echo "Columna descripcion agregada a barriles.\n";
    }

    // ---- remitos (cabecera) ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS remitos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero VARCHAR(20) NOT NULL UNIQUE,
        punto_venta INT NOT NULL DEFAULT 1,
        numero_secuencial INT NOT NULL,
        fecha DATE NOT NULL,
        cliente_id INT NULL,
        cliente_nombre VARCHAR(150) NOT NULL DEFAULT 'Consumidor final',
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        medio_pago VARCHAR(30) NOT NULL,
        usuario VARCHAR(50) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ---- remito_items (renglones) ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS remito_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        remito_id INT NOT NULL,
        tipo ENUM('producto','barril') NOT NULL,
        producto_id INT NULL,
        barril_id VARCHAR(20) NULL,
        descripcion VARCHAR(150) NOT NULL,
        cantidad DECIMAL(10,2) NOT NULL,
        precio_unit DECIMAL(10,2) NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (remito_id) REFERENCES remitos(id) ON DELETE CASCADE,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ---- se descarta la tabla vieja de ventas de renglón único ----
    $pdo->exec("DROP TABLE IF EXISTS ventas");
    echo "Tabla vieja 'ventas' (renglón único) eliminada — reemplazada por remitos + remito_items.\n";

    echo "\nMigración completa. Ahora:\n";
    echo "1) Entrá a index.html y revisá que todo cargue bien.\n";
    echo "2) Borrá este archivo (upgrade2.php) del servidor.\n";
    echo "3) Repasá tus productos existentes y completales el código y la unidad de medida.\n";

} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
