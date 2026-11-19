<?php
namespace App\Http\Controllers;

use App\Models\AdMedia;
use App\Models\Division;
use App\Models\Purpose;
use App\Models\SystemSetting;
use App\Models\Ticket;
use App\Models\TvSetting;
use App\Models\User;
use App\Support\CsvHelper;
use App\Support\UrlValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function getSettings()
    {
        $setting = SystemSetting::firstOrCreate([]);
        return response()->json($setting);
    }

    public function getTvSettings($tv_id)
    {
        $tvId = filter_var($tv_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
        if ($tvId === false) {
            return response()->json(['error' => 'Invalid TV ID'], 400);
        }

        try {
            $tvSetting = TvSetting::firstOrCreate(['tv_id' => $tvId])->toArray();
            unset($tvSetting['disable_fullscreen_ads']);
            return response()->json($tvSetting);
        } catch (\Throwable $e) {
            Log::error('Failed to load TV settings', ['tv_id' => $tvId, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Unable to load TV settings.'], 500);
        }
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'tv_idle_seconds' => 'nullable|integer|min:0|max:86400',
            'shrink_timeout' => 'nullable|integer|min:0|max:86400',
            'collapse_timeout' => 'nullable|integer|min:0|max:86400',
            'periodic_return_timer' => 'nullable|integer|min:0|max:86400',
            'periodic_return_mode' => 'nullable|string|in:full_queue,pip',
            'ads_interval' => 'nullable|integer|min:1|max:86400',
            'announcement' => 'nullable|string|max:1000',
            'auto_scroll_queue' => 'nullable|boolean',
            'disable_fullscreen_ads' => 'nullable|boolean',
        ]);

        $setting = SystemSetting::firstOrCreate([]);
        $setting->update($validated);

        event(new \App\Events\SettingsUpdated($setting->toArray()));
        return response()->json(['success' => true]);
    }

    public function updateTvSettings(Request $request, $tv_id)
    {
        $tvId = filter_var($tv_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
        if ($tvId === false) {
            return response()->json(['error' => 'Invalid TV ID'], 400);
        }

        $validated = $request->validate([
            'media_mode' => 'nullable|string|in:ads,youtube,facebook',
            'youtube_id' => 'nullable|string|max:100',
            'facebook_url' => 'nullable|string|max:255',
            'show_google_calendar' => 'nullable|boolean',
            'google_calendar_id' => 'nullable|string|max:255',
        ]);

        if (!UrlValidator::isValidYoutubeId($validated['youtube_id'] ?? null)) {
            return response()->json(['error' => 'Invalid YouTube video or playlist ID.'], 422);
        }
        if (!UrlValidator::isValidFacebookUrl($validated['facebook_url'] ?? null)) {
            return response()->json(['error' => 'Invalid Facebook video URL. Must be an HTTPS facebook.com or fb.watch link.'], 422);
        }
        if (!UrlValidator::isValidGoogleCalendarSetting($validated['google_calendar_id'] ?? null)) {
            return response()->json(['error' => 'Invalid Google Calendar ID or HTTPS feed URL.'], 422);
        }

        $tvSetting = TvSetting::firstOrCreate(['tv_id' => $tvId]);
        $tvSetting->update($validated);

        $payload = $tvSetting->toArray();
        $payload['is_tv_specific'] = true;
        event(new \App\Events\SettingsUpdated($payload));

        return response()->json(['success' => true]);
    }

    public function getDivisions()
    {
        return response()->json(Division::all());
    }

    public function createDivision(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'prefix' => 'required|string|max:10',
            'tv_id' => 'nullable|integer|min:1|max:20',
        ]);

        $div = Division::create($validated);
        return response()->json(['success' => true, 'id' => $div->id]);
    }

    public function updateDivision(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'prefix' => 'sometimes|required|string|max:10',
            'tv_id' => 'sometimes|nullable|integer|min:1|max:20',
            'teller_id' => 'sometimes|nullable|integer|exists:users,id',
        ]);

        Division::findOrFail($id)->update($validated);
        return response()->json(['success' => true]);
    }

    public function deleteDivision($id)
    {
        $div = Division::findOrFail($id);
        Ticket::where('division_id', $id)->delete();
        Purpose::where('division_id', $id)->delete();

        $divUsers = User::where('division_id', $id)->where('role', '!=', 'admin')->get();
        foreach ($divUsers as $u) {
            $u->tokens()->delete();
            $u->delete();
        }

        $div->delete();
        return response()->json(['success' => true]);
    }

    public function createPurpose(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'division_id' => 'required|integer|exists:divisions,id',
        ]);

        $p = Purpose::create($validated);
        return response()->json(['success' => true, 'id' => $p->id]);
    }

    public function deletePurpose($id)
    {
        try {
            Ticket::where('purpose_id', $id)->delete();
            Purpose::findOrFail($id)->delete();
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('Failed to delete purpose', ['purpose_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Cannot delete purpose.'], 400);
        }
    }

    public function getAds()
    {
        return response()->json(AdMedia::all());
    }

    public function uploadAd(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,gif,webp,mp4,webm,ogg',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/ogg',
                'max:204800',
            ],
            'duration' => ['nullable', 'integer', 'min:1', 'max:3600'],
        ]);

        $file = $request->file('file');
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'ogg'];
        $guessed = strtolower((string) $file->guessExtension());
        $clientExt = strtolower((string) $file->getClientOriginalExtension());
        $ext = in_array($guessed, $allowed, true)
            ? $guessed
            : (in_array($clientExt, $allowed, true) ? $clientExt : null);

        if ($ext === null) {
            return response()->json(['error' => 'Unsupported file type.'], 422);
        }

        $filename = time() . '_' . Str::random(16) . '.' . $ext;
        $file->move(public_path('uploads'), $filename);

        $file_type = in_array($ext, ['mp4', 'webm', 'ogg'], true) ? 'video' : 'image';
        $ad = AdMedia::create([
            'filename' => $filename,
            'file_type' => $file_type,
            'duration' => (int) $request->input('duration', 10),
        ]);

        event(new \App\Events\AdsUpdated([]));
        return response()->json(['success' => true, 'id' => $ad->id]);
    }

    public function deleteAd($id)
    {
        $ad = AdMedia::findOrFail($id);

        $safeName = basename((string) $ad->filename);
        $uploadsDir = realpath(public_path('uploads'));
        $targetPath = $uploadsDir ? realpath($uploadsDir . DIRECTORY_SEPARATOR . $safeName) : false;
        if ($uploadsDir && $targetPath && str_starts_with($targetPath, $uploadsDir . DIRECTORY_SEPARATOR) && is_file($targetPath)) {
            @unlink($targetPath);
        }

        $ad->delete();
        event(new \App\Events\AdsUpdated([]));
        return response()->json(['success' => true]);
    }

    public function exportTickets()
    {
        $tickets = Ticket::with('division')->get();
        $divisions = Division::all()->keyBy('id');

        $csv = CsvHelper::row([
            'ID', 'Ticket Number', 'Division ID', 'Division Name',
            'Customer Type', 'Status', 'Created At', 'Served At',
            'Completed At', 'Queue Time (mins)', 'Total Serving Time (mins)',
        ]);

        $divTotals = [];
        foreach ($divisions as $div) {
            $divTotals[$div->id] = [
                'id' => $div->id,
                'name' => $div->name,
                'count' => 0,
                'queue_count' => 0,
                'serving_count' => 0,
                'queue_time' => 0,
                'serving_time' => 0,
            ];
        }

        foreach ($tickets as $t) {
            $divName = $t->division ? $t->division->name : ($divisions[$t->division_id]->name ?? '');

            $qtVal = $t->served_at ? round(abs(strtotime($t->served_at) - strtotime($t->created_at)) / 60, 2) : null;
            $stVal = ($t->served_at && $t->completed_at) ? round(abs(strtotime($t->completed_at) - strtotime($t->served_at)) / 60, 2) : null;

            $qt = $qtVal !== null ? $qtVal : '';
            $st = $stVal !== null ? $stVal : '';

            $csv .= CsvHelper::row([
                $t->id,
                $t->ticket_number,
                $t->division_id,
                $divName,
                $t->customer_type,
                $t->status,
                (string) $t->created_at,
                (string) $t->served_at,
                (string) $t->completed_at,
                $qt,
                $st,
            ]);

            if (!isset($divTotals[$t->division_id])) {
                $divTotals[$t->division_id] = [
                    'id' => $t->division_id,
                    'name' => $divName,
                    'count' => 0,
                    'queue_count' => 0,
                    'serving_count' => 0,
                    'queue_time' => 0,
                    'serving_time' => 0,
                ];
            }

            $divTotals[$t->division_id]['count']++;
            if ($qtVal !== null) {
                $divTotals[$t->division_id]['queue_count']++;
                $divTotals[$t->division_id]['queue_time'] += $qtVal;
            }
            if ($stVal !== null) {
                $divTotals[$t->division_id]['serving_count']++;
                $divTotals[$t->division_id]['serving_time'] += $stVal;
            }
        }

        $csv .= "\n";
        $csv .= CsvHelper::row([
            'SUMMARY BY DIVISION', '', 'Division ID', 'Division Name',
            'Total Tickets', '', '', 'Avg Queue Time (mins)',
            'Avg Serving Time (mins)', 'Total Queue Time (mins)', 'Total Serving Time (mins)',
        ]);
        foreach ($divTotals as $dt) {
            $qTotal = round($dt['queue_time'], 2);
            $sTotal = round($dt['serving_time'], 2);
            $qAvg = $dt['queue_count'] > 0 ? round($dt['queue_time'] / $dt['queue_count'], 2) : 0;
            $sAvg = $dt['serving_count'] > 0 ? round($dt['serving_time'] / $dt['serving_count'], 2) : 0;
            $csv .= CsvHelper::row([
                'DIVISION SUMMARY', '', $dt['id'], $dt['name'],
                $dt['count'], '', '', $qAvg, $sAvg, $qTotal, $sTotal,
            ]);
        }

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="all_tickets.csv"');
    }

    public function deleteResolved()
    {
        Ticket::whereIn('status', ['COMPLETED', 'CANCELLED'])->delete();
        return response()->json(['success' => true]);
    }
}
