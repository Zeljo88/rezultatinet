-- Rehearsal-only rollback. Drops canonical tables, never legacy football tables.
DELIMITER //
CREATE PROCEDURE assert_rehearsal_database()
BEGIN
    IF DATABASE() <> 'canonical_football_rehearsal' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refusing to run outside canonical_football_rehearsal';
    END IF;
END//
DELIMITER ;
CALL assert_rehearsal_database();
DROP PROCEDURE assert_rehearsal_database;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS identity_quarantines;
DROP TABLE IF EXISTS import_runs;
DROP TABLE IF EXISTS provider_event_mappings;
DROP TABLE IF EXISTS provider_participant_mappings;
DROP TABLE IF EXISTS provider_competition_mappings;
DROP TABLE IF EXISTS event_participants;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS participants;
DROP TABLE IF EXISTS competition_seasons;
DROP TABLE IF EXISTS competitions;
DROP TABLE IF EXISTS providers;
DROP TABLE IF EXISTS sports;
SET FOREIGN_KEY_CHECKS = 1;
