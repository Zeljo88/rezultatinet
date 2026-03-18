<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Standing extends Model
{
    protected $fillable = [
        'league_id','team_id','season','rank','played',
        'win','draw','lose','goals_for','goals_against',
        'goal_diff','points','form','description',
    ];

    public function team() { return $this->belongsTo(Team::class); }
    public function league() { return $this->belongsTo(League::class); }
}
