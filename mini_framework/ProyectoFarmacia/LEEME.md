# Sistema de Farmacia – módulos nuevos

Se agregaron los módulos que faltaban para usar las **15 tablas** de la base de datos.
**No se modificó ninguno de tus archivos** (`index.php`, `producto_*.php`, `productos_lista.php`,
`estilos.css`, `avisos.js`, `conexion.php`, `htmx.min.js`). Todo lo nuevo usa la misma tecnología
(PHP + SQL Server PDO + HTMX + Bulma) y los mismos colores.

## Instalación
1. Copie todos los archivos de esta carpeta dentro de la carpeta de su proyecto (junto a `index.php`).
2. Cree su archivo local de configuración copiando `.env.example` como `.env` en esta carpeta. Ajuste `DB_SERVER` y `DB_NAME` a su SQL Server. Con autenticación integrada de Windows, deje `DB_USERNAME` y `DB_PASSWORD` vacíos; si usa un usuario SQL, complete ambos.
   En PowerShell: `Copy-Item .env.example .env`.
3. Abra `http://localhost/…/login.php`. Como aún no hay usuarios, el sistema le pide **crear el administrador**
   (también crea un cliente «Cliente General» para ventas de mostrador).
4. Listo: desde `inicio.php` se llega a todo el sistema.

El archivo `.env` contiene configuración privada: no lo suba a GitHub ni lo envíe por chat. El repositorio solo incluye `.env.example`; cada colaborador debe crear su propio `.env`. Para despliegues automatizados, configure `DB_SERVER`, `DB_NAME`, `DB_USERNAME` y `DB_PASSWORD` como variables/secretos del entorno de ejecución; las variables de entorno tienen prioridad sobre `.env`.

## Qué tabla se administra en cada pantalla
| Tabla(s) | Pantalla | Qué hace |
|---|---|---|
| medicamento | `index.php` (la suya) | CRUD (se le agregó login y menú) |
| categoria | `categorias.php` | CRUD |
| presentacion | `presentaciones.php` | CRUD |
| medicamento_presentacion | `presentaciones_asignar.php` | asignar presentaciones a cada medicamento |
| proveedor | `proveedores.php` | CRUD |
| cliente | `clientes.php` | CRUD |
| usuario | `usuarios.php` | CRUD + roles + login (`login.php`, `logout.php`) |
| receta | `recetas.php` | CRUD |
| compra, detalle_compra, lote, detalle_lote | `compra_nueva.php`, `compras.php`, `compra_ver.php` | registrar compras (crean los lotes y suman stock), historial, detalle imprimible |
| lote, detalle_lote | `lotes.php`, `inventario.php` | lotes, vencimientos, estado; existencias por medicamento |
| venta, detalle_venta | `venta_nueva.php`, `ventas.php`, `venta_ver.php` | punto de venta con carrito, historial, anulación, factura imprimible |
| auditoria | `auditoria.php` | consulta con filtros (se llena sola) |
| (todas) | `inicio.php`, `reportes.php` | panel con alertas; reportes de ventas/compras/utilidad |

## Reglas de negocio que implementé (puede ajustarlas)
- **Stock** de un lote = `cantidad_inicial` − unidades vendidas en ventas **no anuladas**. No hay columna de stock que se desactualice; anular una venta devuelve las unidades automáticamente.
- **Compras**: cada línea pide número de lote, fabricación, vencimiento, cantidad y costo. Se crea el `lote` + `detalle_lote`; si el medicamento llega con un número de lote que ya existe (misma fecha de vencimiento) se suman las unidades. No se aceptan lotes ya vencidos ni facturas repetidas del mismo proveedor. Opcionalmente actualiza el `precio_costo` del medicamento.
- **Ventas**: solo se venden lotes *Activos*, *no vencidos* y con existencias; el buscador muestra primero los que vencen antes. Los precios, el IVA, el stock y los totales **se recalculan en el servidor** (no se confía en el navegador) dentro de una transacción con bloqueo, así dos cajeros no pueden vender las mismas unidades.
- **Recetas**: si la venta incluye un medicamento con `requiere_receta`, se exige una receta **vigente del mismo cliente**, y esa receta debe ser del medicamento que cubre (la tabla `venta` solo guarda una receta por venta).
- **Cálculo**: `detalle.subtotal` = cant × precio; `detalle.total` = (subtotal − descuento) + IVA; `venta.total` = suma de los totales. El descuento se escribe en colones por línea.
- **Roles** (campo `usuario.rol`): *Administrador* (todo), *Farmaceutico* (inventario, compras, ventas, clientes, recetas, anular ventas), *Vendedor* (ventas, clientes, recetas).
- **Auditoría**: se registra quién creó/actualizó/eliminó/anuló y los ingresos al sistema.

## Cosas que debe saber
- **Contraseñas**: la columna `usuario.contrasenna` es `VARCHAR(8)`, así que solo permite contraseñas de 4 a 8 caracteres en texto plano. Es una limitación del diseño de la base de datos. Si ejecuta `mejoras_opcionales.sql` (amplía la columna) el sistema pasa solo a guardar contraseñas con hash.
- **`index.php` ya está integrado** con el resto del sistema: exige login (Administrador o Farmaceutico), muestra el menú principal (con «Medicamentos» marcado en *Inventario*) y usa `dialogos.php` para el diálogo de confirmación y los avisos.
- Requiere PHP 7.4 o superior con `pdo_sqlsrv`.
- Las compras no se pueden editar ni borrar (por trazabilidad de lotes y stock); las ventas se anulan, no se borran.
- Los montos usan ₡ y el formato 1.234,56 igual que su pantalla de medicamentos.
