DELIMITER //
CREATE PROCEDURE assert_rollback_state()
BEGIN
    DECLARE v_canonical_tables INT DEFAULT 0;
    DECLARE v_legacy_tables INT DEFAULT 0;
    SELECT COUNT(*) INTO v_canonical_tables
      FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name IN
       ('sports','providers','competitions','competition_seasons','participants','events',
        'event_participants','provider_competition_mappings','provider_participant_mappings',
        'provider_event_mappings','import_runs','identity_quarantines');
    SELECT COUNT(*) INTO v_legacy_tables
      FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name IN ('leagues','teams','fixtures','fixture_scores');
    IF v_canonical_tables<>0 OR v_legacy_tables<>4 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rollback assertions failed';
    END IF;
    SELECT 'PASS' AS rollback_assertions, v_canonical_tables AS canonical_tables, v_legacy_tables AS legacy_tables;
END//
DELIMITER ;
CALL assert_rollback_state();
DROP PROCEDURE assert_rollback_state;
