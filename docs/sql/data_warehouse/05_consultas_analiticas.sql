/* =====================================================================
   Data Warehouse: consultas analiticas (solo lectura)
   Salvo la ultima consulta, se excluyen las ventas anuladas (es_anulada = 0).
   ===================================================================== */

USE FarmaciaDW;
GO

SET NOCOUNT ON;

-- 1. Ventas por mes
SELECT f.anio_mes, f.nombre_mes,
       COUNT(DISTINCT h.id_venta) AS ventas,
       SUM(h.cantidad)            AS unidades,
       SUM(h.subtotal)            AS subtotal,
       SUM(h.descuento)           AS descuentos,
       SUM(h.impuesto)            AS iva,
       SUM(h.total)               AS total,
       SUM(h.utilidad_bruta)      AS utilidad_bruta
FROM dw.fact_ventas h
INNER JOIN dw.dim_fecha f ON f.fecha_key = h.fecha_key
WHERE h.es_anulada = 0
GROUP BY f.anio_mes, f.nombre_mes
ORDER BY f.anio_mes;

-- 2. Ventas por categoria, con margen bruto sobre el ingreso neto
SELECT m.categoria,
       SUM(h.cantidad)       AS unidades,
       SUM(h.ingreso_neto)   AS ingreso_neto,
       SUM(h.costo_total)    AS costo,
       SUM(h.utilidad_bruta) AS utilidad_bruta,
       CAST(100.0 * SUM(h.utilidad_bruta) / NULLIF(SUM(h.ingreso_neto), 0) AS DECIMAL(5,2)) AS margen_pct
FROM dw.fact_ventas h
INNER JOIN dw.dim_medicamento m ON m.medicamento_key = h.medicamento_key
WHERE h.es_anulada = 0
GROUP BY m.categoria
ORDER BY ingreso_neto DESC;

-- 3. Medicamentos con mayor ingreso (separa presentaciones del mismo nombre)
SELECT TOP (10)
       m.descripcion_completa AS medicamento, m.categoria,
       SUM(h.cantidad)        AS unidades,
       SUM(h.total)           AS total,
       SUM(h.utilidad_bruta)  AS utilidad_bruta
FROM dw.fact_ventas h
INNER JOIN dw.dim_medicamento m ON m.medicamento_key = h.medicamento_key
WHERE h.es_anulada = 0
GROUP BY m.medicamento_key, m.descripcion_completa, m.categoria
ORDER BY total DESC;

-- 4. Clientes que mas compran (sin contar el Cliente General de mostrador)
SELECT TOP (10)
       c.nombre_completo, c.provincia,
       COUNT(DISTINCT h.id_venta) AS compras,
       SUM(h.total)               AS total_comprado,
       SUM(CASE WHEN h.con_receta = 1 THEN 1 ELSE 0 END) AS lineas_con_receta
FROM dw.fact_ventas h
INNER JOIN dw.dim_cliente c ON c.cliente_key = h.cliente_key
WHERE h.es_anulada = 0 AND c.es_cliente_general = 0
GROUP BY c.cliente_key, c.nombre_completo, c.provincia
ORDER BY total_comprado DESC;

-- 5. Ventas por provincia del cliente
SELECT c.provincia,
       COUNT(DISTINCT h.id_venta) AS ventas,
       SUM(h.total)               AS total
FROM dw.fact_ventas h
INNER JOIN dw.dim_cliente c ON c.cliente_key = h.cliente_key
WHERE h.es_anulada = 0
GROUP BY c.provincia
ORDER BY total DESC;

-- 6. Metodo de pago por mes
SELECT f.anio_mes, p.metodo_pago,
       COUNT(DISTINCT h.id_venta) AS ventas,
       SUM(h.total)               AS total
FROM dw.fact_ventas h
INNER JOIN dw.dim_fecha f       ON f.fecha_key = h.fecha_key
INNER JOIN dw.dim_metodo_pago p ON p.metodo_pago_key = h.metodo_pago_key
WHERE h.es_anulada = 0
GROUP BY f.anio_mes, p.metodo_pago
ORDER BY f.anio_mes, total DESC;

