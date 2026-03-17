<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('api_team_id')->unique();
            $table->string('name', 100);
            $table->string('short_name', 10)->nullable();
            $table->string('logo_url', 255)->nullable();
            $table->string('country', 60)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('teams'); }
};
