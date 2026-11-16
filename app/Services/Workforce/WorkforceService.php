<?php

namespace App\Services\Workforce;

use App\Models\OrderAssignment;
use App\Models\OrderStatusEvent;
use App\Models\ServiceOrder;
use App\Models\TechnicianAvailabilityLog;
use App\Models\TechnicianWorkSession;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Sla\SlaConfig;
use App\Services\Sla\SlaService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WorkforceService
{
    /** @var list<string> */
    public const AVAILABILITY_STATUSES = [
        'available',
        'busy',
        'unavailable',
        'on_leave',
    ];

    /** @var array<string, string> */
    public const AVAILABILITY_LABELS = [
        'available' => 'Available',
        'busy' => 'Busy',
        'unavailable' => 'Unavailable',
        'on_leave' => 'On Leave',
    ];

    /** @var array<string, string> */
    public const AVAILABILITY_LABELS_ID = [
        'available' => 'Tersedia',
        'busy' => 'Sedang Bertugas',
        'unavailable' => 'Tidak Tersedia',
        'on_leave' => 'Cuti / Izin',
    ];

    /** @var list<string> */
    public const ACTIVE_FIELD_ORDER_STATUSES = [
        'on_the_way',
        'in_progress',
    ];

    /** @var list<string> */
    public const ACTIVE_WORKLOAD_STATUSES = [
        'on_the_way',
        'in_progress',
        'verification',
    ];

    /** @var list<string> */
    public const PENDING_WORKLOAD_STATUSES = [
        'new',
        'needs_info',
        'triaged',
        'waiting_estimate',
        'waiting_approval',
        'scheduled',
        'waiting_payment',
        'reopened',
    ];

    /**
     * Determine whether a technician currently has active in-field work or an active work session.
     */
    public function hasActiveFieldWork(User $technician, ?string $ignoreOrderId = null): bool
    {
        $hasActiveSession = TechnicianWorkSession::query()
            ->where('technician_id', $technician->id)
            ->where('status', 'in_progress')
            ->when($ignoreOrderId !== null, fn (Builder $q) => $q->where('service_order_id', '!=', $ignoreOrderId))
            ->exists();

        if ($hasActiveSession) {
            return true;
        }

        $hasActiveFieldOrder = ServiceOrder::query()
            ->whereIn('status', self::ACTIVE_FIELD_ORDER_STATUSES)
            ->when($ignoreOrderId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreOrderId))
            ->where(function (Builder $query) use ($technician): void {
                $query->where('assigned_technician_id', $technician->id)
                    ->orWhereHas('activeTechnicianAssignments', fn (Builder $a) => $a->where('user_id', $technician->id));
            })
            ->exists();

        return $hasActiveFieldOrder;
    }

    /**
     * Resolve effective availability for a technician.
     */
    public function resolveEffectiveAvailability(User $technician): string
    {
        if (! $technician->is_active) {
            return 'unavailable';
        }

        $stored = $technician->availability_status ?: 'available';
        if (in_array($stored, ['unavailable', 'on_leave'], true)) {
            return $stored;
        }

        if ($this->hasActiveFieldWork($technician)) {
            return 'busy';
        }

        return in_array($stored, self::AVAILABILITY_STATUSES, true) ? $stored : 'available';
    }

    /**
     * Synchronize stored availability_status when active field work starts or finishes.
     */
    public function syncAvailabilityWithActiveWork(User $technician, ?User $actor = null): void
    {
        if ($technician->role !== 'technician') {
            return;
        }

        $hasActive = $this->hasActiveFieldWork($technician);
        $current = $technician->availability_status ?: 'available';

        if ($hasActive && $current !== 'busy') {
            $technician->forceFill([
                'availability_status' => 'busy',
                'availability_updated_at' => now(),
            ])->save();

            TechnicianAvailabilityLog::query()->create([
                'user_id' => $technician->id,
                'actor_id' => $actor?->id,
                'previous_status' => $current,
                'new_status' => 'busy',
                'reason' => 'Otomatis: memiliki pekerjaan aktif di lapangan',
            ]);

            return;
        }

        if (! $hasActive && $current === 'busy') {
            $technician->forceFill([
                'availability_status' => 'available',
                'availability_note' => null,
                'availability_updated_at' => now(),
            ])->save();

            TechnicianAvailabilityLog::query()->create([
                'user_id' => $technician->id,
                'actor_id' => $actor?->id,
                'previous_status' => 'busy',
                'new_status' => 'available',
                'reason' => 'Otomatis: pekerjaan lapangan selesai',
            ]);
        }
    }

    /**
     * Update a technician's availability with policy checks, conflict prevention, and audit logging.
     */
    public function updateAvailability(
        User $technician,
        User $actor,
        string $newStatus,
        ?string $note = null,
        ?string $ipAddress = null,
    ): User {
        if (! $actor->is_active) {
            throw new AuthorizationException('Akun tidak aktif.');
        }

        $isManager = in_array($actor->role, ['operator', 'admin', 'supervisor'], true);
        $isSelfTechnician = $actor->id === $technician->id && $actor->role === 'technician';

        if (! $isManager && ! $isSelfTechnician) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk mengubah ketersediaan teknisi ini.');
        }

        if ($technician->role !== 'technician') {
            throw ValidationException::withMessages([
                'availability_status' => 'Pengaturan ketersediaan hanya berlaku untuk akun teknisi.',
            ]);
        }

        if (! in_array($newStatus, self::AVAILABILITY_STATUSES, true)) {
            throw ValidationException::withMessages([
                'availability_status' => 'Status ketersediaan tidak valid.',
            ]);
        }

        if ($newStatus === 'available' && $this->hasActiveFieldWork($technician)) {
            throw ValidationException::withMessages([
                'availability_status' => 'Teknisi masih memiliki sesi kerja atau tiket aktif di lapangan dan tidak dapat ditandai Available.',
            ]);
        }

        return DB::transaction(function () use ($technician, $actor, $newStatus, $note, $ipAddress): User {
            $locked = User::query()->lockForUpdate()->findOrFail($technician->id);
            $previousStatus = $locked->availability_status ?: 'available';
            $cleanNote = trim((string) $note) !== '' ? trim((string) $note) : null;

            $locked->forceFill([
                'availability_status' => $newStatus,
                'availability_note' => $newStatus === 'available' ? null : $cleanNote,
                'availability_updated_at' => now(),
            ])->save();

            TechnicianAvailabilityLog::query()->create([
                'user_id' => $locked->id,
                'actor_id' => $actor->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'reason' => $cleanNote,
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'technician.availability_updated',
                'auditable_type' => User::class,
                'auditable_id' => (string) $locked->id,
                'before' => json_encode(['availability_status' => $previousStatus]),
                'after' => json_encode([
                    'availability_status' => $newStatus,
                    'availability_note' => $locked->availability_note,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * Detect if a technician has a conflict preventing assignment to the given order.
     */
    public function detectAssignmentConflict(User $technician, ServiceOrder $order): ?string
    {
        if (! $technician->is_active || $technician->role !== 'technician') {
            return "Teknisi {$technician->name} tidak aktif.";
        }

        $alreadyAssigned = $order->assigned_technician_id === $technician->id
            || $order->activeTechnicianAssignments()->where('user_id', $technician->id)->exists();

        $effectiveAvailability = $this->resolveEffectiveAvailability($technician);

        if (! $alreadyAssigned && in_array($effectiveAvailability, ['unavailable', 'on_leave'], true)) {
            $label = self::AVAILABILITY_LABELS[$effectiveAvailability];

            return "Teknisi {$technician->name} sedang berstatus {$label} dan tidak dapat ditugaskan.";
        }

        $conflictingSession = TechnicianWorkSession::query()
            ->where('technician_id', $technician->id)
            ->where('status', 'in_progress')
            ->where('service_order_id', '!=', $order->id)
            ->with('serviceOrder:id,ticket_number')
            ->first();

        if ($conflictingSession !== null && in_array($order->status, self::ACTIVE_FIELD_ORDER_STATUSES, true)) {
            $otherTicket = $conflictingSession->serviceOrder->ticket_number ?? $conflictingSession->service_order_id;

            return "Teknisi {$technician->name} sedang memiliki sesi kerja aktif pada tiket {$otherTicket}.";
        }

        if ($order->scheduled_at !== null) {
            $windowStart = CarbonImmutable::instance($order->scheduled_at)->subMinutes(89);
            $windowEnd = CarbonImmutable::instance($order->scheduled_at)->addMinutes(89);

            $conflictingOrder = ServiceOrder::query()
                ->where('id', '!=', $order->id)
                ->whereNotIn('status', SlaService::TERMINAL_STATUSES)
                ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
                ->where(function (Builder $q) use ($technician): void {
                    $q->where('assigned_technician_id', $technician->id)
                        ->orWhereHas('activeTechnicianAssignments', fn (Builder $a) => $a->where('user_id', $technician->id));
                })
                ->first(['id', 'ticket_number', 'scheduled_at']);

            if ($conflictingOrder !== null) {
                return "Teknisi {$technician->name} memiliki jadwal bentrok pada tiket {$conflictingOrder->ticket_number}.";
            }
        }

        return null;
    }

    /**
     * Compute comprehensive workload and performance metrics for a single technician.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     username: string|null,
     *     avatar_url: string|null,
     *     specialty: string|null,
     *     is_active: bool,
     *     availability_status: string,
     *     availability_label: string,
     *     availability_label_id: string,
     *     availability_note: string|null,
     *     active_assignments_count: int,
     *     pending_assignments_count: int,
     *     completed_jobs_count: int,
     *     current_workload: int,
     *     workload_level: 'idle'|'light'|'moderate'|'high',
     *     sla_risk_assignments_count: int,
     *     avg_resolution_minutes: float|null,
     *     avg_work_session_minutes: float|null,
     *     sla_compliance_rate: float,
     *     reopened_jobs_count: int
     * }
     */
    public function metricsForTechnician(User $technician, ?CarbonInterface $now = null): array
    {
        $resolvedNow = CarbonImmutable::instance($now ?? now());
        $slaService = app(SlaService::class);
        $slaConfig = SlaConfig::get();

        $activeOrderIds = OrderAssignment::query()
            ->where('user_id', $technician->id)
            ->where('assignment_role', 'technician')
            ->whereNull('released_at')
            ->pluck('service_order_id')
            ->merge(
                ServiceOrder::query()
                    ->where('assigned_technician_id', $technician->id)
                    ->pluck('id'),
            )
            ->unique()
            ->values();

        $allOrderIds = $activeOrderIds;

        $currentOrders = ServiceOrder::query()
            ->whereIn('id', $activeOrderIds)
            ->whereNotIn('status', SlaService::TERMINAL_STATUSES)
            ->get();

        $allOrders = ServiceOrder::query()
            ->whereIn('id', $allOrderIds)
            ->get();

        $activeAssignments = 0;
        $pendingAssignments = 0;
        $slaRiskCount = 0;

        foreach ($currentOrders as $order) {
            if (in_array($order->status, self::ACTIVE_WORKLOAD_STATUSES, true)) {
                $activeAssignments++;
            } else {
                $pendingAssignments++;
            }

            $sla = $slaService->evaluate($order, $resolvedNow, $slaConfig);
            if (in_array($sla['status'], ['approaching_deadline', 'breached'], true)) {
                $slaRiskCount++;
            }
        }

        $completedCount = 0;
        $resolutionMinutes = [];
        $evaluatedSlaTotal = 0;
        $compliantSlaCount = 0;

        foreach ($allOrders as $order) {
            $sla = $slaService->evaluate($order, $resolvedNow, $slaConfig);

            if (in_array($order->status, ['completed', 'warranty', 'closed'], true)) {
                $completedCount++;
                if ($sla['resolution_minutes'] !== null) {
                    $resolutionMinutes[] = $sla['resolution_minutes'];
                }
            }

            if ($order->status !== 'cancelled') {
                $evaluatedSlaTotal++;
                if ($sla['compliant']) {
                    $compliantSlaCount++;
                }
            }
        }

        $completedSessions = TechnicianWorkSession::query()
            ->where('technician_id', $technician->id)
            ->where('status', 'completed')
            ->pluck('duration_seconds');

        $avgWorkSessionMinutes = $completedSessions->isNotEmpty()
            ? round(($completedSessions->avg() ?? 0) / 60, 1)
            : null;

        $reopenedJobsCount = OrderStatusEvent::query()
            ->whereIn('service_order_id', $allOrderIds)
            ->where('status', 'reopened')
            ->distinct()
            ->count('service_order_id');

        $currentWorkload = $activeAssignments + $pendingAssignments;
        $workloadLevel = match (true) {
            $currentWorkload === 0 => 'idle',
            $currentWorkload === 1 => 'light',
            $currentWorkload <= 3 => 'moderate',
            default => 'high',
        };

        $effectiveAvailability = $this->resolveEffectiveAvailability($technician);

        return [
            'id' => $technician->id,
            'name' => $technician->name,
            'username' => $technician->username,
            'avatar_url' => $technician->avatar_url,
            'specialty' => $technician->specialty,
            'is_active' => (bool) $technician->is_active,
            'availability_status' => $effectiveAvailability,
            'availability_label' => self::AVAILABILITY_LABELS[$effectiveAvailability] ?? 'Available',
            'availability_label_id' => self::AVAILABILITY_LABELS_ID[$effectiveAvailability] ?? 'Tersedia',
            'availability_note' => $technician->availability_note,
            'active_assignments_count' => $activeAssignments,
            'pending_assignments_count' => $pendingAssignments,
            'completed_jobs_count' => $completedCount,
            'current_workload' => $currentWorkload,
            'workload_level' => $workloadLevel,
            'sla_risk_assignments_count' => $slaRiskCount,
            'avg_resolution_minutes' => $resolutionMinutes !== []
                ? round(array_sum($resolutionMinutes) / count($resolutionMinutes), 1)
                : null,
            'avg_work_session_minutes' => $avgWorkSessionMinutes,
            'sla_compliance_rate' => $evaluatedSlaTotal > 0
                ? round(($compliantSlaCount / $evaluatedSlaTotal) * 100, 1)
                : 100.0,
            'reopened_jobs_count' => $reopenedJobsCount,
        ];
    }

    /**
     * Generate explainable smart dispatch recommendations for a service order.
     *
     * @return list<array{
     *     id: int,
     *     name: string,
     *     avatar_url: string|null,
     *     specialty: string|null,
     *     availability_status: string,
     *     availability_label: string,
     *     current_workload: int,
     *     active_assignments_count: int,
     *     pending_assignments_count: int,
     *     sla_risk_assignments_count: int,
     *     sla_compliance_rate: float,
     *     eligible: bool,
     *     conflict_reason: string|null,
     *     score: int,
     *     recommended: bool,
     *     reasons: list<string>
     * }>
     */
    public function recommendForOrder(ServiceOrder $order, ?CarbonInterface $now = null): array
    {
        $order->loadMissing('service');
        $resolvedNow = CarbonImmutable::instance($now ?? now());
        $orderSla = app(SlaService::class)->evaluate($order, $resolvedNow);
        $isHighPriority = in_array($order->urgency, ['soon', 'urgent', 'high'], true)
            || $order->risk_level === 'high'
            || in_array($orderSla['status'], ['approaching_deadline', 'breached'], true);

        $technicians = User::query()
            ->where('role', 'technician')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $results = [];

        foreach ($technicians as $technician) {
            $metrics = $this->metricsForTechnician($technician, $resolvedNow);
            $conflict = $this->detectAssignmentConflict($technician, $order);
            $eligible = $conflict === null;

            $score = 50;
            $reasons = [];

            if (! $eligible) {
                $score = 0;
                $reasons[] = $conflict;
            } else {
                if ($metrics['availability_status'] === 'available') {
                    $score += 25;
                    $reasons[] = 'Status Available (siap ditugaskan)';
                } elseif ($metrics['availability_status'] === 'busy') {
                    $score -= 15;
                    $reasons[] = 'Sedang bertugas pada tiket lain (Busy)';
                }

                if ($metrics['current_workload'] === 0) {
                    $score += 20;
                    $reasons[] = 'Beban kerja kosong (0 antrean aktif)';
                } elseif ($metrics['current_workload'] === 1) {
                    $score += 10;
                    $reasons[] = 'Beban kerja ringan (1 tiket berjalan)';
                } elseif ($metrics['current_workload'] >= 3) {
                    $score -= min(30, ($metrics['current_workload'] - 2) * 10);
                    $reasons[] = "Beban kerja tinggi ({$metrics['current_workload']} tiket aktif/pending)";
                }

                if ($this->matchesSpecialization($technician->specialty, $order)) {
                    $score += 20;
                    $reasons[] = "Spesialisasi sesuai ({$technician->specialty})";
                }

                if ($isHighPriority && $metrics['sla_compliance_rate'] >= 90.0 && $metrics['current_workload'] <= 1) {
                    $score += 10;
                    $reasons[] = "Rekam SLA {$metrics['sla_compliance_rate']}% cocok untuk tiket prioritas/risiko tinggi";
                }

                if ($metrics['sla_risk_assignments_count'] > 0) {
                    $score -= 10;
                    $reasons[] = "Sedang menangani {$metrics['sla_risk_assignments_count']} tiket berisiko SLA";
                }
            }

            $clampedScore = max(0, min(100, $score));

            $results[] = [
                'id' => $technician->id,
                'name' => $technician->name,
                'avatar_url' => $technician->avatar_url,
                'specialty' => $technician->specialty,
                'availability_status' => $metrics['availability_status'],
                'availability_label' => $metrics['availability_label'],
                'current_workload' => $metrics['current_workload'],
                'active_assignments_count' => $metrics['active_assignments_count'],
                'pending_assignments_count' => $metrics['pending_assignments_count'],
                'sla_risk_assignments_count' => $metrics['sla_risk_assignments_count'],
                'sla_compliance_rate' => $metrics['sla_compliance_rate'],
                'eligible' => $eligible,
                'conflict_reason' => $conflict,
                'score' => $clampedScore,
                'recommended' => $eligible && $clampedScore >= 70,
                'reasons' => $reasons,
            ];
        }

        usort($results, function (array $a, array $b): int {
            if ($a['eligible'] !== $b['eligible']) {
                return $a['eligible'] ? -1 : 1;
            }
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['current_workload'] <=> $b['current_workload'];
        });

        return $results;
    }

    /**
     * Start a new work session for a technician on an assigned service order.
     */
    public function startWorkSession(
        ServiceOrder $order,
        User $actor,
        ?User $targetTechnician = null,
        ?string $notes = null,
        ?string $ipAddress = null,
        ?CarbonInterface $at = null,
    ): TechnicianWorkSession {
        Gate::forUser($actor)->authorize('update', $order);

        $technician = $targetTechnician ?? ($actor->role === 'technician' ? $actor : $order->assignedTechnician);
        if ($technician === null || $technician->role !== 'technician') {
            throw ValidationException::withMessages([
                'session' => 'Pilih atau tugaskan PIC teknisi terlebih dahulu sebelum memulai sesi kerja.',
            ]);
        }

        if ($actor->role === 'technician' && $actor->id !== $technician->id) {
            throw new AuthorizationException('Teknisi hanya dapat memulai sesi kerja untuk dirinya sendiri.');
        }

        $isAssigned = $order->assigned_technician_id === $technician->id
            || $order->activeTechnicianAssignments()->where('user_id', $technician->id)->exists();

        if (! $isAssigned) {
            throw ValidationException::withMessages([
                'session' => 'Teknisi belum ditugaskan pada tiket ini.',
            ]);
        }

        if (in_array($order->status, SlaService::TERMINAL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'session' => 'Sesi kerja tidak dapat dimulai pada tiket yang sudah selesai atau dibatalkan.',
            ]);
        }

        $startedAt = CarbonImmutable::instance($at ?? now());

        $session = DB::transaction(function () use ($order, $actor, $technician, $notes, $ipAddress, $startedAt): TechnicianWorkSession {
            $lockedTech = User::query()->lockForUpdate()->findOrFail($technician->id);
            $lockedOrder = ServiceOrder::query()->lockForUpdate()->findOrFail($order->id);

            $existingOpen = TechnicianWorkSession::query()
                ->where('technician_id', $lockedTech->id)
                ->whereIn('status', ['in_progress', 'paused'])
                ->first();

            if ($existingOpen !== null) {
                throw ValidationException::withMessages([
                    'session' => 'Teknisi masih memiliki sesi kerja yang belum diselesaikan.',
                ]);
            }

            $created = TechnicianWorkSession::query()->create([
                'service_order_id' => $lockedOrder->id,
                'technician_id' => $lockedTech->id,
                'status' => 'in_progress',
                'started_at' => $startedAt,
                'paused_seconds' => 0,
                'duration_seconds' => 0,
                'notes' => trim((string) $notes) !== '' ? trim((string) $notes) : null,
            ]);

            app(SlaService::class)->recordFirstResponse($lockedOrder, $startedAt);
            $this->syncAvailabilityWithActiveWork($lockedTech, $actor);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'work_session.started',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $lockedOrder->id,
                'before' => null,
                'after' => json_encode([
                    'session_id' => $created->id,
                    'technician_id' => $lockedTech->id,
                    'started_at' => $startedAt->toIso8601String(),
                ]),
                'ip_address' => $ipAddress,
                'created_at' => $startedAt,
            ]);

            return $created;
        });

        $recipients = $this->notifyRecipientsForOrder($order, $actor);
        if ($recipients->isNotEmpty()) {
            app(NotificationDispatcher::class)->notifyUsers(
                $recipients,
                'work.started',
                "Pengerjaan dimulai: {$order->ticket_number}",
                "Teknisi {$technician->name} telah memulai sesi pengerjaan pada tiket {$order->ticket_number}.",
                route('orders.show', $order),
                ['ticket_number' => $order->ticket_number, 'session_id' => $session->id],
            );
        }

        return $session;
    }

    /**
     * Pause an active in-progress work session.
     */
    public function pauseWorkSession(
        TechnicianWorkSession $session,
        User $actor,
        ?string $notes = null,
        ?string $ipAddress = null,
        ?CarbonInterface $at = null,
    ): TechnicianWorkSession {
        $order = $session->serviceOrder;
        Gate::forUser($actor)->authorize('update', $order);

        if ($actor->role === 'technician' && $actor->id !== $session->technician_id) {
            throw new AuthorizationException('Teknisi hanya dapat menjeda sesi kerjanya sendiri.');
        }

        $pausedAt = CarbonImmutable::instance($at ?? now());

        return DB::transaction(function () use ($session, $actor, $notes, $ipAddress, $pausedAt): TechnicianWorkSession {
            $locked = TechnicianWorkSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->status !== 'in_progress') {
                throw ValidationException::withMessages([
                    'session' => 'Hanya sesi kerja yang sedang berjalan (in_progress) yang dapat dijeda.',
                ]);
            }

            $locked->forceFill([
                'status' => 'paused',
                'paused_at' => $pausedAt,
                'notes' => trim((string) $notes) !== '' ? trim((string) $notes) : $locked->notes,
            ])->save();

            if ($locked->technician !== null) {
                $this->syncAvailabilityWithActiveWork($locked->technician, $actor);
            }

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'work_session.paused',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $locked->service_order_id,
                'before' => json_encode(['status' => 'in_progress']),
                'after' => json_encode([
                    'session_id' => $locked->id,
                    'status' => 'paused',
                    'paused_at' => $pausedAt->toIso8601String(),
                ]),
                'ip_address' => $ipAddress,
                'created_at' => $pausedAt,
            ]);

            return $locked;
        });
    }

    /**
     * Resume a paused work session.
     */
    public function resumeWorkSession(
        TechnicianWorkSession $session,
        User $actor,
        ?string $notes = null,
        ?string $ipAddress = null,
        ?CarbonInterface $at = null,
    ): TechnicianWorkSession {
        $order = $session->serviceOrder;
        Gate::forUser($actor)->authorize('update', $order);

        if ($actor->role === 'technician' && $actor->id !== $session->technician_id) {
            throw new AuthorizationException('Teknisi hanya dapat melanjutkan sesi kerjanya sendiri.');
        }

        $resumedAt = CarbonImmutable::instance($at ?? now());

        return DB::transaction(function () use ($session, $actor, $notes, $ipAddress, $resumedAt): TechnicianWorkSession {
            $locked = TechnicianWorkSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->status !== 'paused' || $locked->paused_at === null) {
                throw ValidationException::withMessages([
                    'session' => 'Hanya sesi kerja yang sedang dijeda (paused) yang dapat dilanjutkan.',
                ]);
            }

            $deltaSeconds = max(0, (int) CarbonImmutable::instance($locked->paused_at)->diffInSeconds($resumedAt, false));
            $totalPaused = (int) $locked->paused_seconds + $deltaSeconds;

            $locked->forceFill([
                'status' => 'in_progress',
                'paused_at' => null,
                'resumed_at' => $resumedAt,
                'paused_seconds' => $totalPaused,
                'notes' => trim((string) $notes) !== '' ? trim((string) $notes) : $locked->notes,
            ])->save();

            if ($locked->technician !== null) {
                $this->syncAvailabilityWithActiveWork($locked->technician, $actor);
            }

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'work_session.resumed',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $locked->service_order_id,
                'before' => json_encode(['status' => 'paused']),
                'after' => json_encode([
                    'session_id' => $locked->id,
                    'status' => 'in_progress',
                    'paused_delta_seconds' => $deltaSeconds,
                    'paused_seconds' => $totalPaused,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => $resumedAt,
            ]);

            return $locked;
        });
    }

    /**
     * Complete an active or paused work session, recording net work duration separately from SLA duration.
     */
    public function completeWorkSession(
        TechnicianWorkSession $session,
        User $actor,
        ?string $notes = null,
        ?string $ipAddress = null,
        ?CarbonInterface $at = null,
    ): TechnicianWorkSession {
        $order = $session->serviceOrder;
        Gate::forUser($actor)->authorize('update', $order);

        if ($actor->role === 'technician' && $actor->id !== $session->technician_id) {
            throw new AuthorizationException('Teknisi hanya dapat menyelesaikan sesi kerjanya sendiri.');
        }

        $completedAt = CarbonImmutable::instance($at ?? now());

        $completedSession = DB::transaction(function () use ($session, $actor, $notes, $ipAddress, $completedAt): TechnicianWorkSession {
            $locked = TechnicianWorkSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->status === 'completed') {
                throw ValidationException::withMessages([
                    'session' => 'Sesi kerja ini sudah diselesaikan sebelumnya.',
                ]);
            }

            $extraPaused = $locked->paused_at !== null
                ? max(0, (int) CarbonImmutable::instance($locked->paused_at)->diffInSeconds($completedAt, false))
                : 0;
            $totalPaused = (int) $locked->paused_seconds + $extraPaused;

            $totalElapsed = max(0, (int) CarbonImmutable::instance($locked->started_at)->diffInSeconds($completedAt, false));
            $netDuration = max(0, $totalElapsed - $totalPaused);

            $locked->forceFill([
                'status' => 'completed',
                'paused_at' => null,
                'paused_seconds' => $totalPaused,
                'completed_at' => $completedAt,
                'duration_seconds' => $netDuration,
                'notes' => trim((string) $notes) !== '' ? trim((string) $notes) : $locked->notes,
            ])->save();

            if ($locked->technician !== null) {
                $this->syncAvailabilityWithActiveWork($locked->technician, $actor);
            }

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'work_session.completed',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $locked->service_order_id,
                'before' => json_encode(['status' => $session->status]),
                'after' => json_encode([
                    'session_id' => $locked->id,
                    'status' => 'completed',
                    'duration_seconds' => $netDuration,
                    'paused_seconds' => $totalPaused,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => $completedAt,
            ]);

            return $locked;
        });

        $recipients = $this->notifyRecipientsForOrder($order, $actor);
        if ($recipients->isNotEmpty()) {
            $minutes = max(1, (int) round($completedSession->duration_seconds / 60));
            app(NotificationDispatcher::class)->notifyUsers(
                $recipients,
                'work.completed',
                "Sesi pengerjaan selesai: {$order->ticket_number}",
                "Sesi kerja pada tiket {$order->ticket_number} telah diselesaikan (durasi efektif {$minutes} menit).",
                route('orders.show', $order),
                ['ticket_number' => $order->ticket_number, 'session_id' => $completedSession->id],
            );
        }

        return $completedSession;
    }

    private function matchesSpecialization(?string $specialty, ServiceOrder $order): bool
    {
        if ($specialty === null || trim($specialty) === '') {
            return false;
        }

        $haystack = mb_strtolower($specialty);
        $serviceText = mb_strtolower(($order->service->name ?? '').' '.($order->service->code ?? '').' '.$order->complaint);

        $keywords = preg_split('/[\s,\/&-]+/', $haystack) ?: [];
        foreach ($keywords as $word) {
            $clean = trim($word);
            if (mb_strlen($clean) >= 3 && str_contains($serviceText, $clean)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, User>
     */
    private function notifyRecipientsForOrder(ServiceOrder $order, User $actor): Collection
    {
        $order->loadMissing(['customer', 'supervisor']);

        $managers = User::query()
            ->whereIn('role', ['operator', 'admin', 'supervisor'])
            ->where('is_active', true)
            ->get();

        return collect([$order->customer, $order->supervisor])
            ->merge($managers)
            ->filter(fn ($u): bool => $u instanceof User && $u->is_active && $u->id !== $actor->id)
            ->unique('id')
            ->values();
    }
}
