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
        unset($tvSetting['disable_fullscreen_ads']);
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
    public function calendarFeed($tv_id) {
        $tvSetting = \App\Models\TvSetting::where('tv_id', $tv_id)->first();
        if (!$tvSetting || !$tvSetting->google_calendar_id) {
            return response('No calendar ID set', 404);
        }
        
        $url = $tvSetting->google_calendar_id;
        try {
            $ics_content = \Illuminate\Support\Facades\Http::withoutVerifying()->get($url)->body();
            
            $events = [];
            if (preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics_content, $matches)) {
                foreach ($matches[1] as $event_str) {
                    $summary = '';
                    $dtstart = '';
                    if (preg_match('/SUMMARY:(.*?)\r?\n/', $event_str, $m)) $summary = trim($m[1]);
                    if (preg_match('/DTSTART(?:;.*?)?:(.*?)\r?\n/', $event_str, $m)) $dtstart = trim($m[1]);
                    
                    if ($summary && $dtstart) {
                        $timestamp = strtotime($dtstart);
                        if ($timestamp) {
                            $events[] = [
                                'summary' => $summary,
                                'timestamp' => $timestamp,
                                'date_formatted' => date('F j, Y - g:i A', $timestamp)
                            ];
                        }
                    }
                }
            }
            
            usort($events, function($a, $b) { return $a['timestamp'] - $b['timestamp']; });
            
            $now = strtotime('today');
            $events = array_filter($events, function($e) use ($now) {
                return $e['timestamp'] >= $now;
            });
            
            $events = array_slice($events, 0, 30);

            $duration = max(15, count($events) * 3);

            $html = '<!DOCTYPE html><html><head><script src="https://cdn.tailwindcss.com"></script>';
            $html .= '<style>
                body { margin: 0; background: #fff; font-family: sans-serif; overflow: hidden; height: 100vh; }
                .marquee { animation: scroll ' . $duration . 's linear infinite; }
                @keyframes scroll {
                    0% { transform: translateY(100vh); }
                    100% { transform: translateY(-150%); }
                }
            </style>';
            $html .= '</head><body>';
            $html .= '<div class="w-full h-full p-6">';
            $html .= '<h1 class="text-2xl font-bold mb-6 text-gray-800 text-center uppercase tracking-widest border-b-2 pb-2">Upcoming Events</h1>';
            $html .= '<div class="marquee">';
            foreach ($events as $e) {
                $html .= '<div class="mb-5 p-4 bg-gray-50 border-l-[6px] border-green-600 rounded shadow-sm">';
                $html .= '<div class="text-sm text-green-700 font-bold mb-1">' . $e['date_formatted'] . '</div>';
                $html .= '<div class="text-xl text-gray-900">' . htmlspecialchars($e['summary']) . '</div>';
                $html .= '</div>';
            }
            if (empty($events)) {
                $html .= '<div class="text-center text-gray-500 mt-10">No upcoming events found.</div>';
            }
            $html .= '</div></div></body></html>';
            
            return response($html)->header('Content-Type', 'text/html');
        } catch (\Exception $e) {
            return response('Error fetching calendar: ' . $e->getMessage(), 500);
        }
    }
}

