<?php

namespace App\Http\Controllers;

use App\Actions\Orders\AssignOrderTeam;
use App\Http\Requests\UpdateOrderTeamRequest;
use App\Models\ServiceOrder;
use Illuminate\Http\RedirectResponse;

class OrderTeamController extends Controller
{
    public function update(UpdateOrderTeamRequest $request, ServiceOrder $order, AssignOrderTeam $assignOrderTeam): RedirectResponse
    {
        $actor = $request->user();
        if ($actor === null) {
            abort(403);
        }

        $assignOrderTeam->handle(
            order: $order,
            actor: $actor,
            technicianIds: $request->technicianIds(),
            leadTechnicianId: $request->leadTechnicianId(),
            ipAddress: $request->ip(),
        );

        return back()->with('success', 'Tim teknisi berhasil diperbarui.');
    }
}
