<?php
/*
 * lib.php - Funciones compartidas de los módulos nuevos:
 * sesión y permisos, auditoría, diseño de página, paginación y cálculos de stock.
 * NO modifica ningún archivo existente del proyecto.
 */
require_once __DIR__ . '/conexion.php';
date_default_timezone_set('America/Costa_Rica');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

class ErrorNegocio extends Exception {}

/* Respaldo por si la extensión mbstring no está habilitada en PHP. */
if (!function_exists('mb_strlen')) {
    function mb_strlen($s) { return preg_match_all('/./su', (string)$s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $ini, $len = null) { return implode('', array_slice(preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY), $ini, $len)); }
}

const ROLES          = ['Administrador', 'Farmaceutico', 'Vendedor'];
const ROLES_GESTION  = ['Administrador', 'Farmaceutico'];
const ROLES_ADMIN    = ['Administrador'];
const METODOS_PAGO   = ['Efectivo', 'Tarjeta', 'SINPE Móvil', 'Transferencia'];
const CERRAR_MODAL   = "document.getElementById('modal-contenido').innerHTML=''";

/* ------------------------------ Utilidades ------------------------------ */
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function dinero($v)  { return '₡' . number_format((float)$v, 2, ',', '.'); }
function fecha_fmt($v, $conHora = false) {
    if (!$v) return '';
    $t = strtotime((string)$v);
    return $t ? date($conHora ? 'd/m/Y H:i' : 'd/m/Y', $t) : h($v);
}
function fecha_valida($s) {
    $d = DateTime::createFromFormat('!Y-m-d', (string)$s);
    return $d && $d->format('Y-m-d') === $s;
}
function fallo($mensaje, $status = 422) {
    http_response_code($status);
    exit('<div class="notification is-danger">' . h($mensaje) . '</div>');
}
function filas($sql, $params = []) {
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}
function fila($sql, $params = []) {
    $r = filas($sql, $params);
    return $r[0] ?? null;
}
function escalar($sql, $params = []) {
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchColumn();
}
/* Expresión SQL con el nombre completo de un cliente (alias de tabla opcional). */
function sql_nombre_cliente($a = 'c') {
    $p = $a === '' ? '' : $a . '.';
    return "LTRIM(RTRIM(CONCAT({$p}primer_nombre,' ',ISNULL({$p}segundo_nombre+' ',''),{$p}primer_apellido,' ',ISNULL({$p}segundo_apellido,''))))";
}
function sql_nombre_usuario($a = 'u') {
    $p = $a === '' ? '' : $a . '.';
    return "LTRIM(RTRIM(CONCAT({$p}nombre,' ',{$p}primer_apellido,' ',ISNULL({$p}segundo_apellido,''))))";
}

/* ------------------------------ Sesión y permisos ------------------------------ */
function usuario_actual() { return $_SESSION['usuario'] ?? null; }

function requerir_login(array $roles = []) {
    $u = usuario_actual();
    $esHtmx = !empty($_SERVER['HTTP_HX_REQUEST']);
    if (!$u) {
        if ($esHtmx) { header('HX-Redirect: login.php'); exit; }
        header('Location: login.php');
        exit;
    }
    if ($roles && !in_array($u['rol'], $roles, true)) {
        http_response_code(403);
        if ($esHtmx) exit('<div class="notification is-danger">No tiene permiso para esta acción.</div>');
        pagina_inicio('Sin permiso', '');
        echo '<div class="notification is-danger">Su rol (' . h($u['rol']) . ') no tiene permiso para entrar a esta sección.</div><a class="button is-info" href="inicio.php">Volver al inicio</a>';
        pagina_fin();
        exit;
    }
}
function tiene_rol(array $roles) { $u = usuario_actual(); return $u && in_array($u['rol'], $roles, true); }

/* ------------------------------ Contraseñas ------------------------------
 * La columna usuario.contrasenna del script original es VARCHAR(8): solo cabe
 * texto plano. Si ejecuta mejoras_opcionales.sql (la amplía), el sistema pasa
 * automáticamente a guardar contraseñas con hash seguro.
 */
