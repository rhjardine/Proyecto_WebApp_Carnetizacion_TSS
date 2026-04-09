<?php
// Datos de configuración de la base de datos
$host = 'localhost';
$port = '5432';
$dbname = 'prueba';
$user = 'postgres';
$password = 'k1ab30315';

try {
    // Crear cadena de conexión DSN (Data Source Name)
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    
    // Crear la instancia de PDO
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    if ($pdo) {
         echo "Conectado a la base de datos con éxito!";
    }
} catch (PDOException $e) {
    // Manejo de errores de conexión
    echo "Error de conexión: " . $e->getMessage();
}
?>