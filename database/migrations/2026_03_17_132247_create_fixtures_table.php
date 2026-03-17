<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('fixtures', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('api_fixture_id')->unique();
            $table->foreignId('league_id')->constrained();
            $table->foreignId('home_team_id')->references('id')->on('teams');
            $table->foreignId('away_team_id')->references('id')->on('teams');
            $table->unsignedSmallInteger('season');
            $table->string('round', 50)->nullable();
            $table->dateTime('kick_off');
            $table->string('status_long', 50)->nullable();
            $table->string('status_short', 10)->nullable();
            $table->unsignedSmallInteger('elapsed_minute')->nullable();
            $table->string('venue_name', 100)->nullable();
            $table->string('referee', 100)->nullable();
            $table->timestamps();
            $table->index('kick_off');
            $table->index('status_short');
            $table->index(['league_id', 'season']);
        });
    }
    public function down(): void { Schema::dropIfExists('fixtures'); }
};
