<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standings', function (Blueprint $table) {
            $table->unsignedTinyInteger('home_played')->default(0)->after('form');
            $table->unsignedTinyInteger('home_win')->default(0)->after('home_played');
            $table->unsignedTinyInteger('home_draw')->default(0)->after('home_win');
            $table->unsignedTinyInteger('home_lose')->default(0)->after('home_draw');
            $table->unsignedSmallInteger('home_goals_for')->default(0)->after('home_lose');
            $table->unsignedSmallInteger('home_goals_against')->default(0)->after('home_goals_for');
            $table->unsignedTinyInteger('away_played')->default(0)->after('home_goals_against');
            $table->unsignedTinyInteger('away_win')->default(0)->after('away_played');
            $table->unsignedTinyInteger('away_draw')->default(0)->after('away_win');
            $table->unsignedTinyInteger('away_lose')->default(0)->after('away_draw');
            $table->unsignedSmallInteger('away_goals_for')->default(0)->after('away_lose');
            $table->unsignedSmallInteger('away_goals_against')->default(0)->after('away_goals_for');
        });
    }

    public function down(): void
    {
        Schema::table('standings', function (Blueprint $table) {
            $table->dropColumn([
                'home_played','home_win','home_draw','home_lose','home_goals_for','home_goals_against',
                'away_played','away_win','away_draw','away_lose','away_goals_for','away_goals_against',
            ]);
        });
    }
};
