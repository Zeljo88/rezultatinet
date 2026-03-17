<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('fixture_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('home_halftime')->default(0);
            $table->unsignedTinyInteger('away_halftime')->default(0);
            $table->unsignedTinyInteger('home_fulltime')->default(0);
            $table->unsignedTinyInteger('away_fulltime')->default(0);
            $table->unsignedTinyInteger('home_extratime')->nullable();
            $table->unsignedTinyInteger('away_extratime')->nullable();
            $table->unsignedTinyInteger('home_penalties')->nullable();
            $table->unsignedTinyInteger('away_penalties')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }
    public function down(): void { Schema::dropIfExists('fixture_scores'); }
};
