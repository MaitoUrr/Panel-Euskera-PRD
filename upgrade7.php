<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();

    // Renombramos la tabla vieja para no perder el historial
    $pdo->exec("RENAME TABLE compras TO compras_legacy");
    echo "Tabla compras renombrada a compras_legacy (queda como respaldo).\n";

    $pdo->exec("CREATE TABLE compras (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL,
        proveedor_id INT NULL,
        proveedor_nombre VARCHAR(150) DEFAULT '',
        numero_documento VARCHAR(50) DEFAULT '',
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        medio_pago VARCHAR(30) NOT NULL,
        usuario VARCHAR(50) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE compra_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        compra_id INT NOT NULL,
        tipo ENUM('insumo','producto') NOT NULL,
        insumo_id INT NULL,
        producto_id INT NULL,
        descripcion VARCHAR(150) NOT NULL,
        cantidad DECIMAL(10,2) NOT NULL,
        unidad VARCHAR(20) DEFAULT '',
        precio_unitario DECIMAL(10,2) NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tablas compras y compra_items creadas.\n";

    // Migramos los datos viejos (una línea por cada compra anterior)
    $legacy = $pdo->query("SELECT * FROM compras_legacy")->fetchAll(PDO::FETCH_ASSOC);
    $migradas = 0;
    foreach ($legacy as $c) {
        $cantidad = floatval($c['cantidad']);
        $total = floatval($c['monto_total']);
        $precioUnit = $cantidad > 0 ? round($total / $cantidad, 2) : $total;

        $pdo->prepare("INSERT INTO compras (fecha, proveedor_id, proveedor_nombre, numero_documento, total, medio_pago, usuario)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([$c['fecha'], $c['proveedor_id'], $c['proveedor_nombre'], '', $total, $c['medio_pago'], $c['usuario']]);
        $compraId = $pdo->lastInsertId();

        $tipo = !empty($c['producto_id']) ? 'producto' : 'insumo';
        $desc = $c['insumo_nombre'] ?: ($c['producto_nombre'] ?: 'Ítem sin especificar');

        $pdo->prepare("INSERT INTO compra_items (compra_id, tipo, insumo_id, producto_id, descripcion, cantidad, unidad, precio_unitario, subtotal)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$compraId, $tipo, $c['insumo_id'], $c['producto_id'] ?? null, $desc, $cantidad, $c['unidad'], $precioUnit, $total]);
        $migradas++;
    }
    echo "$migradas compra(s) migrada(s) al nuevo formato.\n";

    echo "\nMigración completa. Borrá este archivo (upgrade7.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
