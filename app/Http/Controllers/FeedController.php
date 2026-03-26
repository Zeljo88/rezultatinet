<?php
namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Response;

class FeedController extends Controller
{
    public function rss()
    {
        $posts = Post::where('published', 1)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        $content = view('feed.rss', compact('posts'))->render();

        return response($content, 200)
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}
