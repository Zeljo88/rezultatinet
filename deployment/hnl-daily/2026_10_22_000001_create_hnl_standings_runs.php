<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        throw new RuntimeException('Preserve HNL ledger; no destructive automatic rollback.');
    }
};
