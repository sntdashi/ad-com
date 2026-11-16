<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Services\Sla\SlaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderSlaController extends Controller
{
    public function update(Request $request, ServiceOrder $order, SlaService $sla): RedirectResponse
    {
        Gate::authorize('manageSla', $order);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['pause', 'resume', 'update_priority'])],
            'reason' => ['nullable', 'string', 'max:255'],
            'urgency' => ['nullable', Rule::in(['normal', 'soon', 'urgent'])],
            'risk_level' => ['nullable', Rule::in(['unassessed', 'low', 'medium', 'high'])],
        ]);

        $actor = $request->user();
        $action = (string) $validated['action'];

        DB::transaction(function () use ($request, $order, $sla, $validated, $actor, $action): void {
            $lockedOrder = ServiceOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($action === 'pause') {
                $reason = trim((string) ($validated['reason'] ?? '')) ?: 'Menunggu konfirmasi / respons pelanggan';
                $sla->pause($lockedOrder, $reason, $actor, $request->ip());

                return;
            }

            if ($action === 'resume') {
                $sla->resume($lockedOrder, $actor, $request->ip(), 'manual');

                return;
            }

            $before = [
                'urgency' => $lockedOrder->urgency,
                'risk_level' => $lockedOrder->risk_level,
                'response_due_at' => $lockedOrder->response_due_at?->toIso8601String(),
                'resolution_due_at' => $lockedOrder->resolution_due_at?->toIso8601String(),
            ];

            $newUrgency = (string) ($validated['urgency'] ?? $lockedOrder->urgency);
            $newRiskLevel = (string) ($validated['risk_level'] ?? $lockedOrder->risk_level);

            $lockedOrder->forceFill([
                'urgency' => $newUrgency,
                'risk_level' => $newRiskLevel,
            ])->save();

            $sla->recalculateDeadlines($lockedOrder);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'service_order.sla_priority_updated',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $lockedOrder->id,
                'before' => json_encode($before),
                'after' => json_encode([
                    'urgency' => $lockedOrder->urgency,
                    'risk_level' => $lockedOrder->risk_level,
                    'response_due_at' => $lockedOrder->response_due_at?->toIso8601String(),
                    'resolution_due_at' => $lockedOrder->resolution_due_at?->toIso8601String(),
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        $message = match ($action) {
            'pause' => 'Timer SLA tiket berhasil dijeda.',
            'resume' => 'Timer SLA tiket berhasil dilanjutkan.',
            default => 'Prioritas dan batas waktu SLA tiket berhasil diperbarui.',
        };

        return back()->with('success', $message);
    }
}
