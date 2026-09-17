INSERT INTO leagues (
    id, api_league_id, name, country, sport, is_active, current_season, created_at, updated_at
) VALUES (
    9001, 99001, 'Canonical migration fixture league', 'Test', 'football', 1, 2026,
    '2026-09-17 00:00:00', '2026-09-17 00:00:00'
);

INSERT INTO teams (
    id, api_team_id, name, slug, short_name, country, created_at, updated_at
) VALUES
    (9101, 99101, 'Fixture Home', 'fixture-home', 'HOME', 'Test', '2026-09-17 00:00:00', '2026-09-17 00:00:00'),
    (9102, 99102, 'Fixture Away', 'fixture-away', 'AWAY', 'Test', '2026-09-17 00:00:00', '2026-09-17 00:00:00');

INSERT INTO fixtures (
    id, api_fixture_id, league_id, home_team_id, away_team_id, season, round,
    kick_off, status_long, status_short, created_at, updated_at
) VALUES (
    9201, 99201, 9001, 9101, 9102, 2026, 'Fixture round',
    '2026-09-17 12:00:00', 'Not Started', 'NS',
    '2026-09-17 00:00:00', '2026-09-17 00:00:00'
);

INSERT INTO fixture_scores (
    id, fixture_id, goals_home, goals_away, updated_at
) VALUES (
    9301, 9201, NULL, NULL, '2026-09-17 00:00:00'
);
