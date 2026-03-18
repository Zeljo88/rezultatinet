<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prediction extends Model
{
    protected $fillable = ['fixture_id', 'vote', 'ip', 'session_id'];

    public function fixture() { return $this->belongsTo(Fixture::class); }

    public static function getStats(int $fixtureId): array
    {
        $total = static::where('fixture_id', $fixtureId)->count();
        if ($total === 0) return ['home' => 0, 'draw' => 0, 'away' => 0, 'total' => 0];

        return [
            'home'  => round(static::where('fixture_id', $fixtureId)->where('vote', 'home')->count() / $total * 100),
            'draw'  => round(static::where('fixture_id', $fixtureId)->where('vote', 'draw')->count() / $total * 100),
            'away'  => round(static::where('fixture_id', $fixtureId)->where('vote', 'away')->count() / $total * 100),
            'total' => $total,
        ];
    }
}
