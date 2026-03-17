<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('fixture_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('player_name', 100)->nullable();
            $table->string('assist_name', 100)->nullable();
            $table->enum('type', ['Goal','Card','subst','Var']);
            $table->string('detail', 50)->nullable();
            $table->unsignedSmallInteger('elapsed_minute')->nullable();
            $table->timestamps();
            $table->index('fixture_id');
        });
    }
    public function down(): void { Schema::dropIfExists('fixture_events'); }
};
