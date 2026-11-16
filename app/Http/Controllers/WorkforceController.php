<?php

namespace App\Http\Controllers;

use App\Models\TechnicianAvailabilityLog;
use App\Models\User;
use App\Services\Workforce\WorkforceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkforceController extends Controller
{
    public function index(Request $request, WorkforceService $workforce): Response
    {
        Gate::authorize('view-workforce');

        $user = $request->user();
        $filters = $request->validate([
            'availability' => ['nullable', Rule::in(WorkforceService::AVAILABILITY_STATUSES)],
            'workload' => ['nullable', Rule::in(['idle', 'light', 'moderate', 'high'])],
            'specialty' => ['nullable', 'string', 'max:100'],
            'assignment' => ['nullable', Rule::in(['active', 'pending', 'sla_risk', 'none'])],
        ]);

        $technicianModels = User::query()
            ->where('role', 'technician')
            ->when($user->role === 'technician', fn ($query) => $query->whereKey($user->id))
            ->when($filters['specialty'] ?? null, fn ($query, string $specialty) => $query->where('specialty', 'like', "%{$specialty}%"))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $now = now();
        $technicians = $technicianModels
            ->map(fn (User $technician): array => $workforce->metricsForTechnician($technician, $now))
            ->filter(function (array $row) use ($filters): bool {
                if (($filters['availability'] ?? null) !== null && $row['availability_status'] !== $filters['availability']) {
                    return false;
                }

                if (($filters['workload'] ?? null) !== null && $row['workload_level'] !== $filters['workload']) {
                    return false;
                }

                return match ($filters['assignment'] ?? null) {
                    'active' => $row['active_assignments_count'] > 0,
                    'pending' => $row['pending_assignments_count'] > 0,
                    'sla_risk' => $row['sla_risk_assignments_count'] > 0,
                    'none' => $row['current_workload'] === 0,
                    default => true,
                };
            })
            ->values();

        $canManageAll = in_array($user->role, ['operator', 'admin', 'supervisor'], true);

        $recentLogs = TechnicianAvailabilityLog::query()
            ->with(['user:id,name', 'actor:id,name'])
            ->when(! $canManageAll, fn ($query) => $query->where('user_id', $user->id))
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(fn (TechnicianAvailabilityLog $log): array => [
                'id' => $log->id,
                'technician' => $log->user?->name,
                'actor' => $log->actor->name ?? 'Sistem',
                'previous_status' => $log->previous_status,
                'new_status' => $log->new_status,
                'reason' => $log->reason,
                'created_at' => $log->created_at?->translatedFormat('d M Y, H.i'),
            ]);

        return Inertia::render('operations/workforce', [
            'role' => $user->role,
            'currentUserId' => $user->id,
            'canManageAll' => $canManageAll,
            'technicians' => $technicians,
            'summary' => [
                'total' => $technicians->count(),
                'available' => $technicians->where('availability_status', 'available')->count(),
                'busy' => $technicians->where('availability_status', 'busy')->count(),
                'unavailable' => $technicians->whereIn('availability_status', ['unavailable', 'on_leave'])->count(),
                'sla_risk' => $technicians->sum('sla_risk_assignments_count'),
            ],
            'filters' => [
                'availability' => $filters['availability'] ?? '',
                'workload' => $filters['workload'] ?? '',
                'specialty' => $filters['specialty'] ?? '',
                'assignment' => $filters['assignment'] ?? '',
            ],
            'availabilityOptions' => collect(WorkforceService::AVAILABILITY_STATUSES)
                ->map(fn (string $status): array => [
                    'value' => $status,
                    'label' => WorkforceService::AVAILABILITY_LABELS[$status],
                    'label_id' => WorkforceService::AVAILABILITY_LABELS_ID[$status],
                ])
                ->values(),
            'recentLogs' => $recentLogs,
        ]);
    }
}
