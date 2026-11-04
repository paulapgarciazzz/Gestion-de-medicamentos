/* =====================================================================
   Data Warehouse: ejecutar la carga (ETL)
   Se puede ejecutar todas las veces que se quiera: la primera vez carga
   todo y las siguientes solo agregan lo nuevo o actualizan lo que cambio
   (por ejemplo, ventas anuladas despues de la carga anterior).
   ===================================================================== */

USE FarmaciaDW;
GO

EXEC etl.sp_ejecutar_etl;
GO

-- Ultimas cargas registradas
SELECT TOP (10) * FROM etl.log_carga ORDER BY id_carga DESC;
GO
