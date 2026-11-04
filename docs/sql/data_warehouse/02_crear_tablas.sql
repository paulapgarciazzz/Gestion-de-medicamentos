/* =====================================================================
   Data Warehouse: tablas del modelo estrella
   Crea cada tabla solo si no existe (no borra ni recrea nada).

   Grano de la tabla de hechos dw.fact_ventas:
     UNA FILA POR CADA LINEA DE VENTA (farmacia.dbo.detalle_venta),
     es decir, un medicamento (lote) vendido en una venta concreta.
   Las ventas anuladas tambien se cargan, marcadas con es_anulada = 1,
   para poder identificarlas o excluirlas en los analisis.

                         dim_fecha
                             |
     dim_medicamento --- fact_ventas --- dim_cliente
                             |
                      dim_metodo_pago
   ===================================================================== */

USE FarmaciaDW;
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

/* ----------------------------- Dimensiones ----------------------------- */

IF OBJECT_ID(N'dw.dim_fecha', N'U') IS NULL
CREATE TABLE dw.dim_fecha (
    fecha_key     INT          NOT NULL,   -- aaaammdd, por ejemplo 20261103
    fecha         DATE         NOT NULL,
    anio          SMALLINT     NOT NULL,
    trimestre     TINYINT      NOT NULL,
    mes           TINYINT      NOT NULL,
    nombre_mes    NVARCHAR(15) NOT NULL,
    anio_mes      CHAR(7)      NOT NULL,   -- aaaa-mm
    dia           TINYINT      NOT NULL,
    dia_semana    TINYINT      NOT NULL,   -- 1 = lunes ... 7 = domingo
    nombre_dia    NVARCHAR(15) NOT NULL,
    es_fin_semana BIT          NOT NULL,
    CONSTRAINT PK_dim_fecha PRIMARY KEY (fecha_key),
    CONSTRAINT UQ_dim_fecha_fecha UNIQUE (fecha)
);
GO

IF OBJECT_ID(N'dw.dim_medicamento', N'U') IS NULL
CREATE TABLE dw.dim_medicamento (
    medicamento_key      INT IDENTITY(1,1) NOT NULL,
    id_medicamento       INT           NOT NULL,   -- clave en farmacia
    nombre               NVARCHAR(100) NOT NULL,
    concentracion        NVARCHAR(50)  NULL,
    presentacion         NVARCHAR(50)  NOT NULL,
    descripcion_completa NVARCHAR(220) NOT NULL,   -- nombre + concentracion + presentacion
    categoria            NVARCHAR(30)  NOT NULL,
    requiere_receta      BIT           NOT NULL,
    iva_porcentaje       DECIMAL(5,2)  NOT NULL,
    codigo_barras        NVARCHAR(50)  NULL,
    fecha_actualizacion  DATETIME2(0)  NOT NULL CONSTRAINT DF_dim_medicamento_fecha DEFAULT SYSDATETIME(),
    CONSTRAINT PK_dim_medicamento PRIMARY KEY (medicamento_key),
    CONSTRAINT UQ_dim_medicamento_id UNIQUE (id_medicamento)
);
GO

IF OBJECT_ID(N'dw.dim_cliente', N'U') IS NULL
CREATE TABLE dw.dim_cliente (
    cliente_key         INT IDENTITY(1,1) NOT NULL,
    id_cliente          INT           NOT NULL,   -- clave en farmacia
    nombre_completo     NVARCHAR(210) NOT NULL,
    identificacion      NVARCHAR(50)  NOT NULL,
    provincia           NVARCHAR(50)  NOT NULL,   -- texto antes de la primera coma de la direccion
    es_cliente_general  BIT           NOT NULL,   -- ventas de mostrador (identificacion '0')
    fecha_actualizacion DATETIME2(0)  NOT NULL CONSTRAINT DF_dim_cliente_fecha DEFAULT SYSDATETIME(),
    CONSTRAINT PK_dim_cliente PRIMARY KEY (cliente_key),
    CONSTRAINT UQ_dim_cliente_id UNIQUE (id_cliente)
);
GO

IF OBJECT_ID(N'dw.dim_metodo_pago', N'U') IS NULL
CREATE TABLE dw.dim_metodo_pago (
    metodo_pago_key INT IDENTITY(1,1) NOT NULL,
    metodo_pago     NVARCHAR(30) NOT NULL,
    CONSTRAINT PK_dim_metodo_pago PRIMARY KEY (metodo_pago_key),
    CONSTRAINT UQ_dim_metodo_pago UNIQUE (metodo_pago)
);
GO

