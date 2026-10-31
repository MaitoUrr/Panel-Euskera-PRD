<?php
// ------------------------------------------------------------------
// Ejecutar UNA SOLA VEZ visitando esta página desde el navegador
// (ej: https://panel.euskera.com.ar/install.php), y después BORRAR
// este archivo del servidor por seguridad.
// ------------------------------------------------------------------
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(50) UNIQUE NOT NULL,
        nombre VARCHAR(100) NOT NULL,
        password_hash VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS app_data (
        id INT PRIMARY KEY,
        data LONGTEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Datos iniciales vacíos (solo si todavía no existen)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM app_data WHERE id = 1");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $seed = json_encode([
            "barriles" => [],
            "productos" => [],
            "clientes" => [],
            "ventas" => []
        ]);
        $pdo->prepare("INSERT INTO app_data (id, data) VALUES (1, ?)")->execute([$seed]);
        echo "Datos iniciales creados.\n";
    } else {
        echo "La tabla de datos ya existía, no se tocó.\n";
    }

    // Usuarios iniciales — CAMBIÁ estas contraseñas antes o después de instalar.
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

    echo "\nInstalación completa. Ahora:\n";
    echo "1) Entrá a index.html e iniciá sesión.\n";
    echo "2) Borrá este archivo (install.php) del servidor.\n";

} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Revisá los datos en config.php (host, base, usuario, contraseña).\n";
}
