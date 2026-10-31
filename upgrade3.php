<?php
// Ejecutar UNA VEZ y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

function col_exists($pdo, $table, $col){
    return count($pdo->query("SHOW COLUMNS FROM $table LIKE '$col'")->fetchAll()) > 0;
}

try {
    $pdo = get_db();

    if (!col_exists($pdo,'remitos','tipo_venta')) {
        $pdo->exec("ALTER TABLE remitos ADD COLUMN tipo_venta ENUM('granel','recarga','varios') NOT NULL DEFAULT 'varios' AFTER cliente_nombre");
        echo "Columna tipo_venta agregada a remitos.\n";
    }
    if (!col_exists($pdo,'remitos','barril_id')) {
        $pdo->exec("ALTER TABLE remitos ADD COLUMN barril_id VARCHAR(20) NULL AFTER tipo_venta");
        echo "Columna barril_id agregada a remitos.\n";
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS medios_pago (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(50) UNIQUE NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $default = ['Efectivo','Transferencia','MercadoPago','Tarjeta'];
    foreach ($default as $nombre) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM medios_pago WHERE nombre=?");
        $chk->execute([$nombre]);
        if ($chk->fetchColumn() == 0) {
            $pdo->prepare("INSERT INTO medios_pago (nombre) VALUES (?)")->execute([$nombre]);
            echo "Medio de pago creado: $nombre\n";
        }
    }

    echo "\nMigración completa. Borrá este archivo (upgrade3.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
