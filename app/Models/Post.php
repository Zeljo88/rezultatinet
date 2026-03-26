<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    protected $fillable = [
        'title', 'slug', 'meta_title', 'meta_description',
        'content', 'keyword', 'featured_image', 'published', 'fixture_id',
    ];

    protected $casts = ['published' => 'boolean'];

    // ── Relationships ────────────────────────────────────────────────────────

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    public function getExcerptAttribute(): string
    {
        return substr(strip_tags($this->content), 0, 200) . '...';
    }

    // ── OG Image ─────────────────────────────────────────────────────────────

    public function getOgImageUrl(): string
    {
        // 1. Manual upload takes priority
        if ($this->featured_image) {
            return asset($this->featured_image);
        }

        // 2. Linked to a fixture → dynamic match image
        if ($this->fixture_id && $this->fixture) {
            return $this->getMatchOgImage();
        }

        // 3. Fallback: loremflickr by keyword (Unsplash source is deprecated)
        $keyword = $this->keyword
            ? urlencode(explode(',', $this->keyword)[0])
            : 'football';

        return "https://loremflickr.com/1200/630/{$keyword}";
    }

    private function getMatchOgImage(): string
    {
        $fixture  = $this->fixture;
        $homeTeam = $fixture->homeTeam;
        $awayTeam = $fixture->awayTeam;

        $homeLogo = $homeTeam->logo_url ?? '';
        $awayLogo = $awayTeam->logo_url ?? '';

        $isFinished = in_array($fixture->status_short, ['FT', 'AET', 'PEN']);

        if ($isFinished && $fixture->score) {
            $score = $fixture->score->goals_home . ' : ' . $fixture->score->goals_away;
        } else {
            $score = '? : ?';
        }

        return route('og.match-image', [
            'home_logo'  => $homeLogo,
            'away_logo'  => $awayLogo,
            'score'      => $score,
            'home_name'  => $homeTeam->name ?? '',
            'away_name'  => $awayTeam->name ?? '',
        ]);
    }
}
