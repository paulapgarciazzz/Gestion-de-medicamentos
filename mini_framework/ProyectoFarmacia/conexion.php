
<?php

// Buscar el archivo .env en las ubicaciones posibles.
$posiblesRutas = [
    __DIR__ . '/.env',
    dirname(__DIR__) . '/.env',
    dirname(__DIR__, 2) . '/.env',
];

$archivoEntorno = null;

foreach ($posiblesRutas as $ruta) {
    if (is_file($ruta)) {
        $archivoEntorno = $ruta;
        break;
    }
}

// Leer configuración.
$configuracion = [];

if ($archivoEntorno !== null) {
    $configuracion = parse_ini_file(
        $archivoEntorno,
        false,
        INI_SCANNER_RAW
    );

    if ($configuracion === false) {
        throw new RuntimeException(
            'No se pudo leer el archivo .env: ' . $archivoEntorno
        );
    }
}

// Obtener una variable del entorno o del archivo .env.
$obtenerConfiguracion = static function (
    string $clave
) use (&$configuracion): ?string {
    $valor = getenv($clave);

    if ($valor === false) {
        $valor = $_ENV[$clave] ?? $_SERVER[$clave] ?? null;
    }

    if ($valor === null || trim((string) $valor) === '') {
        $valor = $configuracion[$clave] ?? $valor;
    }

    return $valor === null
        ? null
        : trim((string) $valor);
};

// Leer los datos de conexión.
$servidor = $obtenerConfiguracion('DB_SERVER');

$baseDatos = $obtenerConfiguracion('DB_NAME');

if ($baseDatos === null || $baseDatos === '') {
    $baseDatos = $obtenerConfiguracion('DB_DATABASE');
}

$usuario = $obtenerConfiguracion('DB_USERNAME') ?? '';
$contrasena = $obtenerConfiguracion('DB_PASSWORD') ?? '';

$certificado = $obtenerConfiguracion(
    'DB_TRUST_SERVER_CERTIFICATE'
) ?? 'true';

// Validar configuración.
if ($servidor === null || $servidor === ''
    || $baseDatos === null || $baseDatos === '') {

    throw new RuntimeException(
        'No se encontraron DB_SERVER y DB_NAME (o DB_DATABASE). '
        . 'Archivo .env buscado en: '
        . implode(' | ', $posiblesRutas)
    );
}

if (($usuario === '') !== ($contrasena === '')) {
    throw new RuntimeException(
        'Configure DB_USERNAME y DB_PASSWORD juntos, '
        . 'o deje ambos vacíos para autenticación integrada.'
    );
}

// Conectar con SQL Server.
try {
    $cadenaConexion = sprintf(
        'sqlsrv:Server=%s;Database=%s;TrustServerCertificate=%s',
        $servidor,
        $baseDatos,
        $certificado
    );

    $pdo = new PDO(
        $cadenaConexion,
        $usuario !== '' ? $usuario : null,
        $contrasena !== '' ? $contrasena : null
    );

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $pdo->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

    // Compatibilidad con archivos que usan ambos nombres.
    $conexion = $pdo;

} catch (PDOException $e) {
    error_log(
        'Error de conexión SQL Server: ' . $e->getMessage()
    );

    http_response_code(500);

    exit(
        'No se pudo conectar a SQL Server. '
        . 'Revise el registro de errores de Apache.'
    );
}
