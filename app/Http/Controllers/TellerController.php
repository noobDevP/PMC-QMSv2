<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ticket;
use App\Models\Purpose;
use App\Models\Division;
use App\Models\Officer;
use Carbon\Carbon;

class TellerController extends Controller
{
    public function getQueue($division_id) {
        // Collect all division IDs assigned to this teller from the divisions table
        $assignedDivisions = Division::where('teller_id', auth()->id())->pluck('id')->toArray();
        
        // Also include their primary division_id from the users table (if they have one)
        $primaryDivision = auth()->user()->division_id;
        if ($primaryDivision && !in_array($primaryDivision, $assignedDivisions)) {
            $assignedDivisions[] = $primaryDivision;
        }
        
        // Also include any JSON array divisions if the frontend sends multiple
        $jsonDivisions = json_decode(auth()->user()->division_id, true);
        if (is_array($jsonDivisions)) {
            $assignedDivisions = array_unique(array_merge($assignedDivisions, $jsonDivisions));
        }

        $tickets = Ticket::whereIn('division_id', empty($assignedDivisions) ? [$division_id] : $assignedDivisions)
            ->whereIn('status', ['IN_QUEUE', 'SERVING'])
            ->orderBy('created_at')
            ->get()->map(function($t) {
                return [
                    'id' => $t->id,
                    'ticket_number' => $t->ticket_number,
                    'customer_type' => $t->customer_type,
                    'customer_name' => $t->customer_name,
                    'purpose' => $t->purpose ? $t->purpose->name : '',
                    'additional_info' => $t->additional_info,
                    'status' => $t->status,
                    'created_at' => $t->created_at->toIso8601String()
                ];
            });
        return response()->json($tickets);
    }

    public function getPurposes(Request $request) {
        $assignedDivisions = \App\Models\Division::where('teller_id', auth()->id())->pluck('id')->toArray();
        $primaryDivision = auth()->user()->division_id;
        if ($primaryDivision && !in_array($primaryDivision, $assignedDivisions)) {
            $assignedDivisions[] = $primaryDivision;
        }
        $jsonDivisions = json_decode(auth()->user()->division_id, true);
        if (is_array($jsonDivisions)) {
            $assignedDivisions = array_unique(array_merge($assignedDivisions, $jsonDivisions));
        }
        
        // Fetch purposes and join division name so UI can distinguish them
        $purposes = Purpose::whereIn('division_id', empty($assignedDivisions) ? [$request->query('division_id')] : $assignedDivisions)
            ->join('divisions', 'purposes.division_id', '=', 'divisions.id')
            ->select('purposes.id', 'purposes.name', 'divisions.name as division_name', 'purposes.division_id')
            ->get();
            
        return response()->json($purposes);
    }

    public function createPurpose(Request $request) {
        $assignedDivisions = \App\Models\Division::where('teller_id', auth()->id())->pluck('id')->toArray();
        $primaryDivision = auth()->user()->division_id;
        if ($primaryDivision && !in_array($primaryDivision, $assignedDivisions)) {
            $assignedDivisions[] = $primaryDivision;
        }
        $jsonDivisions = json_decode(auth()->user()->division_id, true);
        if (is_array($jsonDivisions)) {
            $assignedDivisions = array_unique(array_merge($assignedDivisions, $jsonDivisions));
        }
        
        $divs = empty($assignedDivisions) ? [$request->input('division_id')] : $assignedDivisions;
        $lastId = null;
        foreach ($divs as $d) {
            $p = Purpose::create(['name' => $request->input('name'), 'division_id' => $d]);
            $lastId = $p->id;
        }
        
        return response()->json(['success' => true, 'id' => $lastId]);
    }

    public function deletePurpose($id) {
        try {
            \App\Models\Ticket::where('purpose_id', $id)->delete();
            \App\Models\Purpose::findOrFail($id)->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Cannot delete purpose: ' . $e->getMessage()], 400);
        }
    }

    public function acceptTicket(Request $request, $id) {
        $ticket = Ticket::findOrFail($id);
        if ($ticket->status !== 'IN_QUEUE') return response()->json(['error' => 'Invalid ticket'], 400);

        $ticket->update([
            'status' => 'SERVING',
            'served_at' => now(),
            'teller_id' => $request->input('teller_id', auth()->id()),
            'served_by' => $request->input('served_by')
        ]);
        
        // TODO: Generate TTS URL or let frontend handle it
        event(new \App\Events\TicketServing([
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'division_name' => $ticket->division->name ?? '',
            'customer_type' => $ticket->customer_type,
            'customer_name' => $ticket->customer_name,
            'purpose' => $ticket->purpose->name ?? '',
            'additional_info' => $ticket->additional_info,
            'tv_id' => $ticket->division->tv_id ?? 1,
            'served_by' => $ticket->served_by,
            'audio_url' => ''
        ]));
        return response()->json(['success' => true]);
    }

