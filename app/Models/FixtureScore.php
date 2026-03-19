<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixtureScore extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'goals_home','goals_away',
        'fixture_id','home_halftime','away_halftime',
        'home_fulltime','away_fulltime',
        'home_extratime','away_extratime',
        'home_penalties','away_penalties'
    ];
    public $updated_at = true;
    public $created_at = false;
}
