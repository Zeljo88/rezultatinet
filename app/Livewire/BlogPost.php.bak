<?php
namespace App\Livewire;

use App\Models\Post;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class BlogPost extends Component
{
    public Post $post;

    public function mount(string $slug): void
    {
        $this->post = Post::where('slug', $slug)->where('published', true)->firstOrFail();
    }

    public function render()
    {
        return view('livewire.blog-post');
    }
}
