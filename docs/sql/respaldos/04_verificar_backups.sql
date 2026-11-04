/* =====================================================================
   Verificacion de los respaldos de farmacia (solo lectura)
   1. Historial de respaldos registrado en msdb.
   2. RESTORE VERIFYONLY WITH CHECKSUM sobre el ultimo backup completo, el
      ultimo diferencial y el ultimo de log (comprueba que el archivo existe,
      se puede leer y sus paginas no estan danadas). No restaura nada.
   ===================================================================== */

USE master;
GO

SET NOCOUNT ON;

-- 1. Historial (D = completo, I = diferencial, L = log)
SELECT TOP (30)
       b.backup_start_date                          AS inicio,
       CASE b.type WHEN 'D' THEN 'Completo' WHEN 'I' THEN 'Diferencial' WHEN 'L' THEN 'Log' END AS tipo,
       b.is_copy_only                               AS copy_only,
       b.has_backup_checksums                       AS con_checksum,
       CAST(b.backup_size / 1048576.0 AS DECIMAL(10,2))            AS mb,
       CAST(b.compressed_backup_size / 1048576.0 AS DECIMAL(10,2)) AS mb_comprimido,
       b.first_lsn, b.last_lsn, b.differential_base_lsn,
       f.physical_device_name                       AS archivo
FROM msdb.dbo.backupset b
INNER JOIN msdb.dbo.backupmediafamily f ON f.media_set_id = b.media_set_id
WHERE b.database_name = N'farmacia'
ORDER BY b.backup_start_date DESC;

-- 2. Verificar el ultimo archivo de cada tipo
DECLARE @tipo CHAR(1), @archivo NVARCHAR(500);
DECLARE @ultimos TABLE (tipo CHAR(1), archivo NVARCHAR(500));

INSERT INTO @ultimos
SELECT x.type, x.physical_device_name
FROM (
    SELECT b.type, f.physical_device_name,
           ROW_NUMBER() OVER (PARTITION BY b.type ORDER BY b.backup_finish_date DESC) AS n
    FROM msdb.dbo.backupset b
    INNER JOIN msdb.dbo.backupmediafamily f ON f.media_set_id = b.media_set_id
    WHERE b.database_name = N'farmacia'
) x
WHERE x.n = 1;

IF NOT EXISTS (SELECT 1 FROM @ultimos)
    PRINT 'No hay respaldos registrados para farmacia.';

WHILE EXISTS (SELECT 1 FROM @ultimos)
BEGIN
    SELECT TOP (1) @tipo = tipo, @archivo = archivo FROM @ultimos ORDER BY tipo;
    BEGIN TRY
        RESTORE VERIFYONLY FROM DISK = @archivo WITH CHECKSUM;
        PRINT CONCAT('OK (', @tipo, '): ', @archivo);
    END TRY
    BEGIN CATCH
        PRINT CONCAT('ERROR (', @tipo, '): ', @archivo, ' -> ', ERROR_MESSAGE());
    END CATCH
    DELETE FROM @ultimos WHERE tipo = @tipo;
END
GO
