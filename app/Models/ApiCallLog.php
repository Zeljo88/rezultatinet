<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiCallLog extends Model
{
    protected $fillable = ['endpoint','called_date'];

    public static function getTodayCount(): int
    {
        return static::whereDate('called_date', today())->count();
    }
}
