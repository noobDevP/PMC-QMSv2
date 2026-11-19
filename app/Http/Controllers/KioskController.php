<?php
namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Purpose;
use App\Models\Ticket;
use Illuminate\Http\Request;

class KioskController extends Controller
{
    private function makeCancelToken(Ticket $ticket): string
    {
        return hash_hmac('sha256', "kiosk-cancel:{$ticket->id}:{$ticket->ticket_number}", (string) config('app.key'));
    }

    public function getDivisions()
    {
        return response()->json(Division::all(['id', 'name']));
    }

    public function getPurposes($division_id)
    {
        $divisionId = filter_var($division_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($divisionId === false) {
            return response()->json(['error' => 'Invalid division ID'], 400);
        }

        return response()->json(Purpose::where('division_id', $divisionId)->get(['id', 'name']));
    }

    public function createTicket(Request $request)
    {
        $validated = $request->validate([
            'division_id' => 'required|integer|exists:divisions,id',
            'purpose_id' => 'required|integer|exists:purposes,id',
            'customer_type' => 'required|string|in:Active,Retired,Civillian,Civilian',
            'customer_name' => 'nullable|string|max:100',
            'additional_info' => 'nullable|string|max:255',
        ]);

        $division = Division::findOrFail($validated['division_id']);
        $purpose = Purpose::findOrFail($validated['purpose_id']);
        if ((int) $purpose->division_id !== (int) $division->id) {
            return response()->json(['error' => 'Invalid purpose for selected division.'], 422);
        }

        $count = Ticket::where('division_id', $division->id)->whereDate('created_at', today())->count();
        $suffix = $validated['customer_type'] === 'Active'
            ? 'A'
            : (in_array($validated['customer_type'], ['Civillian', 'Civilian'], true) ? 'C' : 'R');
        $number = sprintf('%s-%s%03d', $division->prefix, $suffix, $count + 1);

        $ticket = Ticket::create([
            'ticket_number' => $number,
            'customer_type' => $validated['customer_type'],
            'customer_name' => $validated['customer_name'] ?? null,
            'additional_info' => $validated['additional_info'] ?? null,
            'division_id' => $division->id,
            'purpose_id' => $purpose->id,
            'status' => 'IN_QUEUE',
        ]);

        event(new \App\Events\TicketCreated([
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'division_id' => $ticket->division_id,
            'division_name' => $division->name,
            'customer_type' => $ticket->customer_type,
            'customer_name' => $ticket->customer_name,
            'purpose' => $purpose->name,
            'tv_id' => $division->tv_id,
            'audio_url' => '',
        ]));

        return response()->json([
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'status' => $ticket->status,
            'cancel_token' => $this->makeCancelToken($ticket),
        ], 201);
    }

    public function cancelTicket(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);

        if ($ticket->status !== 'IN_QUEUE') {
            return response()->json(['error' => 'Ticket can no longer be cancelled.'], 400);
        }

        if (!$ticket->created_at || $ticket->created_at->lt(now()->subMinutes(2))) {
            return response()->json(['error' => 'Cancellation window expired.'], 403);
        }

        $providedToken = (string) $request->input('cancel_token', '');
        if (!hash_equals($this->makeCancelToken($ticket), $providedToken)) {
            return response()->json(['error' => 'Invalid cancel token.'], 403);
        }

        $ticket->update(['status' => 'CANCELLED']);
        event(new \App\Events\TicketCancelled(['id' => $ticket->id, 'tv_id' => $ticket->division->tv_id ?? 1]));
        return response()->json(['success' => true]);
    }
}
