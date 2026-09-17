DELIMITER //
CREATE PROCEDURE assert_operational_state()
BEGIN
    DECLARE v_failures INT DEFAULT 0;

    SELECT v_failures + IF(COUNT(*)=1,0,1) INTO v_failures
      FROM import_runs
     WHERE run_key='overlap-run' AND status='failed'
       AND error_summary='backfill failed: single-flight lock timeout after 2 seconds';
    SELECT v_failures + IF(COUNT(*)=1,0,1) INTO v_failures
      FROM import_runs
     WHERE run_key='injected-failure-run' AND status='failed'
       AND error_summary='backfill failed: competition mapping conflict';
    SELECT v_failures + IF(COUNT(*)=1,0,1) INTO v_failures
      FROM import_runs
     WHERE run_key='synthetic-stale-run' AND status='failed'
       AND error_summary='stale run recovered by stale-recovery-run';
    SELECT v_failures + IF(COUNT(*)=3,0,1) INTO v_failures
      FROM import_runs
     WHERE run_key IN ('overlap-retry', 'injected-failure-retry', 'stale-recovery-run')
       AND status='completed' AND last_legacy_id=6
       AND source_rows_seen=6 AND applied_count=4 AND unresolved_count=2;
    SELECT v_failures + IF(COUNT(*)=0,0,1) INTO v_failures
      FROM import_runs WHERE status IN ('waiting', 'running');
    SELECT v_failures + IF(IS_FREE_LOCK('canonical_football:legacy_header_backfill')=1,0,1) INTO v_failures;

    IF v_failures <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='operational backfill assertions failed';
    END IF;

    SELECT 'PASS' AS operational_assertions,
           (SELECT COUNT(*) FROM import_runs WHERE status='failed') AS failed_runs_preserved,
           (SELECT COUNT(*) FROM import_runs WHERE status='completed') AS completed_runs,
           IS_FREE_LOCK('canonical_football:legacy_header_backfill') AS advisory_lock_free;
END//
DELIMITER ;
CALL assert_operational_state();
DROP PROCEDURE assert_operational_state;

SELECT run_key, status, last_legacy_id, source_rows_seen, applied_count, unresolved_count,
       error_summary
  FROM import_runs
