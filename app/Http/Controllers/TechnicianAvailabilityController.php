<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Workforce\WorkforceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TechnicianAvailabilityController extends Controller
{
    public function update(Request $request, User $user, WorkforceService $workforce): RedirectResponse
    {
        $validated = $request->validate([
            'availability_status' => ['required', Rule::in(WorkforceService::AVAILABILITY_STATUSES)],
            'availability_note' => ['nullable', 'string', 'max:255'],
        ]);

        $workforce->updateAvailability(
            $user,
            $request->user(),
            (string) $validated['availability_status'],
            isset($validated['availability_note']) ? (string) $validated['availability_note'] : null,
            $request->ip(),
        );

        return back()->with('success', 'Status ketersediaan teknisi berhasil diperbarui.');
    }
}
