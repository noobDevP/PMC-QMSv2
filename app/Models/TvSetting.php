<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TvSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'tv_id',
        'media_mode',
        'youtube_id',
        'facebook_url',
        'disable_fullscreen_ads'
    ];
}
