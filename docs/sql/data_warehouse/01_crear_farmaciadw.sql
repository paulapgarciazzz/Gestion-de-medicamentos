/* =====================================================================
   Data Warehouse: creacion de la base FarmaciaDW
   - Crea la base solo si no existe (nunca la elimina ni la reemplaza).
   - Modelo de recuperacion SIMPLE: el DW se reconstruye con el ETL a partir
     de farmacia, no necesita backups de log.
   - Esquemas:
       dw     -> tablas del modelo estrella (dimensiones y hechos)
       etl    -> procedimientos de carga y bitacora
       origen -> sinonimos que apuntan a las tablas de farmacia (solo lectura)
   - No modifica la base farmacia.
   ===================================================================== */

USE master;
GO

IF DB_ID(N'FarmaciaDW') IS NULL
BEGIN
    CREATE DATABASE FarmaciaDW;
    ALTER DATABASE FarmaciaDW SET RECOVERY SIMPLE;
    PRINT 'Base FarmaciaDW creada.';
END
ELSE
    PRINT 'La base FarmaciaDW ya existe: no se modifica.';
GO

USE FarmaciaDW;
GO

IF SCHEMA_ID(N'dw') IS NULL     EXEC (N'CREATE SCHEMA dw');
IF SCHEMA_ID(N'etl') IS NULL    EXEC (N'CREATE SCHEMA etl');
IF SCHEMA_ID(N'origen') IS NULL EXEC (N'CREATE SCHEMA origen');
GO

/* Sinonimos hacia la base transaccional. Si la base de origen tuviera otro
   nombre, basta con recrear estos sinonimos. */
IF OBJECT_ID(N'origen.venta', N'SN') IS NULL          CREATE SYNONYM origen.venta          FOR farmacia.dbo.venta;
IF OBJECT_ID(N'origen.detalle_venta', N'SN') IS NULL  CREATE SYNONYM origen.detalle_venta  FOR farmacia.dbo.detalle_venta;
IF OBJECT_ID(N'origen.detalle_lote', N'SN') IS NULL   CREATE SYNONYM origen.detalle_lote   FOR farmacia.dbo.detalle_lote;
IF OBJECT_ID(N'origen.detalle_compra', N'SN') IS NULL CREATE SYNONYM origen.detalle_compra FOR farmacia.dbo.detalle_compra;
IF OBJECT_ID(N'origen.medicamento', N'SN') IS NULL    CREATE SYNONYM origen.medicamento    FOR farmacia.dbo.medicamento;
IF OBJECT_ID(N'origen.categoria', N'SN') IS NULL      CREATE SYNONYM origen.categoria      FOR farmacia.dbo.categoria;
IF OBJECT_ID(N'origen.presentacion', N'SN') IS NULL   CREATE SYNONYM origen.presentacion   FOR farmacia.dbo.presentacion;
IF OBJECT_ID(N'origen.cliente', N'SN') IS NULL        CREATE SYNONYM origen.cliente        FOR farmacia.dbo.cliente;
GO

PRINT 'Esquemas y sinonimos listos.';
GO
