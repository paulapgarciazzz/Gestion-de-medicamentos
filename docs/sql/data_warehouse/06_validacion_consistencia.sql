/* =====================================================================
   Data Warehouse: validacion de consistencia contra farmacia
   Compara los totales del DW con la base transaccional. Cada comprobacion
   devuelve OK o ERROR. La ultima ejecuta el ETL otra vez para demostrar que
   no duplica datos (solo escribe en FarmaciaDW: bitacora y nada mas).
   ===================================================================== */

USE FarmaciaDW;
GO

SET NOCOUNT ON;

DECLARE @r TABLE (n INT IDENTITY(1,1), comprobacion NVARCHAR(200), resultado NVARCHAR(10), detalle NVARCHAR(400));

/* 1. Dimensiones: una fila por registro del origen */
INSERT INTO @r (comprobacion, resultado, detalle)
SELECT N'Medicamentos: origen = dimension',
       IIF(o.n = d.n, N'OK', N'ERROR'), CONCAT(N'origen ', o.n, N' | DW ', d.n)
FROM (SELECT COUNT(*) AS n FROM origen.medicamento) o, (SELECT COUNT(*) AS n FROM dw.dim_medicamento) d
UNION ALL
SELECT N'Clientes: origen = dimension',
       IIF(o.n = d.n, N'OK', N'ERROR'), CONCAT(N'origen ', o.n, N' | DW ', d.n)
FROM (SELECT COUNT(*) AS n FROM origen.cliente) o, (SELECT COUNT(*) AS n FROM dw.dim_cliente) d;

/* 2. Lineas, unidades y montos por estado de la venta */
WITH o AS (
    SELECT v.estado, COUNT(*) AS lineas, SUM(dv.cantidad) AS unidades, SUM(dv.subtotal) AS subtotal,
           SUM(dv.descuento) AS descuento, SUM(dv.total) AS total
    FROM origen.detalle_venta dv INNER JOIN origen.venta v ON v.id_venta = dv.id_venta
    GROUP BY v.estado
), d AS (
    SELECT estado_venta AS estado, COUNT(*) AS lineas, SUM(cantidad) AS unidades, SUM(subtotal) AS subtotal,
           SUM(descuento) AS descuento, SUM(total) AS total
    FROM dw.fact_ventas
    GROUP BY estado_venta
)
INSERT INTO @r (comprobacion, resultado, detalle)
SELECT CONCAT(N'Ventas ', ISNULL(o.estado, d.estado), N': lineas, unidades y montos'),
       IIF(o.lineas = d.lineas AND o.unidades = d.unidades AND o.subtotal = d.subtotal
           AND o.descuento = d.descuento AND o.total = d.total, N'OK', N'ERROR'),
       CONCAT(N'lineas ', o.lineas, N'/', d.lineas, N' | unidades ', o.unidades, N'/', d.unidades,
              N' | total ', o.total, N'/', d.total)
FROM o FULL OUTER JOIN d ON d.estado = o.estado;

/* 3. Cada venta: el encabezado de farmacia coincide con la suma de sus lineas en el DW */
INSERT INTO @r (comprobacion, resultado, detalle)
SELECT N'Encabezado de cada venta = suma de sus lineas en el DW',
       IIF(x.n = 0, N'OK', N'ERROR'), CONCAT(x.n, N' ventas con diferencias')
FROM (
    SELECT COUNT(*) AS n FROM (
        SELECT v.id_venta, v.subtotal, v.descuento, v.impuesto, v.total FROM origen.venta v
        WHERE EXISTS (SELECT 1 FROM origen.detalle_venta dv WHERE dv.id_venta = v.id_venta)
        EXCEPT
        SELECT id_venta, SUM(subtotal), SUM(descuento), SUM(impuesto), SUM(total) FROM dw.fact_ventas GROUP BY id_venta
    ) dif
) x;

/* 4. Unidades y total por mes y medicamento (sin anuladas), en ambos sentidos */
WITH o AS (
    SELECT CONVERT(CHAR(7), v.fecha_venta, 120) AS anio_mes, dl.id_medicamento,
           SUM(dv.cantidad) AS unidades, SUM(dv.total) AS total
    FROM origen.detalle_venta dv
    INNER JOIN origen.venta v         ON v.id_venta = dv.id_venta
    INNER JOIN origen.detalle_lote dl ON dl.id_detalle_lote = dv.id_detalle_lote
    WHERE v.estado <> 'Anulada'
    GROUP BY CONVERT(CHAR(7), v.fecha_venta, 120), dl.id_medicamento
), d AS (
    SELECT f.anio_mes, m.id_medicamento, SUM(h.cantidad) AS unidades, SUM(h.total) AS total
    FROM dw.fact_ventas h
    INNER JOIN dw.dim_fecha f       ON f.fecha_key = h.fecha_key
    INNER JOIN dw.dim_medicamento m ON m.medicamento_key = h.medicamento_key
    WHERE h.es_anulada = 0
    GROUP BY f.anio_mes, m.id_medicamento
)
INSERT INTO @r (comprobacion, resultado, detalle)
SELECT N'Mes x medicamento: origen y DW iguales (sin anuladas)',
       IIF(a.n + b.n = 0, N'OK', N'ERROR'), CONCAT(a.n, N' filas solo en origen | ', b.n, N' filas solo en DW')