-- 7. Dia de la semana con mas ventas
SELECT f.dia_semana, f.nombre_dia,
       COUNT(DISTINCT h.id_venta) AS ventas,
       SUM(h.total)               AS total
FROM dw.fact_ventas h
INNER JOIN dw.dim_fecha f ON f.fecha_key = h.fecha_key
WHERE h.es_anulada = 0
GROUP BY f.dia_semana, f.nombre_dia
ORDER BY f.dia_semana;

-- 8. Ventas anuladas (identificadas, no sumadas en los analisis anteriores)
SELECT f.anio_mes,
       COUNT(DISTINCT h.id_venta) AS ventas_anuladas,
       SUM(h.cantidad)            AS unidades,
       SUM(h.total)               AS total_anulado
FROM dw.fact_ventas h
INNER JOIN dw.dim_fecha f ON f.fecha_key = h.fecha_key
WHERE h.es_anulada = 1
GROUP BY f.anio_mes
ORDER BY f.anio_mes;

/* ----------------------------- Inventario y caducidad -----------------------------
   Se usa la ultima foto (el dia mas reciente cargado en fact_inventario). */
DECLARE @ultima INT = (SELECT MAX(fecha_key) FROM dw.fact_inventario);

-- 9. Valor del inventario vendible por categoria (ultima foto)
SELECT m.categoria,
       SUM(i.unidades_disponibles) AS unidades,
       SUM(i.valor_costo)          AS valor_costo,
       SUM(i.valor_venta)          AS valor_venta,
       SUM(i.valor_venta) - SUM(i.valor_costo) AS utilidad_potencial
FROM dw.fact_inventario i
INNER JOIN dw.dim_medicamento m ON m.medicamento_key = i.medicamento_key
WHERE i.fecha_key = @ultima AND i.es_vendible = 1
GROUP BY m.categoria
ORDER BY valor_costo DESC;

-- 10. Caducidad: lotes vencidos o por vencer (90 dias) con unidades, y el dinero en riesgo
SELECT i.estado_caducidad, m.descripcion_completa AS medicamento, l.numero_lote,
       l.fecha_vencimiento, i.dias_para_vencer, i.unidades_disponibles, i.valor_en_riesgo
FROM dw.fact_inventario i
INNER JOIN dw.dim_lote l        ON l.lote_key = i.lote_key
INNER JOIN dw.dim_medicamento m ON m.medicamento_key = i.medicamento_key
WHERE i.fecha_key = @ultima AND i.estado_caducidad <> N'Vigente' AND i.unidades_disponibles > 0
ORDER BY i.dias_para_vencer;

-- 11. Resumen de caducidad (ultima foto)
SELECT i.estado_caducidad,
       COUNT(*)                    AS lotes,
       SUM(i.unidades_disponibles) AS unidades,
       SUM(i.valor_costo)          AS valor_costo,
       SUM(i.valor_en_riesgo)      AS valor_en_riesgo
FROM dw.fact_inventario i
WHERE i.fecha_key = @ultima AND i.unidades_disponibles > 0
GROUP BY i.estado_caducidad
ORDER BY CASE i.estado_caducidad WHEN N'Vencido' THEN 1 WHEN N'Por vencer' THEN 2 ELSE 3 END;

-- 12. Evolucion del stock vendible por dia (una fila por foto)
SELECT f.fecha, SUM(i.unidades_disponibles) AS unidades, SUM(i.valor_costo) AS valor_costo,
       SUM(i.valor_en_riesgo) AS valor_en_riesgo
FROM dw.fact_inventario i
INNER JOIN dw.dim_fecha f ON f.fecha_key = i.fecha_key
WHERE i.es_vendible = 1
GROUP BY f.fecha
ORDER BY f.fecha;
GO
