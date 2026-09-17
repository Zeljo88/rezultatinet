-- Deterministic, synthetic-only source rows. No production data.
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

INSERT INTO leagues
    (id, api_league_id, name, country, logo_url, sport, is_active, current_season, created_at, updated_at)
VALUES
    (1, 1001, 'Synthetic Premier League', 'Bosnia and Herzegovina', 'https://invalid.example/league-1.png', 'football', 1, 2026, '2026-01-01 00:00:00', '2026-09-01 10:00:00'),
    (2, 1002, 'Synthetic First League', 'Croatia', 'https://invalid.example/league-2.png', 'football', 1, 2026, '2026-01-01 00:00:00', '2026-09-01 10:00:00');

INSERT INTO teams
    (id, api_team_id, name, slug, short_name, logo_url, country, created_at, updated_at)
VALUES
    (1, 2001, 'Synthetic Sarajevo', 'synthetic-sarajevo', 'SAR', 'https://invalid.example/team-1.png', 'Bosnia and Herzegovina', '2026-01-01 00:00:00', '2026-09-01 10:00:00'),
    (2, 2002, 'Synthetic Mostar', 'synthetic-mostar', 'MOS', 'https://invalid.example/team-2.png', 'Bosnia and Herzegovina', '2026-01-01 00:00:00', '2026-09-01 10:00:00'),
    (3, 2003, 'Synthetic Zagreb', NULL, 'ZAG', 'https://invalid.example/team-3.png', 'Croatia', '2026-01-01 00:00:00', '2026-09-01 10:00:00'),
    (4, 2004, 'Synthetic Split', 'synthetic-split', 'SPL', 'https://invalid.example/team-4.png', 'Croatia', '2026-01-01 00:00:00', '2026-09-01 10:00:00');

INSERT INTO fixtures
    (id, api_fixture_id, league_id, home_team_id, away_team_id, season, round, kick_off, status_long, status_short, elapsed_minute, elapsed_extra, venue_name, referee, created_at, updated_at)
VALUES
    (1, 3001, 1, 1, 2, 2026, 'Round 1', '2026-09-18 18:00:00', 'Not Started', 'NS', NULL, NULL, 'Synthetic Stadium A', NULL, '2026-09-01 00:00:00', '2026-09-17 08:00:00'),
    (2, 3002, 1, 2, 1, 2025, 'Round 20', '2026-05-01 18:00:00', 'Match Finished', 'FT', 90, NULL, 'Synthetic Stadium B', 'Synthetic Referee', '2026-05-01 00:00:00', '2026-05-01 20:00:00'),
    (3, 3003, 2, 3, 4, 2026, 'Round 2', '2026-09-17 19:00:00', 'Second Half', '2H', 71, 2, 'Synthetic Stadium C', NULL, '2026-09-01 00:00:00', '2026-09-17 20:12:00'),
    (4, 3004, 2, 4, 3, 2026, 'Round 3', '2026-09-20 16:00:00', 'Postponed', 'PST', NULL, NULL, 'Synthetic Stadium D', NULL, '2026-09-01 00:00:00', '2026-09-17 09:00:00'),
    (5, 3005, 1, 1, 1, 2026, 'Round X', '2026-09-21 16:00:00', 'Not Started', 'NS', NULL, NULL, NULL, NULL, '2026-09-01 00:00:00', '2026-09-17 09:30:00'),
    (6, 3006, 1, 1, 2, 2026, 'Round Y', '2026-09-22 16:00:00', 'Novel Provider State', 'ZZ', NULL, NULL, NULL, NULL, '2026-09-01 00:00:00', '2026-09-17 09:45:00');

INSERT INTO fixture_scores
    (id, fixture_id, goals_home, goals_away, home_halftime, away_halftime, home_fulltime, away_fulltime, home_extratime, away_extratime, home_penalties, away_penalties, updated_at)
VALUES
    (1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-17 08:00:00'),
    (2, 2, 2, 1, 1, 0, 2, 1, NULL, NULL, NULL, NULL, '2026-05-01 20:00:00'),
    (3, 3, 1, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-17 20:12:00'),
    (4, 4, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-17 09:00:00'),
    (5, 5, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-17 09:30:00'),
    (6, 6, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-17 09:45:00');