    public function completeTicket($id) {
        $ticket = Ticket::findOrFail($id);
        if ($ticket->status !== 'SERVING') return response()->json(['error' => 'Invalid ticket'], 400);

        // Enforce 60-second minimum serving time
        if ($ticket->served_at) {
            $servedAt = \Carbon\Carbon::parse($ticket->served_at);
            $diff = abs(now()->getTimestamp() - $servedAt->getTimestamp());
            if ($diff < 60) {
                $remaining = 60 - $diff;
                return response()->json(['error' => 'Please serve the client for at least 1 minute before completing. (' . $remaining . 's remaining)'], 400);
            }
        }

        $ticket->update(['status' => 'COMPLETED', 'completed_at' => now()]);
        event(new \App\Events\TicketCompleted(['id' => $ticket->id, 'tv_id' => $ticket->division->tv_id ?? 1]));
        return response()->json(['success' => true]);
    }

    public function rerouteTicket(Request $request, $id) {
        $ticket = Ticket::findOrFail($id);
        
        $new_info = $ticket->additional_info;
        if (!str_contains((string)$new_info, '(Rerouted)')) {
            $new_info = $new_info ? $new_info . ' (Rerouted)' : '(Rerouted)';
        }

        $ticket->update([
            'division_id' => $request->target_division_id,
            'purpose_id' => $request->target_purpose_id,
            'status' => 'IN_QUEUE',
            'served_at' => null,
            'teller_id' => null,
            'served_by' => null,
            'additional_info' => $new_info
        ]);
        event(new \App\Events\TicketCancelled(['id' => $ticket->id, 'tv_id' => $ticket->division->tv_id ?? 1])); event(new \App\Events\TicketCreated(['tv_id' => \App\Models\Division::find($request->target_division_id)->tv_id ?? 1]));
        return response()->json(['success' => true]);
    }

    public function exportTickets($divisionId) {
        $assignedDivisions = Division::where('teller_id', auth()->id())->pluck('id')->toArray();
        $primaryDivision = auth()->user()->division_id;
        if ($primaryDivision && !in_array($primaryDivision, $assignedDivisions)) {
            $assignedDivisions[] = $primaryDivision;
        }
        $jsonDivisions = json_decode(auth()->user()->division_id, true);
        if (is_array($jsonDivisions)) {
            $assignedDivisions = array_unique(array_merge($assignedDivisions, $jsonDivisions));
        }
        if ($divisionId && is_numeric($divisionId) && !in_array($divisionId, $assignedDivisions)) {
            $assignedDivisions[] = (int)$divisionId;
        }

        $targetDivisions = empty($assignedDivisions) ? [$divisionId] : $assignedDivisions;
        $divisions = Division::whereIn('id', $targetDivisions)->get()->keyBy('id');
        $tickets = \App\Models\Ticket::with('division')->whereIn('division_id', $targetDivisions)->get();

        $csv = "ID,Ticket Number,Division ID,Division Name,Customer Type,Status,Created At,Served At,Completed At,Queue Time (mins),Total Serving Time (mins)\n";

        $divTotals = [];
        foreach ($divisions as $div) {
            $divTotals[$div->id] = [
                'id' => $div->id,
                'name' => $div->name,
                'count' => 0,
                'queue_time' => 0,
                'serving_time' => 0
            ];
        }

        $grandCount = 0;
        $grandQueueTime = 0;
        $grandServingTime = 0;

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
                    'queue_time' => 0,
                    'serving_time' => 0
                ];
            }

            $divTotals[$t->division_id]['count']++;
            $divTotals[$t->division_id]['queue_time'] += ($qtVal ?? 0);
            $divTotals[$t->division_id]['serving_time'] += ($stVal ?? 0);

            $grandCount++;
            $grandQueueTime += ($qtVal ?? 0);
            $grandServingTime += ($stVal ?? 0);
        }

        $csv .= "\nSUMMARY BY DIVISION,,Division ID,Division Name,Total Tickets,,,,,Total Queue Time (mins),Total Serving Time (mins)\n";
        foreach ($divTotals as $dt) {
            $escapedName = '"' . str_replace('"', '""', $dt['name']) . '"';
            $qTotal = round($dt['queue_time'], 2);
            $sTotal = round($dt['serving_time'], 2);
            $csv .= "DIVISION TOTAL,,{$dt['id']},{$escapedName},{$dt['count']},,,,,{$qTotal},{$sTotal}\n";
        }

        if (count($divTotals) > 1) {
            $grandQueueRounded = round($grandQueueTime, 2);
            $grandServingRounded = round($grandServingTime, 2);
            $csv .= "GRAND TOTAL,,,\"All Assigned Divisions\",{$grandCount},,,,,{$grandQueueRounded},{$grandServingRounded}\n";
        }

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="teller_tickets.csv"');
    }

    public function getOfficers() {
        return response()->json(Officer::where('user_id', auth()->id())->get());
    }

    public function createOfficer(Request $request) {
        $request->validate([
            'name' => 'required|string|max:100'
        ]);

        $officer = Officer::create([
            'name' => $request->name,
            'user_id' => auth()->id()
        ]);

        return response()->json(['success' => true, 'id' => $officer->id]);
    }

    public function deleteOfficer($id) {
        $officer = Officer::where('user_id', auth()->id())->findOrFail($id);
        $officer->delete();
        return response()->json(['success' => true]);
    }
}
