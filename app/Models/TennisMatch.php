<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TennisMatch extends Model
{
    protected $fillable = [
        'api_match_id','tournament_name','country_name',
        'player_home','player_away',
        'score','status','match_date',
    ];

    protected $casts = [
        'match_date' => 'datetime',
    ];

    public function isLive(): bool
    {
        return in_array($this->status, ['In Play','1st Set','2nd Set','3rd Set','4th Set','5th Set','Break Time']);
    }
}
