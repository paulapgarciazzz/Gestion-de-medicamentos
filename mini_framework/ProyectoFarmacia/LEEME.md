# Sistema de Farmacia – módulos nuevos

Se agregaron los módulos que faltaban para usar las **14 tablas** de la base de datos.
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

### Scripts SQL (en `docs/sql`, ejecutar en SSMS en este orden)
| # | Script | Qué crea |
|---|---|---|
| 1 | `4535f26e-farmacia.sql` (en `docs/`) | Base `farmacia` con sus 14 tablas |
| 2 | `sp_reporte_ventas_medicamento.sql` | Reporte con cursor (10 más vendidos) |
| 3 | `procedimientos_negocio.sql` | `sp_registrar_venta`, `sp_anular_venta`, `sp_registrar_compra` (con transacciones) |
| 4 | `respaldos/06_respaldo_desde_aplicacion.sql` | Tabla `respaldo` y `sp_respaldar_farmacia` |
| 5 | `data_warehouse/01` a `03` | Base `FarmaciaDW`: modelo estrella (ventas, inventario y caducidad) y procedimientos del ETL |
| 6 | `permisos_aplicacion.sql` | Permisos de la cuenta con la que Apache entra a SQL Server (por defecto `NT AUTHORITY\SYSTEM`) |

Opcionales para revisar desde SSMS: `data_warehouse/04` (ejecutar ETL), `05` (consultas de análisis), `06` (validación DW = farmacia) y `respaldos/01` a `05` (respaldos y restauración de prueba). Lo mismo se puede hacer desde la aplicación en **Administración › Mantenimiento**.

El archivo `.env` contiene configuración privada: no lo suba a GitHub ni lo envíe por chat. El repositorio solo incluye `.env.example`; cada colaborador debe crear su propio `.env`. Para despliegues automatizados, configure `DB_SERVER`, `DB_NAME`, `DB_USERNAME` y `DB_PASSWORD` como variables/secretos del entorno de ejecución; las variables de entorno tienen prioridad sobre `.env`.

## Qué tabla se administra en cada pantalla
| Tabla(s) | Pantalla | Qué hace |
|---|---|---|
| medicamento | `index.php` (la suya) | CRUD (se le agregó login y menú). Cada presentación es un medicamento aparte (`id_presentacion`); el nombre se puede repetir, el código de barras no |
| categoria | `categorias.php` | CRUD |
| presentacion | `presentaciones.php` | CRUD |
| proveedor | `proveedores.php` | CRUD |
| cliente | `clientes.php` | CRUD |
| usuario | `usuarios.php` | CRUD + roles + login (`login.php`, `logout.php`) |
| receta | `recetas.php` | CRUD |
| compra, detalle_compra, lote, detalle_lote | `compra_nueva.php`, `compras.php`, `compra_ver.php` | registrar compras (crean los lotes y suman stock), historial, detalle imprimible |
| lote, detalle_lote | `lotes.php`, `inventario.php` | lotes, vencimientos, estado; existencias por medicamento |
| venta, detalle_venta | `venta_nueva.php`, `ventas.php`, `venta_ver.php` | punto de venta con carrito, historial, anulación, factura imprimible |
| auditoria | `auditoria.php` | consulta con filtros (se llena sola) |
| (todas) | `inicio.php`, `reportes.php` | panel con alertas; reportes de ventas/compras/utilidad |
| respaldo + base `FarmaciaDW` | `mantenimiento.php` (solo Administrador) | ejecutar el ETL del Data Warehouse, ver su bitácora y los reportes de ventas, inventario y caducidad; crear respaldos completo / diferencial / log y ver su historial |

