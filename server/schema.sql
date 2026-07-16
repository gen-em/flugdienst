-- HEMS Einsatzdoku — Schema v1.0 (MySQL >= 5.7 / MariaDB >= 10.2)
SET NAMES utf8mb4;

CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL UNIQUE,        -- Username = E-Mail
  password_hash VARCHAR(255) NULL,                   -- NULL bis Erst-Setzung per Mail-Link
  role          ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE password_resets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,                      -- sha256 des Tokens
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE devices (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  device_id    VARCHAR(64) NOT NULL UNIQUE,          -- oeffentlich, Header X-Device-Id
  api_key_hash VARCHAR(255) NOT NULL,                -- password_hash des Geraeteschluessels
  label        VARCHAR(64) NULL,
  last_seen    TIMESTAMP NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE missions (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  device_id  INT UNSIGNED NOT NULL,
  client_ref VARCHAR(64) NOT NULL,
  day        DATE NOT NULL,                          -- Betriebstag (Datum Dienstbeginn)
  started_at DATETIME NOT NULL,                      -- UTC
  ended_at   DATETIME NULL,                          -- UTC, NULL solange final=0
  distance_m INT UNSIGNED NULL,
  ascent_m   INT UNSIGNED NULL,
  final      TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dev_ref (device_id, client_ref),
  INDEX (user_id, day),
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mission_phases (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mission_id  INT UNSIGNED NOT NULL,
  phase       TINYINT UNSIGNED NOT NULL,             -- 2..10
  occurred_at DATETIME NOT NULL,                     -- UTC
  lat DOUBLE NULL, lon DOUBLE NULL,
  FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE,
  INDEX (mission_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE resus_sessions (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mission_id INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,                      -- UTC = "Reanimationsbeginn"
  FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE,
  INDEX idx_mission (mission_id, started_at)         -- mehrere Reas pro Einsatz
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE resus_events (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id  INT UNSIGNED NOT NULL,
  type        VARCHAR(24) NOT NULL,                  -- adrenalin, rhythmuskontrolle, ...
  occurred_at DATETIME NOT NULL,                     -- UTC
  FOREIGN KEY (session_id) REFERENCES resus_sessions(id) ON DELETE CASCADE,
  INDEX (session_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rest_segments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  device_id  INT UNSIGNED NOT NULL,
  client_ref VARCHAR(64) NOT NULL,
  day        DATE NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at   DATETIME NULL,
  final      TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_dev_ref (device_id, client_ref),
  INDEX (user_id, day),
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Trackpunkte fuer Einsaetze UND Ruhe-Segmente (owner_type unterscheidet)
CREATE TABLE track_points (
  owner_type ENUM('mission','rest') NOT NULL,
  owner_id   INT UNSIGNED NOT NULL,
  seq        INT UNSIGNED NOT NULL,
  lat DOUBLE NOT NULL, lon DOUBLE NOT NULL,
  ele DOUBLE NULL,
  ts  INT UNSIGNED NOT NULL,                          -- Unix-Epoche (s, UTC)
  PRIMARY KEY (owner_type, owner_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
