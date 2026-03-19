<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixtureLineup extends Model
{
    protected $fillable = [
        'fixture_id',
        'team_side',
        'formation',
        'coach_name',
        'startxi',
        'substitutes',
    ];

    protected $casts = [
        'startxi'     => 'array',
        'substitutes' => 'array',
    ];

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }
}
