-- Restartable, provider-free canonical header backfill with capability-wide single flight.
-- Load once, then CALL canonical_football_backfill('<run-key>', <inclusive stop fixture id>).
DELIMITER //
CREATE PROCEDURE canonical_football_backfill(IN p_run_key VARCHAR(96), IN p_stop_after BIGINT UNSIGNED)
main: BEGIN
    DECLARE v_sport_id BIGINT UNSIGNED;
    DECLARE v_provider_id BIGINT UNSIGNED;
    DECLARE v_checkpoint BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_max_fixture_id BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_end BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_run_status VARCHAR(24);
    DECLARE v_now DATETIME(6) DEFAULT UTC_TIMESTAMP(6);
    DECLARE v_lock_name VARCHAR(128) DEFAULT 'canonical_football:legacy_header_backfill';
    DECLARE v_lock_acquired INT DEFAULT 0;
    DECLARE v_run_registered TINYINT DEFAULT 0;
    DECLARE v_error_message TEXT DEFAULT 'unknown SQL exception';

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_error_message = MESSAGE_TEXT;
        ROLLBACK;
        IF v_run_registered = 1 THEN
            START TRANSACTION;
            UPDATE import_runs
               SET status = 'failed',
                   finished_at = UTC_TIMESTAMP(6),
                   error_summary = LEFT(CONCAT('backfill failed: ', COALESCE(v_error_message, 'unknown SQL exception')), 500)
             WHERE run_key = p_run_key;
            COMMIT;
        END IF;
        IF v_lock_acquired = 1 THEN
            DO RELEASE_LOCK(v_lock_name);
            SET v_lock_acquired = 0;
        END IF;
        RESIGNAL;
    END;

    IF DATABASE() <> 'canonical_football_rehearsal' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refusing to run outside canonical_football_rehearsal';
    END IF;
    IF p_run_key IS NULL OR p_run_key = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'run key is required';
    END IF;

    -- Commit only immutable scope bootstrap and the run ledger before attempting work.
    -- This lets a later handler persist failure provenance outside rolled-back projection work.
    START TRANSACTION;

    INSERT INTO sports (code, name, active, created_at, updated_at)
    VALUES ('football', 'Football', 1, v_now, v_now)
    ON DUPLICATE KEY UPDATE name = VALUES(name), active = VALUES(active), updated_at = VALUES(updated_at);

    SELECT id INTO v_sport_id FROM sports WHERE code = 'football';

    INSERT INTO providers
        (sport_id, code, vendor_code, product_namespace, name, active, created_at, updated_at)
    VALUES
        (v_sport_id, 'api_sports_football', 'api_sports', 'football_v3', 'API-Sports Football v3', 1, v_now, v_now)
    ON DUPLICATE KEY UPDATE name = VALUES(name), active = VALUES(active), updated_at = VALUES(updated_at);

    SELECT id INTO v_provider_id FROM providers WHERE code = 'api_sports_football';

    INSERT INTO import_runs
        (provider_id, sport_id, run_key, capability, last_legacy_id, status, started_at)
    VALUES
        (v_provider_id, v_sport_id, p_run_key, 'legacy_header_backfill', 0, 'waiting', v_now)
    ON DUPLICATE KEY UPDATE run_key = VALUES(run_key);
    COMMIT;
    SET v_run_registered = 1;

    SELECT GET_LOCK(v_lock_name, 2) INTO v_lock_acquired;
    IF COALESCE(v_lock_acquired, 0) <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'single-flight lock timeout after 2 seconds';
    END IF;

    START TRANSACTION;

    -- Advisory locks disappear with their owning connection. A waiting/running row observed only
    -- after this lock is acquired is therefore stale and is closed before this run proceeds.
    UPDATE import_runs
       SET status = 'failed',
           finished_at = v_now,
           error_summary = LEFT(CONCAT('stale run recovered by ', p_run_key), 500)
     WHERE capability = 'legacy_header_backfill'
       AND status IN ('waiting', 'running')
       AND run_key <> p_run_key;


    SELECT last_legacy_id, status
      INTO v_checkpoint, v_run_status
      FROM import_runs
     WHERE run_key = p_run_key
     FOR UPDATE;

    IF v_run_status = 'completed' THEN
        COMMIT;
        DO RELEASE_LOCK(v_lock_name);
        SET v_lock_acquired = 0;
        LEAVE main;
    END IF;

    UPDATE import_runs SET status = 'running', finished_at = NULL, error_summary = NULL
     WHERE run_key = p_run_key;

    -- Abort rather than guess if a legacy or external identity disagrees with an existing mapping.
    IF EXISTS (
        SELECT 1
          FROM leagues l
          JOIN provider_competition_mappings pcm
            ON pcm.provider_id = v_provider_id
           AND (pcm.legacy_league_id = l.id OR pcm.external_id = CAST(l.api_league_id AS CHAR))
         WHERE l.sport = 'football'
           AND NOT (pcm.legacy_league_id <=> l.id AND pcm.external_id <=> CAST(l.api_league_id AS CHAR))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'competition mapping conflict';
    END IF;

    INSERT INTO competitions
        (public_id, sport_id, name, slug, country_code, image_url, active, created_at, updated_at)
    SELECT CONCAT('football-competition-l', l.id), v_sport_id, l.name,
           CONCAT('legacy-league-', l.id), NULL, l.logo_url, l.is_active, v_now, v_now
      FROM leagues l
     WHERE l.sport = 'football'
    ON DUPLICATE KEY UPDATE
        name = VALUES(name), image_url = VALUES(image_url), active = VALUES(active), updated_at = VALUES(updated_at);

    IF EXISTS (
        SELECT 1
          FROM leagues l
          JOIN competitions expected ON expected.public_id=CONCAT('football-competition-l', l.id)
          JOIN provider_competition_mappings pcm
            ON pcm.provider_id=v_provider_id AND pcm.legacy_league_id=l.id
         WHERE l.sport='football' AND pcm.competition_id<>expected.id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'competition canonical mapping conflict';
    END IF;

    INSERT INTO provider_competition_mappings
        (provider_id, external_id, competition_id, legacy_league_id, first_seen_at, last_seen_at, source_updated_at)
    SELECT v_provider_id, CAST(l.api_league_id AS CHAR), c.id, l.id, v_now, v_now, l.updated_at
      FROM leagues l
      JOIN competitions c ON c.public_id = CONCAT('football-competition-l', l.id)
     WHERE l.sport = 'football'
    ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), source_updated_at = VALUES(source_updated_at);

    INSERT INTO competition_seasons
        (competition_id, source_key, label, is_current, created_at, updated_at)
    SELECT DISTINCT pcm.competition_id, CAST(f.season AS CHAR), CAST(f.season AS CHAR),
           IF(l.current_season <=> f.season, 1, 0), v_now, v_now
      FROM fixtures f
      JOIN leagues l ON l.id = f.league_id AND l.sport = 'football'
      JOIN provider_competition_mappings pcm
        ON pcm.provider_id = v_provider_id AND pcm.legacy_league_id = l.id
    ON DUPLICATE KEY UPDATE
        label = VALUES(label), is_current = VALUES(is_current), updated_at = VALUES(updated_at);

    IF EXISTS (
        SELECT 1
          FROM (
                SELECT home_team_id AS team_id FROM fixtures f JOIN leagues l ON l.id=f.league_id WHERE l.sport='football'
                UNION
                SELECT away_team_id FROM fixtures f JOIN leagues l ON l.id=f.league_id WHERE l.sport='football'
          ) scoped
          JOIN teams t ON t.id = scoped.team_id
          JOIN provider_participant_mappings ppm
            ON ppm.provider_id = v_provider_id
           AND (ppm.legacy_team_id = t.id OR ppm.external_id = CAST(t.api_team_id AS CHAR))
         WHERE NOT (ppm.legacy_team_id <=> t.id AND ppm.external_id <=> CAST(t.api_team_id AS CHAR))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'participant mapping conflict';
    END IF;

    INSERT INTO participants
        (public_id, sport_id, type, display_name, slug, short_name, country_code, image_url, active, created_at, updated_at)
    SELECT CONCAT('football-team-t', t.id), v_sport_id, 'team', t.name,
           CONCAT(COALESCE(NULLIF(t.slug, ''), 'team'), '-legacy-', t.id),
           t.short_name, NULL, t.logo_url, 1, v_now, v_now
      FROM teams t
      JOIN (
            SELECT f.home_team_id AS team_id FROM fixtures f JOIN leagues l ON l.id=f.league_id WHERE l.sport='football'
            UNION
            SELECT f.away_team_id FROM fixtures f JOIN leagues l ON l.id=f.league_id WHERE l.sport='football'
      ) scoped ON scoped.team_id = t.id
    ON DUPLICATE KEY UPDATE
        display_name = VALUES(display_name), short_name = VALUES(short_name),
        image_url = VALUES(image_url), active = VALUES(active), updated_at = VALUES(updated_at);

    IF EXISTS (
        SELECT 1
          FROM teams t
          JOIN participants expected ON expected.public_id=CONCAT('football-team-t', t.id)
          JOIN provider_participant_mappings ppm
            ON ppm.provider_id=v_provider_id AND ppm.legacy_team_id=t.id
         WHERE ppm.participant_id<>expected.id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'participant canonical mapping conflict';
    END IF;

    INSERT INTO provider_participant_mappings
        (provider_id, external_id, participant_id, legacy_team_id, first_seen_at, last_seen_at, source_updated_at)
    SELECT v_provider_id, CAST(t.api_team_id AS CHAR), p.id, t.id, v_now, v_now, t.updated_at
      FROM teams t
      JOIN participants p ON p.public_id = CONCAT('football-team-t', t.id)
    ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), source_updated_at = VALUES(source_updated_at);

    SELECT COALESCE(MAX(f.id), 0) INTO v_max_fixture_id
      FROM fixtures f JOIN leagues l ON l.id=f.league_id
     WHERE l.sport='football';

    SET v_end = LEAST(COALESCE(p_stop_after, v_max_fixture_id), v_max_fixture_id);

    IF v_checkpoint >= v_max_fixture_id THEN
        UPDATE import_runs SET status='completed', finished_at=v_now WHERE run_key=p_run_key;
        COMMIT;
        DO RELEASE_LOCK(v_lock_name);
        SET v_lock_acquired = 0;
        LEAVE main;
    END IF;

    IF v_end <= v_checkpoint THEN
        COMMIT;
        DO RELEASE_LOCK(v_lock_name);
        SET v_lock_acquired = 0;
        LEAVE main;
    END IF;

    INSERT INTO identity_quarantines
        (provider_id, sport_id, entity_type, external_id, source_table, source_legacy_id,
         reason_code, safe_detail, first_seen_at, last_seen_at, occurrences, status)
    SELECT v_provider_id, v_sport_id, 'event', CAST(f.api_fixture_id AS CHAR), 'fixtures', f.id,
           'self_participant', 'home_team_id equals away_team_id', v_now, v_now, 1, 'open'
      FROM fixtures f JOIN leagues l ON l.id=f.league_id AND l.sport='football'
     WHERE f.id > v_checkpoint AND f.id <= v_end AND f.home_team_id = f.away_team_id
    ON DUPLICATE KEY UPDATE last_seen_at=VALUES(last_seen_at), occurrences=occurrences+1;

    INSERT INTO identity_quarantines
        (provider_id, sport_id, entity_type, external_id, source_table, source_legacy_id,
         reason_code, safe_detail, first_seen_at, last_seen_at, occurrences, status)
    SELECT v_provider_id, v_sport_id, 'event', CAST(f.api_fixture_id AS CHAR), 'fixtures', f.id,
           'unknown_status', CONCAT('unmapped status_short=', COALESCE(f.status_short, '<NULL>')),
           v_now, v_now, 1, 'open'
      FROM fixtures f JOIN leagues l ON l.id=f.league_id AND l.sport='football'
     WHERE f.id > v_checkpoint AND f.id <= v_end
       AND COALESCE(f.status_short, '') NOT IN
           ('NS','TBD','1H','HT','2H','ET','BT','P','LIVE','FT','AET','PEN','PST','CANC','ABD','AWD','WO','INT','SUSP')
    ON DUPLICATE KEY UPDATE last_seen_at=VALUES(last_seen_at), occurrences=occurrences+1;

    IF EXISTS (
        SELECT 1
          FROM fixtures f
          JOIN leagues l ON l.id=f.league_id AND l.sport='football'
          JOIN provider_event_mappings pem
            ON pem.provider_id=v_provider_id
           AND (pem.legacy_fixture_id=f.id OR pem.external_id=CAST(f.api_fixture_id AS CHAR))
         WHERE f.id > v_checkpoint AND f.id <= v_end
           AND NOT (pem.legacy_fixture_id <=> f.id AND pem.external_id <=> CAST(f.api_fixture_id AS CHAR))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'event mapping conflict';
    END IF;

    INSERT INTO events
        (public_id, sport_id, competition_season_id, status_code, starts_at, source_updated_at,
         version, home_score, away_score, publishable, created_at, updated_at)
    SELECT CONCAT('football-event-f', f.id), v_sport_id, cs.id,
           CASE
             WHEN f.status_short IN ('NS','TBD') THEN 'scheduled'
             WHEN f.status_short IN ('1H','HT','2H','ET','BT','P','LIVE') THEN 'live'
             WHEN f.status_short IN ('FT','AET','PEN') THEN 'completed'
             WHEN f.status_short = 'PST' THEN 'postponed'
             WHEN f.status_short IN ('CANC','ABD') THEN 'cancelled'
             WHEN f.status_short = 'AWD' THEN 'awarded'
             WHEN f.status_short = 'WO' THEN 'walkover'
             WHEN f.status_short IN ('INT','SUSP') THEN 'suspended'
           END,
           f.kick_off,
           CASE
             WHEN f.updated_at IS NULL THEN fs.updated_at
             WHEN fs.updated_at IS NULL THEN f.updated_at
             ELSE GREATEST(f.updated_at, fs.updated_at)
           END,
           1, fs.goals_home, fs.goals_away, 0, v_now, v_now
      FROM fixtures f
      JOIN leagues l ON l.id=f.league_id AND l.sport='football'
      JOIN provider_competition_mappings pcm
        ON pcm.provider_id=v_provider_id AND pcm.legacy_league_id=f.league_id
      JOIN competition_seasons cs
        ON cs.competition_id=pcm.competition_id AND cs.source_key=CAST(f.season AS CHAR)
      JOIN provider_participant_mappings home_map
        ON home_map.provider_id=v_provider_id AND home_map.legacy_team_id=f.home_team_id
      JOIN provider_participant_mappings away_map
        ON away_map.provider_id=v_provider_id AND away_map.legacy_team_id=f.away_team_id
      LEFT JOIN fixture_scores fs ON fs.fixture_id=f.id
     WHERE f.id > v_checkpoint AND f.id <= v_end
       AND f.home_team_id <> f.away_team_id
       AND f.status_short IN
           ('NS','TBD','1H','HT','2H','ET','BT','P','LIVE','FT','AET','PEN','PST','CANC','ABD','AWD','WO','INT','SUSP')
    ON DUPLICATE KEY UPDATE
        version = IF(
            NOT (competition_season_id <=> VALUES(competition_season_id))
            OR NOT (status_code <=> VALUES(status_code))
            OR NOT (starts_at <=> VALUES(starts_at))
            OR NOT (events.source_updated_at <=> VALUES(source_updated_at))
            OR NOT (home_score <=> VALUES(home_score))
            OR NOT (away_score <=> VALUES(away_score)),
            version + 1, version),
        competition_season_id=VALUES(competition_season_id),
        status_code=VALUES(status_code),
        starts_at=VALUES(starts_at),
        source_updated_at=VALUES(source_updated_at),
        home_score=VALUES(home_score),
        away_score=VALUES(away_score),
        publishable=0,
        updated_at=VALUES(updated_at);

    IF EXISTS (
        SELECT 1
          FROM fixtures f
          JOIN events expected ON expected.public_id=CONCAT('football-event-f', f.id)
          JOIN provider_event_mappings pem
            ON pem.provider_id=v_provider_id AND pem.legacy_fixture_id=f.id
         WHERE f.id > v_checkpoint AND f.id <= v_end AND pem.event_id<>expected.id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'event canonical mapping conflict';
    END IF;

    INSERT INTO provider_event_mappings
        (provider_id, external_id, event_id, legacy_fixture_id, first_seen_at, last_seen_at, source_updated_at)
    SELECT v_provider_id, CAST(f.api_fixture_id AS CHAR), e.id, f.id, v_now, v_now,
           CASE
             WHEN f.updated_at IS NULL THEN fs.updated_at
             WHEN fs.updated_at IS NULL THEN f.updated_at
             ELSE GREATEST(f.updated_at, fs.updated_at)
           END
      FROM fixtures f
      JOIN events e ON e.public_id=CONCAT('football-event-f', f.id)
      LEFT JOIN fixture_scores fs ON fs.fixture_id=f.id
     WHERE f.id > v_checkpoint AND f.id <= v_end
    ON DUPLICATE KEY UPDATE last_seen_at=VALUES(last_seen_at), source_updated_at=VALUES(source_updated_at);

    INSERT INTO event_participants
        (event_id, participant_id, role, side_order, created_at, updated_at)
    SELECT pem.event_id, ppm.participant_id, sides.role, sides.side_order, v_now, v_now
      FROM (
            SELECT id AS fixture_id, home_team_id AS legacy_team_id, 'home' AS role, 1 AS side_order
              FROM fixtures WHERE id > v_checkpoint AND id <= v_end
            UNION ALL
            SELECT id, away_team_id, 'away', 2
              FROM fixtures WHERE id > v_checkpoint AND id <= v_end
      ) sides
      JOIN provider_event_mappings pem
        ON pem.provider_id=v_provider_id AND pem.legacy_fixture_id=sides.fixture_id
      JOIN provider_participant_mappings ppm
        ON ppm.provider_id=v_provider_id AND ppm.legacy_team_id=sides.legacy_team_id
    ON DUPLICATE KEY UPDATE role=VALUES(role), participant_id=VALUES(participant_id), updated_at=VALUES(updated_at);

    IF EXISTS (
        SELECT pem.event_id
          FROM provider_event_mappings pem
          LEFT JOIN event_participants ep ON ep.event_id=pem.event_id
         WHERE pem.provider_id=v_provider_id
           AND pem.legacy_fixture_id > v_checkpoint AND pem.legacy_fixture_id <= v_end
         GROUP BY pem.event_id
        HAVING COUNT(ep.participant_id)<>2 OR COUNT(DISTINCT ep.participant_id)<>2
            OR SUM(ep.role='home' AND ep.side_order=1)<>1
            OR SUM(ep.role='away' AND ep.side_order=2)<>1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'event participant invariant failure';
    END IF;
    UPDATE import_runs
       SET last_legacy_id = v_end,
           source_rows_seen = source_rows_seen + (
               SELECT COUNT(*) FROM fixtures f JOIN leagues l ON l.id=f.league_id AND l.sport='football'
                WHERE f.id > v_checkpoint AND f.id <= v_end
           ),
           applied_count = applied_count + (
               SELECT COUNT(*) FROM provider_event_mappings
                WHERE provider_id=v_provider_id AND legacy_fixture_id > v_checkpoint AND legacy_fixture_id <= v_end
           ),
           unresolved_count = unresolved_count + (
               SELECT COUNT(DISTINCT source_legacy_id) FROM identity_quarantines
                WHERE provider_id=v_provider_id AND source_table='fixtures'
                  AND source_legacy_id > v_checkpoint AND source_legacy_id <= v_end AND status='open'
           ),
           status = IF(v_end >= v_max_fixture_id, 'completed', 'paused'),
           finished_at = IF(v_end >= v_max_fixture_id, v_now, NULL)
     WHERE run_key=p_run_key;

    COMMIT;
    DO RELEASE_LOCK(v_lock_name);
    SET v_lock_acquired = 0;
END//
DELIMITER ;
