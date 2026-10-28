<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SystemSetting;
use App\Models\TvSetting;
use App\Models\Division;
use App\Models\Purpose;
use App\Models\AdMedia;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    public function getSettings() {
        $setting = SystemSetting::firstOrCreate([]);
        return response()->json($setting);
    }

    public function getTvSettings($tv_id) {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('tv_settings')) {
                \Illuminate\Support\Facades\Schema::create('tv_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->id();
                    $table->integer('tv_id')->unique();
                    $table->string('media_mode', 50)->default('ads');
                    $table->string('youtube_id', 255)->nullable();
                    $table->string('facebook_url', 255)->nullable();
                    $table->boolean('disable_fullscreen_ads')->default(0);
                    $table->boolean('show_google_calendar')->default(0);
                    $table->string('google_calendar_id', 255)->nullable();
                    $table->timestamps();
                });
            }
            if (!\Illuminate\Support\Facades\Schema::hasColumn('tv_settings', 'show_google_calendar')) {
                \Illuminate\Support\Facades\Schema::table('tv_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->boolean('show_google_calendar')->default(0);
                    $table->string('google_calendar_id', 255)->nullable();
                });
            }
            $tvSetting = \App\Models\TvSetting::firstOrCreate(['tv_id' => $tv_id]);
            $tvSetting = $tvSetting->toArray();
            unset($tvSetting['disable_fullscreen_ads']);
            return response()->json($tvSetting);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }

    public function updateSettings(Request $request) {
        $setting = SystemSetting::firstOrCreate([]);
        $setting->update($request->only([
            'tv_idle_seconds', 'shrink_timeout', 'collapse_timeout', 
            'periodic_return_timer', 'periodic_return_mode', 'ads_interval', 
            'announcement', 'auto_scroll_queue', 'disable_fullscreen_ads'
        ]));
        // Note: we still broadcast SettingsUpdated for global settings without tv_id
        event(new \App\Events\SettingsUpdated($setting->toArray()));
        return response()->json(['success' => true]);
    }

    public function updateTvSettings(Request $request, $tv_id) {
        $tvSetting = \App\Models\TvSetting::firstOrCreate(['tv_id' => $tv_id]);
        $tvSetting->update($request->only([
            'media_mode', 'youtube_id', 'facebook_url', 'show_google_calendar', 'google_calendar_id'
        ]));
        
        // Broadcast specifically to this TV
        $payload = $tvSetting->toArray();
        $payload['is_tv_specific'] = true;
        event(new \App\Events\SettingsUpdated($payload));
        
        return response()->json(['success' => true]);
    }

    public function getDivisions() {
        return response()->json(Division::all());
    }

    public function createDivision(Request $request) {
        $div = Division::create($request->only(['name', 'prefix', 'tv_id']));
        return response()->json(['success' => true, 'id' => $div->id]);
    }

    public function updateDivision(Request $request, $id) {
        Division::findOrFail($id)->update($request->only(['name', 'prefix', 'tv_id', 'teller_id']));
        return response()->json(['success' => true]);
    }

    public function deleteDivision($id) {
        $div = Division::findOrFail($id);
        Ticket::where('division_id', $id)->delete();
        Purpose::where('division_id', $id)->delete();
        User::where('division_id', $id)->delete();
        $div->delete();
        return response()->json(['success' => true]);
    }

    public function createPurpose(Request $request) {
        $p = Purpose::create($request->only(['name', 'division_id']));
        return response()->json(['success' => true, 'id' => $p->id]);
    }

    public function deletePurpose($id) {
        try {
            // Delete any tickets that reference this purpose since we cannot nullify purpose_id
            \App\Models\Ticket::where('purpose_id', $id)->delete();
            \App\Models\Purpose::findOrFail($id)->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Cannot delete purpose: ' . $e->getMessage()], 400);
        }
    }

    public function getAds() {
        return response()->json(AdMedia::all());
    }

    public function uploadAd(Request $request) {
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads'), $filename);
            
            $file_type = in_array($file->getClientOriginalExtension(), ['mp4', 'webm', 'ogg']) ? 'video' : 'image';
            $ad = AdMedia::create([
                'filename' => $filename,
                'file_type' => $file_type,
                'duration' => $request->input('duration', 10)
            ]);
            event(new \App\Events\AdsUpdated([]));
            return response()->json(['success' => true, 'id' => $ad->id]);
        }
        return response()->json(['error' => 'No file'], 400);
    }

    public function deleteAd($id) {
        $ad = AdMedia::findOrFail($id);
        @unlink(public_path('uploads/' . $ad->filename));
        $ad->delete();
        event(new \App\Events\AdsUpdated([]));
        return response()->json(['success' => true]);
    }

    public function exportTickets() {
        $tickets = Ticket::with('division')->get();
        $divisions = Division::all()->keyBy('id');

        $csv = "ID,Ticket Number,Division ID,Division Name,Customer Type,Status,Created At,Served At,Completed At,Queue Time (mins),Total Serving Time (mins)\n";

        $divTotals = [];
        foreach ($divisions as $div) {
            $divTotals[$div->id] = [
                'id' => $div->id,
                'name' => $div->name,
                'count' => 0,
                'queue_count' => 0,
                'serving_count' => 0,
                'queue_time' => 0,
                'serving_time' => 0
            ];
        }

        foreach ($tickets as $t) {
            $divName = $t->division ? $t->division->name : ($divisions[$t->division_id]->name ?? '');
            $escapedDivName = '"' . str_replace('"', '""', $divName) . '"';

            $qtVal = $t->served_at ? round(abs(strtotime($t->served_at) - strtotime($t->created_at)) / 60, 2) : null;
            $stVal = ($t->served_at && $t->completed_at) ? round(abs(strtotime($t->completed_at) - strtotime($t->served_at)) / 60, 2) : null;

            $qt = $qtVal !== null ? $qtVal : '';
            $st = $stVal !== null ? $stVal : '';

            $csv .= "{$t->id},{$t->ticket_number},{$t->division_id},{$escapedDivName},{$t->customer_type},{$t->status},{$t->created_at},{$t->served_at},{$t->completed_at},{$qt},{$st}\n";

            if (!isset($divTotals[$t->division_id])) {
                $divTotals[$t->division_id] = [
                    'id' => $t->division_id,
                    'name' => $divName,
                    'count' => 0,
                    'queue_count' => 0,
                    'serving_count' => 0,
                    'queue_time' => 0,
                    'serving_time' => 0
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

        $csv .= "\nSUMMARY BY DIVISION,,Division ID,Division Name,Total Tickets,,,Avg Queue Time (mins),Avg Serving Time (mins),Total Queue Time (mins),Total Serving Time (mins)\n";
        foreach ($divTotals as $dt) {
            $escapedName = '"' . str_replace('"', '""', $dt['name']) . '"';
            $qTotal = round($dt['queue_time'], 2);
            $sTotal = round($dt['serving_time'], 2);
            $qAvg = $dt['queue_count'] > 0 ? round($dt['queue_time'] / $dt['queue_count'], 2) : 0;
            $sAvg = $dt['serving_count'] > 0 ? round($dt['serving_time'] / $dt['serving_count'], 2) : 0;
            $csv .= "DIVISION SUMMARY,,{$dt['id']},{$escapedName},{$dt['count']},,,{$qAvg},{$sAvg},{$qTotal},{$sTotal}\n";
        }

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="all_tickets.csv"');
    }

    public function deleteResolved() {
        Ticket::whereIn('status', ['COMPLETED', 'CANCELLED'])->delete();
        return response()->json(['success' => true]);
    }
}