FROM (SELECT COUNT(*) AS n FROM (SELECT * FROM o EXCEPT SELECT * FROM d) x) a,
     (SELECT COUNT(*) AS n FROM (SELECT * FROM d EXCEPT SELECT * FROM o) x) b;

/* 5. El Top 10 del DW coincide con el reporte con cursor de farmacia */
DECLARE @desde DATE = (SELECT CAST(MIN(fecha_venta) AS DATE) FROM origen.venta);
DECLARE @hasta DATE = (SELECT CAST(MAX(fecha_venta) AS DATE) FROM origen.venta);
IF OBJECT_ID('tempdb..#top_cursor') IS NOT NULL DROP TABLE #top_cursor;
CREATE TABLE #top_cursor (id_medicamento INT, nombre VARCHAR(100), concentracion VARCHAR(50),
                          presentacion VARCHAR(50), unidades INT, monto DECIMAL(14,2));
IF @desde IS NOT NULL
    INSERT INTO #top_cursor EXEC farmacia.dbo.sp_reporte_ventas_medicamento @desde = @desde, @hasta = @hasta;

WITH dw_top AS (
    SELECT TOP (10) m.id_medicamento, SUM(h.cantidad) AS unidades, SUM(h.total) AS monto
    FROM dw.fact_ventas h
    INNER JOIN dw.dim_medicamento m ON m.medicamento_key = h.medicamento_key
    WHERE h.es_anulada = 0
    GROUP BY m.id_medicamento
    ORDER BY unidades DESC, monto DESC, m.id_medicamento
)
INSERT INTO @r (comprobacion, resultado, detalle)
SELECT N'Top 10 del DW = Top 10 del procedimiento con cursor',
       IIF(a.n + b.n = 0, N'OK', N'ERROR'), CONCAT(a.n + b.n, N' diferencias')
FROM (SELECT COUNT(*) AS n FROM (SELECT id_medicamento, unidades, monto FROM #top_cursor EXCEPT SELECT * FROM dw_top) x) a,
     (SELECT COUNT(*) AS n FROM (SELECT * FROM dw_top EXCEPT SELECT id_medicamento, unidades, monto FROM #top_cursor) x) b;

/* 6. Volver a ejecutar el ETL no duplica ni cambia nada */
DECLARE @antes INT = (SELECT COUNT(*) FROM dw.fact_ventas);
DECLARE @log TABLE (id_carga INT, inicio DATETIME2(0), fin DATETIME2(0), estado NVARCHAR(20), fechas_insertadas INT,
                    medicamentos_insertados INT, medicamentos_actualizados INT, clientes_insertados INT,
                    clientes_actualizados INT, metodos_insertados INT, hechos_insertados INT,
                    hechos_actualizados INT, mensaje NVARCHAR(4000));
INSERT INTO @log EXEC etl.sp_ejecutar_etl;
DECLARE @despues INT = (SELECT COUNT(*) FROM dw.fact_ventas);

INSERT INTO @r (comprobacion, resultado, detalle)
SELECT N'Segunda ejecucion del ETL: sin duplicados ni cambios',
       IIF(@antes = @despues AND l.hechos_insertados = 0 AND l.hechos_actualizados = 0
           AND l.medicamentos_insertados + l.medicamentos_actualizados + l.clientes_insertados
             + l.clientes_actualizados + l.metodos_insertados + l.fechas_insertadas = 0, N'OK', N'ERROR'),
       CONCAT(N'hechos antes ', @antes, N' | despues ', @despues, N' | insertados ', l.hechos_insertados,
              N' | actualizados ', l.hechos_actualizados)
FROM @log l;

SELECT n, comprobacion, resultado, detalle FROM @r ORDER BY n;

IF EXISTS (SELECT 1 FROM @r WHERE resultado <> N'OK')
    PRINT 'ERROR: hay comprobaciones con diferencias.';
ELSE
    PRINT 'OK: el Data Warehouse coincide con farmacia en todas las comprobaciones.';
GO
