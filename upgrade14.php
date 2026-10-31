<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();

    // ---- recetas ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS recetas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        producto_id INT NOT NULL,
        nombre VARCHAR(150) NOT NULL,
        litros_base DECIMAL(10,2) NOT NULL DEFAULT 100,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS receta_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receta_id INT NOT NULL,
        insumo_id INT NOT NULL,
        cantidad DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (receta_id) REFERENCES recetas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tablas de recetas creadas.\n";

    // ---- lotes de insumo (para FIFO) ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS lotes_insumo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        insumo_id INT NOT NULL,
        compra_item_id INT NULL,
        fecha_ingreso DATE NOT NULL,
        cantidad_inicial DECIMAL(10,2) NOT NULL,
        cantidad_disponible DECIMAL(10,2) NOT NULL,
        precio_unitario DECIMAL(10,2) NOT NULL DEFAULT 0,
        origen VARCHAR(150) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (insumo_id) REFERENCES insumos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabla lotes_insumo creada.\n";

    // migrar el stock actual de cada insumo a un lote inicial, para que el FIFO tenga de dónde tomar
    $insumos = $pdo->query("SELECT * FROM insumos")->fetchAll(PDO::FETCH_ASSOC);
    $migrados = 0;
    foreach ($insumos as $ins) {
        if (floatval($ins['stock']) <= 0) continue;
        $chk = $pdo->prepare("SELECT COUNT(*) FROM lotes_insumo WHERE insumo_id=?");
        $chk->execute([$ins['id']]);
        if ($chk->fetchColumn() > 0) continue; // ya tiene lotes, no migrar de nuevo
        $ultPrecio = $pdo->prepare("SELECT precio_unitario FROM compra_items WHERE tipo='insumo' AND insumo_id=? ORDER BY id DESC LIMIT 1");
        $ultPrecio->execute([$ins['id']]);
        $precio = $ultPrecio->fetchColumn() ?: 0;
        $pdo->prepare("INSERT INTO lotes_insumo (insumo_id, fecha_ingreso, cantidad_inicial, cantidad_disponible, precio_unitario, origen) VALUES (?, CURDATE(), ?, ?, ?, 'Stock inicial (migración)')")
            ->execute([$ins['id'], $ins['stock'], $ins['stock'], $precio]);
        $migrados++;
    }
    echo "$migrados insumo(s) con stock existente migrados a un lote inicial.\n";

    // ---- lotes de producción ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS lotes_produccion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero VARCHAR(20) NOT NULL,
        producto_id INT NOT NULL,
        litros_objetivo DECIMAL(10,2) NOT NULL,
        litros_reales DECIMAL(10,2) NULL,
        fecha_coccion DATE NOT NULL,
        fecha_fermentacion DATE NULL,
        fecha_terminado DATE NULL,
        estado ENUM('en_coccion','fermentando','terminado') NOT NULL DEFAULT 'en_coccion',
        notas VARCHAR(255) DEFAULT '',
        usuario VARCHAR(50) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS produccion_consumos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lote_produccion_id INT NOT NULL,
        insumo_id INT NOT NULL,
        lote_insumo_id INT NOT NULL,
        cantidad DECIMAL(10,2) NOT NULL,
        costo DECIMAL(10,2) NOT NULL DEFAULT 0,
        FOREIGN KEY (lote_produccion_id) REFERENCES lotes_produccion(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tablas de lotes de producción creadas.\n";

    // ---- ampliar el kardex para los nuevos tipos de movimiento ----
    $pdo->exec("ALTER TABLE stock_movimientos MODIFY tipo_movimiento ENUM('venta','compra','ajuste','anulacion_venta','anulacion_compra','edicion','produccion_consumo','produccion') NOT NULL");
    echo "Kardex ampliado con tipos de movimiento de producción.\n";

    echo "\nMigración completa. Borrá este archivo (upgrade14.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
