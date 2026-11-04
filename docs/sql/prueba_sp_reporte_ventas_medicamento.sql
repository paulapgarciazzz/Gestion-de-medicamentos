/* =====================================================================
   Pruebas de dbo.sp_reporte_ventas_medicamento (ejecutar en SSMS)
   Requisito: haber ejecutado antes sp_reporte_ventas_medicamento.sql
   Este script solo lee datos: no inserta, actualiza ni borra registros.
   Las pruebas de errores intencionales estan en
   prueba_errores_sp_reporte_ventas_medicamento.sql
   ===================================================================== */

USE farmacia;
GO

SET NOCOUNT ON;

-- Periodo de la prueba: por defecto todas las ventas hasta hoy (se puede ajustar)
DECLARE @desde DATE = '2000-01-01';
DECLARE @hasta DATE = CAST(GETDATE() AS DATE);

/* ---------------------------------------------------------------------
   Prueba 1: ejecutar el procedimiento (resultado que ve reportes.php)
   --------------------------------------------------------------------- */
PRINT '--- Prueba 1: resultado del procedimiento con cursor ---';
EXEC dbo.sp_reporte_ventas_medicamento @desde = @desde, @hasta = @hasta;

/* ---------------------------------------------------------------------
   Prueba 2: comparar con una consulta equivalente usando GROUP BY.
   Se agrupa por id_medicamento para distinguir medicamentos con el
   mismo nombre. Ambas diferencias deben devolver 0 filas.
   --------------------------------------------------------------------- */
PRINT '--- Prueba 2: cursor vs GROUP BY ---';

-- Huella de las tablas de ventas antes de ejecutar (para la prueba 4)
DECLARE @venta_antes INT = (SELECT CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.venta);
DECLARE @detalle_antes INT = (SELECT CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.detalle_venta);
DECLARE @filas_venta_antes INT = (SELECT COUNT(*) FROM dbo.venta);
DECLARE @filas_detalle_antes INT = (SELECT COUNT(*) FROM dbo.detalle_venta);

IF OBJECT_ID('tempdb..#cursor') IS NOT NULL DROP TABLE #cursor;
IF OBJECT_ID('tempdb..#group_by') IS NOT NULL DROP TABLE #group_by;

CREATE TABLE #cursor (
    id_medicamento INT, nombre VARCHAR(100), concentracion VARCHAR(50),
    presentacion VARCHAR(50), unidades INT, monto DECIMAL(14,2)
);
INSERT INTO #cursor
EXEC dbo.sp_reporte_ventas_medicamento @desde = @desde, @hasta = @hasta;

SELECT TOP (10)
       m.id_medicamento, m.nombre, m.concentracion, p.nombre AS presentacion,
       SUM(dv.cantidad) AS unidades, CAST(SUM(dv.total) AS DECIMAL(14,2)) AS monto
INTO #group_by
FROM dbo.detalle_venta dv
INNER JOIN dbo.venta v         ON v.id_venta = dv.id_venta
INNER JOIN dbo.detalle_lote dl ON dl.id_detalle_lote = dv.id_detalle_lote
INNER JOIN dbo.medicamento m   ON m.id_medicamento = dl.id_medicamento
LEFT  JOIN dbo.presentacion p  ON p.id_presentacion = m.id_presentacion
WHERE v.estado <> 'Anulada'
  AND v.fecha_venta >= @desde
  AND v.fecha_venta <  DATEADD(DAY, 1, @hasta)
GROUP BY m.id_medicamento, m.nombre, m.concentracion, p.nombre
ORDER BY unidades DESC, monto DESC, m.id_medicamento;

SELECT 'GROUP BY' AS consulta, * FROM #group_by ORDER BY unidades DESC, monto DESC, id_medicamento;

