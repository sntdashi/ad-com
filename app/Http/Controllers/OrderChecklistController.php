<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\OrderChecklistResult;
use App\Models\ServiceOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderChecklistController extends Controller
{
    public function update(Request $request, ServiceOrder $order, ChecklistTemplate $checklistTemplate): RedirectResponse
    {
        Gate::authorize('update', $order);

        abort_if($checklistTemplate->service_id !== $order->service_id, 404);

        $validated = $request->validate([
            'result' => ['required', Rule::in(['pending', 'passed', 'failed'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $actor = $request->user();
        $resultValue = $validated['result'];
        $notes = isset($validated['notes']) && trim($validated['notes']) !== ''
            ? trim($validated['notes'])
            : null;

        DB::transaction(function () use ($order, $checklistTemplate, $actor, $resultValue, $notes, $request): void {
            OrderChecklistResult::query()->updateOrCreate(
                [
                    'service_order_id' => $order->id,
                    'checklist_template_id' => $checklistTemplate->id,
                ],
                [
                    'completed_by' => $resultValue === 'pending' ? null : $actor->id,
                    'result' => $resultValue,
                    'notes' => $notes,
                    'completed_at' => $resultValue === 'pending' ? null : now(),
                ],
            );

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'order.checklist_updated',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $order->id,
                'before' => null,
                'after' => json_encode([
                    'checklist_template_id' => $checklistTemplate->id,
                    'title' => $checklistTemplate->title,
                    'result' => $resultValue,
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return back()->with('success', 'Checklist pengerjaan berhasil diperbarui.');
    }
}
