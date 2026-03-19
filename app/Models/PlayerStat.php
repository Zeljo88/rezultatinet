<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerStat extends Model
{
    protected $table = 'player_stats';
    protected $fillable = [
        'player_id', 'league_id', 'season',
        'appearances', 'goals', 'assists',
        'yellow_cards', 'red_cards', 'rating',
        'minutes_played',
    ];

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function league()
    {
        return $this->belongsTo(League::class);
    }
}
