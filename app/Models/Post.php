<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title','slug','meta_title','meta_description','content','keyword','published'];
    protected $casts = ['published' => 'boolean'];

    public function getExcerptAttribute(): string
    {
        return substr(strip_tags($this->content), 0, 200) . '...';
    }
}
