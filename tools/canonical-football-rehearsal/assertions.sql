DELIMITER //
CREATE PROCEDURE assert_final_state()
BEGIN
    DECLARE v_failures INT DEFAULT 0;

    SELECT v_failures + IF(COUNT(*)=1,0,1) INTO v_failures FROM sports WHERE code='football';
    SELECT v_failures + IF(COUNT(*)=1,0,1) INTO v_failures FROM providers WHERE code='api_sports_football' AND product_namespace='football_v3';
    SELECT v_failures + IF(COUNT(*)=2,0,1) INTO v_failures FROM competitions;
    SELECT v_failures + IF(COUNT(*)=3,0,1) INTO v_failures FROM competition_seasons;
    SELECT v_failures + IF(COUNT(*)=4,0,1) INTO v_failures FROM participants WHERE type='team';
    SELECT v_failures + IF(COUNT(*)=4,0,1) INTO v_failures FROM events;
    SELECT v_failures + IF(COUNT(*)=8,0,1) INTO v_failures FROM event_participants;
    SELECT v_failures + IF(COUNT(*)=2,0,1) INTO v_failures FROM provider_competition_mappings;
    SELECT v_failures + IF(COUNT(*)=4,0,1) INTO v_failures FROM provider_participant_mappings;
    SELECT v_failures + IF(COUNT(*)=4,0,1) INTO v_failures FROM provider_event_mappings;
    SELECT v_failures + IF(COUNT(*)=2,0,1) INTO v_failures FROM identity_quarantines WHERE status='open';
    SELECT v_failures + IF(COUNT(*)=1,0,1) INTO v_failures FROM identity_quarantines WHERE reason_code='self_participant';
    SELECT v_failures + IF(COUNT(*)=1,0,1) INTO v_failures FROM identity_quarantines WHERE reason_code='unknown_status';
    SELECT v_failures + IF(COUNT(*)=0,0,1) INTO v_failures FROM events WHERE publishable<>0 OR version<>1;
    SELECT v_failures + IF(COUNT(*)=2,0,1) INTO v_failures FROM events WHERE home_score IS NULL AND away_score IS NULL;
    SELECT v_failures + IF(COUNT(*)=0,0,1) INTO v_failures FROM events e LEFT JOIN competition_seasons cs ON cs.id=e.competition_season_id WHERE cs.id IS NULL;
    SELECT v_failures + IF(COUNT(*)=0,0,1) INTO v_failures FROM event_participants ep LEFT JOIN events e ON e.id=ep.event_id LEFT JOIN participants p ON p.id=ep.participant_id WHERE e.id IS NULL OR p.id IS NULL;
    SELECT v_failures + IF(COUNT(*)=0,0,1) INTO v_failures FROM (
        SELECT event_id FROM event_participants GROUP BY event_id HAVING COUNT(*)<>2 OR COUNT(DISTINCT participant_id)<>2 OR MIN(side_order)<>1 OR MAX(side_order)<>2
    ) bad_cardinality;
    SELECT v_failures + IF(COUNT(*)=0,0,1) INTO v_failures FROM (
        SELECT event_id
          FROM event_participants
         GROUP BY event_id
        HAVING SUM(role='home' AND side_order=1)<>1 OR SUM(role='away' AND side_order=2)<>1
    ) bad_roles;
    SELECT v_failures + IF(COUNT(*)=0,0,1) INTO v_failures FROM event_participants WHERE (role='home')<>(side_order=1);
    SELECT v_failures + IF(COUNT(*)=0,0,1) INTO v_failures FROM provider_event_mappings pem
      JOIN fixtures f ON f.id=pem.legacy_fixture_id
     WHERE pem.external_id<>CAST(f.api_fixture_id AS CHAR);
    SELECT v_failures + IF(COUNT(*)=0,0,1) INTO v_failures FROM import_runs
     WHERE status='completed' AND NOT (last_legacy_id=6 AND source_rows_seen=6 AND applied_count=4 AND unresolved_count=2);

    IF v_failures <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='final canonical assertions failed';
    END IF;

    SELECT 'PASS' AS final_assertions,
           (SELECT COUNT(*) FROM events) AS events,
           (SELECT COUNT(*) FROM event_participants) AS event_participants,
           (SELECT COUNT(*) FROM identity_quarantines WHERE status='open') AS quarantined,
           (SELECT COUNT(*) FROM events WHERE publishable=1) AS published;
END//
DELIMITER ;
CALL assert_final_state();
DROP PROCEDURE assert_final_state;

SELECT pem.legacy_fixture_id, e.public_id, e.status_code, e.home_score, e.away_score,
       e.version, e.publishable, COUNT(ep.participant_id) AS participant_count
FROM provider_event_mappings pem
JOIN events e ON e.id=pem.event_id
JOIN event_participants ep ON ep.event_id=e.id
GROUP BY pem.legacy_fixture_id, e.public_id, e.status_code, e.home_score, e.away_score, e.version, e.publishable
ORDER BY pem.legacy_fixture_id;

SELECT source_legacy_id, reason_code, status, occurrences
FROM identity_quarantines
ORDER BY source_legacy_id, reason_code;
