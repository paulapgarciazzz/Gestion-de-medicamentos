/* =====================================================================
   Script de base de datos: Farmacia
   Motor: SQL Server (T-SQL)
   Generado a partir del diagrama entidad-relacion (15 tablas)
   ===================================================================== */

CREATE DATABASE farmacia;
GO

USE farmacia;
GO

/* ---------------------------------------------------------------------
   1. TABLAS INDEPENDIENTES (sin llaves foraneas)
   --------------------------------------------------------------------- */

CREATE TABLE categoria (
    id_categoria    INT IDENTITY(1,1) NOT NULL,
    nombre          VARCHAR(30)       NOT NULL,
    descripcion     VARCHAR(50)       NULL,
    CONSTRAINT PK_categoria PRIMARY KEY (id_categoria)
);
GO

CREATE TABLE presentacion (
    id_presentacion INT IDENTITY(1,1) NOT NULL,
    nombre          VARCHAR(50)       NOT NULL,
    CONSTRAINT PK_presentacion PRIMARY KEY (id_presentacion)
);
GO

CREATE TABLE proveedor (
    id_proveedor     INT IDENTITY(1,1) NOT NULL,
    nombre           VARCHAR(50)       NOT NULL,
    identificacion   VARCHAR(50)       NOT NULL,
    telefono         VARCHAR(20)       NULL,
    direccion        VARCHAR(100)      NULL,
    correo           VARCHAR(50)       NULL,
    condiciones_pago VARCHAR(100)      NULL,
    CONSTRAINT PK_proveedor PRIMARY KEY (id_proveedor)
);
GO

CREATE TABLE usuario (
    id_usuario       INT IDENTITY(1,1) NOT NULL,
    nombre           VARCHAR(20)       NOT NULL,
    primer_apellido  VARCHAR(20)       NOT NULL,
    segundo_apellido VARCHAR(20)       NULL,
    correo           VARCHAR(100)      NOT NULL,
    contrasenna      VARCHAR(8)        NOT NULL,
    rol              VARCHAR(20)       NOT NULL,
    estado           BIT               NOT NULL DEFAULT 1,
    fecha_registro   DATETIME          NOT NULL DEFAULT GETDATE(),
    CONSTRAINT PK_usuario PRIMARY KEY (id_usuario)
);
GO

CREATE TABLE cliente (
    id_cliente       INT IDENTITY(1,1) NOT NULL,
    primer_nombre    VARCHAR(50)       NOT NULL,
    segundo_nombre   VARCHAR(50)       NULL,
    primer_apellido  VARCHAR(50)       NOT NULL,
    segundo_apellido VARCHAR(50)       NULL,
    identificacion   VARCHAR(50)       NOT NULL,
    telefono         VARCHAR(20)       NULL,
    correo           VARCHAR(50)       NULL,
    direccion        VARCHAR(50)       NULL,
    CONSTRAINT PK_cliente PRIMARY KEY (id_cliente)
);
GO

CREATE TABLE lote (
    id_lote           INT IDENTITY(1,1) NOT NULL,
    numero_lote       VARCHAR(50)       NOT NULL,
    fecha_fabricacion DATE              NOT NULL,
    fecha_vencimiento DATE              NOT NULL,
    cantidad_inicial  INT               NOT NULL,
    estado            VARCHAR(30)       NOT NULL,
    CONSTRAINT PK_lote PRIMARY KEY (id_lote)
);
GO

/* ---------------------------------------------------------------------
   2. MEDICAMENTO Y SUS RELACIONES
   --------------------------------------------------------------------- */

