<?php

namespace App\Services\Analytics;

use App\Models\OrderAssignment;
use App\Models\OrderStatusEvent;
use App\Models\ServiceOrder;
use App\Models\TechnicianAvailabilityLog;
use App\Models\TechnicianWorkSession;
use App\Models\User;
use App\Services\Sla\SlaConfig;
use App\Services\Sla\SlaService;
use App\Services\Workforce\WorkforceService;
use Illuminate\Support\Collection;

class WorkforceAnalyticsService
{
    public function __construct(
        public WorkforceService $workforceService,
        public SlaService $slaService,
        public AnalyticsCacheManager $cacheManager,
        public AnalyticsAccess $access,
    ) {}

    /**
     * Build Workforce BI payload (strictly scoped to self when viewer is a technician).
     *
     * @return array<string, mixed>
     */
    public function summary(AnalyticsPeriod $period, User $viewer): array
    {
        $scopedTechId = $this->access->scopedTechnicianId($viewer);

        return $this->cacheManager->remember(
            namespace: 'workforce',
            domains: ['workforce', 'orders'],
            period: $period,
            extraKey: $scopedTechId !== null ? 'tech:'.$scopedTechId : 'team',
            resolver: fn (): array => $this->computeSummary($period, $scopedTechId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeSummary(AnalyticsPeriod $period, ?int $scopedTechId): array
    {
        $techQuery = User::query()->where('role', 'technician')->orderBy('name');
        if ($scopedTechId !== null) {
            $techQuery->whereKey($scopedTechId);
        }

        /** @var Collection<int, User> $technicians */
        $technicians = $techQuery->get();
        $slaConfig = SlaConfig::get();

        $periodOrders = ServiceOrder::query()
            ->with(['activeTechnicianAssignments:id,service_order_id,user_id,assignment_role'])
            ->whereBetween('created_at', [$period->start, $period->end])
            ->get([
                'id',
                'assigned_technician_id',
                'status',
                'urgency',
                'risk_level',
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

        $assignmentsInPeriod = OrderAssignment::query()
            ->where('assignment_role', 'technician')
            ->whereBetween('assigned_at', [$period->start, $period->end])
            ->when($scopedTechId !== null, fn ($q) => $q->where('user_id', $scopedTechId))
            ->get(['service_order_id', 'user_id']);

        $periodOrderIdsByTech = [];
        foreach ($assignmentsInPeriod as $assignment) {
            $tid = (int) $assignment->user_id;
            $periodOrderIdsByTech[$tid][$assignment->service_order_id] = true;
        }
        foreach ($periodOrders as $order) {
            if ($order->assigned_technician_id !== null) {
                $tid = (int) $order->assigned_technician_id;
                if ($scopedTechId === null || $scopedTechId === $tid) {
                    $periodOrderIdsByTech[$tid][$order->id] = true;
                }
            }
            foreach ($order->activeTechnicianAssignments as $activeAssignment) {
                $tid = (int) $activeAssignment->user_id;
                if ($scopedTechId === null || $scopedTechId === $tid) {
                    $periodOrderIdsByTech[$tid][$order->id] = true;
                }
            }
        }

        $ordersById = $periodOrders->keyBy('id');
        $missingOrderIds = [];
        foreach ($periodOrderIdsByTech as $orderSet) {
            foreach (array_keys($orderSet) as $oid) {
                if (! $ordersById->has($oid)) {
                    $missingOrderIds[$oid] = true;
                }
            }
        }
        if ($missingOrderIds !== []) {
            $extraOrders = ServiceOrder::query()
                ->whereIn('id', array_keys($missingOrderIds))
                ->get([
                    'id',
                    'assigned_technician_id',
                    'status',
                    'urgency',
                    'risk_level',
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
            foreach ($extraOrders as $extra) {
                $ordersById->put($extra->id, $extra);
            }
        }

        $sessionsByTech = TechnicianWorkSession::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$period->start, $period->end])
            ->when($scopedTechId !== null, fn ($q) => $q->where('technician_id', $scopedTechId))
            ->get(['technician_id', 'duration_seconds'])
            ->groupBy('technician_id');

        $reopenedByOrderId = OrderStatusEvent::query()
            ->where('status', 'reopened')
            ->whereBetween('occurred_at', [$period->start, $period->end])
            ->pluck('service_order_id')
            ->flip()
            ->all();

        $availabilityLogsByTech = TechnicianAvailabilityLog::query()
            ->whereBetween('created_at', [$period->start, $period->end])
            ->when($scopedTechId !== null, fn ($q) => $q->where('user_id', $scopedTechId))
            ->selectRaw('user_id, COUNT(*) as changes_count')
            ->groupBy('user_id')
            ->pluck('changes_count', 'user_id');

        $rows = [];
        $availabilitySummary = [
            'available' => 0,
            'busy' => 0,
            'on_break' => 0,
            'offline' => 0,
        ];
        $workloadDistribution = [
            'idle' => 0,
            'light' => 0,
            'moderate' => 0,
            'high' => 0,
        ];
        $specialties = [];

        $totalAssignedPeriod = 0;
        $totalCompletedPeriod = 0;
        $totalReopenedPeriod = 0;
        $allResolutionMinutes = [];
        $allWorkMinutes = [];
        $slaRates = [];

        foreach ($technicians as $technician) {
            $live = $this->workforceService->metricsForTechnician($technician);
            $tid = (int) $technician->id;
            $orderIds = array_keys($periodOrderIdsByTech[$tid] ?? []);

            $assignedInPeriod = count($orderIds);
            $completedInPeriod = 0;
            $highRiskCount = 0;
            $resolutionMinutes = [];
            $evaluatedSla = 0;
            $compliantSla = 0;
            $reopenedInPeriod = 0;

            foreach ($orderIds as $oid) {
                $order = $ordersById->get($oid);
                if (! $order instanceof ServiceOrder) {
                    continue;
                }

                if ($order->risk_level === 'high' || $order->urgency === 'critical' || $order->urgency === 'high') {
                    $highRiskCount++;
                }

                if (isset($reopenedByOrderId[$oid])) {
                    $reopenedInPeriod++;
                }

                $sla = $this->slaService->evaluate($order, null, $slaConfig);
                if (in_array($order->status, ExecutiveAnalyticsService::COMPLETED_STATUSES, true)) {
                    $completedInPeriod++;
                    if ($sla['resolution_minutes'] !== null) {
                        $resolutionMinutes[] = (int) $sla['resolution_minutes'];
                        $allResolutionMinutes[] = (int) $sla['resolution_minutes'];
                    }
                }

                if ($order->status !== 'cancelled') {
                    $evaluatedSla++;
                    if ($sla['compliant']) {
                        $compliantSla++;
                    }
                }
            }

            /** @var Collection<int, TechnicianWorkSession> $techSessions */
            $techSessions = $sessionsByTech->get($tid, collect());
            $totalWorkSeconds = (int) $techSessions->sum('duration_seconds');
            $workSessionCount = $techSessions->count();
            $avgWorkMinutes = $workSessionCount > 0 ? round(($totalWorkSeconds / $workSessionCount) / 60, 1) : null;

            foreach ($techSessions as $session) {
                $allWorkMinutes[] = round($session->duration_seconds / 60, 1);
            }

            $periodSlaRate = $evaluatedSla > 0 ? round(($compliantSla / $evaluatedSla) * 100, 1) : null;
            if ($periodSlaRate !== null) {
                $slaRates[] = $periodSlaRate;
            }

            $avgResolution = $resolutionMinutes !== []
                ? round(array_sum($resolutionMinutes) / count($resolutionMinutes), 1)
                : null;

            $highComplexityShare = $assignedInPeriod > 0 ? round(($highRiskCount / $assignedInPeriod) * 100, 1) : 0.0;

            $availability = (string) $live['availability_status'];
            if (isset($availabilitySummary[$availability])) {
                $availabilitySummary[$availability]++;
            }

            $workloadLevel = $live['workload_level'];
            $workloadDistribution[$workloadLevel]++;

            $totalAssignedPeriod += $assignedInPeriod;
            $totalCompletedPeriod += $completedInPeriod;
            $totalReopenedPeriod += $reopenedInPeriod;

            $specialtyKey = trim((string) ($technician->specialty ?: 'Umum'));
            if (! isset($specialties[$specialtyKey])) {
                $specialties[$specialtyKey] = [
                    'specialty' => $specialtyKey,
                    'technicians_count' => 0,
                    'assigned_jobs' => 0,
                    'completed_jobs' => 0,
                    'sla_rates' => [],
                ];
            }
            $specialties[$specialtyKey]['technicians_count']++;
            $specialties[$specialtyKey]['assigned_jobs'] += $assignedInPeriod;
            $specialties[$specialtyKey]['completed_jobs'] += $completedInPeriod;
            if ($periodSlaRate !== null) {
                $specialties[$specialtyKey]['sla_rates'][] = $periodSlaRate;
            }

            $rows[] = [
                'id' => $tid,
                'name' => $technician->name,
                'specialty' => $specialtyKey,
                'is_active' => (bool) $technician->is_active,
                'availability_status' => $availability,
                'availability_label_id' => $live['availability_label_id'],
                'availability_changes_count' => (int) ($availabilityLogsByTech[$tid] ?? 0),
                'assigned_jobs_period' => $assignedInPeriod,
                'completed_jobs_period' => $completedInPeriod,
                'high_complexity_jobs_period' => $highRiskCount,
                'high_complexity_share_pct' => $highComplexityShare,
                'avg_resolution_minutes_period' => $avgResolution,
                'avg_work_session_minutes_period' => $avgWorkMinutes,
                'total_work_hours_period' => round($totalWorkSeconds / 3600, 1),
                'work_sessions_count' => $workSessionCount,
                'sla_compliance_rate_period' => $periodSlaRate,
                'reopened_jobs_period' => $reopenedInPeriod,
                'current_workload' => (int) $live['current_workload'],
                'active_assignments_count' => (int) $live['active_assignments_count'],
                'pending_assignments_count' => (int) $live['pending_assignments_count'],
                'sla_risk_assignments_count' => (int) $live['sla_risk_assignments_count'],
                'workload_level' => $workloadLevel,
                'context_note' => sprintf(
                    '%d order kompleksitas tinggi (%.0f%%), beban aktif saat ini %d tiket.',
                    $highRiskCount,
                    $highComplexityShare,
                    (int) $live['current_workload'],
                ),
            ];
        }

        $specialtyPerformance = array_values(array_map(
            static function (array $row): array {
                $rates = $row['sla_rates'];

                return [
                    'specialty' => $row['specialty'],
                    'technicians_count' => $row['technicians_count'],
                    'assigned_jobs' => $row['assigned_jobs'],
                    'completed_jobs' => $row['completed_jobs'],
                    'avg_sla_compliance_rate' => $rates !== [] ? round(array_sum($rates) / count($rates), 1) : null,
                ];
            },
            $specialties,
        ));

        return [
            'period' => $period->toArray(),
            'is_personal_scope' => $scopedTechId !== null,
            'overview' => [
                'technicians_count' => $technicians->count(),
                'assigned_jobs_period' => $totalAssignedPeriod,
                'completed_jobs_period' => $totalCompletedPeriod,
                'reopened_jobs_period' => $totalReopenedPeriod,
                'avg_resolution_minutes' => $allResolutionMinutes !== []
                    ? round(array_sum($allResolutionMinutes) / count($allResolutionMinutes), 1)
                    : null,
                'avg_work_session_minutes' => $allWorkMinutes !== []
                    ? round(array_sum($allWorkMinutes) / count($allWorkMinutes), 1)
                    : null,
                'avg_sla_compliance_rate' => $slaRates !== []
                    ? round(array_sum($slaRates) / count($slaRates), 1)
                    : null,
            ],
            'availability_distribution' => $availabilitySummary,
            'workload_distribution' => $workloadDistribution,
            'specialty_performance' => $scopedTechId === null ? $specialtyPerformance : [],
            'technicians' => $rows,
        ];
    }
}
