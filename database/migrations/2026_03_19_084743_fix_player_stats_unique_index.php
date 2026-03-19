<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop existing rows to start clean
        DB::statement('DELETE FROM player_stats');

        // Drop FK referencing player_id (which uses the unique index)
        DB::statement('ALTER TABLE player_stats DROP FOREIGN KEY player_stats_player_id_foreign');

        // Drop old unique index
        DB::statement('ALTER TABLE player_stats DROP INDEX player_stats_player_id_season_unique');

        // Add new unique index with league_id included
        DB::statement('ALTER TABLE player_stats ADD UNIQUE KEY player_stats_player_league_season_unique (player_id, league_id, season)');

        // Re-add the player FK
        DB::statement('ALTER TABLE player_stats ADD CONSTRAINT player_stats_player_id_foreign FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE player_stats DROP FOREIGN KEY player_stats_player_id_foreign');
        DB::statement('ALTER TABLE player_stats DROP INDEX player_stats_player_league_season_unique');
        DB::statement('ALTER TABLE player_stats ADD UNIQUE KEY player_stats_player_id_season_unique (player_id, season)');
        DB::statement('ALTER TABLE player_stats ADD CONSTRAINT player_stats_player_id_foreign FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE');
    }
};
