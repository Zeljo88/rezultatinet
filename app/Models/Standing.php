<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Standing extends Model
{
    protected $fillable = [
        'league_id','team_id','season','rank','played',
        'win','draw','lose','goals_for','goals_against',
        'goal_diff','points','form','description',
        'home_played','home_win','home_draw','home_lose','home_goals_for','home_goals_against',
        'away_played','away_win','away_draw','away_lose','away_goals_for','away_goals_against',
    ];

    public function team() { return $this->belongsTo(Team::class); }
    public function league() { return $this->belongsTo(League::class); }
}
