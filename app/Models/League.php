<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class League extends Model
{
    protected $fillable = ['api_league_id','name','country','logo_url','sport','is_active','current_season'];

    public function fixtures() { return $this->hasMany(Fixture::class); }
}