## Reglas de negocio que implementé (puede ajustarlas)
- **Stock** de un lote = `cantidad_inicial` − unidades vendidas en ventas **no anuladas**. No hay columna de stock que se desactualice; anular una venta devuelve las unidades automáticamente.
- **Compras**: cada línea pide número de lote, fabricación, vencimiento, cantidad y costo. Se crea el `lote` + `detalle_lote`; si el medicamento llega con un número de lote que ya existe (mismas fechas) se suman las unidades, salvo que ese lote esté *Inactivo* o *Retirado*. No se aceptan lotes ya vencidos ni facturas repetidas del mismo proveedor. Opcionalmente actualiza el `precio_costo` del medicamento.
- **Ventas**: solo se venden lotes *Activos*, *no vencidos* y con existencias; el buscador muestra primero los que vencen antes. Los precios, el IVA, el stock y los totales **se recalculan en SQL Server** (`sp_registrar_venta`, no se confía en el navegador) dentro de una transacción con bloqueo, así dos cajeros no pueden vender las mismas unidades.
- **Recetas**: si la venta incluye un medicamento con `requiere_receta`, se exige una receta **vigente del mismo cliente**, y esa receta debe ser del medicamento que cubre (la tabla `venta` solo guarda una receta por venta).
- **Cálculo**: `detalle.subtotal` = cant × precio; `detalle.total` = (subtotal − descuento) + IVA; `venta.total` = suma de los totales. El descuento se escribe en colones por línea.
- **Roles** (campo `usuario.rol`): *Administrador* (todo), *Farmaceutico* (inventario, compras, ventas, clientes, recetas, anular ventas), *Vendedor* (ventas, clientes, recetas).
- **Auditoría**: se registra quién creó/actualizó/eliminó/anuló y los ingresos al sistema.
- **Procedimientos almacenados y transacciones**: registrar una venta, anularla y registrar una compra lo hacen los procedimientos de `procedimientos_negocio.sql`. Cada uno valida, guarda y audita dentro de una transacción (`BEGIN TRAN` / `COMMIT`, y `ROLLBACK` en el `CATCH`): si algo falla no queda nada a medias. Los errores de negocio usan `THROW` 51000-51999 y la aplicación muestra su mensaje.
- **Data Warehouse**: `FarmaciaDW` guarda las ventas (una fila por línea, con el costo real del lote y la utilidad) y una foto diaria del inventario de cada lote con su caducidad (vigente / por vencer en 90 días / vencido y el dinero en riesgo). El ETL se puede repetir sin duplicar datos y deja registro en `etl.log_carga`.
- **Respaldos desde la aplicación**: quedan en la carpeta de respaldos de SQL Server (`…\MSSQL\Backup\farmacia`) y en la tabla `respaldo`. La verificación con `RESTORE VERIFYONLY` necesita un permiso de servidor que no se le da a la aplicación; los respaldos se crean con `CHECKSUM` y se verifican con `respaldos/04_verificar_backups.sql`.

## Cosas que debe saber
- **Contraseñas**: la columna `usuario.contrasenna` es `VARCHAR(8)`, así que solo permite contraseñas de 4 a 8 caracteres en texto plano. Es una limitación del diseño de la base de datos. Si ejecuta `mejoras_opcionales.sql` (amplía la columna) el sistema pasa solo a guardar contraseñas con hash.
- **`index.php` ya está integrado** con el resto del sistema: exige login (Administrador o Farmaceutico), muestra el menú principal (con «Medicamentos» marcado en *Inventario*) y usa `dialogos.php` para el diálogo de confirmación y los avisos.
- Requiere PHP 7.4 o superior con `pdo_sqlsrv`.
- Las compras no se pueden editar ni borrar (por trazabilidad de lotes y stock); las ventas se anulan, no se borran.
- Los montos usan ₡ y el formato 1.234,56 igual que su pantalla de medicamentos.
- **Reportes**: la tarjeta «10 medicamentos más vendidos» de `reportes.php` usa el procedimiento con cursor `dbo.sp_reporte_ventas_medicamento`. Instálelo con `docs/sql/sp_reporte_ventas_medicamento.sql`; si falta, la tarjeta muestra un aviso.
