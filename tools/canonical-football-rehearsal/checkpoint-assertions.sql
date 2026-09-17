DELIMITER //
CREATE PROCEDURE assert_checkpoint_state()
BEGIN
    DECLARE v_failures INT DEFAULT 0;
    SELECT v_failures + IF(COUNT(*)=1,0,1) INTO v_failures FROM import_runs
     WHERE run_key='checkpoint-run' AND status='paused' AND last_legacy_id=3
       AND source_rows_seen=3 AND applied_count=3 AND unresolved_count=0;
    SELECT v_failures + IF(COUNT(*)=3,0,1) INTO v_failures FROM events;
    SELECT v_failures + IF(COUNT(*)=3,0,1) INTO v_failures FROM provider_event_mappings;
    SELECT v_failures + IF(COUNT(*)=6,0,1) INTO v_failures FROM event_participants;
    IF v_failures <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='checkpoint assertions failed';
    END IF;
    SELECT 'PASS' AS checkpoint_restart_state, 3 AS last_legacy_id, 3 AS mapped_events;
END//
DELIMITER ;
CALL assert_checkpoint_state();
DROP PROCEDURE assert_checkpoint_state;
