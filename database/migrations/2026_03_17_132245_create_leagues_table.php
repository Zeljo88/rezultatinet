<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('api_league_id')->unique();
            $table->string('name', 100);
            $table->string('country', 60)->nullable();
            $table->string('logo_url', 255)->nullable();
            $table->enum('sport', ['football','basketball','tennis'])->default('football');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('current_season')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'sport']);
        });
    }
    public function down(): void { Schema::dropIfExists('leagues'); }
};