CREATE TABLE medicamento (
    id_medicamento            INT IDENTITY(1,1) NOT NULL,
    id_categoria              INT               NOT NULL,
    nombre                    VARCHAR(100)      NOT NULL,
    presentacion              VARCHAR(50)       NULL,
    concentracion             VARCHAR(50)       NULL,
    precio_venta              DECIMAL(12,2)     NOT NULL,
    requiere_receta           BIT               NOT NULL DEFAULT 0,
    condicion_almacenamiento  VARCHAR(100)      NULL,
    codigo_barras             VARCHAR(50)       NULL,
    precio_costo              DECIMAL(12,2)     NOT NULL,
    iva_porcentaje            DECIMAL(5,2)      NOT NULL,
    CONSTRAINT PK_medicamento PRIMARY KEY (id_medicamento),
    CONSTRAINT FK_medicamento_categoria
        FOREIGN KEY (id_categoria) REFERENCES categoria (id_categoria)
);
GO

CREATE TABLE medicamento_presentacion (
    id_medicamento_presentacion INT IDENTITY(1,1) NOT NULL,
    id_presentacion             INT               NOT NULL,
    id_medicamento              INT               NOT NULL,
    CONSTRAINT PK_medicamento_presentacion PRIMARY KEY (id_medicamento_presentacion),
    CONSTRAINT FK_medpres_presentacion
        FOREIGN KEY (id_presentacion) REFERENCES presentacion (id_presentacion),
    CONSTRAINT FK_medpres_medicamento
        FOREIGN KEY (id_medicamento)  REFERENCES medicamento (id_medicamento)
);
GO

CREATE TABLE detalle_lote (
    id_detalle_lote   INT IDENTITY(1,1) NOT NULL,
    id_medicamento    INT               NOT NULL,
    id_lote           INT               NOT NULL,
    numero_lote       VARCHAR(50)       NOT NULL,
    fecha_fabricacion DATE              NOT NULL,
    fecha_vencimiento DATE              NOT NULL,
    cantidad_inicial  INT               NOT NULL,
    estado            VARCHAR(30)       NOT NULL,
    CONSTRAINT PK_detalle_lote PRIMARY KEY (id_detalle_lote),
    CONSTRAINT FK_detallelote_medicamento
        FOREIGN KEY (id_medicamento) REFERENCES medicamento (id_medicamento),
    CONSTRAINT FK_detallelote_lote
        FOREIGN KEY (id_lote)        REFERENCES lote (id_lote)
);
GO

/* ---------------------------------------------------------------------
   3. RECETA Y VENTAS
   --------------------------------------------------------------------- */

CREATE TABLE receta (
    id_receta         INT IDENTITY(1,1) NOT NULL,
    id_cliente        INT               NOT NULL,
    id_medicamento    INT               NOT NULL,
    numero_receta     INT               NOT NULL,
    fecha_emision     DATE              NOT NULL,
    fecha_vencimiento DATE              NOT NULL,
    indicaciones      VARCHAR(100)      NULL,
    duracion          VARCHAR(20)       NULL,
    diagnostico       VARCHAR(100)      NULL,
    observaciones     VARCHAR(100)      NULL,
    dosis             VARCHAR(50)       NULL,
    CONSTRAINT PK_receta PRIMARY KEY (id_receta),
    CONSTRAINT FK_receta_cliente
        FOREIGN KEY (id_cliente)     REFERENCES cliente (id_cliente),
    CONSTRAINT FK_receta_medicamento
        FOREIGN KEY (id_medicamento) REFERENCES medicamento (id_medicamento)
);
GO

CREATE TABLE venta (
    id_venta     INT IDENTITY(1,1) NOT NULL,
    id_cliente   INT               NOT NULL,
    id_receta    INT               NULL,   -- solo aplica a medicamentos con receta
    fecha_venta  DATETIME          NOT NULL DEFAULT GETDATE(),
    subtotal     DECIMAL(10,2)     NOT NULL,
    impuesto     DECIMAL(10,2)     NOT NULL,
    descuento    DECIMAL(10,2)     NOT NULL DEFAULT 0,
    total        DECIMAL(10,2)     NOT NULL,
    metodo_pago  VARCHAR(30)       NOT NULL,
    estado       VARCHAR(30)       NOT NULL,
    CONSTRAINT PK_venta PRIMARY KEY (id_venta),
    CONSTRAINT FK_venta_cliente
        FOREIGN KEY (id_cliente) REFERENCES cliente (id_cliente),
    CONSTRAINT FK_venta_receta
        FOREIGN KEY (id_receta)  REFERENCES receta (id_receta)
);
GO

