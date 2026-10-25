<?php

$servidor = "(localdb)\\MSSQLLocalDB";
$baseDatos = "farmacia";

try {
    $pdo = new PDO(
        "sqlsrv:Server=$servidor;Database=$baseDatos;TrustServerCertificate=true",
        null,
        null
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $conexion = $pdo;
} catch (PDOException $e) {
    error_log("Error de conexión SQL Server: " . $e->getMessage());
    http_response_code(500);
    exit("No se pudo conectar a SQL Server. Compruebe que exista la base de datos '$baseDatos' en la instancia (localdb)\\MSSQLLocalDB y que PHP tenga habilitado PDO_SQLSRV.");
}
