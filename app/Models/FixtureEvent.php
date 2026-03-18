<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixtureEvent extends Model
{
    protected $fillable = ['fixture_id','team_id','player_name','assist_name','type','detail','elapsed_minute'];

    public function fixture() { return $this->belongsTo(Fixture::class); }
    public function team() { return $this->belongsTo(Team::class); }
}