/* ----------------------------- Hechos ----------------------------- */

IF OBJECT_ID(N'dw.fact_ventas', N'U') IS NULL
CREATE TABLE dw.fact_ventas (
    venta_linea_key   BIGINT IDENTITY(1,1) NOT NULL,
    id_detalle_venta  INT           NOT NULL,   -- clave natural del grano (evita duplicados)
    id_venta          INT           NOT NULL,   -- dimension degenerada: numero de venta
    fecha_key         INT           NOT NULL,
    medicamento_key   INT           NOT NULL,
    cliente_key       INT           NOT NULL,
    metodo_pago_key   INT           NOT NULL,
    numero_lote       NVARCHAR(50)  NOT NULL,
    estado_venta      NVARCHAR(30)  NOT NULL,
    es_anulada        BIT           NOT NULL,
    con_receta        BIT           NOT NULL,
    -- Medidas
    cantidad          INT           NOT NULL,
    precio_unitario   DECIMAL(12,2) NOT NULL,
    subtotal          DECIMAL(14,2) NOT NULL,   -- cantidad x precio
    descuento         DECIMAL(14,2) NOT NULL,
    ingreso_neto      DECIMAL(14,2) NOT NULL,   -- subtotal - descuento (sin IVA)
    impuesto          DECIMAL(14,2) NOT NULL,   -- IVA de la linea
    total             DECIMAL(14,2) NOT NULL,   -- ingreso_neto + impuesto
    costo_unitario    DECIMAL(12,2) NOT NULL,   -- costo real del lote segun detalle_compra
    costo_total       DECIMAL(14,2) NOT NULL,
    utilidad_bruta    DECIMAL(14,2) NOT NULL,   -- ingreso_neto - costo_total
    fecha_carga         DATETIME2(0) NOT NULL CONSTRAINT DF_fact_ventas_carga DEFAULT SYSDATETIME(),
    fecha_actualizacion DATETIME2(0) NULL,
    CONSTRAINT PK_fact_ventas PRIMARY KEY (venta_linea_key),
    CONSTRAINT UQ_fact_ventas_detalle UNIQUE (id_detalle_venta),
    CONSTRAINT FK_fact_ventas_fecha       FOREIGN KEY (fecha_key)       REFERENCES dw.dim_fecha (fecha_key),
    CONSTRAINT FK_fact_ventas_medicamento FOREIGN KEY (medicamento_key) REFERENCES dw.dim_medicamento (medicamento_key),
    CONSTRAINT FK_fact_ventas_cliente     FOREIGN KEY (cliente_key)     REFERENCES dw.dim_cliente (cliente_key),
    CONSTRAINT FK_fact_ventas_metodo_pago FOREIGN KEY (metodo_pago_key) REFERENCES dw.dim_metodo_pago (metodo_pago_key)
);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_fact_ventas_fecha' AND object_id = OBJECT_ID(N'dw.fact_ventas'))
    CREATE INDEX IX_fact_ventas_fecha ON dw.fact_ventas (fecha_key) INCLUDE (cantidad, total, es_anulada);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_fact_ventas_medicamento' AND object_id = OBJECT_ID(N'dw.fact_ventas'))
    CREATE INDEX IX_fact_ventas_medicamento ON dw.fact_ventas (medicamento_key);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_fact_ventas_cliente' AND object_id = OBJECT_ID(N'dw.fact_ventas'))
    CREATE INDEX IX_fact_ventas_cliente ON dw.fact_ventas (cliente_key);
GO

/* ----------------------------- Bitacora del ETL ----------------------------- */

IF OBJECT_ID(N'etl.log_carga', N'U') IS NULL
CREATE TABLE etl.log_carga (
    id_carga                  INT IDENTITY(1,1) NOT NULL,
    inicio                    DATETIME2(0)   NOT NULL,
    fin                       DATETIME2(0)   NULL,
    estado                    NVARCHAR(20)   NOT NULL,   -- En proceso / Correcto / Error
    fechas_insertadas         INT NULL,
    medicamentos_insertados   INT NULL,
    medicamentos_actualizados INT NULL,
    clientes_insertados       INT NULL,
    clientes_actualizados     INT NULL,
    metodos_insertados        INT NULL,
    hechos_insertados         INT NULL,
    hechos_actualizados       INT NULL,
    mensaje                   NVARCHAR(4000) NULL,
    CONSTRAINT PK_log_carga PRIMARY KEY (id_carga)
);
GO

PRINT 'Tablas del Data Warehouse listas.';
GO
