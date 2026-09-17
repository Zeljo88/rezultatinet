SELECT 'leagues' AS table_name, COUNT(*) AS row_count,
       COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', id, api_league_id, name, COALESCE(country, '<NULL>'), COALESCE(logo_url, '<NULL>'), sport, is_active, COALESCE(current_season, '<NULL>'), COALESCE(updated_at, '<NULL>')))), 0) AS checksum
FROM leagues
UNION ALL
SELECT 'teams', COUNT(*),
       COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', id, api_team_id, name, COALESCE(slug, '<NULL>'), COALESCE(short_name, '<NULL>'), COALESCE(logo_url, '<NULL>'), COALESCE(country, '<NULL>'), COALESCE(updated_at, '<NULL>')))), 0)
FROM teams
UNION ALL
SELECT 'fixtures', COUNT(*),
       COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', id, api_fixture_id, league_id, home_team_id, away_team_id, season, COALESCE(round, '<NULL>'), kick_off, COALESCE(status_long, '<NULL>'), COALESCE(status_short, '<NULL>'), COALESCE(elapsed_minute, '<NULL>'), COALESCE(elapsed_extra, '<NULL>'), COALESCE(updated_at, '<NULL>')))), 0)
FROM fixtures
UNION ALL
SELECT 'fixture_scores', COUNT(*),
       COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', id, fixture_id, COALESCE(goals_home, '<NULL>'), COALESCE(goals_away, '<NULL>'), COALESCE(home_halftime, '<NULL>'), COALESCE(away_halftime, '<NULL>'), COALESCE(home_fulltime, '<NULL>'), COALESCE(away_fulltime, '<NULL>'), COALESCE(home_extratime, '<NULL>'), COALESCE(away_extratime, '<NULL>'), COALESCE(home_penalties, '<NULL>'), COALESCE(away_penalties, '<NULL>'), COALESCE(updated_at, '<NULL>')))), 0)
FROM fixture_scores
ORDER BY table_name;
