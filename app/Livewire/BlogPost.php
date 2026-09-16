<?php
namespace App\Livewire;

use App\Models\Post;
use App\Support\BlogMetaTitle;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout("layouts.app")]
class BlogPost extends Component
{
    public Post $post;

    public function mount(string $slug): void
    {
        $this->post = Post::where("slug", $slug)
            ->where("published", true)
            ->with(['fixture.homeTeam', 'fixture.awayTeam', 'fixture.score'])
            ->firstOrFail();
    }

    public function render()
    {
        // Build SEO meta title (max 60 chars) per spec
        $rawTitle  = $this->post->meta_title ?? $this->post->title;
        $metaTitle = BlogMetaTitle::format($rawTitle);

        // Build SEO meta description (max 155 chars) per spec
        $rawExcerpt      = $this->post->meta_description ?? mb_substr(strip_tags($this->post->content ?? ""), 0, 130);
        $descSuffix      = " — Detaljna analiza na rezultati.net.";
        $maxDescLen      = 155 - strlen($descSuffix);
        $trimmedExcerpt  = strlen($rawExcerpt) > $maxDescLen ? mb_substr($rawExcerpt, 0, $maxDescLen) : $rawExcerpt;
        $metaDescription = $trimmedExcerpt . $descSuffix;

        $ogImage = $this->post->getOgImageUrl();

        return view("livewire.blog-post")
            ->layout("layouts.app", [
                "metaTitle"       => $metaTitle,
                "metaDescription" => $metaDescription,
                "ogImage"         => $ogImage,
                "schemaBlocks"    => [],
            ]);
    }
}
