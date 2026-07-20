-- HEMS Einsatzdoku — Schema v1.0 (MySQL >= 5.7 / MariaDB >= 10.2)
SET NAMES utf8mb4;

CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL UNIQUE,
  name          VARCHAR(120) NULL,                   -- Anzeigename (Kopfleiste)
  password_hash VARCHAR(255) NULL,                   -- Hash des Auth-Tokens (kdf_ver 1) bzw. Passworts (0)
  kdf_salt      VARCHAR(64) NULL,                    -- Salt der Browser-Schluesselableitung
  kdf_ver       TINYINT NOT NULL DEFAULT 0,          -- 0 = Alt (Klartext-Login), 1 = abgeleitetes Token
  pat_wrap_pw   TEXT NULL,                           -- Inhaltsschluessel, passwortverpackt (Pflicht-Verschlüsselung)
  pat_wrap_rc   TEXT NULL,                           -- Inhaltsschluessel, mit Wiederherstellungsschluessel verpackt
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
  active       TINYINT(1) NOT NULL DEFAULT 1,        -- deaktiviert = Upload gesperrt, Daten bleiben
  last_seen    TIMESTAMP NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE missions (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  device_id  INT UNSIGNED NULL,               -- NULL = Geraet geloescht (Daten bleiben)
  client_ref VARCHAR(64) NOT NULL,
  day        DATE NOT NULL,                          -- Flugtag (Datum Dienstbeginn)
  started_at DATETIME NOT NULL,                      -- UTC
  ended_at   DATETIME NULL,                          -- UTC, NULL solange final=0
  distance_m INT UNSIGNED NULL,
  ascent_m   INT UNSIGNED NULL,
  final      TINYINT(1) NOT NULL DEFAULT 0,
  manual     TINYINT(1) NOT NULL DEFAULT 0,           -- von Hand angelegt/bearbeitet: Uhr ueberschreibt nicht mehr
  mission_no VARCHAR(64) NULL,                        -- Zusatzfelder (mission_fields.php):
  transport_dest VARCHAR(190) NULL,
  site_desc  VARCHAR(190) NULL,
  winch      TINYINT(1) NOT NULL DEFAULT 0,
  winch_cycles TINYINT NULL,
  winch_cycles_pat TINYINT NULL,
  winch_airload TINYINT(1) NOT NULL DEFAULT 0,
  bergwacht  TINYINT(1) NOT NULL DEFAULT 0,
  bw_unit    VARCHAR(120) NULL,
  bw_info    VARCHAR(190) NULL,
  other_ema  VARCHAR(190) NULL,
  other_resources VARCHAR(190) NULL,
  pat_blob   TEXT NULL,                              -- E2E-verschluesselt: Diagnose, Alter, Einsatzort (Server: nur Chiffretext)
  notes      TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dev_ref (device_id, client_ref),
  INDEX (user_id, day),
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
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
  device_id  INT UNSIGNED NULL,               -- NULL = Geraet geloescht (Daten bleiben)
  client_ref VARCHAR(64) NOT NULL,
  day        DATE NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at   DATETIME NULL,
  final      TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_dev_ref (device_id, client_ref),
  INDEX (user_id, day),
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stammdaten (je NutzerIn): Standorte, Hubschrauber mit Rollen,
-- Besatzungs-Vorbelegungen, Bergwacht-Bereitschaften
CREATE TABLE bases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,        -- Flugtag-Vorbelegung
  UNIQUE KEY uq_user_name (user_id, name),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE aircraft (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  registration VARCHAR(64) NOT NULL,               -- Kennung
  p1 TINYINT(1) NOT NULL DEFAULT 0,                -- Pilot 1 an Bord
  p2 TINYINT(1) NOT NULL DEFAULT 0,                -- Pilot 2
  hems TINYINT(1) NOT NULL DEFAULT 0,              -- HEMS-TC
  fr TINYINT(1) NOT NULL DEFAULT 0,                -- Flugretter
  other TINYINT(1) NOT NULL DEFAULT 0,             -- Sonstige
  is_default TINYINT(1) NOT NULL DEFAULT 0,        -- Flugtag-Vorbelegung
  UNIQUE KEY uq_user_reg (user_id, registration),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE crew_presets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('p1','p2','hems','fr','other') NOT NULL,
  name VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_user_role_name (user_id, role, name),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bw_units (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_user_name (user_id, name),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Flugtage: editierbare Metadaten je Flugtag. Verknuepfung zu Einsaetzen
-- und Ruhe-Segmenten ueber den natuerlichen Schluessel (user_id, day).
CREATE TABLE days (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id  INT UNSIGNED NOT NULL,
  day      DATE NOT NULL,
  aircraft_id INT UNSIGNED NULL,    -- Maschine (Stammdaten)
  base_id     INT UNSIGNED NULL,    -- Standort (Stammdaten)
  crew_p1     VARCHAR(120) NULL,    -- Besatzung je Rolle (aus Vorbelegungen)
  crew_p2     VARCHAR(120) NULL,
  crew_hems   VARCHAR(120) NULL,
  crew_fr     VARCHAR(120) NULL,
  crew_other  VARCHAR(120) NULL,
  aircraft VARCHAR(64) NULL,        -- Alt (Freitext, wird nicht mehr beschrieben)
  base     VARCHAR(64) NULL,        -- Alt
  crew     VARCHAR(190) NULL,       -- Alt
  notes    TEXT NULL,
  UNIQUE KEY uq_user_day (user_id, day),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (aircraft_id) REFERENCES aircraft(id) ON DELETE SET NULL,
  FOREIGN KEY (base_id) REFERENCES bases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kopplungscodes: kurzlebige Einmal-Codes (60 min), mit denen sich eine Uhr
-- selbst Zugangsdaten holt (pair.php) — ohne Abtippen langer Schluessel.
CREATE TABLE pair_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code VARCHAR(8) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sperrliste geloeschter Einsaetze: verhindert, dass eine Uhr mit noch
-- gepufferten Daten einen im Web geloeschten Einsatz wieder anlegt.
-- Eintraege verfallen nach 90 Tagen (Aufraeumjob).
CREATE TABLE deleted_refs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id INT UNSIGNED NOT NULL,
  client_ref VARCHAR(64) NOT NULL,
  deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dev_ref (device_id, client_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kleiner Schluessel/Wert-Speicher fuer App-interne Zustaende (z. B. Wartung)
CREATE TABLE app_state (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v VARCHAR(190) NULL
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

-- ---------------------------------------------------------------------------
-- Migrations-Buchfuehrung. Eine frische Installation ist bereits auf dem
-- Stand aller bisherigen Migrationen, deshalb werden sie hier als erledigt
-- eingetragen — update.php findet dann nichts mehr zu tun.
--
-- WICHTIG bei neuen Migrationen: die neue ID zusaetzlich hier ergaenzen,
-- sonst laeuft sie bei jeder Neuinstallation unnoetig (und ggf. auf Daten,
-- die es noch gar nicht gibt).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
  id         VARCHAR(120) NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status     VARCHAR(16) NOT NULL DEFAULT 'applied'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (id, status) VALUES
  ('2026_07_16_mehrere_reanimationen', 'skipped'),
  ('2026_07_17_flugtage', 'skipped'),
  ('2026_07_17_wartung', 'skipped'),
  ('2026_07_18_geraete_status', 'skipped'),
  ('2026_07_18_manuelle_einsaetze', 'skipped'),
  ('2026_07_19_phase10_entfernen', 'skipped'),
  ('2026_07_19_profil_name', 'skipped'),
  ('2026_07_19_geraete_entkoppeln', 'skipped'),
  ('2026_07_19_stammdaten', 'skipped'),
  ('2026_07_20_einsatzfelder_ort', 'skipped'),
  ('2026_07_20_stammdaten_defaults', 'skipped'),
  ('2026_07_20_kopplung', 'skipped'),
  ('2026_07_20_patientinnendaten', 'skipped'),
  ('2026_07_21_pflicht_e2e', 'skipped'),
  ('2026_07_22_tag_zuordnung', 'skipped');
