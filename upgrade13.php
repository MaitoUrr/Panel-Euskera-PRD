<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_movimientos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_tipo ENUM('producto','insumo') NOT NULL,
        item_id INT NOT NULL,
        fecha DATE NOT NULL,
        tipo_movimiento ENUM('venta','compra','ajuste','anulacion_venta','anulacion_compra','edicion') NOT NULL,
        cantidad DECIMAL(10,2) NOT NULL,
        saldo_resultante DECIMAL(10,2) NOT NULL,
        referencia VARCHAR(150) DEFAULT '',
        usuario VARCHAR(50) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_item (item_tipo, item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabla stock_movimientos creada.\n";
    echo "\nMigración completa. Borrá este archivo (upgrade13.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
