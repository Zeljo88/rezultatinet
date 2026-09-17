-- Aggregate-only source preflight; no row data and no writes.
SELECT 'legacy_leagues' AS metric, COUNT(*) AS value FROM leagues WHERE sport='football'
UNION ALL SELECT 'legacy_scoped_teams', COUNT(DISTINCT team_id) FROM (
    SELECT f.home_team_id AS team_id FROM fixtures f JOIN leagues l ON l.id=f.league_id WHERE l.sport='football'
    UNION ALL
    SELECT f.away_team_id FROM fixtures f JOIN leagues l ON l.id=f.league_id WHERE l.sport='football'
) scoped
UNION ALL SELECT 'legacy_fixtures', COUNT(*) FROM fixtures f JOIN leagues l ON l.id=f.league_id WHERE l.sport='football'
UNION ALL SELECT 'self_participant_fixtures', COUNT(*) FROM fixtures f JOIN leagues l ON l.id=f.league_id WHERE l.sport='football' AND f.home_team_id=f.away_team_id
UNION ALL SELECT 'unknown_status_fixtures', COUNT(*) FROM fixtures f JOIN leagues l ON l.id=f.league_id
 WHERE l.sport='football' AND COALESCE(f.status_short,'') NOT IN
 ('NS','TBD','1H','HT','2H','ET','BT','P','LIVE','FT','AET','PEN','PST','CANC','ABD','AWD','WO','INT','SUSP')
UNION ALL SELECT 'missing_score_rows', COUNT(*) FROM fixtures f JOIN leagues l ON l.id=f.league_id
 LEFT JOIN fixture_scores fs ON fs.fixture_id=f.id WHERE l.sport='football' AND fs.id IS NULL
ORDER BY metric;

SELECT COALESCE(status_short, '<NULL>') AS status_short, COUNT(*) AS fixture_count
FROM fixtures f JOIN leagues l ON l.id=f.league_id
WHERE l.sport='football'
GROUP BY status_short
ORDER BY status_short;
