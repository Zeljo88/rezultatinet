<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hnl_standings_runs', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('league_id');
            $t->date('utc_day');
            $t->unsignedSmallInteger('season');
            $t->string('policy', 64);
            $t->string('outcome', 48);
            $t->dateTime('attempted_at', 6);
            $t->unsignedTinyInteger('reserved_ceiling')->default(1);
            $t->unsignedTinyInteger('physical_observed')->nullable();
            $t->dateTime('validated_at', 6)->nullable();
            $t->dateTime('commit_observed_at', 6)->nullable();
            $t->uuid('predecessor_id')->nullable();
            $t->char('payload_sha256', 64)->nullable();
            $t->unsignedTinyInteger('changed_rows')->nullable();
            $t->json('postimage')->nullable();
            $t->json('provider_vector')->nullable();
            $t->json('before_image')->nullable();
            $t->string('reason', 96)->nullable();
            $t->unique(['league_id', 'utc_day'], 'hnl_daily_slot_unique');
            $t->index(['league_id', 'outcome', 'validated_at'], 'hnl_last_success');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || DB::transactionLevel() !== 0) {
            throw new RuntimeException('Empty rollback requires isolated MariaDB admission.');
        }
        DB::statement('SET SESSION lock_wait_timeout = 5');
        DB::statement('LOCK TABLES hnl_standings_runs WRITE');
        try {
            if (DB::table('hnl_standings_runs')->exists()) {
                throw new RuntimeException('Preserve HNL ledger: populated rollback refused.');
            }
            Schema::drop('hnl_standings_runs');
        } finally {
            DB::statement('UNLOCK TABLES');
        }
    }
};
