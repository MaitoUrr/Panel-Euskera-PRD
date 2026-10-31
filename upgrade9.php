<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

function col_exists($pdo, $table, $col){
    return count($pdo->query("SHOW COLUMNS FROM $table LIKE '$col'")->fetchAll()) > 0;
}

try {
    $pdo = get_db();

    // ---- tipos de insumo ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS tipos_insumo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(50) UNIQUE NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['Productivo','Repuesto','Equipamiento'] as $nombre) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM tipos_insumo WHERE nombre=?");
        $chk->execute([$nombre]);
        if ($chk->fetchColumn() == 0) {
            $pdo->prepare("INSERT INTO tipos_insumo (nombre) VALUES (?)")->execute([$nombre]);
            echo "Tipo de insumo creado: $nombre\n";
        }
    }

    if (!col_exists($pdo,'insumos','tipo_id')) {
        $pdo->exec("ALTER TABLE insumos ADD COLUMN tipo_id INT NULL AFTER nombre");
        echo "Columna tipo_id agregada a insumos.\n";
    }
    if (!col_exists($pdo,'insumos','stock_seguridad')) {
        $pdo->exec("ALTER TABLE insumos ADD COLUMN stock_seguridad DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER stock_minimo");
        echo "Columna stock_seguridad agregada a insumos.\n";
    }

    // ---- mail en clientes y proveedores ----
    if (!col_exists($pdo,'clientes','email')) {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN email VARCHAR(150) DEFAULT '' AFTER telefono");
        echo "Columna email agregada a clientes.\n";
    }
    if (!col_exists($pdo,'proveedores','email')) {
        $pdo->exec("ALTER TABLE proveedores ADD COLUMN email VARCHAR(150) DEFAULT '' AFTER telefono");
        echo "Columna email agregada a proveedores.\n";
    }

    // ---- contactos (uno a varios) ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS cliente_contactos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        nombre VARCHAR(150) DEFAULT '',
        telefono VARCHAR(50) DEFAULT '',
        mail VARCHAR(150) DEFAULT '',
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS proveedor_contactos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proveedor_id INT NOT NULL,
        nombre VARCHAR(150) DEFAULT '',
        telefono VARCHAR(50) DEFAULT '',
        mail VARCHAR(150) DEFAULT '',
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tablas de contactos creadas.\n";

    echo "\nMigración completa. Borrá este archivo (upgrade9.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