function contrasenna_permite_hash() {
    static $r = null;
    if ($r === null) {
        $n = (int)escalar("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='usuario' AND COLUMN_NAME='contrasenna'");
        $r = ($n === -1 || $n >= 60);
    }
    return $r;
}
function contrasenna_preparar($p) { return contrasenna_permite_hash() ? password_hash($p, PASSWORD_DEFAULT) : $p; }
function contrasenna_validar_formato($p) {
    if (contrasenna_permite_hash()) return mb_strlen($p) < 6 ? 'La contraseña debe tener al menos 6 caracteres.' : null;
    $n = mb_strlen($p);
    return ($n < 4 || $n > 8) ? 'La contraseña debe tener entre 4 y 8 caracteres (límite de la columna; vea mejoras_opcionales.sql).' : null;
}
function contrasenna_verificar($p, $guardada) {
    $guardada = (string)$guardada;
    if (strncmp($guardada, '$2y$', 4) === 0 || strncmp($guardada, '$argon', 6) === 0) return password_verify($p, $guardada);
    return hash_equals($guardada, (string)$p);
}

/* ------------------------------ Auditoría ------------------------------ */
function auditar($tabla, $idRegistro, $accion) {
    global $pdo;
    $u = usuario_actual();
    if (!$u) return;
    try {
        $pdo->prepare('INSERT INTO dbo.auditoria (id_usuario,id_registro_afectado,tabla_afectada,accion) VALUES (?,?,?,?)')
            ->execute([$u['id'], (int)$idRegistro, $tabla, $accion]);
    } catch (PDOException $e) {
        error_log('Auditoría: ' . $e->getMessage());
    }
}

/* ------------------------------ Respuestas HTMX ------------------------------ */
function hx_eventos(array $eventos) { header('HX-Trigger: ' . json_encode($eventos)); }
function respuesta_ok($texto) {
    hx_eventos(['crudGuardado' => ['texto' => $texto], 'aviso' => ['texto' => $texto]]);
    echo '<div class="notification is-success">' . h($texto) . '</div>';
}

/* ------------------------------ Stock ------------------------------
 * Stock disponible de un lote = cantidad_inicial - unidades vendidas en
 * ventas NO anuladas. Anular una venta devuelve el stock automáticamente.
 */
