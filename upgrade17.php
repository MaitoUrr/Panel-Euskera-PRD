<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();

    $cambios = [
        "ALTER TABLE insumos MODIFY stock DECIMAL(10,3) NOT NULL DEFAULT 0" => "insumos.stock",
        "ALTER TABLE insumos MODIFY stock_minimo DECIMAL(10,3) NOT NULL DEFAULT 0" => "insumos.stock_minimo",
        "ALTER TABLE insumos MODIFY stock_seguridad DECIMAL(10,3) NOT NULL DEFAULT 0" => "insumos.stock_seguridad",
        "ALTER TABLE receta_items MODIFY cantidad DECIMAL(10,3) NOT NULL" => "receta_items.cantidad",
        "ALTER TABLE lotes_insumo MODIFY cantidad_inicial DECIMAL(10,3) NOT NULL" => "lotes_insumo.cantidad_inicial",
        "ALTER TABLE lotes_insumo MODIFY cantidad_disponible DECIMAL(10,3) NOT NULL" => "lotes_insumo.cantidad_disponible",
        "ALTER TABLE produccion_consumos MODIFY cantidad DECIMAL(10,3) NOT NULL" => "produccion_consumos.cantidad",
        // compartidas con productos/litros — se amplían igual porque no afecta su precisión existente (solo permite más decimales)
        "ALTER TABLE compra_items MODIFY cantidad DECIMAL(10,3) NOT NULL" => "compra_items.cantidad",
        "ALTER TABLE stock_movimientos MODIFY cantidad DECIMAL(10,3) NOT NULL" => "stock_movimientos.cantidad",
        "ALTER TABLE stock_movimientos MODIFY saldo_resultante DECIMAL(10,3) NOT NULL" => "stock_movimientos.saldo_resultante",
    ];

    foreach ($cambios as $sql => $nombre) {
        $pdo->exec($sql);
        echo "Ampliado: $nombre\n";
    }

    echo "\nMigración completa. Los litros (recetas.litros_base, ventas, producción) y barriles quedaron sin tocar, como pediste.\n";
    echo "Borrá este archivo (upgrade17.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
