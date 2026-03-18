<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fixture_id');
            $table->enum('vote', ['home', 'draw', 'away']);
            $table->string('ip', 45);
            $table->string('session_id', 255);
            $table->timestamps();

            $table->foreign('fixture_id')->references('id')->on('fixtures')->onDelete('cascade');
            $table->index('fixture_id');
            $table->index(['fixture_id', 'vote']);
            $table->index('ip');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
