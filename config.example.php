<?php
// ------------------------------------------------------------------
// Datos de conexión a la base de datos MySQL de tu hosting.
// Los completás con lo que te dé el panel de tu hosting (cPanel > MySQL Databases).
// ------------------------------------------------------------------

define('DB_HOST', 'localhost');           // casi siempre "localhost"
define('DB_NAME', 'c2061780_panel');       // tu base de datos en DonWeb
define('DB_USER', 'c2061780_panel');       // tu usuario MySQL en DonWeb
define('DB_PASS', 'TU_CLAVE_AQUI');   // la contraseña que le pusiste a ese usuario al crearlo

function get_db(){
    static $pdo = null;
    if($pdo === null){
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}
