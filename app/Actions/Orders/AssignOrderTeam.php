<?php

namespace App\Actions\Orders;

use App\Models\OrderAssignment;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Sla\SlaService;
use App\Services\Workforce\WorkforceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AssignOrderTeam
{
    public function __construct(
        private readonly WorkforceService $workforce,
        private readonly SlaService $sla,
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    /**
     * Assign or update the active technician team and lead PIC for a service order.
     *
     * @param  list<int>  $technicianIds
     * @return array{newly_assigned: list<int>, released: list<int>, lead_changed: bool}
     */
    public function handle(
        ServiceOrder $order,
        User $actor,
        array $technicianIds,
        int $leadTechnicianId,
        ?string $ipAddress = null,
    ): array {
        $uniqueTechIds = collect($technicianIds)
            ->map(fn (int $id): int => (int) $id)
            ->unique()
            ->values();

        /** @var array{newly_assigned: list<int>, released: list<int>, lead_changed: bool} $changes */
        $changes = DB::transaction(function () use ($order, $actor, $uniqueTechIds, $leadTechnicianId, $ipAddress): array {
            $lockedOrder = ServiceOrder::query()->lockForUpdate()->findOrFail($order->id);
            Gate::forUser($actor)->authorize('updateTeam', $lockedOrder);

            $previousTechnicianIds = $lockedOrder->activeTechnicianAssignments()
                ->pluck('user_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $previousLeadId = $lockedOrder->assigned_technician_id;

            $before = [
                'lead_technician_id' => $previousLeadId,
                'technician_ids' => $previousTechnicianIds,
            ];
            $assignedAt = now();

            $releasedIds = array_values(array_diff($previousTechnicianIds, $uniqueTechIds->all()));
            $newlyAssignedIds = array_values(array_diff($uniqueTechIds->all(), $previousTechnicianIds));

            OrderAssignment::query()
                ->whereBelongsTo($lockedOrder, 'serviceOrder')
                ->where('assignment_role', 'technician')
                ->whereNull('released_at')
                ->whereNotIn('user_id', $uniqueTechIds)
                ->update(['released_at' => $assignedAt]);

            foreach ($uniqueTechIds as $technicianId) {
                $assignment = $lockedOrder->assignments()->firstOrNew([
                    'user_id' => $technicianId,
                    'assignment_role' => 'technician',
                ]);

                if (! $assignment->exists || $assignment->released_at !== null) {
                    $assignment->assigned_at = $assignedAt;
                }

                $assignment->released_at = null;
                $assignment->save();
            }

            $lockedOrder->update([
                'assigned_technician_id' => $leadTechnicianId,
            ]);

            if ($uniqueTechIds->isNotEmpty()) {
                $this->sla->recordFirstResponse($lockedOrder, $assignedAt);
            }

            $affectedTechIds = array_values(array_unique([...$previousTechnicianIds, ...$uniqueTechIds->all()]));
            foreach (User::query()->whereKey($affectedTechIds)->get() as $tech) {
                $this->workforce->syncAvailabilityWithActiveWork($tech, $actor);
            }

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'service_order.team_updated',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $lockedOrder->id,
                'before' => json_encode($before),
                'after' => json_encode([
                    'lead_technician_id' => $leadTechnicianId,
                    'technician_ids' => $uniqueTechIds->all(),
                ]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return [
                'newly_assigned' => $newlyAssignedIds,
                'released' => $releasedIds,
                'lead_changed' => $previousLeadId !== $leadTechnicianId,
            ];
        });

        if ($changes['newly_assigned'] !== []) {
            $newTechs = User::query()->whereKey($changes['newly_assigned'])->get();
            $this->dispatcher->notifyUsers(
                $newTechs,
                'order.assigned',
                "Penugasan tiket {$order->ticket_number}",
                "Anda ditugaskan menangani tiket {$order->ticket_number} ({$order->customer_name}).",
                route('orders.show', $order),
                ['ticket_number' => $order->ticket_number],
            );
        }

        if ($changes['released'] !== []) {
            $releasedTechs = User::query()->whereKey($changes['released'])->get();
            $this->dispatcher->notifyUsers(
                $releasedTechs,
                'order.assignment_changed',
                "Perubahan penugasan {$order->ticket_number}",
                "Penugasan Anda pada tiket {$order->ticket_number} telah diperbarui atau dialihkan.",
                route('orders.show', $order),
                ['ticket_number' => $order->ticket_number],
            );
        }

        return $changes;
    }
}
