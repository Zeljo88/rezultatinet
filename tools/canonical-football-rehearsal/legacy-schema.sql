-- Minimal exact legacy subset needed for the synthetic rehearsal.
-- Column types/keys mirror origin/main:database/schema/mariadb-schema.sql.
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

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS fixture_scores;
DROP TABLE IF EXISTS fixtures;
DROP TABLE IF EXISTS teams;
DROP TABLE IF EXISTS leagues;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE leagues (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_league_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    country VARCHAR(60) NULL,
    logo_url VARCHAR(255) NULL,
    sport ENUM('football','basketball','tennis') NOT NULL DEFAULT 'football',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    current_season SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY leagues_api_league_id_unique (api_league_id),
    KEY leagues_is_active_sport_index (is_active, sport)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE teams (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_team_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(255) NULL,
    short_name VARCHAR(10) NULL,
    logo_url VARCHAR(255) NULL,
    country VARCHAR(60) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY teams_api_team_id_unique (api_team_id),
    UNIQUE KEY teams_slug_unique (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fixtures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_fixture_id INT UNSIGNED NOT NULL,
    league_id BIGINT UNSIGNED NOT NULL,
    home_team_id BIGINT UNSIGNED NOT NULL,
    away_team_id BIGINT UNSIGNED NOT NULL,
    season SMALLINT UNSIGNED NOT NULL,
    round VARCHAR(50) NULL,
    kick_off DATETIME NOT NULL,
    status_long VARCHAR(50) NULL,
    status_short VARCHAR(10) NULL,
    elapsed_minute SMALLINT UNSIGNED NULL,
    elapsed_extra INT NULL,
    venue_name VARCHAR(100) NULL,
    referee VARCHAR(100) NULL,
    lineups_fetched_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY fixtures_api_fixture_id_unique (api_fixture_id),
    KEY fixtures_away_team_id_foreign (away_team_id),
    KEY fixtures_home_team_id_foreign (home_team_id),
    KEY fixtures_kick_off_index (kick_off),
    KEY fixtures_league_id_season_index (league_id, season),
    KEY fixtures_status_short_index (status_short),
    KEY idx_kickoff_status (kick_off, status_short),
    CONSTRAINT fixtures_away_team_id_foreign FOREIGN KEY (away_team_id) REFERENCES teams (id),
    CONSTRAINT fixtures_home_team_id_foreign FOREIGN KEY (home_team_id) REFERENCES teams (id),
    CONSTRAINT fixtures_league_id_foreign FOREIGN KEY (league_id) REFERENCES leagues (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fixture_scores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fixture_id BIGINT UNSIGNED NOT NULL,
    goals_home INT NULL,
    goals_away INT NULL,
    home_halftime INT NULL,
    away_halftime INT NULL,
    home_fulltime INT NULL,
    away_fulltime INT NULL,
    home_extratime TINYINT UNSIGNED NULL,
    away_extratime TINYINT UNSIGNED NULL,
    home_penalties TINYINT UNSIGNED NULL,
    away_penalties TINYINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY fixture_scores_fixture_id_unique (fixture_id),
    CONSTRAINT fixture_scores_fixture_id_foreign FOREIGN KEY (fixture_id) REFERENCES fixtures (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