function sql_stock_apply($alias = 'dl') {
    return "OUTER APPLY (SELECT {$alias}.cantidad_inicial - ISNULL(SUM(dv.cantidad),0) AS disponible
            FROM dbo.detalle_venta dv INNER JOIN dbo.venta v ON v.id_venta=dv.id_venta
            WHERE dv.id_detalle_lote={$alias}.id_detalle_lote AND v.estado<>'Anulada') st";
}
function sql_lote_estado_vendible($alias = 'dl') {
    return "UPPER(LTRIM(RTRIM({$alias}.estado))) IN ('ACTIVO','DISPONIBLE','POR VENCER')";
}
function lote_estado_vendible($estado) {
    return in_array(strtoupper(trim((string)$estado)), ['ACTIVO', 'DISPONIBLE', 'POR VENCER'], true);
}
function stock_lote($idDetalleLote) {
    return (int)escalar('SELECT dl.cantidad_inicial - ISNULL((SELECT SUM(dv.cantidad) FROM dbo.detalle_venta dv
        INNER JOIN dbo.venta v ON v.id_venta=dv.id_venta WHERE dv.id_detalle_lote=dl.id_detalle_lote AND v.estado<>\'Anulada\'),0)
        FROM dbo.detalle_lote dl WHERE dl.id_detalle_lote=?', [$idDetalleLote]);
}
function sql_inventario() {
    $hoy = 'CAST(GETDATE() AS DATE)';
    return "SELECT m.id_medicamento, m.nombre, m.concentracion, c.nombre AS categoria, m.precio_costo, m.precio_venta,
        ISNULL(SUM(CASE WHEN " . sql_lote_estado_vendible('dl') . " AND dl.fecha_vencimiento>=$hoy THEN st.disponible END),0) AS stock,
        ISNULL(SUM(CASE WHEN dl.fecha_vencimiento<$hoy AND dl.estado<>'Retirado' THEN st.disponible END),0) AS vencido,
        MIN(CASE WHEN " . sql_lote_estado_vendible('dl') . " AND dl.fecha_vencimiento>=$hoy AND st.disponible>0 THEN dl.fecha_vencimiento END) AS prox_venc
        FROM dbo.medicamento m
        INNER JOIN dbo.categoria c ON c.id_categoria=m.id_categoria
        LEFT JOIN dbo.detalle_lote dl ON dl.id_medicamento=m.id_medicamento
        " . sql_stock_apply('dl') . "
        GROUP BY m.id_medicamento,m.nombre,m.concentracion,c.nombre,m.precio_costo,m.precio_venta";
}

/* ------------------------------ Paginación ------------------------------ */
function paginar($sqlCount, array $params, $porPagina, $paginaSolicitada) {
    $total = (int)escalar($sqlCount, $params);
    $paginas = max(1, (int)ceil($total / $porPagina));
    $p = filter_var($paginaSolicitada, FILTER_VALIDATE_INT);
    if (!$p || $p < 1) $p = 1;
    $pagina = min($paginas, $p);
    return [$total, $paginas, $pagina, ($pagina - 1) * $porPagina];
}
function filas_paginadas($sql, array $params, $offset, $porPagina) {
    global $pdo;
    $s = $pdo->prepare($sql . ' OFFSET ? ROWS FETCH NEXT ? ROWS ONLY');
    $i = 1;
    foreach ($params as $p) $s->bindValue($i++, $p, PDO::PARAM_STR);
    $s->bindValue($i++, $offset, PDO::PARAM_INT);
    $s->bindValue($i, $porPagina, PDO::PARAM_INT);
    $s->execute();
    return $s->fetchAll();
}
function html_paginacion($url, $pagina, $paginas, $total, $offset, $porPagina, $etiqueta, $include = '[name=buscar]', $destino = '#lista-registros') {
    $sep = strpos($url, '?') === false ? '?' : '&';
    $ant = max(1, $pagina - 1);
    $sig = min($paginas, $pagina + 1);
    return '<input type="hidden" id="pagina-lista" name="pagina" value="' . $pagina . '">'
        . '<div class="paginacion-productos"><p>Mostrando ' . ($total ? $offset + 1 : 0) . '–' . min($offset + $porPagina, $total) . ' de ' . $total . ' ' . h($etiqueta) . '</p>'
        . '<nav class="pagination" aria-label="Páginas" hx-target="' . $destino . '" hx-include="' . h($include) . '" hx-swap="innerHTML">'
        . '<button class="pagination-previous" hx-get="' . h($url . $sep . 'pagina=' . $ant) . '" ' . ($pagina === 1 ? 'disabled' : '') . '>Anterior</button>'
        . '<button class="pagination-next" hx-get="' . h($url . $sep . 'pagina=' . $sig) . '" ' . ($pagina === $paginas ? 'disabled' : '') . '>Siguiente</button>'
        . '<ul class="pagination-list"><li><span class="pagination-link is-current">' . $pagina . ' / ' . $paginas . '</span></li></ul></nav></div>';
}

/* ------------------------------ Diseño de página ------------------------------ */
function pagina_inicio($titulo, $activo = '') {
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Farmacia | ' . h($titulo) . '</title>'
        . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">'
        . '<link rel="stylesheet" href="estilos.css?v=20261028-2207"><link rel="stylesheet" href="estilos_extra.css?v=20261028-2207">'
        . '<script src="htmx.min.js"></script></head><body>';
    include __DIR__ . '/menu.php';
    echo '<section class="section"><div class="container">';
}
function pagina_fin() {
    echo '</div></section><div id="modal-contenido"></div>';
    include __DIR__ . '/dialogos.php';
    echo '<script src="avisos.js"></script><script src="app.js"></script></body></html>';
}
function encabezado($titulo, $subtitulo = '', $botones = '') {
    echo '<div class="level encabezado-productos no-imprimir"><div class="level-left"><div><h1 class="title">' . h($titulo) . '</h1>'
        . ($subtitulo !== '' ? '<p class="subtitle">' . h($subtitulo) . '</p>' : '')
        . '</div></div><div class="level-right"><div class="buttons">' . $botones . '</div></div></div>';
}
function modal_inicio($titulo, $postUrl, $ancho = '850px') {
    echo '<div class="modal is-active"><div class="modal-background" onclick="' . CERRAR_MODAL . '"></div>'
        . '<div class="modal-card" style="width:min(' . $ancho . ',95vw)"><header class="modal-card-head"><p class="modal-card-title">' . h($titulo) . '</p>'
        . '<button class="delete" aria-label="Cerrar" onclick="' . CERRAR_MODAL . '"></button></header>'
        . '<form hx-post="' . h($postUrl) . '" hx-target="#mensaje-form" hx-swap="innerHTML"><section class="modal-card-body"><div id="mensaje-form" role="alert"></div>';
}
function modal_fin($textoGuardar = 'Guardar') {
    echo '</section><footer class="modal-card-foot"><button type="submit" class="button is-primary">' . h($textoGuardar) . '</button>'
        . '<button type="button" class="button" onclick="' . CERRAR_MODAL . '">Cancelar</button></footer></form></div></div>';
}