CREATE TABLE detalle_venta (
    id_detalle_venta INT IDENTITY(1,1) NOT NULL,
    id_venta         INT               NOT NULL,
    id_detalle_lote  INT               NOT NULL,
    cantidad         INT               NOT NULL,
    precio_unidad    DECIMAL(10,2)     NOT NULL,
    descuento        DECIMAL(10,2)     NOT NULL DEFAULT 0,
    subtotal         DECIMAL(10,2)     NOT NULL,
    total            DECIMAL(10,2)     NOT NULL,
    CONSTRAINT PK_detalle_venta PRIMARY KEY (id_detalle_venta),
    CONSTRAINT FK_detalleventa_venta
        FOREIGN KEY (id_venta)        REFERENCES venta (id_venta),
    CONSTRAINT FK_detalleventa_detallelote
        FOREIGN KEY (id_detalle_lote) REFERENCES detalle_lote (id_detalle_lote)
);
GO

/* ---------------------------------------------------------------------
   4. COMPRAS
   --------------------------------------------------------------------- */

CREATE TABLE compra (
    id_compra      INT IDENTITY(1,1) NOT NULL,
    id_proveedor   INT               NOT NULL,
    id_usuario     INT               NOT NULL,
    fecha_compra   DATETIME          NOT NULL DEFAULT GETDATE(),
    numero_factura INT               NOT NULL,
    total          DECIMAL(10,2)     NOT NULL,
    impuesto       DECIMAL(10,2)     NOT NULL,
    CONSTRAINT PK_compra PRIMARY KEY (id_compra),
    CONSTRAINT FK_compra_proveedor
        FOREIGN KEY (id_proveedor) REFERENCES proveedor (id_proveedor),
    CONSTRAINT FK_compra_usuario
        FOREIGN KEY (id_usuario)   REFERENCES usuario (id_usuario)
);
GO

CREATE TABLE detalle_compra (
    id_detalle_compra INT IDENTITY(1,1) NOT NULL,
    id_compra         INT               NOT NULL,
    id_medicamento    INT               NOT NULL,
    id_detalle_lote   INT               NOT NULL,
    cantidad          INT               NOT NULL,
    costo_unidad      DECIMAL(10,2)     NOT NULL,
    subtotal          DECIMAL(10,2)     NOT NULL,
    total             DECIMAL(10,2)     NOT NULL,
    CONSTRAINT PK_detalle_compra PRIMARY KEY (id_detalle_compra),
    CONSTRAINT FK_detallecompra_compra
        FOREIGN KEY (id_compra)       REFERENCES compra (id_compra),
    CONSTRAINT FK_detallecompra_medicamento
        FOREIGN KEY (id_medicamento)  REFERENCES medicamento (id_medicamento),
    CONSTRAINT FK_detallecompra_detallelote
        FOREIGN KEY (id_detalle_lote) REFERENCES detalle_lote (id_detalle_lote)
);
GO

/* ---------------------------------------------------------------------
   5. AUDITORIA
   --------------------------------------------------------------------- */

CREATE TABLE auditoria (
    id_auditoria          INT IDENTITY(1,1) NOT NULL,
    id_usuario            INT               NOT NULL,
    id_registro_afectado  INT               NOT NULL,  -- id del registro en tabla_afectada (sin FK: apunta a distintas tablas)
    tabla_afectada        VARCHAR(50)       NOT NULL,
    accion                VARCHAR(20)       NOT NULL,
    fecha_hora            DATETIME          NOT NULL DEFAULT GETDATE(),
    CONSTRAINT PK_auditoria PRIMARY KEY (id_auditoria),
    CONSTRAINT FK_auditoria_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
);
GO
