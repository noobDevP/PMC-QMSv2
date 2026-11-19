<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'tv_idle_seconds',
        'shrink_timeout',
        'collapse_timeout',
        'periodic_return_timer',
        'periodic_return_mode',
        'ads_interval',
        'announcement',
        'media_mode',
        'youtube_id',
        'facebook_url',
        'auto_scroll_queue',
        'disable_fullscreen_ads',
    ];
}
