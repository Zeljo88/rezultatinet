<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('country_name', 100)->nullable()->change();
            $table->string('position', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('country_name', 50)->nullable(false)->default('')->change();
            $table->string('position', 50)->nullable(false)->default('')->change();
        });
    }
};
