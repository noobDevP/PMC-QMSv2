<?php
namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\AdMedia;
use App\Models\Ticket;
use App\Models\Division;
use App\Models\Purpose;

class TvController extends Controller
{
    public function getState($tv_id) {
        if (!\Illuminate\Support\Facades\Schema::hasTable('tv_settings')) {
            \Illuminate\Support\Facades\Schema::create('tv_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->integer('tv_id')->unique();
                $table->string('media_mode', 50)->default('ads');
                $table->string('youtube_id', 255)->nullable();
                $table->string('facebook_url', 255)->nullable();
                $table->boolean('disable_fullscreen_ads')->default(0);
                $table->timestamps();
            });
        }
                if (!\Illuminate\Support\Facades\Schema::hasColumn('system_settings', 'auto_scroll_queue')) {
            \Illuminate\Support\Facades\Schema::table('system_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->boolean('auto_scroll_queue')->default(0);
            });
        }
        $setting = SystemSetting::firstOrCreate([])->toArray();
        $tvSetting = \App\Models\TvSetting::firstOrCreate(['tv_id' => $tv_id])->toArray();
        $merged_settings = array_merge($setting, $tvSetting);
        $ads = AdMedia::all();

        $query = Ticket::with(['purpose', 'division'])
            ->whereIn('status', ['IN_QUEUE', 'SERVING']);
            
        if ((int)$tv_id !== 10) {
            $query->whereHas('division', function($q) use ($tv_id) {
                $q->where('tv_id', $tv_id);
            });
        }
        
        $active_tickets = $query->orderBy('created_at')->get();

        $in_queue = [];
        $serving = [];

        foreach ($active_tickets as $t) {
            $data = [
                'id' => $t->id,
                'ticket_number' => $t->ticket_number,
                'customer_type' => $t->customer_type,
                'customer_name' => $t->customer_name,
                'purpose' => $t->purpose ? $t->purpose->name : '',
                'division_name' => $t->division ? $t->division->name : '',
                'served_by' => $t->served_by
            ];
            if ($t->status === 'IN_QUEUE') {
                $in_queue[] = $data;
            } else {
                $serving[] = array_merge($data, ['served_at' => $t->served_at]);
            }
        }

        usort($serving, function($a, $b) {
            return strtotime($b['served_at']) - strtotime($a['served_at']);
        });

        $serving = array_map(function($s) { unset($s['served_at']); return $s; }, $serving);

        return response()->json([
            'settings' => $merged_settings,
            'ads' => $ads,
            'queue' => [
                'in_queue' => $in_queue,
                'serving' => array_values($serving)
            ]
        ]);
    }
}