SELECT 'Filas del cursor que no estan en GROUP BY (debe ser 0)' AS verificacion, *
FROM (SELECT * FROM #cursor EXCEPT SELECT * FROM #group_by) d;

SELECT 'Filas de GROUP BY que no estan en el cursor (debe ser 0)' AS verificacion, *
FROM (SELECT * FROM #group_by EXCEPT SELECT * FROM #cursor) d;

IF NOT EXISTS (SELECT * FROM #cursor EXCEPT SELECT * FROM #group_by)
   AND NOT EXISTS (SELECT * FROM #group_by EXCEPT SELECT * FROM #cursor)
    PRINT 'OK: el cursor y GROUP BY devuelven los mismos resultados.';
ELSE
    PRINT 'ERROR: el cursor y GROUP BY no coinciden.';

/* ---------------------------------------------------------------------
   Prueba 3: medicamentos con el mismo nombre.
   Lista los nombres repetidos dentro del reporte; cada uno debe aparecer
   como filas separadas (distinto id_medicamento) con sus propios totales.
   --------------------------------------------------------------------- */
PRINT '--- Prueba 3: medicamentos con el mismo nombre ---';

SELECT c.nombre, c.id_medicamento, c.concentracion, c.presentacion, c.unidades, c.monto
FROM #cursor c
WHERE c.nombre IN (SELECT nombre FROM #cursor GROUP BY nombre HAVING COUNT(*) > 1)
ORDER BY c.nombre, c.id_medicamento;

IF EXISTS (SELECT nombre FROM #cursor GROUP BY nombre HAVING COUNT(*) > 1)
    PRINT 'OK: los medicamentos con el mismo nombre aparecen por separado.';
ELSE
    PRINT 'Aviso: en el periodo no hay medicamentos con el mismo nombre dentro del top 10. Registre ventas de dos presentaciones del mismo medicamento para ver este caso.';

/* ---------------------------------------------------------------------
   Prueba 3b: las ventas anuladas no se cuentan.
   Para cada medicamento del reporte: unidades del cursor + unidades en
   ventas anuladas = unidades de todas las ventas (sin filtrar por estado).
   --------------------------------------------------------------------- */
PRINT '--- Prueba 3b: ventas anuladas excluidas ---';

SELECT c.id_medicamento, c.nombre, c.presentacion,
       c.unidades AS unidades_cursor,
       ISNULL(a.unidades_anuladas, 0) AS unidades_anuladas,
       t.unidades_todas,
       CASE WHEN c.unidades + ISNULL(a.unidades_anuladas, 0) = t.unidades_todas THEN 'OK' ELSE 'ERROR' END AS resultado
FROM #cursor c
CROSS APPLY (SELECT SUM(dv.cantidad) AS unidades_todas
             FROM dbo.detalle_venta dv
             INNER JOIN dbo.venta v         ON v.id_venta = dv.id_venta
             INNER JOIN dbo.detalle_lote dl ON dl.id_detalle_lote = dv.id_detalle_lote
             WHERE dl.id_medicamento = c.id_medicamento
               AND v.fecha_venta >= @desde AND v.fecha_venta < DATEADD(DAY, 1, @hasta)) t
OUTER APPLY (SELECT SUM(dv.cantidad) AS unidades_anuladas
             FROM dbo.detalle_venta dv
             INNER JOIN dbo.venta v         ON v.id_venta = dv.id_venta
             INNER JOIN dbo.detalle_lote dl ON dl.id_detalle_lote = dv.id_detalle_lote
             WHERE dl.id_medicamento = c.id_medicamento AND v.estado = 'Anulada'
               AND v.fecha_venta >= @desde AND v.fecha_venta < DATEADD(DAY, 1, @hasta)) a
ORDER BY c.unidades DESC, c.monto DESC, c.id_medicamento;

IF NOT EXISTS (SELECT 1 FROM dbo.venta WHERE estado = 'Anulada' AND fecha_venta >= @desde AND fecha_venta < DATEADD(DAY, 1, @hasta))
    PRINT 'Aviso: no hay ventas anuladas en el periodo; anule una venta desde la aplicacion para ver este caso.';

/* ---------------------------------------------------------------------
   Prueba 4: el procedimiento es de solo lectura.
   Las tablas venta y detalle_venta quedan exactamente igual.
   --------------------------------------------------------------------- */
PRINT '--- Prueba 4: solo lectura ---';

IF @filas_venta_antes = (SELECT COUNT(*) FROM dbo.venta)
   AND @filas_detalle_antes = (SELECT COUNT(*) FROM dbo.detalle_venta)
   AND ISNULL(@venta_antes, 0) = ISNULL((SELECT CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.venta), 0)
   AND ISNULL(@detalle_antes, 0) = ISNULL((SELECT CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.detalle_venta), 0)
    PRINT 'OK: venta y detalle_venta no cambiaron.';
ELSE
    PRINT 'ERROR: las tablas de ventas cambiaron durante la prueba.';

/* ---------------------------------------------------------------------
   Prueba 5: el cursor se libero (CLOSE + DEALLOCATE). Debe devolver 0 filas.
   --------------------------------------------------------------------- */
PRINT '--- Prueba 5: cursores abiertos en esta sesion ---';
SELECT * FROM sys.dm_exec_cursors(@@SPID);

IF NOT EXISTS (SELECT 1 FROM sys.dm_exec_cursors(@@SPID))
    PRINT 'OK: no quedan cursores abiertos.';
ELSE
    PRINT 'ERROR: quedo un cursor abierto.';

DROP TABLE #cursor;
DROP TABLE #group_by;
GO
