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
                    'status' => $t->status,
                    'created_at' => $t->created_at->toIso8601String()
                ];
            });
        return response()->json($tickets);
    }

    public function getPurposes(Request $request) {
        $div_id = $request->query('division_id');
        if (!$div_id) return response()->json([]);
        return response()->json(Purpose::where('division_id', $div_id)->get(['id', 'name']));
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

        $ticket->update(['status' => 'COMPLETED', 'completed_at' => now()]);
        event(new \App\Events\TicketCompleted(['id' => $ticket->id, 'tv_id' => $ticket->division->tv_id ?? 1]));
        return response()->json(['success' => true]);
    }

    public function rerouteTicket(Request $request, $id) {
        $ticket = Ticket::findOrFail($id);
        $ticket->update([
            'division_id' => $request->target_division_id,
            'purpose_id' => $request->target_purpose_id,
            'status' => 'IN_QUEUE',
            'served_at' => null,
            'teller_id' => null
        ]);
        event(new \App\Events\TicketCancelled(['id' => $ticket->id, 'tv_id' => $ticket->division->tv_id ?? 1])); event(new \App\Events\TicketCreated(['tv_id' => \App\Models\Division::find($request->target_division_id)->tv_id ?? 1]));
        return response()->json(['success' => true]);
    }

    public function exportTickets($divisionId) {
        $tickets = \App\Models\Ticket::where('division_id', $divisionId)->get();
        $csv = "ID,Ticket Number,Division ID,Customer Type,Status,Created At,Served At,Completed At,Queue Time (mins),Total Serving Time (mins)\n";
        foreach ($tickets as $t) {
            $qt = $t->served_at ? round(abs(strtotime($t->served_at) - strtotime($t->created_at)) / 60, 2) : '';
            $st = ($t->served_at && $t->completed_at) ? round(abs(strtotime($t->completed_at) - strtotime($t->served_at)) / 60, 2) : '';
            $csv .= "{$t->id},{$t->ticket_number},{$t->division_id},{$t->customer_type},{$t->status},{$t->created_at},{$t->served_at},{$t->completed_at},{$qt},{$st}\n";
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

