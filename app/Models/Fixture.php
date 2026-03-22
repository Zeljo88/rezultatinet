<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fixture extends Model
{
    protected $fillable = [
        'api_fixture_id','league_id','home_team_id','away_team_id',
        'season','round','kick_off','status_long','status_short',
        'elapsed_minute','venue_name','referee','lineups_fetched_at'
    ];

    protected $casts = [
        'kick_off' => 'datetime',
        'lineups_fetched_at' => 'datetime',
    ];

    public function league()    { return $this->belongsTo(League::class); }
    public function homeTeam()  { return $this->belongsTo(Team::class, 'home_team_id'); }
    public function awayTeam()  { return $this->belongsTo(Team::class, 'away_team_id'); }
    public function score()     { return $this->hasOne(FixtureScore::class); }
    public function events()    { return $this->hasMany(FixtureEvent::class); }
    public function lineups()   { return $this->hasMany(FixtureLineup::class); }

    public function scopeLive($q) {
        return $q->whereIn('status_short', ['1H','2H','HT','ET','BT','P','SUSP','INT','LIVE']);
    }

    public function scopeToday($q) {
        return $q->whereDate('kick_off', today());
    }
}
