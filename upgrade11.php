<?php
// Ejecutar UNA VEZ visitando esta página, y después BORRAR el archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

function col_exists($pdo, $table, $col){
    return count($pdo->query("SHOW COLUMNS FROM $table LIKE '$col'")->fetchAll()) > 0;
}

try {
    $pdo = get_db();

    if (!col_exists($pdo,'remitos','pagado')) {
        $pdo->exec("ALTER TABLE remitos ADD COLUMN pagado TINYINT(1) NOT NULL DEFAULT 1 AFTER medio_pago");
        echo "Columna pagado agregada a remitos.\n";
    }
    if (!col_exists($pdo,'remitos','medio_cobro')) {
        $pdo->exec("ALTER TABLE remitos ADD COLUMN medio_cobro VARCHAR(30) NULL AFTER pagado");
        echo "Columna medio_cobro agregada a remitos.\n";
    }
    if (!col_exists($pdo,'remitos','fecha_cobro')) {
        $pdo->exec("ALTER TABLE remitos ADD COLUMN fecha_cobro DATE NULL AFTER medio_cobro");
        echo "Columna fecha_cobro agregada a remitos.\n";
    }

    // los remitos ya cargados como "Cuenta Corriente" pasan a quedar pendientes de cobro
    $pdo->exec("UPDATE remitos SET pagado=0 WHERE medio_pago='Cuenta Corriente' AND pagado=1");
    echo "Remitos existentes de Cuenta Corriente marcados como pendientes.\n";

    // aseguramos que exista el medio de pago "Cuenta Corriente"
    $chk = $pdo->prepare("SELECT COUNT(*) FROM medios_pago WHERE nombre=?");
    $chk->execute(['Cuenta Corriente']);
    if ($chk->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO medios_pago (nombre) VALUES (?)")->execute(['Cuenta Corriente']);
        echo "Medio de pago 'Cuenta Corriente' creado.\n";
    }

    echo "\nMigración completa. Borrá este archivo (upgrade11.php) del servidor.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
