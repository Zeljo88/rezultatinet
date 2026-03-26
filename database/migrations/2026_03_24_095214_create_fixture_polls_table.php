<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_id')->constrained()->onDelete('cascade');
            $table->string('vote', 10); // 'home', 'draw', 'away'
            $table->string('voter_ip', 45); // IPv4 + IPv6
            $table->string('voter_session', 100)->nullable();
            $table->timestamps();

            // 1 glas po IP po utakmici
            $table->unique(['fixture_id', 'voter_ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_polls');
    }
};
