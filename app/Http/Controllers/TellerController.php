<?php
namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Officer;
use App\Models\Purpose;
use App\Models\Ticket;
use App\Support\CsvHelper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TellerController extends Controller
{
    private function isAdmin(): bool
    {
        return auth()->user() && auth()->user()->role === 'admin';
    }

    private function getAuthorizedDivisionIds(): array
    {
        $assignedDivisions = Division::where('teller_id', auth()->id())->pluck('id')->map(fn ($id) => (int) $id)->toArray();

        $rawPrimary = auth()->user()->division_id;
        if (is_numeric($rawPrimary) && !in_array((int) $rawPrimary, $assignedDivisions, true)) {
            $assignedDivisions[] = (int) $rawPrimary;
        }

        $jsonDivisions = is_string($rawPrimary) ? json_decode($rawPrimary, true) : null;
        if (is_array($jsonDivisions)) {
            foreach ($jsonDivisions as $id) {
                if (is_numeric($id)) {
                    $assignedDivisions[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($assignedDivisions));
    }

    private function assertCanManageDivision(int $divisionId): void
    {
        if (!$this->isAdmin() && !in_array($divisionId, $this->getAuthorizedDivisionIds(), true)) {
            abort(403, 'Forbidden');
        }
    }

    public function getQueue($division_id)
    {
        $assignedDivisions = $this->getAuthorizedDivisionIds();

        if ($this->isAdmin() && empty($assignedDivisions)) {
            if (!is_numeric($division_id)) {
                return response()->json(['error' => 'Invalid division ID'], 400);
            }
            $targetDivisions = [(int) $division_id];
        } else {
            if (empty($assignedDivisions)) {
                return response()->json([]);
            }
            $targetDivisions = $assignedDivisions;
        }

        $tickets = Ticket::whereIn('division_id', $targetDivisions)
            ->whereIn('status', ['IN_QUEUE', 'SERVING'])
            ->orderBy('created_at')
            ->get()
            ->map(function ($t) {
                return [
                    'id' => $t->id,
                    'ticket_number' => $t->ticket_number,
                    'customer_type' => $t->customer_type,
                    'customer_name' => $t->customer_name,
                    'purpose' => $t->purpose ? $t->purpose->name : '',
                    'additional_info' => $t->additional_info,
                    'status' => $t->status,
                    'created_at' => $t->created_at->toIso8601String(),
                ];
            });

        return response()->json($tickets);
    }

    public function getPurposes(Request $request)
    {
        $assignedDivisions = $this->getAuthorizedDivisionIds();

        if ($this->isAdmin() && empty($assignedDivisions)) {
            $qid = $request->query('division_id');
            if (!is_numeric($qid)) {
                return response()->json([]);
            }
            $targetDivisions = [(int) $qid];
        } else {
            if (empty($assignedDivisions)) {
                return response()->json([]);
            }
            $targetDivisions = $assignedDivisions;
        }

        $purposes = Purpose::whereIn('division_id', $targetDivisions)
            ->join('divisions', 'purposes.division_id', '=', 'divisions.id')
            ->select('purposes.id', 'purposes.name', 'divisions.name as division_name', 'purposes.division_id')
            ->get();

        return response()->json($purposes);
    }

    public function createPurpose(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'division_id' => 'nullable|integer|exists:divisions,id',
        ]);

        $assignedDivisions = $this->getAuthorizedDivisionIds();

        if ($this->isAdmin() && empty($assignedDivisions)) {
            if (empty($validated['division_id'])) {
                return response()->json(['error' => 'Division ID is required.'], 422);
            }
            $divs = [(int) $validated['division_id']];
        } else {
            if (empty($assignedDivisions)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            $divs = $assignedDivisions;
        }

        $lastId = null;
        foreach ($divs as $d) {
            $p = Purpose::create(['name' => $validated['name'], 'division_id' => $d]);
            $lastId = $p->id;
        }

        return response()->json(['success' => true, 'id' => $lastId]);
    }

    public function deletePurpose($id)
    {
        $purpose = Purpose::findOrFail($id);
        $this->assertCanManageDivision((int) $purpose->division_id);

        try {
            Ticket::where('purpose_id', $id)->delete();
            $purpose->delete();
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('Teller failed to delete purpose', ['purpose_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Cannot delete purpose.'], 400);
        }
    }

    public function acceptTicket(Request $request, $id)
    {
        $validated = $request->validate([
            'served_by' => 'nullable|string|max:100',
        ]);

        $ticket = Ticket::findOrFail($id);
        $this->assertCanManageDivision((int) $ticket->division_id);

        if ($ticket->status !== 'IN_QUEUE') {
            return response()->json(['error' => 'Invalid ticket'], 400);
        }

        $ticket->update([
            'status' => 'SERVING',
            'served_at' => now(),
            'teller_id' => auth()->id(),
            'served_by' => $validated['served_by'] ?? null,
        ]);

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
            'audio_url' => '',
        ]));

        return response()->json(['success' => true]);
    }

    public function completeTicket($id)
    {
        $ticket = Ticket::findOrFail($id);
        $this->assertCanManageDivision((int) $ticket->division_id);

        if ($ticket->status !== 'SERVING') {
            return response()->json(['error' => 'Invalid ticket'], 400);
        }

        // Enforce 60-second minimum serving time
        if ($ticket->served_at) {
            $servedAt = Carbon::parse($ticket->served_at);
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

    public function rerouteTicket(Request $request, $id)
    {
        $validated = $request->validate([
            'target_division_id' => 'required|integer|exists:divisions,id',
            'target_purpose_id' => 'required|integer|exists:purposes,id',
        ]);

        $targetPurpose = Purpose::findOrFail($validated['target_purpose_id']);
        if ((int) $targetPurpose->division_id !== (int) $validated['target_division_id']) {
            return response()->json(['error' => 'Selected purpose does not belong to target division.'], 422);
        }

        $ticket = Ticket::findOrFail($id);
        $this->assertCanManageDivision((int) $ticket->division_id);

        $new_info = $ticket->additional_info;
        if (!str_contains((string) $new_info, '(Rerouted)')) {
            $new_info = $new_info ? $new_info . ' (Rerouted)' : '(Rerouted)';
        }

        $ticket->update([
            'division_id' => $validated['target_division_id'],
            'purpose_id' => $validated['target_purpose_id'],
            'status' => 'IN_QUEUE',
            'served_at' => null,
            'teller_id' => null,
            'served_by' => null,
            'additional_info' => $new_info,
        ]);

        event(new \App\Events\TicketCancelled(['id' => $ticket->id, 'tv_id' => $ticket->division->tv_id ?? 1]));
        event(new \App\Events\TicketCreated(['tv_id' => Division::find($validated['target_division_id'])->tv_id ?? 1]));
        return response()->json(['success' => true]);
    }

    public function exportTickets($divisionId)
    {
        $assignedDivisions = $this->getAuthorizedDivisionIds();

        if ($this->isAdmin() && empty($assignedDivisions)) {
            if (!is_numeric($divisionId)) {
                return response()->json(['error' => 'Invalid division ID'], 400);
            }
            $targetDivisions = [(int) $divisionId];
        } else {
            if (empty($assignedDivisions)) {
                return response()->json(['error' => 'No division assigned.'], 403);
            }
            $targetDivisions = $assignedDivisions;
        }

        $divisions = Division::whereIn('id', $targetDivisions)->get()->keyBy('id');
        $tickets = Ticket::with('division')->whereIn('division_id', $targetDivisions)->get();

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
            ->header('Content-Disposition', 'attachment; filename="teller_tickets.csv"');
    }

    public function getOfficers()
    {
        return response()->json(Officer::where('user_id', auth()->id())->get());
    }

    public function createOfficer(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $officer = Officer::create([
            'name' => $request->name,
            'user_id' => auth()->id(),
        ]);

        return response()->json(['success' => true, 'id' => $officer->id]);
    }

    public function deleteOfficer($id)
    {
        $officer = Officer::where('user_id', auth()->id())->findOrFail($id);
        $officer->delete();
        return response()->json(['success' => true]);
    }
}
