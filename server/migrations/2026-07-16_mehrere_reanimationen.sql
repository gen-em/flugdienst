-- Migration 2026-07-16: mehrere Reanimationen pro Einsatz
-- Auf einer BESTEHENDEN Datenbank einmalig ausfuehren (z. B. via phpMyAdmin).
-- Neue Installationen brauchen das nicht (schema.sql ist bereits aktuell).

ALTER TABLE resus_sessions DROP INDEX uq_mission;
ALTER TABLE resus_sessions ADD INDEX idx_mission (mission_id, started_at);
