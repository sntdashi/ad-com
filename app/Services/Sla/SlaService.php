<?php

namespace App\Services\Sla;

use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SlaService
{
    /** @var list<string> */
    public const PAUSED_STATUSES = [
        'needs_info',
        'waiting_approval',
        'waiting_payment',
    ];

    /** @var list<string> */
    public const TERMINAL_STATUSES = [
        'completed',
        'warranty',
        'closed',
        'cancelled',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'on_track' => 'On Track',
        'approaching_deadline' => 'Approaching Deadline',
        'breached' => 'Breached',
        'paused' => 'Paused',
        'completed' => 'Completed',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS_ID = [
        'on_track' => 'Sesuai Target',
        'approaching_deadline' => 'Mendekati Batas',
        'breached' => 'Melewati SLA',
        'paused' => 'Dijeda (Tunggu Pelanggan)',
        'completed' => 'Tuntas',
    ];

    /**
     * Calculate initial or updated response and resolution deadlines.
     *
     * @param  array<string, mixed>|null  $config
     * @return array{response_due_at: CarbonImmutable, resolution_due_at: CarbonImmutable}
     */
    public function calculateDeadlines(
        CarbonInterface $start,
        string $urgency = 'normal',
        string $riskLevel = 'unassessed',
        int $pausedSeconds = 0,
        ?array $config = null,
    ): array {
        $resolvedConfig = $config ?? SlaConfig::get();
        $targets = SlaConfig::targetsFor($urgency, $riskLevel, $resolvedConfig);

        $responseDueAt = $this->addWorkingMinutes($start, $targets['response_minutes'], $resolvedConfig)
            ->addSeconds(max(0, $pausedSeconds));
        $resolutionDueAt = $this->addWorkingMinutes($start, $targets['resolution_minutes'], $resolvedConfig)
            ->addSeconds(max(0, $pausedSeconds));

        return [
            'response_due_at' => $responseDueAt,
            'resolution_due_at' => $resolutionDueAt,
        ];
    }

    /**
     * Add SLA target minutes respecting business hours, working days, and holidays when enabled.
     *
     * @param  array<string, mixed>|null  $config
     */
    public function addWorkingMinutes(
        CarbonInterface $start,
        int $minutes,
        ?array $config = null,
    ): CarbonImmutable {
        $resolved = $config ?? SlaConfig::get();
        $cursor = CarbonImmutable::instance($start);
        $remaining = max(0, $minutes);

        if (! (bool) ($resolved['business_hours_enabled'] ?? false) || $remaining === 0) {
            return $cursor->addMinutes($remaining);
        }

        [$startHour, $startMin] = $this->parseHourMinute((string) ($resolved['work_start_time'] ?? '08:00'), [8, 0]);
        [$endHour, $endMin] = $this->parseHourMinute((string) ($resolved['work_end_time'] ?? '17:00'), [17, 0]);

        if (($endHour * 60 + $endMin) <= ($startHour * 60 + $startMin)) {
            [$startHour, $startMin] = [8, 0];
            [$endHour, $endMin] = [17, 0];
        }

        /** @var list<int> $workingDays */
        $workingDays = $resolved['working_days'] ?? [1, 2, 3, 4, 5, 6];
        /** @var list<string> $holidays */
        $holidays = $resolved['holidays'] ?? [];

        for ($guard = 0; $guard < 400 && $remaining > 0; $guard++) {
            $isWorkingDay = in_array($cursor->dayOfWeekIso, $workingDays, true)
                && ! in_array($cursor->format('Y-m-d'), $holidays, true);

            if (! $isWorkingDay) {
                $cursor = $cursor->addDay()->setTime($startHour, $startMin, 0);

                continue;
            }

            $dayStart = $cursor->setTime($startHour, $startMin, 0);
            $dayEnd = $cursor->setTime($endHour, $endMin, 0);

            if ($cursor->lessThan($dayStart)) {
                $cursor = $dayStart;
            }

            if ($cursor->greaterThanOrEqualTo($dayEnd)) {
                $cursor = $cursor->addDay()->setTime($startHour, $startMin, 0);

                continue;
            }

            $availableSeconds = max(0, (int) $cursor->diffInSeconds($dayEnd, false));
            $availableMinutes = intdiv($availableSeconds, 60);

            if ($availableMinutes <= 0) {
                $cursor = $cursor->addDay()->setTime($startHour, $startMin, 0);

                continue;
            }

            if ($remaining <= $availableMinutes) {
                return $cursor->addMinutes($remaining);
            }

            $remaining -= $availableMinutes;
            $cursor = $cursor->addDay()->setTime($startHour, $startMin, 0);
        }

        return $cursor->addMinutes($remaining);
    }

    /**
     * Record the first meaningful technician or staff response if not yet recorded.
     */
    public function recordFirstResponse(ServiceOrder $order, ?CarbonInterface $at = null): bool
    {
        if ($order->first_responded_at !== null) {
            return false;
        }

        $respondedAt = CarbonImmutable::instance($at ?? now());
        $order->forceFill([
            'first_responded_at' => $respondedAt,
        ])->save();

        return true;
    }

    /**
     * Recalculate SLA deadlines when priority (urgency or risk_level) changes.
     *
     * @param  array<string, mixed>|null  $config
     */
    public function recalculateDeadlines(ServiceOrder $order, ?array $config = null): void
    {
        $createdAt = $order->created_at ?? now();
        $deadlines = $this->calculateDeadlines(
            $createdAt,
            $order->urgency ?: 'normal',
            $order->risk_level ?: 'unassessed',
            (int) $order->sla_paused_seconds,
            $config,
        );

        $updates = [
            'resolution_due_at' => $deadlines['resolution_due_at'],
        ];

        if ($order->first_responded_at === null) {
            $updates['response_due_at'] = $deadlines['response_due_at'];
        }

        $order->forceFill($updates)->save();
    }

    /**
     * Pause the SLA clock when waiting on customer input or authorized manual pause.
     */
    public function pause(
        ServiceOrder $order,
        string $reason,
        ?User $actor = null,
        ?string $ipAddress = null,
        ?CarbonInterface $at = null,
    ): bool {
        if (in_array($order->status, self::TERMINAL_STATUSES, true) || $order->sla_paused_at !== null) {
            return false;
        }

        $pausedAt = CarbonImmutable::instance($at ?? now());
        $cleanReason = trim($reason) !== '' ? trim($reason) : 'Menunggu respons atau konfirmasi pelanggan';

        $order->forceFill([
            'sla_paused_at' => $pausedAt,
            'sla_pause_reason' => $cleanReason,
        ])->save();

        DB::table('audit_logs')->insert([
            'actor_id' => $actor?->id,
            'event' => 'service_order.sla_paused',
            'auditable_type' => ServiceOrder::class,
            'auditable_id' => $order->id,
            'before' => json_encode(['sla_paused_at' => null, 'sla_pause_reason' => null]),
            'after' => json_encode([
                'sla_paused_at' => $pausedAt->toIso8601String(),
                'sla_pause_reason' => $cleanReason,
            ]),
            'ip_address' => $ipAddress,
            'created_at' => $pausedAt,
        ]);

        return true;
    }

    /**
     * Resume the SLA clock, shifting active deadlines forward by the paused duration.
     */
    public function resume(
        ServiceOrder $order,
        ?User $actor = null,
        ?string $ipAddress = null,
        string $trigger = 'manual',
        ?CarbonInterface $at = null,
    ): bool {
        if ($order->sla_paused_at === null) {
            return false;
        }

        $resumedAt = CarbonImmutable::instance($at ?? now());
        $pausedSince = CarbonImmutable::instance($order->sla_paused_at);
        $deltaSeconds = max(0, (int) $pausedSince->diffInSeconds($resumedAt, false));
        $totalPausedSeconds = (int) $order->sla_paused_seconds + $deltaSeconds;

        $before = [
            'sla_paused_at' => $pausedSince->toIso8601String(),
            'sla_paused_seconds' => (int) $order->sla_paused_seconds,
            'sla_pause_reason' => $order->sla_pause_reason,
            'response_due_at' => $order->response_due_at?->toIso8601String(),
            'resolution_due_at' => $order->resolution_due_at?->toIso8601String(),
        ];

        $newResponseDueAt = $order->response_due_at !== null
            ? ($order->first_responded_at === null
                ? CarbonImmutable::instance($order->response_due_at)->addSeconds($deltaSeconds)
                : CarbonImmutable::instance($order->response_due_at))
            : null;

        $newResolutionDueAt = $order->resolution_due_at !== null
            ? ($order->completed_at === null
                ? CarbonImmutable::instance($order->resolution_due_at)->addSeconds($deltaSeconds)
                : CarbonImmutable::instance($order->resolution_due_at))
            : null;

        $order->forceFill([
            'sla_paused_at' => null,
            'sla_pause_reason' => null,
            'sla_paused_seconds' => $totalPausedSeconds,
            'response_due_at' => $newResponseDueAt,
            'resolution_due_at' => $newResolutionDueAt,
        ])->save();

        DB::table('audit_logs')->insert([
            'actor_id' => $actor?->id,
            'event' => 'service_order.sla_resumed',
            'auditable_type' => ServiceOrder::class,
            'auditable_id' => $order->id,
            'before' => json_encode($before),
            'after' => json_encode([
                'trigger' => $trigger,
                'paused_delta_seconds' => $deltaSeconds,
                'sla_paused_seconds' => $totalPausedSeconds,
                'response_due_at' => $newResponseDueAt?->toIso8601String(),
                'resolution_due_at' => $newResolutionDueAt?->toIso8601String(),
            ]),
            'ip_address' => $ipAddress,
            'created_at' => $resumedAt,
        ]);

        if (in_array($trigger, ['customer_response', 'status_resumed', 'manual'], true)) {
            $recipients = $this->operationalRecipientsFor($order);
            if ($recipients->isNotEmpty()) {
                app(NotificationDispatcher::class)->notifyUsers(
                    $recipients,
                    'sla.resumed',
                    "SLA dilanjutkan: {$order->ticket_number}",
                    "Timer SLA untuk tiket {$order->ticket_number} ({$order->customer_name}) kembali berjalan.",
                    route('orders.show', $order),
                    [
                        'ticket_number' => $order->ticket_number,
                        'trigger' => $trigger,
                    ],
                );
            }
        }

        return true;
    }

    /**
     * Evaluate server-side SLA state for a ticket.
     *
     * @param  array<string, mixed>|null  $config
     * @return array{
     *     status: 'on_track'|'approaching_deadline'|'breached'|'paused'|'completed',
     *     label: string,
     *     label_id: string,
     *     response_due_at: string|null,
     *     resolution_due_at: string|null,
     *     response_due_formatted: string|null,
     *     resolution_due_formatted: string|null,
     *     first_responded_at: string|null,
     *     first_responded_formatted: string|null,
     *     completed_at: string|null,
     *     is_paused: bool,
     *     paused_seconds: int,
     *     paused_minutes: int,
     *     pause_reason: string|null,
     *     response_breached: bool,
     *     resolution_breached: bool,
     *     approaching_response: bool,
     *     approaching_resolution: bool,
     *     compliant: bool,
     *     first_response_minutes: float|null,
     *     resolution_minutes: float|null,
     *     remaining_minutes: int|null
     * }
     */
    public function evaluate(
        ServiceOrder $order,
        ?CarbonInterface $now = null,
        ?array $config = null,
    ): array {
        $resolvedNow = CarbonImmutable::instance($now ?? now());
        $resolvedConfig = $config ?? SlaConfig::get();
        $createdAt = CarbonImmutable::instance($order->created_at ?? $resolvedNow);
        $targets = SlaConfig::targetsFor(
            $order->urgency ?: 'normal',
            $order->risk_level ?: 'unassessed',
            $resolvedConfig,
        );

        $isPaused = $order->sla_paused_at !== null && ! in_array($order->status, self::TERMINAL_STATUSES, true);
        $activePauseSeconds = $isPaused
            ? max(0, (int) CarbonImmutable::instance($order->sla_paused_at)->diffInSeconds($resolvedNow, false))
            : 0;
        $totalPausedSeconds = (int) $order->sla_paused_seconds + $activePauseSeconds;

        if ($order->response_due_at === null || $order->resolution_due_at === null) {
            $computed = $this->calculateDeadlines(
                $createdAt,
                $order->urgency ?: 'normal',
                $order->risk_level ?: 'unassessed',
                (int) $order->sla_paused_seconds,
                $resolvedConfig,
            );
            $baseResponseDue = $order->response_due_at !== null
                ? CarbonImmutable::instance($order->response_due_at)
                : $computed['response_due_at'];
            $baseResolutionDue = $order->resolution_due_at !== null
                ? CarbonImmutable::instance($order->resolution_due_at)
                : $computed['resolution_due_at'];
        } else {
            $baseResponseDue = CarbonImmutable::instance($order->response_due_at);
            $baseResolutionDue = CarbonImmutable::instance($order->resolution_due_at);
        }

        $effectiveResponseDue = $isPaused && $order->first_responded_at === null
            ? $baseResponseDue->addSeconds($activePauseSeconds)
            : $baseResponseDue;
        $effectiveResolutionDue = $isPaused && $order->completed_at === null
            ? $baseResolutionDue->addSeconds($activePauseSeconds)
            : $baseResolutionDue;

        $firstRespondedAt = $order->first_responded_at !== null
            ? CarbonImmutable::instance($order->first_responded_at)
            : null;
        $completedAt = $order->completed_at !== null
            ? CarbonImmutable::instance($order->completed_at)
            : null;

        $isTerminal = in_array($order->status, self::TERMINAL_STATUSES, true) || $completedAt !== null;

        $responseBreached = $firstRespondedAt !== null
            ? $firstRespondedAt->greaterThan($effectiveResponseDue)
            : (! $isTerminal && ! $isPaused && $resolvedNow->greaterThan($effectiveResponseDue));

        $completionRef = $completedAt ?? ($isTerminal ? CarbonImmutable::instance($order->updated_at ?? $resolvedNow) : $resolvedNow);
        $resolutionBreached = $order->status === 'cancelled'
            ? false
            : ($isTerminal
                ? $completionRef->greaterThan($effectiveResolutionDue)
                : (! $isPaused && $resolvedNow->greaterThan($effectiveResolutionDue)));

        $thresholdPercent = (int) ($resolvedConfig['approaching_threshold_percent'] ?? 80);
        $remainingRatio = max(0.05, (100 - $thresholdPercent) / 100);

        $responseRemainingSeconds = $firstRespondedAt === null
            ? (int) $resolvedNow->diffInSeconds($effectiveResponseDue, false)
            : null;
        $resolutionRemainingSeconds = ! $isTerminal
            ? (int) $resolvedNow->diffInSeconds($effectiveResolutionDue, false)
            : null;

        $approachingResponse = ! $isTerminal
            && ! $isPaused
            && $firstRespondedAt === null
            && $responseRemainingSeconds !== null
            && $responseRemainingSeconds > 0
            && $responseRemainingSeconds <= (int) round($targets['response_minutes'] * 60 * $remainingRatio);

        $approachingResolution = ! $isTerminal
            && ! $isPaused
            && $resolutionRemainingSeconds !== null
            && $resolutionRemainingSeconds > 0
            && $resolutionRemainingSeconds <= (int) round($targets['resolution_minutes'] * 60 * $remainingRatio);

        if ($isTerminal) {
            $status = 'completed';
        } elseif ($isPaused) {
            $status = 'paused';
        } elseif ($responseBreached || $resolutionBreached) {
            $status = 'breached';
        } elseif ($approachingResponse || $approachingResolution) {
            $status = 'approaching_deadline';
        } else {
            $status = 'on_track';
        }

        $firstResponseMinutes = $firstRespondedAt !== null
            ? round(max(0, $createdAt->diffInSeconds($firstRespondedAt, false)) / 60, 1)
            : null;

        $resolutionMinutes = $completedAt !== null
            ? round(max(0, $createdAt->diffInSeconds($completedAt, false) - (int) $order->sla_paused_seconds) / 60, 1)
            : null;

        $nextDeadlineRemainingSeconds = $firstRespondedAt === null
            ? $responseRemainingSeconds
            : $resolutionRemainingSeconds;

        return [
            'status' => $status,
            'label' => self::STATUS_LABELS[$status],
            'label_id' => self::STATUS_LABELS_ID[$status],
            'response_due_at' => $effectiveResponseDue->toIso8601String(),
            'resolution_due_at' => $effectiveResolutionDue->toIso8601String(),
            'response_due_formatted' => $effectiveResponseDue->translatedFormat('d M Y, H.i'),
            'resolution_due_formatted' => $effectiveResolutionDue->translatedFormat('d M Y, H.i'),
            'first_responded_at' => $firstRespondedAt?->toIso8601String(),
            'first_responded_formatted' => $firstRespondedAt?->translatedFormat('d M Y, H.i'),
            'completed_at' => $completedAt?->toIso8601String(),
            'is_paused' => $isPaused,
            'paused_seconds' => $totalPausedSeconds,
            'paused_minutes' => (int) round($totalPausedSeconds / 60),
            'pause_reason' => $isPaused ? $order->sla_pause_reason : null,
            'response_breached' => $responseBreached,
            'resolution_breached' => $resolutionBreached,
            'approaching_response' => $approachingResponse,
            'approaching_resolution' => $approachingResolution,
            'compliant' => ! $responseBreached && ! $resolutionBreached,
            'first_response_minutes' => $firstResponseMinutes,
            'resolution_minutes' => $resolutionMinutes,
            'remaining_minutes' => $nextDeadlineRemainingSeconds !== null
                ? (int) floor($nextDeadlineRemainingSeconds / 60)
                : null,
        ];
    }

    /**
     * Dispatch approaching/breached SLA notifications for a single ticket without duplicates.
     *
     * @param  array<string, mixed>|null  $config
     * @return list<string>
     */
    public function dispatchNotificationsForOrder(
        ServiceOrder $order,
        ?CarbonInterface $now = null,
        ?array $config = null,
    ): array {
        $resolvedNow = CarbonImmutable::instance($now ?? now());
        $resolvedConfig = $config ?? SlaConfig::get();

        /** @var array{events: list<array{key: string, title: string, body: string}>, lockedOrder: ServiceOrder|null} $outcome */
        $outcome = DB::transaction(function () use ($order, $resolvedNow, $resolvedConfig): array {
            $locked = ServiceOrder::query()->lockForUpdate()->find($order->id);
            if ($locked === null) {
                return ['events' => [], 'lockedOrder' => null];
            }

            $snapshot = $this->evaluate($locked, $resolvedNow, $resolvedConfig);
            if (in_array($snapshot['status'], ['completed', 'paused'], true)) {
                return ['events' => [], 'lockedOrder' => $locked];
            }

            $events = [];
            $updates = [];

            if ($snapshot['status'] === 'breached' && $locked->sla_breach_notified_at === null) {
                $updates['sla_breach_notified_at'] = $resolvedNow;
                $breachType = $snapshot['response_breached'] && $locked->first_responded_at === null
                    ? 'respon awal'
                    : 'penyelesaian';
                $events[] = [
                    'key' => 'sla.breached',
                    'title' => "SLA terlewati: {$locked->ticket_number}",
                    'body' => "Tiket {$locked->ticket_number} ({$locked->customer_name}) telah melewati batas waktu {$breachType} SLA.",
                ];
            } else {
                if (
                    $snapshot['approaching_response']
                    && $locked->first_responded_at === null
                    && $locked->sla_response_notified_at === null
                ) {
                    $updates['sla_response_notified_at'] = $resolvedNow;
                    $events[] = [
                        'key' => 'sla.approaching_response',
                        'title' => "Mendekati batas respon: {$locked->ticket_number}",
                        'body' => "Tiket {$locked->ticket_number} ({$locked->customer_name}) mendekati tenggat respon awal ({$snapshot['response_due_formatted']}).",
                    ];
                }

                if (
                    $snapshot['approaching_resolution']
                    && $locked->sla_resolution_notified_at === null
                ) {
                    $updates['sla_resolution_notified_at'] = $resolvedNow;
                    $events[] = [
                        'key' => 'sla.approaching_resolution',
                        'title' => "Mendekati batas penyelesaian: {$locked->ticket_number}",
                        'body' => "Tiket {$locked->ticket_number} ({$locked->customer_name}) mendekati tenggat penyelesaian ({$snapshot['resolution_due_formatted']}).",
                    ];
                }
            }

            if ($updates !== []) {
                $locked->forceFill($updates)->save();
            }

            return ['events' => $events, 'lockedOrder' => $locked];
        });

        if ($outcome['events'] === [] || $outcome['lockedOrder'] === null) {
            return [];
        }

        $recipients = $this->operationalRecipientsFor($outcome['lockedOrder']);
        if ($recipients->isEmpty()) {
            return [];
        }

        $dispatcher = app(NotificationDispatcher::class);
        $dispatchedKeys = [];

        foreach ($outcome['events'] as $event) {
            $dispatcher->notifyUsers(
                $recipients,
                $event['key'],
                $event['title'],
                $event['body'],
                route('orders.show', $outcome['lockedOrder']),
                ['ticket_number' => $outcome['lockedOrder']->ticket_number],
            );
            $dispatchedKeys[] = $event['key'];
        }

        return $dispatchedKeys;
    }

    /**
     * Scan all active open tickets and dispatch due SLA notifications.
     *
     * @return array{scanned: int, notifications_sent: int}
     */
    public function monitorOpenOrders(?CarbonInterface $now = null): array
    {
        $resolvedNow = CarbonImmutable::instance($now ?? now());
        $config = SlaConfig::get();

        $openOrders = ServiceOrder::query()
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->whereNull('sla_paused_at')
            ->get();

        $sent = 0;
        foreach ($openOrders as $order) {
            $events = $this->dispatchNotificationsForOrder($order, $resolvedNow, $config);
            $sent += count($events);
        }

        return [
            'scanned' => $openOrders->count(),
            'notifications_sent' => $sent,
        ];
    }

    /**
     * Compute aggregate SLA metrics for a scoped ServiceOrder query.
     *
     * @param  Builder<ServiceOrder>  $query
     * @return array{
     *     on_track: int,
     *     approaching_deadline: int,
     *     breached: int,
     *     paused: int,
     *     completed: int,
     *     avg_first_response_minutes: float|null,
     *     avg_resolution_minutes: float|null,
     *     compliance_rate: float
     * }
     */
    public function metricsForQuery(Builder $query, ?CarbonInterface $now = null): array
    {
        $resolvedNow = CarbonImmutable::instance($now ?? now());
        $config = SlaConfig::get();
        $orders = (clone $query)->get([
            'id',
            'urgency',
            'risk_level',
            'status',
            'created_at',
            'updated_at',
            'first_responded_at',
            'response_due_at',
            'resolution_due_at',
            'sla_paused_at',
            'sla_paused_seconds',
            'sla_pause_reason',
            'completed_at',
        ]);

        $counts = [
            'on_track' => 0,
            'approaching_deadline' => 0,
            'breached' => 0,
            'paused' => 0,
            'completed' => 0,
        ];

        $responseSamples = [];
        $resolutionSamples = [];
        $evaluatedTotal = 0;
        $compliantCount = 0;

        foreach ($orders as $order) {
            $snapshot = $this->evaluate($order, $resolvedNow, $config);
            $counts[$snapshot['status']]++;

            if ($snapshot['first_response_minutes'] !== null) {
                $responseSamples[] = $snapshot['first_response_minutes'];
            }

            if ($snapshot['resolution_minutes'] !== null) {
                $resolutionSamples[] = $snapshot['resolution_minutes'];
            }

            if ($order->status !== 'cancelled') {
                $evaluatedTotal++;
                if ($snapshot['compliant']) {
                    $compliantCount++;
                }
            }
        }

        return [
            ...$counts,
            'avg_first_response_minutes' => $responseSamples !== []
                ? round(array_sum($responseSamples) / count($responseSamples), 1)
                : null,
            'avg_resolution_minutes' => $resolutionSamples !== []
                ? round(array_sum($resolutionSamples) / count($resolutionSamples), 1)
                : null,
            'compliance_rate' => $evaluatedTotal > 0
                ? round(($compliantCount / $evaluatedTotal) * 100, 1)
                : 100.0,
        ];
    }

    /**
     * Resolve operational staff and assigned technicians who should receive SLA notifications.
     *
     * @return Collection<int, User>
     */
    private function operationalRecipientsFor(ServiceOrder $order): Collection
    {
        $order->loadMissing(['assignedTechnician', 'activeTechnicianAssignments.user', 'supervisor']);

        $assignedUsers = collect([$order->assignedTechnician, $order->supervisor])
            ->merge($order->activeTechnicianAssignments->pluck('user'))
            ->filter(fn ($user): bool => $user instanceof User && $user->is_active);

        $managers = User::query()
            ->whereIn('role', ['operator', 'admin', 'supervisor'])
            ->where('is_active', true)
            ->get();

        return $assignedUsers
            ->merge($managers)
            ->unique('id')
            ->values();
    }

    /**
     * @param  array{0: int, 1: int}  $fallback
     * @return array{0: int, 1: int}
     */
    private function parseHourMinute(string $time, array $fallback): array
    {
        if (preg_match('/^(\d{2}):(\d{2})$/', trim($time), $matches) !== 1) {
            return $fallback;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return $fallback;
        }

        return [$hour, $minute];
    }
}
