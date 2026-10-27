<?php
$archivoEntorno = __DIR__ . '/.env';
$configuracion = [];

if (is_file($archivoEntorno)) {
    $configuracion = parse_ini_file($archivoEntorno, false, INI_SCANNER_RAW);
    if ($configuracion === false) {
        throw new RuntimeException('No se pudo leer el archivo .env de la aplicación.');
    }
}

$obtenerConfiguracion = static function (string $clave) use ($configuracion): ?string {
    $valor = getenv($clave);
    if ($valor === false) {
        $valor = $configuracion[$clave] ?? null;
    }

    return $valor === null ? null : trim((string) $valor);
};

$servidor = $obtenerConfiguracion('DB_SERVER');
$baseDatos = $obtenerConfiguracion('DB_NAME');
$usuario = $obtenerConfiguracion('DB_USERNAME') ?? '';
$contrasena = $obtenerConfiguracion('DB_PASSWORD') ?? '';

if ($servidor === null || $servidor === '' || $baseDatos === null || $baseDatos === '') {
    throw new RuntimeException('Configure DB_SERVER y DB_NAME en el archivo .env o en las variables de entorno.');
}

if (($usuario === '') !== ($contrasena === '')) {
    throw new RuntimeException('Configure DB_USERNAME y DB_PASSWORD juntos, o deje ambos vacíos para usar autenticación integrada.');
}

try {
    $pdo = new PDO(
        "sqlsrv:Server=$servidor;Database=$baseDatos;TrustServerCertificate=true",
        $usuario !== '' ? $usuario : null,
        $contrasena !== '' ? $contrasena : null
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $conexion = $pdo;

} catch (PDOException $e) {
    error_log("Error de conexión SQL Server: " . $e->getMessage());

    http_response_code(500);

    exit(
        "Error real de conexión SQL Server: "
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
    );
}

