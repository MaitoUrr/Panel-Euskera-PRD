<?php
// ------------------------------------------------------------------
// Ejecutar UNA SOLA VEZ visitando esta página desde el navegador
// (ej: https://panel.euskera.com.ar/upgrade.php), y después BORRAR
// este archivo del servidor.
//
// Este script reemplaza el modelo viejo (todo en un JSON) por tablas
// relacionales de verdad. Los datos que estaban cargados en el JSON
// viejo NO se migran (se empieza de cero según lo charlado).
// ------------------------------------------------------------------
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(50) UNIQUE NOT NULL,
        nombre VARCHAR(100) NOT NULL,
        password_hash VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        razon_social VARCHAR(150) DEFAULT '',
        cuit VARCHAR(20) DEFAULT '',
        direccion VARCHAR(200) DEFAULT '',
        localidad VARCHAR(100) DEFAULT '',
        provincia VARCHAR(100) DEFAULT '',
        telefono VARCHAR(50) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS proveedores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        cuit VARCHAR(20) DEFAULT '',
        contacto VARCHAR(150) DEFAULT '',
        telefono VARCHAR(50) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS insumos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        unidad VARCHAR(20) NOT NULL DEFAULT 'unidad',
        stock DECIMAL(10,2) NOT NULL DEFAULT 0,
        stock_minimo DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS productos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        tipo ENUM('envase','recarga','merch') NOT NULL DEFAULT 'merch',
        precio DECIMAL(10,2) NOT NULL DEFAULT 0,
        stock INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS barriles (
        id VARCHAR(20) PRIMARY KEY,
        litros INT NOT NULL,
        estado ENUM('planta','entregado') NOT NULL DEFAULT 'planta',
        cliente_id INT NULL,
        fecha_entrega DATE NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS barril_movimientos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barril_id VARCHAR(20) NOT NULL,
        accion ENUM('entregado','devuelto') NOT NULL,
        cliente_id INT NULL,
        cliente_nombre VARCHAR(150) DEFAULT '',
        fecha DATE NOT NULL,
        usuario VARCHAR(50) DEFAULT '',
        FOREIGN KEY (barril_id) REFERENCES barriles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ventas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL,
        tipo ENUM('producto','barril') NOT NULL,
        producto_id INT NULL,
        barril_id VARCHAR(20) NULL,
        item_nombre VARCHAR(150) NOT NULL,
        cantidad INT NOT NULL,
        precio_unit DECIMAL(10,2) NOT NULL,
        cliente_id INT NULL,
        cliente_nombre VARCHAR(150) NOT NULL DEFAULT 'Consumidor final',
        medio_pago VARCHAR(30) NOT NULL,
        usuario VARCHAR(50) DEFAULT '',
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL,
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS compras (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL,
        proveedor_id INT NULL,
        proveedor_nombre VARCHAR(150) DEFAULT '',
        insumo_id INT NULL,
        insumo_nombre VARCHAR(150) DEFAULT '',
        cantidad DECIMAL(10,2) NOT NULL,
        unidad VARCHAR(20) DEFAULT '',
        monto_total DECIMAL(10,2) NOT NULL,
        medio_pago VARCHAR(30) NOT NULL,
        usuario VARCHAR(50) DEFAULT '',
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL,
        FOREIGN KEY (insumo_id) REFERENCES insumos(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

    // Se descarta el modelo viejo (todo-en-un-JSON), según lo charlado.
    $pdo->exec("DROP TABLE IF EXISTS app_data");
    echo "Tablas nuevas creadas. Tabla vieja (app_data) eliminada.\n";

    // Usuarios: se preservan si ya existen, se crean si no.
    $usuariosIniciales = [
        ['usuario' => 'gabriel', 'nombre' => 'Gabriel', 'pass' => 'euskera2026'],
        ['usuario' => 'mariano', 'nombre' => 'Mariano', 'pass' => 'euskera2026'],
    ];
    foreach ($usuariosIniciales as $u) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario = ?");
        $check->execute([$u['usuario']]);
        if ($check->fetchColumn() == 0) {
            $hash = password_hash($u['pass'], PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO usuarios (usuario, nombre, password_hash) VALUES (?, ?, ?)")
                ->execute([$u['usuario'], $u['nombre'], $hash]);
            echo "Usuario creado: {$u['usuario']} (contraseña inicial: {$u['pass']})\n";
        } else {
            echo "El usuario {$u['usuario']} ya existía, no se tocó.\n";
        }
    }

    echo "\nActualización completa. Ahora:\n";
    echo "1) Entrá a index.html e iniciá sesión.\n";
    echo "2) Borrá este archivo (upgrade.php) del servidor.\n";
    echo "3) Vas a tener que volver a cargar barriles, productos, clientes y ventas (se empezó de cero).\n";

} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
