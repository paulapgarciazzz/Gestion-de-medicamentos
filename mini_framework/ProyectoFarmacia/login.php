<?php
require_once __DIR__ . '/lib.php';

if (usuario_actual()) { header('Location: index.php'); exit; }

$hayUsuarios = (int)escalar('SELECT COUNT(*) FROM dbo.usuario') > 0;
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!$hayUsuarios) {
            /* Primera ejecución: se crea el administrador inicial y un "Cliente General" para ventas de mostrador. */
            $nombre = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $correo = trim($_POST['correo'] ?? '');
            $clave = (string)($_POST['clave'] ?? '');
            if ($nombre === '' || $apellido === '') throw new ErrorNegocio('Nombre y apellido son obligatorios.');
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new ErrorNegocio('Ingrese un correo válido.');
            if ($m = contrasenna_validar_formato($clave)) throw new ErrorNegocio($m);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO dbo.usuario (nombre,primer_apellido,correo,contrasenna,rol,estado) VALUES (?,?,?,?, 'Administrador',1)")
                ->execute([mb_substr($nombre, 0, 20), mb_substr($apellido, 0, 20), $correo, contrasenna_preparar($clave)]);
            if ((int)escalar('SELECT COUNT(*) FROM dbo.cliente') === 0) {
                $pdo->exec("INSERT INTO dbo.cliente (primer_nombre,primer_apellido,identificacion) VALUES ('Cliente','General','0')");
            }
            $pdo->commit();
            $hayUsuarios = true;
            $error = '';
            $mensajeOk = 'Administrador creado. Ya puede iniciar sesión.';
        } else {
            $correo = trim($_POST['correo'] ?? '');
            $clave = (string)($_POST['clave'] ?? '');
            $u = fila('SELECT id_usuario,nombre,rol,correo,contrasenna,estado FROM dbo.usuario WHERE correo=?', [$correo]);
            if (!$u || !contrasenna_verificar($clave, $u['contrasenna'])) throw new ErrorNegocio('Correo o contraseña incorrectos.');
            if (!$u['estado']) throw new ErrorNegocio('Este usuario está desactivado. Consulte al administrador.');
            session_regenerate_id(true);
            $_SESSION['usuario'] = ['id' => (int)$u['id_usuario'], 'nombre' => $u['nombre'], 'rol' => $u['rol'], 'correo' => $u['correo']];
            auditar('usuario', $u['id_usuario'], 'LOGIN');
            header('Location: inicio.php');
            exit;
        }
    } catch (ErrorNegocio $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        $error = 'No se pudo completar la operación. Verifique la base de datos.';
    }
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Farmacia | Ingreso</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
<link rel="stylesheet" href="estilos.css"><link rel="stylesheet" href="estilos_extra.css"></head><body>
<div class="login-caja"><div class="box">
  <h1 class="title is-4"><?= $hayUsuarios ? 'Ingreso al sistema' : 'Configuración inicial' ?></h1>
  <?php if (!$hayUsuarios): ?><p class="mb-4">No hay usuarios registrados. Cree el <strong>administrador</strong> del sistema.</p><?php endif; ?>
  <?php if (!empty($mensajeOk)): ?><div class="notification is-success"><?= h($mensajeOk) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notification is-danger"><?= h($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?php if (!$hayUsuarios): ?>
      <div class="columns"><div class="column"><label class="label">Nombre</label><input class="input" name="nombre" maxlength="20" required></div>
      <div class="column"><label class="label">Apellido</label><input class="input" name="apellido" maxlength="20" required></div></div>
    <?php endif; ?>
    <div class="field"><label class="label">Correo</label><input class="input" type="email" name="correo" required autofocus></div>
    <div class="field"><label class="label">Contraseña</label><input class="input" type="password" name="clave" required></div>
    <button class="button is-primary is-fullwidth" type="submit"><?= $hayUsuarios ? 'Ingresar' : 'Crear administrador' ?></button>
  </form>
</div></div></body></html>
