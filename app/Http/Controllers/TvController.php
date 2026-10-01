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
            $currentMonth = date('Y-m');
            $nextMonth = date('Y-m', strtotime('first day of next month'));
            $events = array_filter($events, function($e) use ($now, $currentMonth, $nextMonth) {
                if ($e['timestamp'] < $now) return false;
                $eventMonth = date('Y-m', $e['timestamp']);
                if ($eventMonth !== $currentMonth && $eventMonth !== $nextMonth) return false;
                if (stripos($e['summary'], 'birthday') !== false || stripos($e['summary'], 'bday') !== false) return false;
                return true;
            });
            
            $events = array_slice($events, 0, 30);

            $html = '<!DOCTYPE html><html><head>';
            $html .= '<script src="https://cdn.tailwindcss.com"></script>';
            $html .= '<style>
                body { margin: 0; background: #f4f6f8; font-family: sans-serif; overflow: hidden; height: 100vh; display: flex; flex-direction: column; justify-content: center; }
                .header-title { text-align: center; font-weight: 700; font-size: 1.5vw; color: #003366; padding-top: 0; margin-bottom: 0.5vw; flex-shrink: 0; background: transparent; }
                .carousel-container { width: 100%; overflow: hidden; display: flex; align-items: center; padding-bottom: 0; margin-top: 0; }
                .carousel-wrapper { display: flex; flex-wrap: nowrap; width: 100%; align-items: center; }
                .carousel-slide { flex: 0 0 50%; max-width: 50%; box-sizing: border-box; padding: 0 1vw; }
                .event-card { width: 100%; background: #ffffff; border-left: 0.4vw solid #16a34a; border-radius: 0.4vw; padding: 1vw 1.5vw; box-shadow: 0 0.2vw 0.5vw rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: center; box-sizing: border-box; }
                .card-date { font-size: 0.8vw; color: #0f766e; font-weight: 700; margin-bottom: 0.3vw; }
                .card-title { font-size: 1.1vw; color: #111827; font-weight: 500; line-height: 1.2; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
            </style>';
            $html .= '</head><body>';
            $html .= '<div class="header-title">PMC Calendar of Activities</div>';
            
            if (empty($events)) {
                $html .= '<div class="text-center text-gray-500 mt-10 w-full">No upcoming events found.</div>';
            } else if (count($events) < 2) {
                // Not enough events to carousel
                $html .= '<div class="carousel-container"><div class="carousel-wrapper" style="justify-content: center;">';
                foreach ($events as $e) {
                    $html .= '<div class="carousel-slide">';
                    $html .= '<div class="event-card">';
                    $html .= '<div class="card-date">'. $e['date_formatted'] .'</div>';
                    $html .= '<div class="card-title">'. htmlspecialchars($e['summary']) .'</div>';
                    $html .= '</div></div>';
                }
                $html .= '</div></div>';
            } else {
                $html .= '<div class="carousel-container"><div class="carousel-wrapper" id="wrapper">';
                foreach ($events as $e) {
                    $html .= '<div class="carousel-slide">';
                    $html .= '<div class="event-card">';
                    $html .= '<div class="card-date">'. $e['date_formatted'] .'</div>';
                    $html .= '<div class="card-title">'. htmlspecialchars($e['summary']) .'</div>';
                    $html .= '</div></div>';
                }
                $html .= '</div></div>';
                
                $html .= '<script>
                    var wrapper = document.getElementById("wrapper");
                    if (wrapper && wrapper.children.length > 2) {
                        setInterval(function() {
                            var firstSlide = wrapper.firstElementChild;
                            firstSlide.style.transition = "margin-left 1.8s ease-in-out";
                            firstSlide.style.marginLeft = "-50%";
                            
                            setTimeout(function() {
                                firstSlide.style.transition = "none";
                                firstSlide.style.marginLeft = "0";
                                wrapper.appendChild(firstSlide);
                            }, 1800);
                        }, 5300); // 3.5s pause + 1.8s transition
                    }
                </script>';
            }
            
            $html .= '</body></html>';
            
            return response($html)->header('Content-Type', 'text/html');
        } catch (\Exception $e) {
            return response('Error fetching calendar: ' . $e->getMessage(), 500);
        }
    }
}
