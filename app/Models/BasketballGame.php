<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BasketballGame extends Model
{
    protected $fillable = [
        'api_game_id','league_name','country_name',
        'home_team','away_team',
        'home_score','away_score',
        'status_short','elapsed','game_date',
    ];

    protected $casts = [
        'game_date' => 'datetime',
    ];

    public function isLive(): bool
    {
        return in_array($this->status_short, ['Q1','Q2','Q3','Q4','HT','OT','LIVE','BP']);
    }

    public function statusLabel(): string
    {
        return match($this->status_short) {
            'NS'  => 'Zakazano',
            'Q1'  => '1. četvrtina',
            'Q2'  => '2. četvrtina',
            'Q3'  => '3. četvrtina',
            'Q4'  => '4. četvrtina',
            'OT'  => 'Produžeci',
            'HT'  => 'Poluvrijeme',
            'FT'  => 'Završeno',
            'AOT' => 'Završeno (OT)',
            'BT'  => 'Pauza',
            'BP'  => 'Pauza',
            default => $this->status_short ?? '',
        };
    }
}
