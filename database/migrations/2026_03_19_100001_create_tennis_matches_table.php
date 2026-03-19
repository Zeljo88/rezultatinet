<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('tennis_matches', function (Blueprint $table) {
            $table->id();
            $table->integer('api_match_id')->unique();
            $table->string('tournament_name')->nullable();
            $table->string('country_name')->nullable();
            $table->string('player_home')->nullable();
            $table->string('player_away')->nullable();
            $table->string('score')->nullable(); // e.g. "6-3, 7-5"
            $table->string('status')->nullable();
            $table->timestamp('match_date')->nullable();
            $table->timestamps();
            $table->index(['match_date', 'status']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('tennis_matches');
    }
};
