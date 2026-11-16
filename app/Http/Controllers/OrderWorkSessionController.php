<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Models\TechnicianWorkSession;
use App\Models\User;
use App\Services\Workforce\WorkforceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderWorkSessionController extends Controller
{
    public function store(Request $request, ServiceOrder $order, WorkforceService $workforce): RedirectResponse
    {
        $validated = $request->validate([
            'technician_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetTech = isset($validated['technician_id'])
            ? User::query()->find((int) $validated['technician_id'])
            : null;

        $workforce->startWorkSession(
            $order,
            $request->user(),
            $targetTech,
            isset($validated['notes']) ? (string) $validated['notes'] : null,
            $request->ip(),
        );

        return back()->with('success', 'Sesi pengerjaan teknisi berhasil dimulai.');
    }

    public function update(
        Request $request,
        ServiceOrder $order,
        TechnicianWorkSession $workSession,
        WorkforceService $workforce,
    ): RedirectResponse {
        abort_unless($workSession->service_order_id === $order->id, 404);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['pause', 'resume', 'complete'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $action = (string) $validated['action'];
        $notes = isset($validated['notes']) ? (string) $validated['notes'] : null;

        match ($action) {
            'pause' => $workforce->pauseWorkSession($workSession, $request->user(), $notes, $request->ip()),
            'resume' => $workforce->resumeWorkSession($workSession, $request->user(), $notes, $request->ip()),
            'complete' => $workforce->completeWorkSession($workSession, $request->user(), $notes, $request->ip()),
            default => throw ValidationException::withMessages(['action' => 'Aksi sesi pengerjaan tidak valid.']),
        };

        $message = match ($action) {
            'pause' => 'Sesi pengerjaan berhasil dijeda.',
            'resume' => 'Sesi pengerjaan berhasil dilanjutkan.',
            default => 'Sesi pengerjaan berhasil diselesaikan.',
        };

        return back()->with('success', $message);
    }
}
