/* =====================================================================
   Permisos de la cuenta con la que la aplicación se conecta a SQL Server
   ---------------------------------------------------------------------
   Con autenticación integrada de Windows (DB_USERNAME vacío en .env) la
   aplicación entra con la cuenta del servicio Apache. Si Apache corre como
   servicio de Windows con "Sistema local", esa cuenta es NT AUTHORITY\SYSTEM.
   Si su Apache usa otra cuenta (o un usuario SQL), reemplace
   [NT AUTHORITY\SYSTEM] por esa cuenta en todo el archivo (Ctrl+H).

   Requisitos: ejecutar ANTES
     - docs/sql/sp_reporte_ventas_medicamento.sql
     - docs/sql/procedimientos_negocio.sql
     - docs/sql/respaldos/06_respaldo_desde_aplicacion.sql
     - docs/sql/data_warehouse/01 a 03
   Se puede ejecutar varias veces.
   ===================================================================== */

USE master;
GO
IF SUSER_ID(N'NT AUTHORITY\SYSTEM') IS NULL
    CREATE LOGIN [NT AUTHORITY\SYSTEM] FROM WINDOWS;
GO

/* ---------- farmacia: datos, procedimientos y respaldos ---------- */
USE farmacia;
GO
IF USER_ID(N'NT AUTHORITY\SYSTEM') IS NULL
    CREATE USER [NT AUTHORITY\SYSTEM] FOR LOGIN [NT AUTHORITY\SYSTEM];
ALTER ROLE db_datareader     ADD MEMBER [NT AUTHORITY\SYSTEM];
ALTER ROLE db_datawriter     ADD MEMBER [NT AUTHORITY\SYSTEM];
ALTER ROLE db_backupoperator ADD MEMBER [NT AUTHORITY\SYSTEM];   -- BACKUP DATABASE / BACKUP LOG

GRANT EXECUTE ON OBJECT::dbo.sp_reporte_ventas_medicamento TO [NT AUTHORITY\SYSTEM];
GRANT EXECUTE ON OBJECT::dbo.sp_registrar_venta            TO [NT AUTHORITY\SYSTEM];
GRANT EXECUTE ON OBJECT::dbo.sp_anular_venta               TO [NT AUTHORITY\SYSTEM];
GRANT EXECUTE ON OBJECT::dbo.sp_registrar_compra           TO [NT AUTHORITY\SYSTEM];
GRANT EXECUTE ON OBJECT::dbo.sp_respaldar_farmacia         TO [NT AUTHORITY\SYSTEM];
GO

/* ---------- FarmaciaDW: ejecutar el ETL y leer los reportes ---------- */
USE FarmaciaDW;
GO
IF USER_ID(N'NT AUTHORITY\SYSTEM') IS NULL
    CREATE USER [NT AUTHORITY\SYSTEM] FOR LOGIN [NT AUTHORITY\SYSTEM];
GRANT EXECUTE ON SCHEMA::etl TO [NT AUTHORITY\SYSTEM];   -- etl.sp_ejecutar_etl y sus pasos
GRANT SELECT  ON SCHEMA::etl TO [NT AUTHORITY\SYSTEM];   -- bitácora etl.log_carga
GRANT SELECT  ON SCHEMA::dw  TO [NT AUTHORITY\SYSTEM];   -- dimensiones y hechos
GO

PRINT 'Permisos de la aplicación listos.';
GO
