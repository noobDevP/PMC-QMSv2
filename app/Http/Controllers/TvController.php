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
                                'date_formatted' => date('M j, Y - g:i A', $timestamp)
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

            $html = '<!DOCTYPE html><html><head>';
            $html .= '<script src="https://cdn.tailwindcss.com"></script>';
            $html .= '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />';
            $html .= '<style>
                body { margin: 0; background: #ffffff; font-family: sans-serif; overflow: hidden; height: 100vh; display: flex; align-items: center; justify-content: center; }
                .swiper { width: 100%; height: 100%; padding: 0 1rem; }
                .swiper-slide { display: flex; align-items: center; justify-content: center; }
                .event-card { width: 100%; max-width: 100%; background: #f8fafc; border-left: 5px solid #16a34a; border-radius: 6px; padding: 0.75rem 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
            </style>';
            $html .= '</head><body>';
            
            if (empty($events)) {
                $html .= '<div class="text-center text-gray-500 mt-10">No upcoming events found.</div>';
            } else {
                $html .= '<div class="swiper mySwiper"><div class="swiper-wrapper">';
                foreach ($events as $e) {
                    $html .= '<div class="swiper-slide">';
                    $html .= '<div class="event-card">';
                    $html .= '<div class="text-xs text-green-700 font-bold mb-1">' . $e['date_formatted'] . '</div>';
                    $html .= '<div class="text-base text-gray-900 font-semibold leading-snug line-clamp-2">' . htmlspecialchars($e['summary']) . '</div>';
                    $html .= '</div></div>';
                }
                $html .= '</div></div>';
                
                $html .= '<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>';
                $html .= '<script>
                    var swiper = new Swiper(".mySwiper", {
                        slidesPerView: 2,
                        spaceBetween: 20,
                        loop: true,
                        autoplay: {
                            delay: 3500,
                            disableOnInteraction: false,
                        },
                        speed: 800,
                    });
                </script>';
            }
            
            $html .= '</body></html>';
            
            return response($html)->header('Content-Type', 'text/html');
        } catch (\Exception $e) {
            return response('Error fetching calendar: ' . $e->getMessage(), 500);
        }
    }
}
