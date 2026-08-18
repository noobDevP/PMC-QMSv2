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
        $setting = SystemSetting::firstOrCreate([]);
        $ads = AdMedia::all();

        $active_tickets = Ticket::with(['purpose', 'division'])
            ->whereIn('status', ['IN_QUEUE', 'SERVING'])
            ->whereHas('division', function($q) use ($tv_id) {
                $q->where('tv_id', $tv_id);
            })
            ->orderBy('created_at')
            ->get();

        $in_queue = [];
        $serving = [];

        foreach ($active_tickets as $t) {
            $data = [
                'id' => $t->id,
                'ticket_number' => $t->ticket_number,
                'customer_type' => $t->customer_type,
                'customer_name' => $t->customer_name,
                'purpose' => $t->purpose ? $t->purpose->name : '',
                'division_name' => $t->division ? $t->division->name : ''
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
            'settings' => $setting,
            'ads' => $ads,
            'queue' => [
                'in_queue' => $in_queue,
                'serving' => array_values($serving)
            ]
        ]);
    }
}
