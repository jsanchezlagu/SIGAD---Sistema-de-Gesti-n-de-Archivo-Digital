-- SIGAD PHP · esquema MySQL para hosting compartido
-- Ejecutar en phpMyAdmin (importar) o mysql < db/sigad.sql

CREATE DATABASE IF NOT EXISTS sigad CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sigad;

CREATE TABLE usuarios (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    password       VARCHAR(255) NOT NULL,            -- password_hash()
    nombres        VARCHAR(100) NOT NULL DEFAULT '',
    apellidos      VARCHAR(100) NOT NULL DEFAULT '',
    cargo          VARCHAR(100) NOT NULL DEFAULT '',
    rol            ENUM('ADMIN','OPERADOR','CONSULTA') NOT NULL DEFAULT 'CONSULTA',
    activo         TINYINT(1)    NOT NULL DEFAULT 1,  -- 1=activo, 0=suspendido
    creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_ingreso DATETIME      NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pabellones (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    codigo      VARCHAR(10)  NOT NULL UNIQUE,
    nombre      VARCHAR(150) NOT NULL,
    ubicacion   VARCHAR(150) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE estantes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    pabellon_id INT NOT NULL,
    codigo      VARCHAR(20) NOT NULL,
    FOREIGN KEY (pabellon_id) REFERENCES pabellones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE expedientes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nro_expediente  VARCHAR(40)  NOT NULL UNIQUE,
    cui             VARCHAR(40)  NOT NULL DEFAULT '',   -- Código Único de Inversión
    anio            INT          NOT NULL,
    fecha_expediente DATE        NULL,
    area_origen     VARCHAR(100) NOT NULL DEFAULT '',
    asunto          VARCHAR(255) NOT NULL DEFAULT '',
    nombre_proyecto VARCHAR(255) NOT NULL DEFAULT '',   -- nombre de obra / proyecto (búsqueda)
    pabellon_id     INT NOT NULL,
    estante_id      INT NULL,
    folios          INT          NOT NULL DEFAULT 0,
    estado          ENUM('EN_PROCESO','APROBADO') NOT NULL DEFAULT 'EN_PROCESO',
    aprobado_por    INT          NULL,
    aprobado_en     DATETIME     NULL,
    creado_por      INT NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pabellon_id) REFERENCES pabellones(id),
    FOREIGN KEY (estante_id)  REFERENCES estantes(id) ON DELETE SET NULL,
    FOREIGN KEY (creado_por)  REFERENCES usuarios(id) ON DELETE SET NULL,
    FOREIGN KEY (aprobado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX (anio), INDEX (estado), INDEX idx_cui (cui),
    FULLTEXT INDEX ft_exp (nro_expediente, cui, nombre_proyecto, asunto, area_origen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE documentos (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    expediente_id   INT NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    archivo         VARCHAR(255) NOT NULL,          -- ruta en /uploads
    tamano_bytes    BIGINT       NOT NULL DEFAULT 0,
    tamano_mb       DECIMAL(8,1) NOT NULL DEFAULT 0,
    hash_sha256     VARCHAR(64)  NOT NULL DEFAULT '',
    texto           MEDIUMTEXT   NULL,              -- texto extraído para búsqueda
    texto_origen    VARCHAR(10)  NOT NULL DEFAULT '', -- capa | ocr | vacio
    paginas         INT          NOT NULL DEFAULT 0,
    version         INT          NOT NULL DEFAULT 1,
    subido_por      INT NULL,
    subido_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE,
    FOREIGN KEY (subido_por)    REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX (hash_sha256),
    FULLTEXT INDEX ft_texto (texto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auditoria (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NULL,
    username    VARCHAR(50)  NOT NULL DEFAULT 'anónimo',
    accion      VARCHAR(40)  NOT NULL,
    detalle     VARCHAR(255) NOT NULL DEFAULT '',
    ip          VARCHAR(45)  NOT NULL DEFAULT '',
    fecha       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE areas (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    codigo      VARCHAR(20)  NOT NULL UNIQUE,
    nombre      VARCHAR(150) NOT NULL,
    descripcion VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Administrador inicial (cambiar clave en produccion)
-- usuario: admin  |  clave: Sigad2026
INSERT INTO usuarios (username, password, nombres, apellidos, cargo, rol, activo)
VALUES ('admin',
        '$2y$10$3R6GxRfcfxlng0.2b3zjE.UGjBg1q5PRmyztatA3P6LfyQ8irip/a',
        'Admin', 'Sistema', 'Administrador del sistema', 'ADMIN', 1);

-- Pabellones de ejemplo (Archivo Central MDSM)
INSERT INTO pabellones (codigo, nombre, ubicacion) VALUES
 ('PAB-A','Pabellón A - Gerencia Municipal','Ala norte'),
 ('PAB-B','Pabellón B - Rentas y Tributación','Ala norte'),
 ('PAB-C','Pabellón C - Obras Públicas','Ala sur'),
 ('PAB-D','Pabellón D - Registro Civil','Ala sur');
INSERT INTO estantes (pabellon_id, codigo) VALUES
 (1,'A-01'),(1,'A-02'),(2,'B-01'),(2,'B-02'),(3,'C-01'),(3,'C-02'),(4,'D-01');

-- Areas de ejemplo (Municipalidad Distrital de San Marcos)
INSERT INTO areas (codigo, nombre, descripcion) VALUES
 ('GER','Gerencia Municipal','Dirección y administración general'),
 ('REN','Rentas y Tributación','Recaudación y tributos'),
 ('OBR','Obras Públicas','Infraestructura y servicios'),
 ('REG','Registro Civil','Nacimientos, defunciones, matrimonios'),
 ('SOC','Desarrollo Social','Programas sociales y comunidad');

-- Control de intentos de inicio de sesion (defensa contra fuerza bruta)
CREATE TABLE login_intentos (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    usuario  VARCHAR(50)  NOT NULL DEFAULT '',
    ip       VARCHAR(45)  NOT NULL DEFAULT '',
    exito    TINYINT(1)   NOT NULL DEFAULT 0,
    fecha    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (usuario), INDEX (ip), INDEX (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registro de busquedas para el ranking de "documentos mas buscados"
CREATE TABLE busquedas (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    expediente_id INT NULL,
    termino       VARCHAR(255) NOT NULL DEFAULT '',
    usuario_id    INT NULL,
    fecha         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id)    REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
