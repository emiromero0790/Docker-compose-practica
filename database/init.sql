SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS incidencias (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titulo         VARCHAR(120) NOT NULL,
  descripcion    VARCHAR(500) NOT NULL DEFAULT '',
  area           VARCHAR(80)  NOT NULL,
  prioridad      ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  estado         ENUM('abierta','en_proceso','resuelta') NOT NULL DEFAULT 'abierta',
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_titulo CHECK (CHAR_LENGTH(titulo) >= 3),
  CONSTRAINT uq_titulo_area UNIQUE (titulo, area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO incidencias (titulo, descripcion, area, prioridad, estado) VALUES
('Proyector sin imagen', 'El proyector del aula enciende pero no muestra imagen', 'Aula 12', 'alta', 'abierta'),
('Sin acceso a internet', 'Los equipos de la fila 3 no tienen red', 'Laboratorio 2', 'media', 'en_proceso'),
('Silla dañada', 'Silla con respaldo roto', 'Biblioteca', 'baja', 'resuelta');