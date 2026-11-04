# Guía de Base de Datos – Gestión de Medicamentos

Esta guía explica cómo instalar, probar y presentar los cuatro componentes de Base de Datos del proyecto:

1. [Cursor para reportes](#1-cursor-para-reportes)
2. [Datos de prueba](#2-datos-de-prueba)
3. [Respaldos y recuperación](#3-respaldos-y-recuperación)
4. [Data Warehouse `FarmaciaDW`](#4-data-warehouse-farmaciadw)

Al final hay una [guía de exposición](#5-guía-de-exposición) y el [registro de la verificación realizada](#6-verificación-realizada-el-3-de-noviembre-de-2026).

## Requisitos

- SQL Server 2016 SP1 o superior (probado en SQL Server 2022 Developer, instancia `localhost\MSSQLSERVER_UNA0`).
- SSMS o `sqlcmd`. Los respaldos y el Data Warehouse requieren un inicio de sesión con permisos de administrador (por ejemplo, autenticación de Windows del administrador del equipo).
- Base `farmacia` creada con `docs/4535f26e-farmacia.sql`.

## Orden de instalación

| Paso | Script | Qué hace |
|---|---|---|
| 1 | `docs/sql/respaldos/01_backup_completo.sql` | Respaldo antes de cualquier cambio |
| 2 | `docs/sql/sp_reporte_ventas_medicamento.sql` | Instala el procedimiento con cursor |
| 3 | `docs/sql/datos_prueba_farmacia.sql` | Carga datos ficticios (solo en desarrollo) |
| 4 | `docs/sql/prueba_sp_reporte_ventas_medicamento.sql` y `prueba_errores_…sql` | Pruebas del cursor |
| 5 | `docs/sql/respaldos/02_…`, `03_…`, `04_…`, `05_…` | Diferencial, log, verificación y restauración de prueba |
| 6 | `docs/sql/data_warehouse/01_…` a `06_…` | Crea, carga y valida `FarmaciaDW` |

En SSMS: abrir el archivo y pulsar **F5**. Con `sqlcmd`, desde la carpeta del script:

```powershell
sqlcmd -S "localhost\MSSQLSERVER_UNA0" -E -C -b -f 65001 -i nombre_del_script.sql
```

- `-E` usa autenticación de Windows, así que no hace falta escribir credenciales.
- `-f 65001` lee el archivo como UTF-8 para que las tildes se guarden bien.
- Los scripts fijan `SET QUOTED_IDENTIFIER ON` porque `sqlcmd` lo deja apagado y `medicamento` tiene un índice filtrado.
- Ejecute `sqlcmd` desde la carpeta del script y pase la ruta relativa: una ruta que contenga `--` se confunde con una opción.

---

## 1. Cursor para reportes

**Archivos**
- `docs/sql/sp_reporte_ventas_medicamento.sql`: instala `dbo.sp_reporte_ventas_medicamento @desde, @hasta`.
- `docs/sql/prueba_sp_reporte_ventas_medicamento.sql`: pruebas normales.
- `docs/sql/prueba_errores_sp_reporte_ventas_medicamento.sql`: errores esperados (por separado).
- `mini_framework/ProyectoFarmacia/reportes.php`: la tarjeta «10 medicamentos más vendidos» llama al procedimiento.

**Cómo funciona**
1. `DECLARE cur_ventas CURSOR LOCAL FAST_FORWARD`: selecciona cada línea de venta (`detalle_venta`) del período, sin las ventas anuladas.
2. `OPEN` abre el cursor y el primer `FETCH NEXT` lee la primera línea.
3. `WHILE @@FETCH_STATUS = 0`: por cada línea suma unidades y monto del medicamento en una tabla en memoria (`UPDATE` si ya existe, `INSERT` si es nuevo) y lee la siguiente con `FETCH NEXT`.
4. `CLOSE` y `DEALLOCATE` liberan el cursor.
5. Si algo falla, el `CATCH` cierra y libera el cursor (según `CURSOR_STATUS`) y relanza el error.
6. Devuelve los 10 medicamentos con más unidades (desempate por monto).

Agrupa por `id_medicamento`, así que dos presentaciones con el mismo nombre (por ejemplo, Acetaminofén tableta y jarabe) salen por separado. Solo lee datos: no modifica ninguna tabla.

**Pruebas incluidas**

| Prueba | Qué demuestra |
|---|---|
| 1 | El procedimiento devuelve el Top 10 |
| 2 | Coincide exactamente con una consulta `GROUP BY` equivalente (`EXCEPT` en ambos sentidos) |
| 3 | Los medicamentos con el mismo nombre aparecen por separado |
| 3b | Unidades del cursor + unidades anuladas = unidades totales: las anuladas no se cuentan |
| 4 | `venta` y `detalle_venta` no cambian (solo lectura) |
| 5 | No quedan cursores abiertos (`sys.dm_exec_cursors`) |
| Errores | Rango invertido → 50001; fecha nula → 50001; fecha inexistente → 8114 |

**En la aplicación**: entrar como Administrador → **Administración → Reportes** → cambiar «Desde» y «Hasta». Si «Desde» es posterior a «Hasta», la tarjeta muestra un aviso. Si el procedimiento no está instalado, avisa cuál script ejecutar.

---

## 2. Datos de prueba

`docs/sql/datos_prueba_farmacia.sql` carga datos **ficticios** de una farmacia costarricense. **Úselo solo en una base local de desarrollo.**

| Tabla | Registros | Clave para no duplicar |
|---|---|---|
| categoria / presentacion | 10 / 9 | nombre |
| proveedor | 4 | identificación |
| cliente | 14 (+ Cliente General si falta) | identificación |
| medicamento | 22 | código de barras (prefijo ficticio `74499`) |
| compra / lote / detalle_lote / detalle_compra | 5 / 29 / 29 / 29 | las 5 facturas de prueba |
| receta | 35 | números 9100001–9100035 |
| venta / detalle_venta | 100 (3 anuladas) / 164 | las 5 facturas de prueba |
| auditoria | 108 | las 5 facturas de prueba |
| usuario | 0 | no crea usuarios ni contraseñas |

- **Todo en una transacción** (`XACT_ABORT`, `TRY/CATCH`, `ROLLBACK`). Si falla, no queda nada.
- **Catálogos**: `INSERT … WHERE NOT EXISTS`; se reutiliza lo que ya existe.
- **Movimientos**: si ya existe cualquiera de las 5 facturas de prueba, se omite el bloque completo (compras, lotes, recetas, ventas y auditoría). Ejecutarlo de nuevo no duplica nada.
- **Fechas relativas** al día de ejecución: compras de hace 40–190 días, ventas en los últimos 180 días.
- **Reglas del sistema respetadas**: el stock de cada lote es su cantidad inicial menos lo vendido en ventas no anuladas. Cada venta usa el lote que vence primero, ya comprado y sin vencer. Cada medicamento con receta tiene una receta vigente del mismo cliente y medicamento, y hay como máximo uno por venta. Los totales se calculan igual que en `venta_nueva.php`.
- **Validación antes del COMMIT**: stock negativo, lotes no comprados o vencidos y recetas inválidas. Si algo no cuadra, se revierte todo.
- **Casos para las alertas**: lote vencido con existencias (Loperamida), lote por vencer (Cetirizina), lote agotado (Clotrimazol), medicamento sin ventas (Diclofenaco) y 2 recetas vigentes sin usar.
- **Registros existentes**: no se actualizan ni se borran. El primer Administrador activo figura como autor de las compras y de la auditoría.

Al final del archivo hay consultas de verificación (conteos, 5 comprobaciones de consistencia, inventario, alertas, ventas por mes y el reporte con cursor).

---

## 3. Respaldos y recuperación

**Estrategia** (la base `farmacia` usa el modelo de recuperación FULL):

| Tipo | Script | Contiene | Uso sugerido |
|---|---|---|---|
| Completo | `respaldos/01_backup_completo.sql` | Toda la base; inicia la cadena | Diario o antes de cambios importantes |
| Diferencial | `respaldos/02_backup_diferencial.sql` | Cambios desde el último completo | Cada pocas horas |
| Log | `respaldos/03_backup_log.sql` | Transacciones desde el último log | Frecuente (por ejemplo, cada 15–60 min) |
| Verificación | `respaldos/04_verificar_backups.sql` | Historial en `msdb` + `RESTORE VERIFYONLY` | Después de cada respaldo |
| Restauración de prueba | `respaldos/05_restauracion_prueba.sql` | Restaura la cadena en una base nueva | Periódicamente, para comprobar que se puede recuperar |

- **Ubicación**: `C:\Program Files\Microsoft SQL Server\MSSQL16.MSSQLSERVER_UNA0\MSSQL\Backup\farmacia\`, la carpeta de respaldos de la instancia. Está fuera del repositorio y el servicio de SQL Server tiene permisos sobre ella.
- **Nombres**: `farmacia_FULL_aaaaMMdd_HHmmss.bak`, `farmacia_DIFF_….bak`, `farmacia_LOG_….trn`. Cada ejecución crea un archivo nuevo; nunca sobrescribe.
- **Opciones**: todos usan `CHECKSUM` y `COMPRESSION` y se verifican con `RESTORE VERIFYONLY` al terminar.
- **Sin borrado automático**: no hay eliminación automática ni tareas programadas. Los `.bak` y `.trn` están en `.gitignore`.

**Restauración de prueba** (`05_…`): busca en `msdb` el último completo, su último diferencial y los logs posteriores, y comprueba que la cadena no tenga huecos. Restaura todo en una base **nueva**, `farmacia_restauracion_aaaaMMdd_HHmmss`, con archivos propios. No usa `WITH REPLACE` y nunca toca `farmacia`. Al final compara las filas de las 14 tablas y ejecuta `DBCC CHECKDB`. La base restaurada queda para revisarla; bórrela manualmente cuando ya no la necesite.

**Recuperación real ante un desastre** (procedimiento manual, no automatizado): si `farmacia` se daña y hay que recuperarla en su lugar, un administrador debe:

1. Si la base todavía responde, respaldar la cola del log: `BACKUP LOG farmacia TO DISK = '…\farmacia_LOG_cola.trn' WITH NO_TRUNCATE, NORECOVERY;`
2. Restaurar el último completo: `RESTORE DATABASE farmacia FROM DISK = '…FULL….bak' WITH NORECOVERY;`
3. Restaurar el último diferencial con `WITH NORECOVERY`.
4. Restaurar cada log posterior, en orden, con `WITH NORECOVERY` (y la cola del paso 1). Para volver a un momento concreto se usa `STOPAT = 'aaaa-mm-dd hh:mm:ss'` en el último log.
5. `RESTORE DATABASE farmacia WITH RECOVERY;` y verificar con `DBCC CHECKDB` y las consultas de conteo.

Antes de hacerlo sobre la base real, ensaye siempre con `05_restauracion_prueba.sql`.

---

## 4. Data Warehouse `FarmaciaDW`

**Grano**: `dw.fact_ventas` tiene **una fila por línea de venta** (`farmacia.dbo.detalle_venta`), es decir, un medicamento (lote) vendido en una venta concreta.

**Modelo estrella**

```
                    dw.dim_fecha
                         │
dw.dim_medicamento ── dw.fact_ventas ── dw.dim_cliente
                         │
                 dw.dim_metodo_pago
```

| Tabla | Contenido |
|---|---|
| `dw.dim_fecha` | Un día por fila (clave `aaaammdd`): año, trimestre, mes, nombre del mes, día de la semana, fin de semana |
| `dw.dim_medicamento` | Nombre, concentración, presentación, descripción completa, categoría, requiere receta, IVA |
| `dw.dim_cliente` | Nombre completo, identificación, provincia (de la dirección), si es el Cliente General |
| `dw.dim_metodo_pago` | Efectivo, Tarjeta, SINPE Móvil, Transferencia |
| `dw.fact_ventas` | Claves de las 4 dimensiones, número de venta y de lote, estado, `es_anulada`, `con_receta` y las medidas |
| `etl.log_carga` | Bitácora de cada ejecución del ETL |

**Medidas**: `cantidad`, `precio_unitario`, `subtotal`, `descuento`, `ingreso_neto` (= subtotal − descuento, sin IVA), `impuesto`, `total`, `costo_unitario` (costo real del lote según `detalle_compra`), `costo_total` y `utilidad_bruta` (= ingreso neto − costo).

**Ventas anuladas**: se cargan con `es_anulada = 1` para poder identificarlas. Las consultas analíticas las excluyen con `WHERE es_anulada = 0` y la consulta 8 las muestra aparte.

**ETL** (`03_procedimientos_etl.sql`, ejecutado con `EXEC etl.sp_ejecutar_etl`):
1. `etl.sp_cargar_dim_fecha`: inserta los días que falten (años completos que cubren las ventas).
2. `etl.sp_cargar_dim_medicamento` y `etl.sp_cargar_dim_cliente`: `MERGE` que inserta nuevos y actualiza cambios (dimensiones tipo 1).
3. `etl.sp_cargar_dim_metodo_pago`: inserta los que falten.
4. `etl.sp_cargar_fact_ventas`: `MERGE` por `id_detalle_venta`. Inserta líneas nuevas y actualiza las que cambiaron, por ejemplo una venta anulada después de la carga anterior. Al final comprueba que todas las líneas del origen estén cargadas.

Todo corre en una transacción y queda registrado en `etl.log_carga`. Ejecutarlo varias veces no duplica filas. Lee `farmacia` mediante sinónimos del esquema `origen` y no modifica su estructura ni sus datos.

**Scripts** (`docs/sql/data_warehouse/`): `01_crear_farmaciadw.sql`, `02_crear_tablas.sql`, `03_procedimientos_etl.sql`, `04_ejecutar_etl.sql`, `05_consultas_analiticas.sql` (por mes, categoría con margen, medicamento, cliente, provincia, método de pago, día de la semana y anuladas) y `06_validacion_consistencia.sql` (8 comprobaciones contra `farmacia`, incluida una segunda ejecución del ETL).

---

## 5. Guía de exposición

**Cursor (≈5 min)**
1. En SSMS, abrir `sp_reporte_ventas_medicamento.sql` y señalar los pasos numerados: `DECLARE CURSOR`, `OPEN`, `FETCH NEXT`, `WHILE @@FETCH_STATUS = 0`, `CLOSE` y `DEALLOCATE`, más el `CATCH` que libera el cursor.
2. Ejecutar `prueba_sp_reporte_ventas_medicamento.sql` y mostrar en *Mensajes* las líneas `OK`. Destacar la prueba 2 (mismo resultado que `GROUP BY`) y la 3b (excluye anuladas).
3. Ejecutar `prueba_errores_…sql`: los tres casos con `resultado = OK`.
4. En la aplicación: Reportes → cambiar las fechas → la tarjeta del Top 10 cambia. Opcional: anular una venta en el Historial de ventas y recargar el reporte.

**Respaldos (≈5 min)**
1. Explicar la estrategia: completo, diferencial y log, y por qué el modelo FULL permite el backup de log.
2. Ejecutar `01_backup_completo.sql` y mostrar el mensaje `OK` con la ruta del archivo.
3. Ejecutar `04_verificar_backups.sql`: historial y `RESTORE VERIFYONLY` de cada tipo.
4. Ejecutar `05_restauracion_prueba.sql`: aparece una base nueva con las 14 tablas iguales a `farmacia` y `DBCC CHECKDB` sin errores. Recalcar que no toca `farmacia`.

**Data Warehouse (≈5 min)**
1. Mostrar el diagrama estrella y explicar el grano (una fila por línea de venta).
2. Ejecutar `04_ejecutar_etl.sql` y mostrar la bitácora (filas insertadas o actualizadas).
3. Ejecutar `05_consultas_analiticas.sql`: ventas por mes, categoría y cliente.
4. Ejecutar `06_validacion_consistencia.sql`: 8 comprobaciones en `OK`, incluida «Top 10 del DW = Top 10 del cursor» y la segunda ejecución sin duplicados.
5. Opcional: anular una venta en la aplicación, ejecutar `04_ejecutar_etl.sql` y ver `hechos_actualizados = 1`.

---

## 6. Verificación realizada el 3 de noviembre de 2026

Ejecutado en `localhost\MSSQLSERVER_UNA0` (SQL Server 2022 Developer, autenticación de Windows).

**Base de partida.** `farmacia` tenía 1 usuario Administrador, el Cliente General, 2 registros de auditoría y ninguna venta. Su estructura coincidía con `docs/4535f26e-farmacia.sql`: 14 tablas y 16 claves foráneas.

**Respaldos**
- Backup completo inicial `farmacia_FULL_20261103_163247.bak`, tomado antes de cualquier cambio y verificado con `RESTORE VERIFYONLY`.
- Restauración de prueba en `farmacia_restauracion_20261103_163253`: 14/14 tablas iguales y `DBCC CHECKDB` sin errores.

**Datos de prueba**
- Ensayados primero en esa copia restaurada. La primera ejecución falló por `QUOTED_IDENTIFIER` (ya corregido) y se revirtió sin dejar datos.
- Ejecutados luego en `farmacia`: insertaron exactamente los registros de la tabla de la sección 2.
- Las 5 comprobaciones de consistencia dieron 0 problemas.
- La segunda ejecución (en la copia y en `farmacia`) insertó 0 registros.
- Las tildes quedaron bien guardadas (é = 233, ó = 243).

**Cursor**
- La definición instalada coincide con el script.
- Pruebas 1–5 y 3b en `OK`. Errores: 50001, 50001 y 8114, como se esperaba.
- `reportes.php` probado por línea de comandos con 4 rangos (6 meses, mes actual, rango invertido y período sin ventas): resultados correctos.

**Cadena de respaldos**
- Diferencial `farmacia_DIFF_20261103_163631.bak` y logs `farmacia_LOG_20261103_163631.trn` y `…_163644.trn`, todos verificados.
- Restauración completo + diferencial + log en `farmacia_restauracion_20261103_163645`: 14/14 tablas iguales (100 ventas, 164 líneas) y `DBCC CHECKDB` sin errores.

**Data Warehouse**
- `FarmaciaDW` creada. Primera carga: 365 fechas, 22 medicamentos, 15 clientes, 4 métodos de pago y 164 hechos.
- Las 8 comprobaciones de `06_validacion_consistencia.sql` en `OK`. El total de ventas no anuladas es ₡763.509,96, igual en `farmacia`, en el DW y en `reportes.php`.
- La rama de actualización del `MERGE` se probó alterando una fila **en el DW**: el ETL la corrigió (1 actualizada, 0 insertadas).

**Bases de prueba que quedaron en la instancia.** `farmacia_restauracion_20261103_163253` (también contiene los datos de prueba, porque se usó para ensayarlos) y `farmacia_restauracion_20261103_163645`. No se eliminaron; se pueden borrar manualmente.
