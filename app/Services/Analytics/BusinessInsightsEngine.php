<?php

namespace App\Services\Analytics;

use App\Models\User;
use Carbon\CarbonImmutable;

class BusinessInsightsEngine
{
    public function __construct(
        public ExecutiveAnalyticsService $executiveService,
        public FinancialAnalyticsService $financialService,
        public ServiceSalesAnalyticsService $serviceSalesService,
        public WorkforceAnalyticsService $workforceService,
        public InventoryAnalyticsService $inventoryService,
        public CustomerAnalyticsService $customerService,
        public AnalyticsCacheManager $cacheManager,
        public AnalyticsAccess $access,
    ) {}

    /**
     * Generate deterministic, evidence-backed operational & business insights scoped to viewer role.
     *
     * @return list<array{
     *     id: string,
     *     title: string,
     *     category: string,
     *     severity: string,
     *     description: string,
     *     supporting_metrics: array<string, mixed>,
     *     comparison_period: string,
     *     detected_at: string,
     *     recommended_action: string,
     *     source_label: string,
     *     href: string
     * }>
     */
    public function generate(AnalyticsPeriod $period, User $viewer): array
    {
        $allowedCategories = $this->access->allowedInsightCategories($viewer);
        if ($allowedCategories === []) {
            return [];
        }

        $scopeKey = sprintf(
            '%s:%s',
            $viewer->role,
            (string) ($this->access->scopedTechnicianId($viewer) ?? 'all'),
        );

        return $this->cacheManager->remember(
            namespace: 'insights',
            domains: AnalyticsCacheManager::DOMAINS,
            period: $period,
            extraKey: $scopeKey,
            resolver: fn (): array => $this->computeInsights($period, $viewer, $allowedCategories),
        );
    }

