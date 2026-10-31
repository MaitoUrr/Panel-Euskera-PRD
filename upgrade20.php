<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS categorias_gasto (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(80) UNIQUE NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach (['Impuestos','Servicios (Agua/Luz/Gas)','Alquiler','Repuestos y Mantenimiento','Sueldos','Seguros','Otros'] as $nombre) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM categorias_gasto WHERE nombre=?");
        $chk->execute([$nombre]);
        if ($chk->fetchColumn() == 0) {
            $pdo->prepare("INSERT INTO categorias_gasto (nombre) VALUES (?)")->execute([$nombre]);
            echo "Categoría creada: $nombre\n";
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS gastos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL,
        categoria_id INT NULL,
        concepto VARCHAR(200) NOT NULL,
        proveedor_id INT NULL,
        proveedor_nombre VARCHAR(150) DEFAULT '',
        monto DECIMAL(10,2) NOT NULL,
        medio_pago VARCHAR(30) NOT NULL,
        usuario VARCHAR(50) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (categoria_id) REFERENCES categorias_gasto(id) ON DELETE SET NULL,
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabla gastos creada.\n";

    echo "\nMigración completa. Borrá este archivo (upgrade20.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
