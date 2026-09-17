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
    protected $casts = [
        'goals_home' => 'integer', 'goals_away' => 'integer',
        'home_halftime' => 'integer', 'away_halftime' => 'integer',
        'home_fulltime' => 'integer', 'away_fulltime' => 'integer',
        'home_extratime' => 'integer', 'away_extratime' => 'integer',
        'home_penalties' => 'integer', 'away_penalties' => 'integer',
    ];

    public $updated_at = true;
    public $created_at = false;
}
