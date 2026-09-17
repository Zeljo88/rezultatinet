SELECT 'leagues' AS table_name, id AS row_key,
       SHA2(CAST(JSON_ARRAY(id, api_league_id, name, country, logo_url, sport, is_active, current_season, created_at, updated_at) AS CHAR), 256) AS row_sha256
FROM leagues
UNION ALL
SELECT 'teams', id,
       SHA2(CAST(JSON_ARRAY(id, api_team_id, name, slug, short_name, logo_url, country, created_at, updated_at) AS CHAR), 256)
FROM teams
UNION ALL
SELECT 'fixtures', id,
       SHA2(CAST(JSON_ARRAY(id, api_fixture_id, league_id, home_team_id, away_team_id, season, round, kick_off, status_long, status_short, elapsed_minute, elapsed_extra, venue_name, referee, lineups_fetched_at, created_at, updated_at) AS CHAR), 256)
FROM fixtures
UNION ALL
SELECT 'fixture_scores', id,
       SHA2(CAST(JSON_ARRAY(id, fixture_id, goals_home, goals_away, home_halftime, away_halftime, home_fulltime, away_fulltime, home_extratime, away_extratime, home_penalties, away_penalties, updated_at) AS CHAR), 256)
FROM fixture_scores
ORDER BY table_name, row_key;
