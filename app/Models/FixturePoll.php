<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixturePoll extends Model
{
    protected $fillable = ['fixture_id', 'vote', 'voter_ip', 'voter_session'];

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }
}
