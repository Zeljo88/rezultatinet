<?php
namespace App\Livewire;

use App\Models\Post;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class Blog extends Component
{
    public function render()
    {
        return view('livewire.blog', [
            'posts' => Post::where('published', true)->latest()->get(),
        ]);
    }
}
