<?php

namespace App\Services\Assistant;

use App\Models\CustomerAsset;
use App\Models\Estimate;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Warranty;
use App\Services\Analytics\AnalyticsAccess;
use App\Services\Analytics\AnalyticsPeriod;
use App\Services\Analytics\BusinessInsightsEngine;
use App\Services\Analytics\CustomerAnalyticsService;
use App\Services\Analytics\ExecutiveAnalyticsService;
use App\Services\Analytics\FinancialAnalyticsService;
use App\Services\Analytics\InventoryAnalyticsService;
use App\Services\Analytics\ServiceSalesAnalyticsService;
use App\Services\Analytics\WorkforceAnalyticsService;
use App\Services\Sla\SlaService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class AnalyticsToolExecutor
{
    public function __construct(
        private AnalyticsAccess $access,
        private ExecutiveAnalyticsService $executiveService,
        private FinancialAnalyticsService $financialService,
        private ServiceSalesAnalyticsService $serviceSalesService,
        private WorkforceAnalyticsService $workforceService,
        private InventoryAnalyticsService $inventoryService,
        private CustomerAnalyticsService $customerService,
        private BusinessInsightsEngine $insightsEngine,
        private SlaService $slaService,
    ) {}

    /**
     * Schema definitions for Phase 4 read-only operational & BI tools.
     *
     * @return array<string, array{
     *     type: 'read',
     *     reasoning_eligible: bool,
     *     description: string,
     *     rules: array<string, list<string>>
     * }>
     */
    public static function schemas(): array
    {
        $periodRules = [
            'period' => ['nullable', 'string', 'in:daily,weekly,monthly,quarterly,yearly,custom'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ];

        return [
            'get_executive_analytics' => [
                'type' => 'read',
                'reasoning_eligible' => true,
                'description' => 'Mengambil KPI eksekutif teragregasi, SLA, dan ringkasan kas/akrual sesuai hak akses.',
                'rules' => $periodRules,
            ],
            'get_financial_analytics' => [
                'type' => 'read',
                'reasoning_eligible' => true,
                'description' => 'Mengambil analitik keuangan akrual vs kas masuk, piutang usaha, laba kotor, dan anomali biaya.',
                'rules' => $periodRules,
            ],
            'get_service_analytics' => [
                'type' => 'read',
                'reasoning_eligible' => true,
                'description' => 'Mengambil analitik permintaan layanan, profitabilitas per layanan, dan konversi quotation.',
                'rules' => $periodRules,
            ],
            'get_workforce_analytics' => [
                'type' => 'read',
                'reasoning_eligible' => true,
                'description' => 'Mengambil performa teknisi, beban kerja aktif, durasi resolusi, dan kepatuhan SLA.',
                'rules' => $periodRules,
            ],
            'get_inventory_analytics' => [
                'type' => 'read',
                'reasoning_eligible' => true,
                'description' => 'Mengambil valuasi aset stok, turnover, item slow/fast-moving, dead-stock, dan indikator restock.',
                'rules' => $periodRules,
            ],
            'get_customer_analytics' => [
                'type' => 'read',
                'reasoning_eligible' => true,
                'description' => 'Mengambil tren pelanggan berulang, perangkat terdaftar, garansi aktif, dan tingkat klaim garansi.',
                'rules' => $periodRules,
            ],
            'get_business_insights' => [
                'type' => 'read',
                'reasoning_eligible' => true,
                'description' => 'Mengambil daftar insight deterministik BusinessInsightsEngine beserta metrik sumber dan rekomendasi.',
                'rules' => [
                    ...$periodRules,
                    'category' => ['nullable', 'string', 'in:sla,service,inventory,finance,workforce,customer'],
                    'severity' => ['nullable', 'string', 'in:critical,warning,info'],
                ],
            ],
            'get_order_details' => [
                'type' => 'read',
                'reasoning_eligible' => true,
                'description' => 'Mengambil detail order servis, status SLA, perangkat, garansi, dan tagihan yang diotorisasi policy.',
                'rules' => [
                    'ticket_number' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
                    'order_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
                ],
            ],
            'get_sla_status' => [
                'type' => 'read',
                'reasoning_eligible' => true,
                'description' => 'Mengambil status risiko SLA (breached, mendekati batas, paused, on-track) pada tiket sesuai cakupan peran.',
                'rules' => $periodRules,
            ],
            'get_inventory_alerts' => [
                'type' => 'read',
                'reasoning_eligible' => true,
                'description' => 'Mengambil daftar SKU kritis perlu restock serta kandidat dead-stock untuk pengadaan gudang.',
                'rules' => $periodRules,
            ],
        ];
    }

    /**
     * Execute one of the Phase 4 read-only tools. Caller handles validation and audit recording.
     *
     * @param  array<string, mixed>  $params
     * @return array{
     *     authorized: bool,
     *     tool: string,
     *     reasoning_eligible: bool,
     *     message: string,
     *     actions: list<array{label: string, href: string}>,
     *     evidence: array<string, mixed>|null
     * }|null
     */
    public function execute(string $tool, ?User $user, array $params = [], string $normalizedMessage = ''): ?array
    {
        $period = AnalyticsPeriod::resolve(
            preset: isset($params['period']) && is_string($params['period']) ? $params['period'] : 'monthly',
            startDate: isset($params['start_date']) && is_string($params['start_date']) ? $params['start_date'] : null,
            endDate: isset($params['end_date']) && is_string($params['end_date']) ? $params['end_date'] : null,
        );

        return match ($tool) {
            'get_executive_analytics' => $this->runExecutive($user, $period),
            'get_financial_analytics' => $this->runFinancial($user, $period),
            'get_service_analytics' => $this->runServices($user, $period),
            'get_workforce_analytics' => $this->runWorkforce($user, $period),
            'get_inventory_analytics' => $this->runInventory($user, $period),
            'get_customer_analytics' => $this->runCustomers($user, $period),
            'get_business_insights' => $this->runInsights($user, $period, $params),
            'get_order_details' => $this->runOrderDetails($user, $params, $normalizedMessage),
            'get_sla_status' => $this->runSlaStatus($user, $period),
            'get_inventory_alerts' => $this->runInventoryAlerts($user, $period),
            default => null,
        };
    }

    /**
     * @return array{authorized: bool, tool: string, reasoning_eligible: bool, message: string, actions: list<array{label: string, href: string}>, evidence: array<string, mixed>|null}
     */
    private function runExecutive(?User $user, AnalyticsPeriod $period): array
    {
        if ($user === null || ! $this->access->canViewSection($user, 'executive')) {
            return $this->denied('get_executive_analytics', 'Ringkasan analitik eksekutif hanya tersedia untuk Supervisor, Admin Operasional, atau Superadmin.');
        }

        $data = $this->executiveService->summary($period, $user);
        $kpis = $data['kpis'];
        $totalOrders = (int) ($kpis['total_orders']['value'] ?? 0);
        $completedOrders = (int) ($kpis['completed_orders']['value'] ?? 0);
        $activeOrders = (int) ($kpis['active_orders']['value'] ?? 0);
        $completionRate = $kpis['completion_rate']['value'];
        $slaRate = $kpis['sla_compliance_rate']['value'];
        $invoiced = (int) ($kpis['invoiced_revenue']['value'] ?? 0);
        $cash = (int) ($kpis['cash_received']['value'] ?? 0);
        $grossProfit = (int) ($kpis['gross_profit_accrual']['value'] ?? 0);
        $receivables = (int) ($kpis['outstanding_receivables']['value'] ?? 0);
        $invVal = (int) ($kpis['inventory_valuation']['value'] ?? 0);

        $periodHint = $this->outsidePeriodHintIfEmpty($totalOrders, $cash, $invoiced, $period);
        $slaText = $slaRate !== null ? number_format((float) $slaRate, 1).'%' : 'belum ada sampel';
        $compText = $completionRate !== null ? number_format((float) $completionRate, 1).'%' : '0%';

        $message = sprintf(
            'Ringkasan eksekutif (%s): %d total order (%d aktif, %d selesai, tingkat penyelesaian %s), kepatuhan SLA %s. Pendapatan akrual (invoice): %s, kas masuk terverifikasi: %s, laba kotor akrual: %s, piutang belum dibayar: %s, valuasi stok saat ini: %s.%s',
            $period->label(),
            $totalOrders,
            $activeOrders,
            $completedOrders,
            $compText,
            $slaText,
            $this->formatIdr($invoiced),
            $this->formatIdr($cash),
            $this->formatIdr($grossProfit),
            $this->formatIdr($receivables),
            $this->formatIdr($invVal),
            $periodHint,
        );

        return [
            'authorized' => true,
            'tool' => 'get_executive_analytics',
            'reasoning_eligible' => true,
            'message' => $message,
            'actions' => [['label' => 'Buka Analitik & BI', 'href' => route('analytics.index', ['section' => 'executive', 'period' => $period->preset], absolute: false)]],
            'evidence' => [
                'verified' => true,
                'source' => 'ExecutiveAnalyticsService',
                'period_label' => $period->label(),
                'comparison_label' => $period->comparisonLabel(),
                'generated_at' => $this->nowIso($period->timezone),
                'data_insufficient' => $totalOrders === 0 && $cash === 0 && $invoiced === 0,
                'facts' => [
                    ['label' => 'Total Order', 'value' => (string) $totalOrders],
                    ['label' => 'Kepatuhan SLA', 'value' => $slaRate !== null ? number_format((float) $slaRate, 1).'%' : 'N/A'],
                    ['label' => 'Pendapatan Akrual', 'value' => $this->formatIdr($invoiced)],
                    ['label' => 'Kas Masuk Terverifikasi', 'value' => $this->formatIdr($cash)],
                    ['label' => 'Laba Kotor (Akrual)', 'value' => $this->formatIdr($grossProfit)],
                    ['label' => 'Piutang (AR)', 'value' => $this->formatIdr($receivables)],
                    ['label' => 'Valuasi Stok', 'value' => $this->formatIdr($invVal)],
                ],
                'raw_metrics' => $kpis,
                'recommendations' => array_values(array_filter([
                    $receivables > 0 ? 'Tindak lanjuti invoice jatuh tempo agar piutang segera terealisasi ke kas.' : null,
                    $periodHint !== '' ? 'Gunakan rentang 90 Hari atau 12 Bulan bila ingin meninjau data historis.' : null,
                ])),
            ],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, reasoning_eligible: bool, message: string, actions: list<array{label: string, href: string}>, evidence: array<string, mixed>|null}
     */
    private function runFinancial(?User $user, AnalyticsPeriod $period): array
    {
        if ($user === null || ! $this->access->canViewSection($user, 'financial')) {
            return $this->denied('get_financial_analytics', 'Analitik keuangan dan margin hanya dapat diakses oleh Supervisor (ringkasan), Admin Operasional, atau Superadmin.');
        }

        $report = $this->financialService->report($period, $user, 1, 5);
        $summary = $report['summary'];
        $aging = $report['receivables_aging'];

        $invoiced = (int) ($summary['invoiced_revenue'] ?? 0);
        $cash = (int) ($summary['cash_received'] ?? 0);
        $receivables = (int) ($summary['outstanding_receivables'] ?? 0);
        $overdue = (int) ($summary['overdue_receivables'] ?? 0);
        $cogs = (int) ($summary['spare_part_cogs'] ?? 0);
        $grossProfit = (int) ($summary['gross_profit_accrual'] ?? 0);
        $netCash = (int) ($summary['net_cash_flow'] ?? 0);
        $pendingCount = (int) ($summary['pending_transactions_count'] ?? 0);

        $gap = $invoiced - $cash;
        $causeNote = match (true) {
            $invoiced === 0 && $cash > 0 => "Kas masuk ({$this->formatIdr($cash)}) berasal dari transaksi pembayaran terverifikasi meski tidak ada invoice non-draft yang diterbitkan pada periode ini.",
            $invoiced > $cash => "Pendapatan akrual lebih tinggi {$this->formatIdr($gap)} daripada kas masuk karena sebagian invoice masih berstatus belum lunas/tercicil (total piutang saat ini {$this->formatIdr($receivables)}) atau ada {$pendingCount} transaksi menunggu verifikasi.",
            $cash > $invoiced => "Kas masuk lebih besar {$this->formatIdr(abs($gap))} daripada pendapatan akrual periode ini karena pelunasan piutang dari periode sebelumnya atau pencatatan transaksi kas langsung.",
            default => 'Pendapatan akrual dan kas masuk terverifikasi berada pada nilai yang seimbang untuk periode ini.',
        };

        $rawNote = $report['can_view_raw_ledger'] && $summary['operational_expenses'] !== null
            ? ' Beban operasional: '.$this->formatIdr((int) $summary['operational_expenses']).'.'
            : ' Rincian ledger pengeluaran mentah disembunyikan untuk peran oversight.';

        $message = sprintf(
            'Analitik keuangan (%s): Pendapatan akrual %s vs kas masuk terverifikasi %s. %s Laba kotor akrual %s (HPP spare-part %s), arus kas bersih %s, piutang belum dibayar %s (jatuh tempo %s; %d inv overdue).%s',
            $period->label(),
            $this->formatIdr($invoiced),
            $this->formatIdr($cash),
            $causeNote,
            $this->formatIdr($grossProfit),
            $this->formatIdr($cogs),
            $this->formatIdr($netCash),
            $this->formatIdr($receivables),
            $this->formatIdr($overdue),
            (int) ($aging['overdue_count'] ?? 0),
            $rawNote,
        );

        return [
            'authorized' => true,
            'tool' => 'get_financial_analytics',
            'reasoning_eligible' => true,
            'message' => $message,
            'actions' => [['label' => 'Buka Analitik Keuangan', 'href' => route('analytics.index', ['section' => 'financial', 'period' => $period->preset], absolute: false)]],
            'evidence' => [
                'verified' => true,
                'source' => 'FinancialAnalyticsService',
                'period_label' => $period->label(),
                'generated_at' => $this->nowIso($period->timezone),
                'data_insufficient' => $invoiced === 0 && $cash === 0 && $receivables === 0,
                'facts' => [
                    ['label' => 'Pendapatan Akrual', 'value' => $this->formatIdr($invoiced)],
                    ['label' => 'Kas Masuk Terverifikasi', 'value' => $this->formatIdr($cash)],
                    ['label' => 'Piutang Belum Dibayar', 'value' => $this->formatIdr($receivables)],
                    ['label' => 'Laba Kotor (Akrual)', 'value' => $this->formatIdr($grossProfit)],
                    ['label' => 'Arus Kas Bersih', 'value' => $this->formatIdr($netCash)],
                    ['label' => 'Transaksi Pending', 'value' => (string) $pendingCount],
                ],
                'raw_metrics' => [
                    'invoiced_revenue' => $invoiced,
                    'cash_received' => $cash,
                    'outstanding_receivables' => $receivables,
                    'overdue_receivables' => $overdue,
                    'gross_profit_accrual' => $grossProfit,
                    'net_cash_flow' => $netCash,
                    'spare_part_cogs' => $cogs,
                    'pending_transactions_count' => $pendingCount,
                    'can_view_raw_ledger' => $report['can_view_raw_ledger'],
                ],
                'recommendations' => array_values(array_filter([
                    $overdue > 0 ? 'Prioritaskan penagihan invoice yang telah melewati jatuh tempo.' : null,
                    $pendingCount > 0 ? 'Verifikasi transaksi keuangan berstatus pending agar laporan kas mutakhir.' : null,
                    $invoiced === 0 && $cash > 0 ? 'Terbitkan invoice resmi pada order terkait agar pelaporan akrual dan kas konsisten.' : null,
                ])),
            ],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, reasoning_eligible: bool, message: string, actions: list<array{label: string, href: string}>, evidence: array<string, mixed>|null}
     */
    private function runServices(?User $user, AnalyticsPeriod $period): array
    {
        if ($user === null || ! $this->access->canViewSection($user, 'services')) {
            return $this->denied('get_service_analytics', 'Analitik performa dan margin layanan hanya dapat diakses oleh Supervisor, Admin Operasional, atau Superadmin.');
        }

        $data = $this->serviceSalesService->summary($period);
        $overview = $data['overview'];
        $profitable = array_values(array_filter(
            $data['most_profitable'],
            fn (array $row): bool => (int) $row['requests_count'] > 0 || (int) $row['invoiced_revenue'] > 0,
        ));

        if ((int) $overview['total_requests'] === 0 && (int) $overview['total_quotations'] === 0) {
            return [
                'authorized' => true,
                'tool' => 'get_service_analytics',
                'reasoning_eligible' => true,
                'message' => sprintf(
                    'Pada periode %s tercatat 0 permintaan layanan dan 0 quotation baru, sehingga layanan paling menguntungkan belum dapat ditentukan pada rentang ini.%s',
                    $period->label(),
                    $this->outsidePeriodHintIfEmpty(0, 0, 0, $period),
                ),
                'actions' => [['label' => 'Buka Analitik Layanan', 'href' => route('analytics.index', ['section' => 'services', 'period' => $period->preset], absolute: false)]],
                'evidence' => [
                    'verified' => true,
                    'source' => 'ServiceSalesAnalyticsService',
                    'period_label' => $period->label(),
                    'generated_at' => $this->nowIso($period->timezone),
                    'data_insufficient' => true,
                    'facts' => [
                        ['label' => 'Total Permintaan', 'value' => '0'],
                        ['label' => 'Total Quotation', 'value' => '0'],
                    ],
                ],
            ];
        }

        usort($profitable, fn (array $a, array $b): int => ((int) $b['gross_profit']) <=> ((int) $a['gross_profit']));
        $topProfit = $profitable[0] ?? null;

        $mostRequested = $data['most_requested'];
        usort($mostRequested, fn (array $a, array $b): int => ((int) $b['requests_count']) <=> ((int) $a['requests_count']));
        $topRequested = $mostRequested[0] ?? null;

        $approvalRate = $overview['quotation_approval_rate'] !== null
            ? number_format((float) $overview['quotation_approval_rate'], 1).'%'
            : 'N/A';

        $topProfitText = $topProfit !== null
            ? sprintf(
                'Layanan profit tertinggi: %s (laba kotor %s, pendapatan %s, margin %s, %d permintaan).',
                $topProfit['name'],
                $this->formatIdr((int) $topProfit['gross_profit']),
                $this->formatIdr((int) $topProfit['invoiced_revenue']),
                $topProfit['margin_pct'] !== null ? number_format((float) $topProfit['margin_pct'], 1).'%' : 'N/A',
                (int) $topProfit['requests_count'],
            )
            : 'Belum ada pendapatan invoice layanan pada periode ini.';

        $topReqText = $topRequested !== null && (int) $topRequested['requests_count'] > 0
            ? sprintf(' Paling diminati: %s (%d order).', $topRequested['name'], (int) $topRequested['requests_count'])
            : '';

        $message = sprintf(
            'Analitik layanan (%s): Total %d permintaan layanan, rasio persetujuan quotation %s (%d quotation). %s%s',
            $period->label(),
            (int) $overview['total_requests'],
            $approvalRate,
            (int) $overview['total_quotations'],
            $topProfitText,
            $topReqText,
        );

        $allZeroRevenue = true;
        foreach ($profitable as $row) {
            if ((int) $row['invoiced_revenue'] > 0) {
                $allZeroRevenue = false;
                break;
            }
        }

        return [
            'authorized' => true,
            'tool' => 'get_service_analytics',
            'reasoning_eligible' => true,
            'message' => $message,
            'actions' => [['label' => 'Buka Analitik Layanan', 'href' => route('analytics.index', ['section' => 'services', 'period' => $period->preset], absolute: false)]],
            'evidence' => [
                'verified' => true,
                'source' => 'ServiceSalesAnalyticsService',
                'period_label' => $period->label(),
                'generated_at' => $this->nowIso($period->timezone),
                'data_insufficient' => $allZeroRevenue,
                'facts' => [
                    ['label' => 'Total Permintaan', 'value' => (string) $overview['total_requests']],
                    ['label' => 'Approval Quotation', 'value' => $approvalRate],
                    ['label' => 'Layanan Teratas', 'value' => (string) ($topRequested['name'] ?? '—')],
                ],
                'raw_metrics' => [
                    'overview' => $overview,
                    'top_profitable' => array_slice($profitable, 0, 3),
                ],
            ],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, reasoning_eligible: bool, message: string, actions: list<array{label: string, href: string}>, evidence: array<string, mixed>|null}
     */
    private function runWorkforce(?User $user, AnalyticsPeriod $period): array
    {
        if ($user === null || ! $this->access->canViewSection($user, 'workforce')) {
            return $this->denied('get_workforce_analytics', 'Analitik kinerja teknisi hanya tersedia untuk Teknisi (data pribadi), Supervisor, Admin Operasional, dan Superadmin.');
        }

        $data = $this->workforceService->summary($period, $user);
        $overview = $data['overview'];
        $techs = $data['technicians'];
        $slaText = $overview['avg_sla_compliance_rate'] !== null ? number_format((float) $overview['avg_sla_compliance_rate'], 1).'%' : 'belum ada sampel';
        $resText = $overview['avg_resolution_minutes'] !== null ? round((float) $overview['avg_resolution_minutes']).' menit' : 'N/A';

        $scopeLabel = $data['is_personal_scope'] ? 'Performa pribadi Anda' : "Performa tim ({$overview['technicians_count']} teknisi)";
        $summaryRows = implode('; ', array_map(
            fn (array $t): string => sprintf(
                '%s: %d selesai / %d ditugaskan, beban aktif %d tiket, status %s',
                $t['name'],
                (int) $t['completed_jobs_period'],
                (int) $t['assigned_jobs_period'],
                (int) $t['current_workload'],
                $t['availability_label_id'],
            ),
            array_slice($techs, 0, 4),
        ));

        $message = sprintf(
            '%s (%s): %d tugas periode ini (%d selesai, %d dibuka ulang), rata-rata SLA %s, rata-rata resolusi %s.%s',
            $scopeLabel,
            $period->label(),
            (int) $overview['assigned_jobs_period'],
            (int) $overview['completed_jobs_period'],
            (int) $overview['reopened_jobs_period'],
            $slaText,
            $resText,
            $summaryRows !== '' ? " Rincian: {$summaryRows}." : '',
        );

        return [
            'authorized' => true,
            'tool' => 'get_workforce_analytics',
            'reasoning_eligible' => true,
            'message' => $message,
            'actions' => [['label' => 'Buka Analitik Workforce', 'href' => route('analytics.index', ['section' => 'workforce', 'period' => $period->preset], absolute: false)]],
            'evidence' => [
                'verified' => true,
                'source' => 'WorkforceAnalyticsService',
                'period_label' => $period->label(),
                'generated_at' => $this->nowIso($period->timezone),
                'facts' => [
                    ['label' => 'Cakupan', 'value' => $data['is_personal_scope'] ? 'Pribadi' : 'Tim Teknisi'],
                    ['label' => 'Tugas Periode', 'value' => (string) $overview['assigned_jobs_period']],
                    ['label' => 'Selesai', 'value' => (string) $overview['completed_jobs_period']],
                    ['label' => 'Kepatuhan SLA', 'value' => $slaText],
                ],
                'raw_metrics' => [
                    'is_personal_scope' => $data['is_personal_scope'],
                    'overview' => $overview,
                    'technicians' => array_slice($techs, 0, 5),
                ],
            ],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, reasoning_eligible: bool, message: string, actions: list<array{label: string, href: string}>, evidence: array<string, mixed>|null}
     */
    private function runInventory(?User $user, AnalyticsPeriod $period): array
    {
        if ($user === null || ! $this->access->canViewSection($user, 'inventory')) {
            return $this->denied('get_inventory_analytics', 'Analitik inventaris gudang hanya tersedia untuk Gudang, Admin Operasional, atau Superadmin.');
        }

        $data = $this->inventoryService->summary($period, $user);
        $overview = $data['overview'];
        $deadStock = $data['dead_stock_candidates'];
        $reorder = $data['reorder_indicators'];

        $turnover = $overview['stock_turnover_ratio'] !== null ? number_format((float) $overview['stock_turnover_ratio'], 2).'x' : 'N/A';
        $reorderSummary = $reorder !== []
            ? ' Prioritas restock: '.implode(', ', array_map(
                fn (array $r): string => "{$r['sku']} {$r['name']} ({$r['quantity_on_hand']}/min {$r['reorder_level']})",
                array_slice($reorder, 0, 4),
            )).'.'
            : '';
        $message = sprintf(
            'Analitik inventaris (%s): %d SKU aktif (%d unit, valuasi aset WAC %s), turnover %s, %d stok menipis, %d stok habis, dan %d kandidat dead-stock (tanpa pergerakan keluar >= 60 hari).%s',
            $period->label(),
            (int) $overview['item_types'],
            (int) $overview['units_on_hand'],
            $this->formatIdr((int) $overview['asset_value']),
            $turnover,
            (int) $overview['low_stock_count'],
            (int) $overview['out_of_stock_count'],
            count($deadStock),
            $reorderSummary,
        );

        return [
            'authorized' => true,
            'tool' => 'get_inventory_analytics',
            'reasoning_eligible' => true,
            'message' => $message,
            'actions' => [['label' => 'Buka Analitik Inventori', 'href' => route('analytics.index', ['section' => 'inventory', 'period' => $period->preset], absolute: false)]],
            'evidence' => [
                'verified' => true,
                'source' => 'InventoryAnalyticsService',
                'period_label' => $period->label(),
                'generated_at' => $this->nowIso($period->timezone),
                'facts' => [
                    ['label' => 'Valuasi Stok (WAC)', 'value' => $this->formatIdr((int) $overview['asset_value'])],
                    ['label' => 'Stok Menipis / Habis', 'value' => sprintf('%d / %d', (int) $overview['low_stock_count'], (int) $overview['out_of_stock_count'])],
                    ['label' => 'Kandidat Dead-Stock', 'value' => (string) count($deadStock)],
                    ['label' => 'Turnover Stok', 'value' => $turnover],
                ],
                'raw_metrics' => [
                    'overview' => $overview,
                    'reorder_indicators' => array_slice($reorder, 0, 6),
                    'dead_stock_candidates' => array_slice($deadStock, 0, 6),
                ],
            ],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, reasoning_eligible: bool, message: string, actions: list<array{label: string, href: string}>, evidence: array<string, mixed>|null}
     */
    private function runCustomers(?User $user, AnalyticsPeriod $period): array
    {
        if ($user === null || ! $this->access->canViewSection($user, 'customers')) {
            return $this->denied('get_customer_analytics', 'Analitik pelanggan dan garansi hanya tersedia untuk Supervisor, Admin Operasional, atau Superadmin.');
        }

        $data = $this->customerService->summary($period, $user);
        $ov = $data['overview'];
        $repeatRate = $ov['repeat_service_rate'] !== null ? number_format((float) $ov['repeat_service_rate'], 1).'%' : 'belum ada sampel';
        $claimRate = $ov['warranty_claim_rate_pct'] !== null ? number_format((float) $ov['warranty_claim_rate_pct'], 1).'%' : 'N/A';
        $piiNote = $data['can_view_pii'] ? '' : ' Identitas pribadi pelanggan disamarkan sesuai hak akses oversight.';

        $message = sprintf(
            'Tren pelanggan & garansi (%s): %d pelanggan aktif memesan (%d baru, %d pelanggan berulang; repeat service rate %s), total %d pelanggan terdaftar. Perangkat terdaftar: %d unit, garansi aktif: %d, klaim garansi periode ini: %d (rasio klaim %s).%s',
            $period->label(),
            (int) ($ov['active_ordering_customers_period'] ?? 0),
            (int) ($ov['new_ordering_customers_period'] ?? 0),
            (int) ($ov['returning_customers_period'] ?? 0),
            $repeatRate,
            (int) ($ov['total_registered_customers'] ?? 0),
            (int) ($ov['registered_devices_total'] ?? 0),
            (int) ($ov['active_warranties_total'] ?? 0),
            (int) ($ov['warranty_claims_period'] ?? 0),
            $claimRate,
            $piiNote,
        );

        return [
            'authorized' => true,
            'tool' => 'get_customer_analytics',
            'reasoning_eligible' => true,
            'message' => $message,
            'actions' => [['label' => 'Buka Analitik Pelanggan', 'href' => route('analytics.index', ['section' => 'customers', 'period' => $period->preset], absolute: false)]],
            'evidence' => [
                'verified' => true,
                'source' => 'CustomerAnalyticsService',
                'period_label' => $period->label(),
                'generated_at' => $this->nowIso($period->timezone),
                'data_insufficient' => (int) ($ov['active_ordering_customers_period'] ?? 0) === 0,
                'facts' => [
                    ['label' => 'Pelanggan Aktif', 'value' => (string) ($ov['active_ordering_customers_period'] ?? 0)],
                    ['label' => 'Pelanggan Berulang', 'value' => (string) ($ov['returning_customers_period'] ?? 0)],
                    ['label' => 'Repeat Rate', 'value' => $repeatRate],
                    ['label' => 'Garansi Aktif', 'value' => (string) ($ov['active_warranties_total'] ?? 0)],
                ],
                'raw_metrics' => $ov,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{authorized: bool, tool: string, reasoning_eligible: bool, message: string, actions: list<array{label: string, href: string}>, evidence: array<string, mixed>|null}
     */
    private function runInsights(?User $user, AnalyticsPeriod $period, array $params): array
    {
        if ($user === null || ! $this->access->canViewSection($user, 'insights')) {
            return $this->denied('get_business_insights', 'Insight operasional dan bisnis hanya dapat diakses oleh peran internal AD Com yang terautentikasi.');
        }

        $insights = collect($this->insightsEngine->generate($period, $user));
        if (! empty($params['category']) && is_string($params['category'])) {
            $insights = $insights->where('category', $params['category'])->values();
        }
        if (! empty($params['severity']) && is_string($params['severity'])) {
            $insights = $insights->where('severity', $params['severity'])->values();
        }

        if ($insights->isEmpty()) {
            return [
                'authorized' => true,
                'tool' => 'get_business_insights',
                'reasoning_eligible' => true,
                'message' => sprintf(
                    'Tidak ada anomali atau peringatan deterministik yang melampaui ambang batas pada kategori izin Anda untuk periode %s.',
                    $period->label(),
                ),
                'actions' => [['label' => 'Buka Analitik & Insight', 'href' => route('analytics.index', ['section' => 'insights', 'period' => $period->preset], absolute: false)]],
                'evidence' => [
                    'verified' => true,
                    'source' => 'BusinessInsightsEngine',
                    'period_label' => $period->label(),
                    'generated_at' => $this->nowIso($period->timezone),
                    'facts' => [['label' => 'Insight Aktif', 'value' => '0']],
                    'insights' => [],
                ],
            ];
        }

        $counts = [
            'critical' => $insights->where('severity', 'critical')->count(),
            'warning' => $insights->where('severity', 'warning')->count(),
            'info' => $insights->where('severity', 'info')->count(),
        ];

        $topItems = $insights->take(3)->map(fn (array $item): string => sprintf(
            '[%s] %s: %s Rekomendasi: %s',
            strtoupper((string) $item['severity']),
            $item['title'],
            $item['description'],
            $item['recommended_action'],
        ))->implode(' ');

        $message = sprintf(
            'Ditemukan %d insight (%d kritis, %d peringatan, %d info) pada %s. %s',
            $insights->count(),
            $counts['critical'],
            $counts['warning'],
            $counts['info'],
            $period->label(),
            $topItems,
        );

        return [
            'authorized' => true,
            'tool' => 'get_business_insights',
            'reasoning_eligible' => true,
            'message' => $message,
            'actions' => [['label' => 'Lihat Semua Insight', 'href' => route('analytics.index', ['section' => 'insights', 'period' => $period->preset], absolute: false)]],
            'evidence' => [
                'verified' => true,
                'source' => 'BusinessInsightsEngine',
                'period_label' => $period->label(),
                'generated_at' => $this->nowIso($period->timezone),
                'facts' => [
                    ['label' => 'Total Insight', 'value' => (string) $insights->count()],
                    ['label' => 'Kritis / Peringatan', 'value' => sprintf('%d / %d', $counts['critical'], $counts['warning'])],
                ],
                'insights' => $insights->take(6)->map(fn (array $item): array => [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'severity' => $item['severity'],
                    'category' => $item['category'],
                    'reason' => $item['description'],
                    'supporting_metrics' => $item['supporting_metrics'],
                    'recommended_action' => $item['recommended_action'],
                    'source_label' => $item['source_label'],
                ])->values()->all(),
                'recommendations' => $insights->take(4)->pluck('recommended_action')->values()->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{authorized: bool, tool: string, reasoning_eligible: bool, message: string, actions: list<array{label: string, href: string}>, evidence: array<string, mixed>|null}
     */
    private function runOrderDetails(?User $user, array $params, string $normalizedMessage): array
    {
        if ($user === null || ! $user->is_active) {
            return $this->denied('get_order_details', 'Silakan masuk ke akun AD Com Anda untuk melihat detail pesanan atau perangkat terdaftar.');
        }

        if ($user->role === 'warehouse') {
            return $this->denied('get_order_details', 'Peran Gudang hanya memiliki akses ke data stok dan pergerakan inventaris, bukan detail pesanan pelanggan.');
        }

        $ticketRef = isset($params['ticket_number']) && is_string($params['ticket_number']) ? strtoupper(trim($params['ticket_number'])) : null;
        $orderIdRef = isset($params['order_id']) && is_string($params['order_id']) ? trim($params['order_id']) : null;

        if ($ticketRef === null && preg_match('/\b(ADC[- ]?[A-Z0-9-]+)\b/i', $normalizedMessage, $m) === 1) {
            $ticketRef = strtoupper(str_replace(' ', '-', $m[1]));
        }

        $order = null;
        if ($ticketRef !== null || $orderIdRef !== null) {
            $order = ServiceOrder::query()
                ->with(['service:id,code,name', 'assignedTechnician:id,name', 'device:id,name,category,serial_number', 'warranties', 'estimates', 'invoices'])
                ->when($orderIdRef !== null, fn (Builder $q) => $q->where('id', $orderIdRef))
                ->when($ticketRef !== null, fn (Builder $q) => $q->orWhereRaw('UPPER(ticket_number) = ?', [$ticketRef]))
                ->first();

            if ($order === null) {
                return [
                    'authorized' => true,
                    'tool' => 'get_order_details',
                    'reasoning_eligible' => true,
                    'message' => 'Nomor tiket atau order tersebut tidak ditemukan di database AD Com.',
                    'actions' => [['label' => 'Buka Daftar Pesanan', 'href' => route('orders.index', absolute: false)]],
                    'evidence' => null,
                ];
            }

            if (Gate::forUser($user)->denies('view', $order)) {
                return $this->denied('get_order_details', 'Anda tidak memiliki hak akses untuk melihat detail order tersebut.');
            }
        } else {
            // Return latest authorized order/asset/warranty overview for viewer scope.
            if ($user->role === 'customer') {
                return $this->customerPortfolioOverview($user);
            }

            $order = ServiceOrder::query()
                ->with(['service:id,code,name', 'assignedTechnician:id,name', 'device:id,name,category,serial_number', 'warranties', 'estimates', 'invoices'])
                ->when($user->role === 'technician', function (Builder $q) use ($user): void {
                    $q->where(function (Builder $inner) use ($user): void {
                        $inner->where('assigned_technician_id', $user->id)
                            ->orWhereHas('activeTechnicianAssignments', fn (Builder $a) => $a->where('user_id', $user->id));
                    });
                })
                ->latest()
                ->first();

            if ($order === null) {
                return [
                    'authorized' => true,
                    'tool' => 'get_order_details',
                    'reasoning_eligible' => true,
                    'message' => 'Belum ada order yang tersedia dalam cakupan akun Anda.',
                    'actions' => [['label' => 'Buka Pesanan', 'href' => route('orders.index', absolute: false)]],
                    'evidence' => null,
                ];
            }
        }

        $sla = $this->slaService->evaluate($order);
        $latestQuote = $order->estimates->sortByDesc('id')->first();
        $latestInvoice = $order->invoices->sortByDesc('id')->first();
        $latestWarranty = $order->warranties->first();

        $canSeeInvoice = $latestInvoice instanceof Invoice && Gate::forUser($user)->allows('view', $latestInvoice);
        $canSeeQuote = $latestQuote instanceof Estimate && Gate::forUser($user)->allows('view', $latestQuote);
        $customerLabel = $this->access->formatCustomerLabel($order->customer_name, $order->email, $user, $order->customer_id);

        $parts = [
            "Order {$order->ticket_number} ({$order->service?->name})",
            "status {$order->status}",
            "SLA {$sla['label_id']}",
            $order->assignedTechnician ? "teknisi {$order->assignedTechnician->name}" : 'belum ada PIC teknisi',
        ];
        if ($user->role !== 'customer') {
            $parts[] = "pelanggan {$customerLabel}";
        }
        if ($order->device !== null) {
            $parts[] = "perangkat {$order->device->name}";
        }
        if ($latestWarranty !== null) {
            $parts[] = 'garansi '.$latestWarranty->resolvedStatus();
        }
        if ($canSeeQuote) {
            $parts[] = 'penawaran '.$latestQuote->resolvedStatus().' ('.$this->formatIdr((int) $latestQuote->total).')';
        }
        if ($canSeeInvoice) {
            $remaining = max(0, (int) $latestInvoice->total_amount - (int) $latestInvoice->paid_amount);
            $parts[] = "invoice {$latestInvoice->status} (sisa tagihan {$this->formatIdr($remaining)})";
        }

        return [
            'authorized' => true,
            'tool' => 'get_order_details',
            'reasoning_eligible' => true,
            'message' => implode(', ', $parts).'.',
            'actions' => [['label' => "Lihat {$order->ticket_number}", 'href' => route('orders.show', ['order' => $order->id], absolute: false)]],
            'evidence' => [
                'verified' => true,
                'source' => 'ServiceOrder + SlaService',
                'generated_at' => $this->nowIso(),
                'facts' => array_values(array_filter([
                    ['label' => 'Nomor Tiket', 'value' => $order->ticket_number],
                    ['label' => 'Status', 'value' => $order->status],
                    ['label' => 'Status SLA', 'value' => $sla['label_id']],
                    $latestWarranty !== null ? ['label' => 'Garansi', 'value' => $latestWarranty->resolvedStatus()] : null,
                    $canSeeInvoice ? ['label' => 'Status Tagihan', 'value' => $latestInvoice->status] : null,
                ])),
            ],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, reasoning_eligible: bool, message: string, actions: list<array{label: string, href: string}>, evidence: array<string, mixed>|null}
     */
    private function customerPortfolioOverview(User $customer): array
    {
        $orders = ServiceOrder::query()
            ->with('service:id,name')
            ->where('customer_id', $customer->id)
            ->latest()
            ->limit(5)
            ->get(['id', 'ticket_number', 'service_id', 'status']);

        $devicesCount = CustomerAsset::query()->where('customer_id', $customer->id)->count();
        $warranties = Warranty::query()
            ->where('customer_id', $customer->id)
            ->latest()
            ->limit(5)
            ->get(['id', 'status', 'expires_at']);

        $activeWarranties = $warranties->filter(fn (Warranty $w): bool => $w->resolvedStatus() === 'active')->count();
        $orderList = $orders->map(fn (ServiceOrder $o): string => "{$o->ticket_number} ({$o->status})")->implode(', ');

        $message = $orders->isEmpty() && $devicesCount === 0
            ? 'Akun Anda belum memiliki pesanan servis, perangkat terdaftar, maupun garansi aktif saat ini.'
            : sprintf(
                'Ringkasan akun Anda: %d pesanan terbaru%s, %d perangkat terdaftar, dan %d garansi aktif.',
                $orders->count(),
                $orderList !== '' ? " ({$orderList})" : '',
                $devicesCount,
                $activeWarranties,
            );

        return [
            'authorized' => true,
            'tool' => 'get_order_details',
            'reasoning_eligible' => true,
            'message' => $message,
            'actions' => [
                ['label' => 'Pesanan Saya', 'href' => route('orders.index', absolute: false)],
                ['label' => 'Garansi Saya', 'href' => route('warranties.index', absolute: false)],
            ],
            'evidence' => [
                'verified' => true,
                'source' => 'Customer Portfolio (ServiceOrderPolicy + Warranty)',
                'generated_at' => $this->nowIso(),
                'facts' => [
                    ['label' => 'Pesanan Terbaru', 'value' => (string) $orders->count()],
                    ['label' => 'Perangkat Terdaftar', 'value' => (string) $devicesCount],
                    ['label' => 'Garansi Aktif', 'value' => (string) $activeWarranties],
                ],
            ],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, reasoning_eligible: bool, message: string, actions: list<array{label: string, href: string}>, evidence: array<string, mixed>|null}
     */
    private function runSlaStatus(?User $user, AnalyticsPeriod $period): array
    {
        if ($user === null || ! in_array($user->role, ['operator', 'admin', 'supervisor', 'technician'], true) || ! $user->is_active) {
            return $this->denied('get_sla_status', 'Pemantauan risiko SLA hanya tersedia untuk Teknisi (tiket tugas sendiri), Supervisor, Admin Operasional, dan Superadmin.');
        }

        $scopedTechId = $this->access->scopedTechnicianId($user);

        $openQuery = ServiceOrder::query()->whereNotIn('status', SlaService::TERMINAL_STATUSES);
        if ($scopedTechId !== null) {
            $openQuery->where(function (Builder $q) use ($scopedTechId): void {
                $q->where('assigned_technician_id', $scopedTechId)
                    ->orWhereHas('activeTechnicianAssignments', fn (Builder $a) => $a->where('user_id', $scopedTechId));
            });
        }

        $openMetrics = $this->slaService->metricsForQuery(clone $openQuery);
        $openOrders = (clone $openQuery)->get([
            'id', 'ticket_number', 'urgency', 'risk_level', 'status', 'created_at', 'updated_at',
            'first_responded_at', 'response_due_at', 'resolution_due_at', 'sla_paused_at', 'sla_paused_seconds', 'completed_at',
        ]);

        $atRiskTickets = [];
        foreach ($openOrders as $order) {
            $eval = $this->slaService->evaluate($order);
            if (in_array($eval['status'], ['breached', 'approaching_deadline'], true)) {
                $atRiskTickets[] = "{$order->ticket_number} ({$eval['label_id']})";
            }
        }

        $periodQuery = ServiceOrder::query()->whereBetween('created_at', [$period->start, $period->end]);
        if ($scopedTechId !== null) {
            $periodQuery->where(function (Builder $q) use ($scopedTechId): void {
                $q->where('assigned_technician_id', $scopedTechId)
                    ->orWhereHas('activeTechnicianAssignments', fn (Builder $a) => $a->where('user_id', $scopedTechId));
            });
        }
        $periodMetrics = $this->slaService->metricsForQuery($periodQuery);
        $hasRisk = $openMetrics['breached'] > 0 || $openMetrics['approaching_deadline'] > 0;
        $riskText = $atRiskTickets !== [] ? ' Tiket berisiko: '.implode(', ', array_slice($atRiskTickets, 0, 5)).'.' : '';

        $message = sprintf(
            'Status SLA %s: Pada tiket terbuka saat ini ada %d melewati SLA (breached), %d mendekati batas, %d dijeda, dan %d sesuai target. Kepatuhan SLA pada periode %s: %.1f%%.%s',
            $scopedTechId !== null ? 'tugas Anda' : 'operasional',
            $openMetrics['breached'],
            $openMetrics['approaching_deadline'],
            $openMetrics['paused'],
            $openMetrics['on_track'],
            $period->label(),
            $periodMetrics['compliance_rate'],
            $riskText,
        );

        return [
            'authorized' => true,
            'tool' => 'get_sla_status',
            'reasoning_eligible' => true,
            'message' => $message,
            'actions' => [['label' => 'Buka Antrean Pesanan', 'href' => route('orders.index', absolute: false)]],
            'evidence' => [
                'verified' => true,
                'source' => 'SlaService',
                'period_label' => $period->label(),
                'generated_at' => $this->nowIso($period->timezone),
                'facts' => [
                    ['label' => 'Melewati SLA', 'value' => (string) $openMetrics['breached']],
                    ['label' => 'Mendekati Batas', 'value' => (string) $openMetrics['approaching_deadline']],
                    ['label' => 'Dijeda', 'value' => (string) $openMetrics['paused']],
                    ['label' => 'Kepatuhan Periode', 'value' => number_format($periodMetrics['compliance_rate'], 1).'%'],
                ],
                'recommendations' => $hasRisk
                    ? ['Prioritaskan penanganan tiket breached/approaching_deadline dan distribusikan ulang ke teknisi yang tersedia.']
                    : ['Pertahankan waktu respons pertama dan pembaruan status sebelum tenggat SLA.'],
            ],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, reasoning_eligible: bool, message: string, actions: list<array{label: string, href: string}>, evidence: array<string, mixed>|null}
     */
    private function runInventoryAlerts(?User $user, AnalyticsPeriod $period): array
    {
        if ($user === null || ! Gate::forUser($user)->allows('viewAny', InventoryItem::class)) {
            return $this->denied('get_inventory_alerts', 'Peringatan restock dan dead-stock inventori hanya tersedia untuk peran internal operasional AD Com.');
        }

        $inventory = $this->inventoryService->summary($period, $user);
        $reorder = $inventory['reorder_indicators'];
        $deadStock = $inventory['dead_stock_candidates'];

        $restockText = $reorder !== []
            ? 'Item perlu segera di-restock ('.count($reorder).' item): '.implode(', ', array_map(
                fn (array $r): string => "{$r['sku']} {$r['name']} (sisa {$r['quantity_on_hand']} vs min {$r['reorder_level']})",
                array_slice($reorder, 0, 6),
            )).'.'
            : 'Tidak ada item di bawah ambang minimum stok saat ini.';

        $deadText = $deadStock !== []
            ? sprintf(
                ' Terdapat %d kandidat dead-stock aktif tanpa pergerakan keluar >= 60 hari (%s). Ini adalah indikator evaluasi, bukan kepastian barang tidak dibutuhkan.',
                count($deadStock),
                implode(', ', array_map(
                    fn (array $d): string => "{$d['sku']} {$d['name']} ({$d['quantity_on_hand']} unit)",
                    array_slice($deadStock, 0, 4),
                )),
            )
            : ' Tidak ada kandidat dead-stock (>= 60 hari tanpa pergerakan keluar).';

        return [
            'authorized' => true,
            'tool' => 'get_inventory_alerts',
            'reasoning_eligible' => true,
            'message' => $restockText.$deadText,
            'actions' => [['label' => 'Buka Manajemen Inventori', 'href' => route('inventory.index', absolute: false)]],
            'evidence' => [
                'verified' => true,
                'source' => 'InventoryAnalyticsService',
                'period_label' => $period->label(),
                'generated_at' => $this->nowIso($period->timezone),
                'facts' => [
                    ['label' => 'Perlu Restock', 'value' => (string) count($reorder)],
                    ['label' => 'Kandidat Dead-Stock', 'value' => (string) count($deadStock)],
                    ['label' => 'Stok Habis', 'value' => (string) $inventory['overview']['out_of_stock_count']],
                ],
                'raw_metrics' => [
                    'reorder_indicators' => array_slice($reorder, 0, 8),
                    'dead_stock_candidates' => array_slice($deadStock, 0, 8),
                ],
                'recommendations' => array_values(array_filter([
                    $reorder !== [] ? 'Lakukan pengadaan ulang pada item kritis sesuai rata-rata pemakaian.' : null,
                    $deadStock !== [] ? 'Evaluasi kesesuaian kandidat dead-stock terhadap katalog layanan dan riwayat permintaan.' : null,
                ])),
            ],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, reasoning_eligible: bool, message: string, actions: list<array{label: string, href: string}>, evidence: null}
     */
    private function denied(string $tool, string $message): array
    {
        return [
            'authorized' => false,
            'tool' => $tool,
            'reasoning_eligible' => false,
            'message' => $message,
            'actions' => [],
            'evidence' => null,
        ];
    }

    private function outsidePeriodHintIfEmpty(int $orders, int $cash, int $invoiced, AnalyticsPeriod $period): string
    {
        if ($orders > 0 || $cash > 0 || $invoiced > 0 || in_array($period->preset, ['quarterly', 'yearly'], true)) {
            return '';
        }

        $latestOrder = ServiceOrder::query()->max('created_at');
        if ($latestOrder === null) {
            return '';
        }

        $latestDate = CarbonImmutable::parse((string) $latestOrder, $period->timezone)->translatedFormat('d M Y');

        return " Data pada rentang {$period->label()} memang 0 karena belum ada transaksi baru di rentang tersebut (order terakhir tercatat pada {$latestDate}; coba rentang 90 Hari).";
    }

    private function formatIdr(int $amount): string
    {
        $prefix = $amount < 0 ? '-Rp ' : 'Rp ';

        return $prefix.number_format(abs($amount), 0, ',', '.');
    }

    private function nowIso(?string $timezone = null): string
    {
        $tz = $timezone ?? (string) config('app.timezone', 'Asia/Jakarta');

        return CarbonImmutable::now($tz)->toIso8601String();
    }
}
