<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillable = ["api_player_id","name","slug","nationality","country_name","country_flag","position","date_of_birth","photo_url","current_club","current_league","current_club_logo","is_featured","is_active","bio"];

    public function stats() { return $this->hasMany(PlayerStat::class); }
    public function currentStats() { return $this->hasOne(PlayerStat::class)->latestOfMany(); }

    public function getPositionLabelAttribute(): string {
        return match($this->position) {
            "Goalkeeper" => "Golman", "Defender" => "Branič",
            "Midfielder" => "Veznjak", "Forward" => "Napadač", default => $this->position ?? ""
        };
    }
}
