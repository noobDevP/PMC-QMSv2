<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Division;
use App\Models\Purpose;
use App\Models\Ticket;
use Carbon\Carbon;

class KioskController extends Controller
{
    public function getDivisions() {
        return response()->json(Division::all(['id', 'name']));
    }

    public function getPurposes($division_id) {
        return response()->json(Purpose::where('division_id', $division_id)->get(['id', 'name']));
    }

    public function createTicket(Request $request) {
        $request->validate([
            'division_id' => 'required',
            'purpose_id' => 'required',
            'customer_type' => 'required'
        ]);

        $division = Division::findOrFail($request->division_id);
        
        $count = Ticket::where('division_id', $division->id)->whereDate('created_at', today())->count();
        $suffix = $request->customer_type === 'Active' ? 'A' : ($request->customer_type === 'Civillian' ? 'C' : 'R');
        $number = sprintf("%s-%s%03d", $division->prefix, $suffix, $count + 1);

        $ticket = Ticket::create([
            'ticket_number' => $number,
            'customer_type' => $request->customer_type,
            'customer_name' => $request->customer_name,
            'additional_info' => $request->additional_info,
            'division_id' => $division->id,
            'purpose_id' => $request->purpose_id,
            'status' => 'IN_QUEUE'
        ]);

        event(new \App\Events\TicketCreated(['id' => $ticket->id, 'ticket_number' => $ticket->ticket_number, 'division_id' => $ticket->division_id, 'division_name' => $division->name, 'customer_type' => $ticket->customer_type, 'customer_name' => $ticket->customer_name, 'purpose' => $ticket->purpose->name ?? '', 'tv_id' => $division->tv_id, 'audio_url' => '']));

        return response()->json([
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'status' => $ticket->status
        ], 201);
    }

    public function cancelTicket($id) {
        $ticket = Ticket::findOrFail($id);
        $ticket->update(['status' => 'CANCELLED']);
        event(new \App\Events\TicketCancelled(['id' => $ticket->id, 'tv_id' => $ticket->division->tv_id ?? 1]));
        return response()->json(['success' => true]);
    }
}
