<?php

use Illuminate\Database\Migrations\Migration;
require_once __DIR__.'/support/CanonicalFootballMigration.php';

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        CanonicalFootballMigration::create('event_participants', '2026_09_17_000007_create_event_participants_table', <<<'SQL'
CREATE TABLE event_participants (
    event_id BIGINT UNSIGNED NOT NULL,
    participant_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    side_order TINYINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_id, side_order),
    UNIQUE KEY event_participants_identity_unique (event_id, participant_id),
    UNIQUE KEY event_participants_role_unique (event_id, role),
    KEY event_participants_participant_index (participant_id),
    CONSTRAINT event_participants_event_id_foreign FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT event_participants_participant_id_foreign FOREIGN KEY (participant_id) REFERENCES participants (id),
    CONSTRAINT event_participants_side_check CHECK (side_order IN (1, 2)),
    CONSTRAINT event_participants_role_side_check CHECK (
        (role = 'home' AND side_order = 1) OR
        (role = 'away' AND side_order = 2)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000007_create_event_participants_table'
SQL);
    }

    public function down(): void
    {
        CanonicalFootballMigration::drop('event_participants', '2026_09_17_000007_create_event_participants_table');
    }
};
