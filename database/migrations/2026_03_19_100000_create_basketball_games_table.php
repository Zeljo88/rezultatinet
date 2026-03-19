<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('basketball_games', function (Blueprint $table) {
            $table->id();
            $table->integer('api_game_id')->unique();
            $table->string('league_name')->nullable();
            $table->string('country_name')->nullable();
            $table->string('home_team')->nullable();
            $table->string('away_team')->nullable();
            $table->integer('home_score')->nullable();
            $table->integer('away_score')->nullable();
            $table->string('status_short')->nullable(); // NS, Q1, Q2, Q3, Q4, HT, FT, OT
            $table->integer('elapsed')->nullable();
            $table->timestamp('game_date')->nullable();
            $table->timestamps();
            $table->index(['game_date', 'status_short']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('basketball_games');
    }
};
