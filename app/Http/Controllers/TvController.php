<?php
namespace App\Http\Controllers;

use App\Models\AdMedia;
use App\Models\SystemSetting;
use App\Models\Ticket;
use App\Models\TvSetting;
use App\Support\UrlValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TvController extends Controller
{
    public function getState($tv_id)
    {
        $tvId = filter_var($tv_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
        if ($tvId === false) {
            return response()->json(['error' => 'Invalid TV ID'], 400);
        }

        $settingRow = SystemSetting::first();
        $setting = $settingRow ? $settingRow->toArray() : [
            'tv_idle_seconds' => 30,
            'shrink_timeout' => 15,
            'collapse_timeout' => 30,
            'periodic_return_timer' => 0,
            'periodic_return_mode' => 'full_queue',
            'ads_interval' => 10,
            'announcement' => null,
            'auto_scroll_queue' => 0,
            'disable_fullscreen_ads' => 0,
        ];

        $tvRow = TvSetting::where('tv_id', $tvId)->first();
        $tvSetting = $tvRow ? $tvRow->toArray() : [
            'tv_id' => $tvId,
            'media_mode' => 'ads',
            'youtube_id' => null,
            'facebook_url' => null,
            'show_google_calendar' => 0,
            'google_calendar_id' => null,
        ];
        unset($tvSetting['disable_fullscreen_ads']);

        $merged_settings = array_merge($setting, $tvSetting);
        $ads = AdMedia::all();

        $query = Ticket::with(['purpose', 'division'])
            ->whereIn('status', ['IN_QUEUE', 'SERVING']);

        if ($tvId !== 10) {
            $query->whereHas('division', function ($q) use ($tvId) {
                $q->where('tv_id', $tvId);
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
                'served_by' => $t->served_by,
            ];
            if ($t->status === 'IN_QUEUE') {
                $in_queue[] = $data;
            } else {
                $serving[] = array_merge($data, ['served_at' => $t->served_at]);
            }
        }

        usort($serving, function ($a, $b) {
            return strtotime($b['served_at']) - strtotime($a['served_at']);
        });

        $serving = array_map(function ($s) {
            unset($s['served_at']);
            return $s;
        }, $serving);

        return response()->json([
            'settings' => $merged_settings,
            'ads' => $ads,
            'queue' => [
                'in_queue' => $in_queue,
                'serving' => array_values($serving),
            ],
        ]);
    }

    public function calendarFeed($tv_id)
    {
        $tvId = filter_var($tv_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
        if ($tvId === false) {
            return response('Invalid TV ID', 400);
        }

        $tvSetting = TvSetting::where('tv_id', $tvId)->first();
        if (!$tvSetting || !$tvSetting->google_calendar_id) {
            return response('No calendar ID set', 404);
        }

        $url = trim((string) $tvSetting->google_calendar_id);
        if (!UrlValidator::isValidGoogleCalendarIcsUrl($url)) {
            return response('Invalid calendar feed configuration.', 400);
        }

        try {
            $currentUrl = $url;
            $response = null;
            for ($hop = 0; $hop < 3; $hop++) {
                $response = Http::timeout(8)->withoutRedirecting()->get($currentUrl);
                if ($response->status() >= 300 && $response->status() < 400 && $response->header('Location')) {
                    $nextUrl = $response->header('Location');
                    if (!UrlValidator::isValidGoogleCalendarIcsUrl($nextUrl)) {
                        return response('Invalid calendar redirect.', 400);
                    }
                    $currentUrl = $nextUrl;
                    continue;
                }
                break;
            }

            if (!$response || !$response->successful()) {
                return response('Unable to load calendar feed.', 502);
            }

            $ics_content = $response->body();

            $events = [];
            if (preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics_content, $matches)) {
                foreach ($matches[1] as $event_str) {
                    $summary = '';
                    $dtstart = '';
                    if (preg_match('/SUMMARY:(.*?)\r?\n/', $event_str, $m)) {
                        $summary = trim($m[1]);
                    }
                    if (preg_match('/DTSTART(?:;.*?)?:(.*?)\r?\n/', $event_str, $m)) {
                        $dtstart = trim($m[1]);
                    }

                    if ($summary && $dtstart) {
                        $dtstart = trim($dtstart);
                        $is_all_day = (strlen($dtstart) == 8);
                        $timestamp = strtotime($dtstart);

                        if ($timestamp) {
                            if (!$is_all_day && substr($dtstart, -1) === 'Z') {
                                $dt = new \DateTime('@' . $timestamp);
                                $dt->setTimezone(new \DateTimeZone('Asia/Manila'));
                                $formatted = $dt->format('M j, Y - g:i A');
                            } elseif (!$is_all_day) {
                                $formatted = date('M j, Y - g:i A', $timestamp);
                            } else {
                                $formatted = date('M j, Y', $timestamp) . ' - All Day';
                            }

                            $events[] = [
                                'summary' => $summary,
                                'timestamp' => $timestamp,
                                'date_formatted' => $formatted,
                            ];
                        }
                    }
                }
            }

            usort($events, function ($a, $b) {
                return $a['timestamp'] - $b['timestamp'];
            });

            $now = strtotime('today');
            $currentMonth = date('Y-m');
            $events = array_filter($events, function ($e) use ($now, $currentMonth) {
                if ($e['timestamp'] < $now) {
                    return false;
                }
                if (date('Y-m', $e['timestamp']) !== $currentMonth) {
                    return false;
                }
                return true;
            });

            $events = array_slice($events, 0, 30);

            $html = '<!DOCTYPE html><html><head>';
            $html .= '<link rel="stylesheet" href="/assets/calendar-carousel.css?v=1" />';
            $html .= '</head><body>';
            $html .= '<div class="header-title">PMC Calendar of Activities</div>';

            if (empty($events)) {
                $html .= '<div class="calendar-empty">No upcoming events found.</div>';
            } elseif (count($events) < 2) {
                $html .= '<div class="carousel-container"><div class="carousel-wrapper carousel-wrapper-centered">';
                foreach ($events as $e) {
                    $html .= '<div class="carousel-slide">';
                    $html .= '<div class="event-card">';
                    $html .= '<div class="card-date">' . htmlspecialchars($e['date_formatted'], ENT_QUOTES, 'UTF-8') . '</div>';
                    $html .= '<div class="card-title">' . htmlspecialchars($e['summary'], ENT_QUOTES, 'UTF-8') . '</div>';
                    $html .= '</div></div>';
                }
                $html .= '</div></div>';
            } else {
                $html .= '<div class="carousel-container"><div class="carousel-wrapper" id="wrapper">';
                foreach ($events as $e) {
                    $html .= '<div class="carousel-slide">';
                    $html .= '<div class="event-card">';
                    $html .= '<div class="card-date">' . htmlspecialchars($e['date_formatted'], ENT_QUOTES, 'UTF-8') . '</div>';
                    $html .= '<div class="card-title">' . htmlspecialchars($e['summary'], ENT_QUOTES, 'UTF-8') . '</div>';
                    $html .= '</div></div>';
                }
                $html .= '</div></div>';

                $html .= '<script src="/assets/calendar-carousel.js?v=1"></script>';
            }

            $html .= '</body></html>';

            return response($html)->header('Content-Type', 'text/html');
        } catch (\Throwable $e) {
            Log::error('Error fetching calendar feed', ['tv_id' => $tvId, 'error' => $e->getMessage()]);
            return response('Error fetching calendar.', 500);
        }
    }
}