    /**
     * @param  list<string>  $allowedCategories
     * @return list<array{
     *     id: string,
     *     title: string,
     *     category: string,
     *     severity: string,
     *     description: string,
     *     supporting_metrics: array<string, mixed>,
     *     comparison_period: string,
     *     detected_at: string,
     *     recommended_action: string,
     *     source_label: string,
     *     href: string
     * }>
     */
    private function computeInsights(AnalyticsPeriod $period, User $viewer, array $allowedCategories): array
    {

        $detectedAt = CarbonImmutable::now($period->timezone)->toIso8601String();
        $comparisonLabel = $period->comparisonLabel();
        $thresholds = (array) config('analytics.thresholds', []);
        $insights = [];

        $canViewExecOrFinance = $this->access->canViewFinancialSummary($viewer);

        if ($canViewExecOrFinance && (in_array('sla', $allowedCategories, true) || in_array('customer', $allowedCategories, true))) {
            $exec = $this->executiveService->summary($period, $viewer);
            $kpis = $exec['kpis'];

            if (in_array('sla', $allowedCategories, true)) {
                $totalOrders = (int) ($kpis['total_orders']['value'] ?? 0);
                $slaRate = $kpis['sla_compliance_rate']['value'];
                $slaDelta = $kpis['sla_compliance_rate']['delta'];
                $minSample = (int) ($thresholds['sla_min_sample'] ?? 3);
                $floorPct = (float) ($thresholds['sla_critical_floor_pct'] ?? 85.0);
                $dropPp = (float) ($thresholds['sla_drop_pp'] ?? 5.0);

                if ($totalOrders >= $minSample && $slaRate !== null && ((float) $slaRate < $floorPct || ($slaDelta !== null && (float) $slaDelta <= -$dropPp))) {
                    $insights[] = [
                        'id' => 'sla-compliance-risk',
                        'title' => (float) $slaRate < $floorPct ? 'Kepatuhan SLA di Bawah Target' : 'Penurunan Kepatuhan SLA',
                        'category' => 'sla',
                        'severity' => (float) $slaRate < $floorPct ? 'critical' : 'warning',
                        'description' => sprintf(
                            'Kepatuhan SLA berada pada %.1f%% dari %d order pada periode ini (perubahan %s poin persentase).',
                            (float) $slaRate,
                            $totalOrders,
                            $slaDelta !== null ? sprintf('%+.1f', (float) $slaDelta) : 'N/A',
                        ),
                        'supporting_metrics' => [
                            'sla_compliance_rate_pct' => $slaRate,
                            'previous_rate_pct' => $kpis['sla_compliance_rate']['previous'],
                            'orders_sample' => $totalOrders,
                            'breached_orders' => (int) ($exec['sla_snapshot']['breached'] ?? 0),
                        ],
                        'comparison_period' => $comparisonLabel,
                        'detected_at' => $detectedAt,
                        'recommended_action' => 'Tinjau order yang mendekati/melewati tenggat di antrean operasional dan distribusikan ulang teknisi.',
                        'source_label' => 'SlaService + ServiceOrder',
                        'href' => '/analytics?section=workforce',
                    ];
                }
            }

            if (in_array('customer', $allowedCategories, true)) {
                $repeatRate = $kpis['repeat_customer_rate']['value'];
                $activeCustomers = (int) ($kpis['active_customers']['value'] ?? 0);
                if ($activeCustomers >= 3 && $repeatRate !== null && (float) $repeatRate >= 35.0) {
                    $insights[] = [
                        'id' => 'customer-repeat-strength',
                        'title' => 'Retensi Pelanggan Berulang Kuat',
                        'category' => 'customer',
                        'severity' => 'info',
                        'description' => sprintf(
                            '%.1f%% pelanggan aktif (%d dari %d pelanggan) merupakan pelanggan berulang pada periode ini.',
                            (float) $repeatRate,
                            (int) ($kpis['returning_customers']['value'] ?? 0),
                            $activeCustomers,
                        ),
                        'supporting_metrics' => [
                            'repeat_customer_rate_pct' => $repeatRate,
                            'returning_customers' => (int) ($kpis['returning_customers']['value'] ?? 0),
                            'active_customers' => $activeCustomers,
                        ],
                        'comparison_period' => $comparisonLabel,
                        'detected_at' => $detectedAt,
                        'recommended_action' => 'Pertahankan SLA penyelesaian dan tawarkan perpanjangan layanan pemeliharaan berkala.',
                        'source_label' => 'CustomerAnalyticsService',
                        'href' => '/analytics?section=customers',
                    ];
                }
            }
        }

        if ($canViewExecOrFinance && in_array('service', $allowedCategories, true)) {
            $servicesReport = $this->serviceSalesService->summary($period);
            $changeThreshold = (float) ($thresholds['service_demand_change_pct'] ?? 25.0);
            $minOrders = (int) ($thresholds['service_demand_min_orders'] ?? 3);

            foreach ($servicesReport['services'] as $serviceRow) {
                $delta = $serviceRow['demand_change_pct'];
                $count = (int) $serviceRow['non_cancelled_count'];
                if ($count >= $minOrders && $delta !== null && abs((float) $delta) >= $changeThreshold) {
                    $isGrowth = (float) $delta > 0;
                    $insights[] = [
                        'id' => 'service-demand-'.$serviceRow['code'],
                        'title' => $isGrowth
                            ? 'Lonjakan Permintaan Layanan: '.$serviceRow['name']
                            : 'Penurunan Permintaan Layanan: '.$serviceRow['name'],
                        'category' => 'service',
                        'severity' => $isGrowth ? 'info' : 'warning',
                        'description' => sprintf(
                            'Permintaan %s (%s) berubah %+.1f%% menjadi %d order dibanding %d order pada periode sebelumnya.',
                            $serviceRow['name'],
                            $serviceRow['code'],
                            (float) $delta,
                            $count,
                            (int) $serviceRow['previous_requests_count'],
                        ),
                        'supporting_metrics' => [
                            'service_code' => $serviceRow['code'],
                            'current_orders' => $count,
                            'previous_orders' => (int) $serviceRow['previous_requests_count'],
                            'demand_change_pct' => $delta,
                        ],
                        'comparison_period' => $comparisonLabel,
                        'detected_at' => $detectedAt,
                        'recommended_action' => $isGrowth
                            ? 'Pastikan kapasitas teknisi dan stok spare-part terkait layanan ini mencukupi.'
                            : 'Evaluasi waktu respons, tingkat konversi penawaran, dan umpan balik pelanggan untuk layanan ini.',
                        'source_label' => 'ServiceSalesAnalyticsService',
                        'href' => '/analytics?section=services',
                    ];
                }

                if ((int) $serviceRow['invoiced_revenue'] > 0 && (int) $serviceRow['gross_profit'] < 0) {
                    $insights[] = [
                        'id' => 'service-negative-margin-'.$serviceRow['code'],
                        'title' => 'Margin Layanan Negatif: '.$serviceRow['name'],
                        'category' => 'service',
                        'severity' => 'critical',
                        'description' => sprintf(
                            'Layanan %s mencatat gross profit negatif (Rp %s) karena HPP spare-part dan biaya langsung melebihi pendapatan tagihan.',
                            $serviceRow['name'],
                            number_format((int) $serviceRow['gross_profit'], 0, ',', '.'),
                        ),
                        'supporting_metrics' => [
                            'invoiced_revenue' => (int) $serviceRow['invoiced_revenue'],
                            'spare_part_cogs' => (int) $serviceRow['spare_part_cogs'],
                            'direct_expenses' => (int) $serviceRow['direct_expenses'],
                            'gross_profit' => (int) $serviceRow['gross_profit'],
                        ],
                        'comparison_period' => $comparisonLabel,
                        'detected_at' => $detectedAt,
                        'recommended_action' => 'Audit komponen biaya spare-part dan perbarui estimasi harga jasa sebelum persetujuan penawaran.',
                        'source_label' => 'ServiceSalesAnalyticsService',
                        'href' => '/analytics?section=services',
                    ];
                }
            }

            $quoteDecided = (int) ($servicesReport['quotations']['decided'] ?? 0);
            $quoteDeltaPp = $servicesReport['overview']['quotation_approval_delta_pp'];
            $quoteMinDecided = (int) ($thresholds['quotation_min_decided'] ?? 3);
            $quoteDropPp = (float) ($thresholds['quotation_approval_drop_pp'] ?? 10.0);

            if ($quoteDecided >= $quoteMinDecided && $quoteDeltaPp !== null && (float) $quoteDeltaPp <= -$quoteDropPp) {
                $insights[] = [
                    'id' => 'quotation-approval-drop',
                    'title' => 'Tingkat Persetujuan Penawaran Menurun',
                    'category' => 'service',
                    'severity' => 'warning',
                    'description' => sprintf(
                        'Persetujuan quotation turun %.1f poin persentase menjadi %.1f%% dari %d penawaran yang diputuskan.',
                        abs((float) $quoteDeltaPp),
                        (float) ($servicesReport['overview']['quotation_approval_rate'] ?? 0),
                        $quoteDecided,
                    ),
                    'supporting_metrics' => [
                        'approval_rate_pct' => $servicesReport['overview']['quotation_approval_rate'],
                        'previous_approval_rate_pct' => $servicesReport['overview']['previous_quotation_approval_rate'],
                        'decided_count' => $quoteDecided,
                    ],
                    'comparison_period' => $comparisonLabel,
                    'detected_at' => $detectedAt,
                    'recommended_action' => 'Review struktur harga, diskon, dan alasan penolakan quotation pada periode ini.',
                    'source_label' => 'EstimateAnalytics',
                    'href' => '/analytics?section=services',
                ];
            }
        }

        if ($canViewExecOrFinance && in_array('finance', $allowedCategories, true)) {
            $finance = $this->financialService->report($period, $viewer);
            foreach ($finance['anomalies'] as $anomaly) {
                $insights[] = [
                    'id' => 'finance-'.$anomaly['code'],
                    'title' => $anomaly['title'],
                    'category' => 'finance',
                    'severity' => $anomaly['severity'],
                    'description' => $anomaly['detail'],
                    'supporting_metrics' => [
                        'invoiced_revenue' => (int) ($finance['summary']['invoiced_revenue'] ?? 0),
                        'cash_received' => (int) ($finance['summary']['cash_received'] ?? 0),
                        'overdue_receivables' => (int) ($finance['summary']['overdue_receivables'] ?? 0),
                    ],
                    'comparison_period' => $comparisonLabel,
                    'detected_at' => $detectedAt,
                    'recommended_action' => 'Periksa daftar invoice jatuh tempo dan rincian profitabilitas per order di tab Keuangan.',
                    'source_label' => 'FinancialReportService',
                    'href' => '/analytics?section=financial',
                ];
            }
        }

        if (in_array('inventory', $allowedCategories, true)) {
            $inventory = $this->inventoryService->summary($period, $viewer);
            $outOfStockCount = (int) ($inventory['overview']['out_of_stock_count'] ?? 0);
            $lowStockCount = (int) ($inventory['overview']['low_stock_count'] ?? 0);

            if ($outOfStockCount > 0 || $lowStockCount > 0) {
                $insights[] = [
                    'id' => 'inventory-reorder-attention',
                    'title' => $outOfStockCount > 0 ? 'Stok Spare-Part Habis / Kritis' : 'Peringatan Stok Minimum Inventori',
                    'category' => 'inventory',
                    'severity' => $outOfStockCount > 0 ? 'critical' : 'warning',
                    'description' => sprintf(
                        'Saat ini terdapat %d item habis stok (0 unit) dan total %d item berada di bawah atau tepat pada batas reorder.',
                        $outOfStockCount,
                        $lowStockCount,
                    ),
                    'supporting_metrics' => [
                        'out_of_stock_count' => $outOfStockCount,
                        'low_stock_count' => $lowStockCount,
                        'asset_value' => (int) ($inventory['overview']['asset_value'] ?? 0),
                    ],
                    'comparison_period' => 'Snapshot Inventori Aktif',
                    'detected_at' => $detectedAt,
                    'recommended_action' => 'Jadwalkan pengadaan ulang manual untuk SKU prioritas tinggi pada modul Inventori.',
                    'source_label' => 'InventoryService',
                    'href' => '/inventory',
                ];
            }

            $deadStockCount = count($inventory['dead_stock_candidates']);
            if ($deadStockCount > 0) {
                $insights[] = [
                    'id' => 'inventory-dead-stock',
                    'title' => 'Kandidat Dead-Stock Terdeteksi',
                    'category' => 'inventory',
                    'severity' => 'info',
                    'description' => sprintf(
                        '%d item aktif dengan stok > 0 belum mencatat pergerakan keluar minimal %d hari.',
                        $deadStockCount,
                        (int) ($thresholds['dead_stock_days'] ?? 60),
                    ),
                    'supporting_metrics' => [
                        'dead_stock_items_count' => $deadStockCount,
                        'threshold_days' => (int) ($thresholds['dead_stock_days'] ?? 60),
                    ],
                    'comparison_period' => $comparisonLabel,
                    'detected_at' => $detectedAt,
                    'recommended_action' => 'Evaluasi kebutuhan stok item pasif agar modal kerja tidak tertahan di gudang.',
                    'source_label' => 'InventoryAnalyticsService',
                    'href' => '/analytics?section=inventory',
                ];
            }

            $costThreshold = (float) ($thresholds['spare_part_cost_increase_pct'] ?? 15.0);
            foreach ($inventory['historical_unit_cost_tracking'] as $costRow) {
                $changePct = $costRow['cost_change_pct'];
                if ($changePct !== null && (float) $changePct >= $costThreshold) {
                    $insights[] = [
                        'id' => 'inventory-cost-spike-'.$costRow['sku'],
                        'title' => 'Kenaikan Harga Beli Spare-Part: '.$costRow['name'],
                        'category' => 'inventory',
                        'severity' => 'warning',
                        'description' => sprintf(
                            'Biaya masuk SKU %s naik %.1f%% dari Rp %s menjadi Rp %s pada periode ini.',
                            $costRow['sku'],
                            (float) $changePct,
                            number_format((int) $costRow['earliest_period_cost'], 0, ',', '.'),
                            number_format((int) $costRow['latest_period_cost'], 0, ',', '.'),
                        ),
                        'supporting_metrics' => [
                            'sku' => $costRow['sku'],
                            'earliest_unit_cost' => (int) $costRow['earliest_period_cost'],
                            'latest_unit_cost' => (int) $costRow['latest_period_cost'],
                            'change_pct' => $changePct,
                        ],
                        'comparison_period' => $comparisonLabel,
                        'detected_at' => $detectedAt,
                        'recommended_action' => 'Perbarui harga jual / patokan quotation jika margin jasa mulai tergerus.',
                        'source_label' => 'InventoryMovement (WAC)',
                        'href' => '/analytics?section=inventory',
                    ];
                    break;
                }
            }
        }

        if (in_array('workforce', $allowedCategories, true)) {
            $workforce = $this->workforceService->summary($period, $viewer);
            $techRows = $workforce['technicians'];

            if ($workforce['is_personal_scope']) {
                $selfRow = $techRows[0] ?? null;
                if ($selfRow !== null && (int) $selfRow['sla_risk_assignments_count'] > 0) {
                    $insights[] = [
                        'id' => 'personal-sla-risk',
                        'title' => 'Tugas Aktif Mendekati / Melewati SLA',
                        'category' => 'workforce',
                        'severity' => 'warning',
                        'description' => sprintf(
                            'Anda memiliki %d tugas aktif dengan risiko SLA saat ini dari total %d beban aktif.',
                            (int) $selfRow['sla_risk_assignments_count'],
                            (int) $selfRow['current_workload'],
                        ),
                        'supporting_metrics' => [
                            'sla_risk_assignments_count' => (int) $selfRow['sla_risk_assignments_count'],
                            'current_workload' => (int) $selfRow['current_workload'],
                        ],
                        'comparison_period' => 'Beban Kerja Saat Ini',
                        'detected_at' => $detectedAt,
                        'recommended_action' => 'Prioritaskan order berisiko tinggi dan catat sesi kerja serta progres terbaru.',
                        'source_label' => 'WorkforceService',
                        'href' => '/dashboard',
                    ];
                }
            } else {
                $availableTechs = array_values(array_filter(
                    $techRows,
                    fn (array $row): bool => $row['is_active'] && $row['availability_status'] !== 'offline',
                ));
                if (count($availableTechs) >= 2) {
                    $loads = array_map(fn (array $row): int => (int) $row['current_workload'], $availableTechs);
                    $spread = max($loads) - min($loads);
                    $spreadThreshold = (int) ($thresholds['workload_imbalance_spread'] ?? 3);

                    if ($spread >= $spreadThreshold) {
                        $insights[] = [
                            'id' => 'workforce-workload-imbalance',
                            'title' => 'Ketimpangan Distribusi Beban Teknisi',
                            'category' => 'workforce',
                            'severity' => 'warning',
                            'description' => sprintf(
                                'Selisih beban aktif antar teknisi non-offline mencapai %d tiket (maks %d vs min %d tiket).',
                                $spread,
                                max($loads),
                                min($loads),
                            ),
                            'supporting_metrics' => [
                                'max_workload' => max($loads),
                                'min_workload' => min($loads),
                                'spread' => $spread,
                            ],
                            'comparison_period' => 'Snapshot Workforce Aktif',
                            'detected_at' => $detectedAt,
                            'recommended_action' => 'Gunakan rekomendasi smart dispatch di Workforce untuk menyeimbangkan antrean tiket baru.',
                            'source_label' => 'WorkforceService',
                            'href' => '/workforce',
                        ];
                    }
                }
            }
        }

        if ($canViewExecOrFinance && in_array('customer', $allowedCategories, true)) {
            $customerReport = $this->customerService->summary($period, $viewer);
            $claimsCount = (int) ($customerReport['overview']['warranty_claims_period'] ?? 0);
            $claimRate = $customerReport['overview']['warranty_claim_rate_pct'];
            if ($claimsCount >= 2 && $claimRate !== null && (float) $claimRate >= 20.0) {
                $insights[] = [
                    'id' => 'warranty-claim-rate-alert',
                    'title' => 'Rasio Klaim Garansi Tinggi',
                    'category' => 'customer',
                    'severity' => 'warning',
                    'description' => sprintf(
                        'Tercatat %d klaim garansi (%.1f%% terhadap garansi terbit pada periode ini).',
                        $claimsCount,
                        (float) $claimRate,
                    ),
                    'supporting_metrics' => [
                        'warranty_claims_period' => $claimsCount,
                        'warranties_issued_period' => (int) ($customerReport['overview']['warranties_issued_period'] ?? 0),
                        'claim_rate_pct' => $claimRate,
                    ],
                    'comparison_period' => $comparisonLabel,
                    'detected_at' => $detectedAt,
                    'recommended_action' => 'Periksa penyebab klaim berulang per layanan atau batch spare-part di modul Garansi.',
                    'source_label' => 'WarrantyAnalytics',
                    'href' => '/warranties',
                ];
            }
        }

        return $insights;
    }
}
