<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_lineups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fixture_id');
            $table->foreign('fixture_id')->references('id')->on('fixtures')->onDelete('cascade');
            $table->string('team_side'); // 'home' or 'away'
            $table->string('formation')->nullable(); // e.g. "4-3-3"
            $table->string('coach_name')->nullable();
            $table->json('startxi')->nullable();
            $table->json('substitutes')->nullable();
            $table->timestamps();
            $table->unique(['fixture_id', 'team_side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_lineups');
    }
};
