<?php

namespace App\Console\Commands;

use App\Http\Controllers\OgImageController;
use App\Models\Post;
use Illuminate\Console\Command;

class GeneratePostOgImages extends Command
{
    protected $signature = 'og:generate-post-images';
    protected $description = 'Pre-generate static og:image PNG files for posts linked to fixtures';

    public function handle()
    {
        $posts = Post::whereNotNull('fixture_id')
            ->whereNull('featured_image')
            ->with(['fixture.homeTeam', 'fixture.awayTeam', 'fixture.score'])
            ->get();

        $this->info("Found {$posts->count()} posts without featured_image.");

        $controller = new OgImageController();

        foreach ($posts as $post) {
            $this->line("Processing post #{$post->id}: {$post->title}");
            $path = $controller->generateAndSave($post);
            if ($path) {
                $post->update(['featured_image' => $path]);
                $this->info("  ✓ Generated: {$path}");
            } else {
                $this->warn("  ✗ Skipped post #{$post->id} (no fixture data)");
            }
        }

        $this->info("Done!");
        return 0;
    }
}
