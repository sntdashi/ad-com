<?php

namespace App\Services\Analytics;

use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsExportService
{
    public function __construct(
        public ExecutiveAnalyticsService $executiveService,
        public FinancialAnalyticsService $financialService,
        public ServiceSalesAnalyticsService $serviceSalesService,
        public WorkforceAnalyticsService $workforceService,
        public InventoryAnalyticsService $inventoryService,
        public CustomerAnalyticsService $customerService,
        public AnalyticsAccess $access,
    ) {}

    public function downloadCsv(string $type, AnalyticsPeriod $period, User $viewer): StreamedResponse
    {
        $filename = sprintf(
            'adcom-analytics-%s-%s-to-%s.csv',
            $type,
            $period->start->toDateString(),
            $period->end->toDateString(),
        );

        [$headers, $rows] = $this->buildDataset($type, $period, $viewer);

        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_map($this->sanitizeCell(...), $headers));

            foreach ($rows as $row) {
                fputcsv($handle, array_map($this->sanitizeCell(...), $row));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Prevent CSV formula injection by prefixing formula trigger characters.
     */
    public function sanitizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }

        $string = (string) $value;
        if ($string !== '' && preg_match('/^[=+\-@\t\r]/', $string) === 1) {
            return "'".$string;
        }

        return $string;
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function buildDataset(string $type, AnalyticsPeriod $period, User $viewer): array
    {
        return match ($type) {
            'executive' => $this->executiveDataset($period, $viewer),
            'financial' => $this->financialDataset($period, $viewer),
            'order-profitability' => $this->orderProfitabilityDataset($period, $viewer),
            'services' => $this->servicesDataset($period),
            'workforce' => $this->workforceDataset($period, $viewer),
            'inventory' => $this->inventoryDataset($period, $viewer),
            'customers' => $this->customersDataset($period, $viewer),
            default => [[], []],
        };
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function executiveDataset(AnalyticsPeriod $period, User $viewer): array
    {
        $summary = $this->executiveService->summary($period, $viewer);
        $rows = [];

        foreach ($summary['kpis'] as $key => $metric) {
            $rows[] = [
                $key,
                $metric['value'] ?? '',
                $metric['previous'] ?? '',
                $metric['delta'] ?? '',
                $metric['delta_unit'] ?? '',
                $metric['basis'] ?? 'operational',
                $period->label(),
            ];
        }

        return [
            ['KPI', 'Nilai Periode Ini', 'Nilai Periode Sebelumnya', 'Perubahan', 'Satuan Delta', 'Basis Data', 'Rentang Periode'],
            $rows,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function financialDataset(AnalyticsPeriod $period, User $viewer): array
    {
        $report = $this->financialService->report($period, $viewer);
        $rows = [];

        foreach ($report['trends'] as $point) {
            $rows[] = [
                $point['label'],
                $point['invoiced_revenue'],
                $point['cash_received'],
                $point['spare_part_cogs'],
                $point['operational_expenses'],
                $point['inventory_purchase_expenses'],
                $point['gross_profit_accrual'],
                $point['net_cash_flow'],
            ];
        }

        return [
            [
                'Periode Bucket',
                'Pendapatan Tertagih (Akrual)',
                'Kas Masuk Terverifikasi (Kas)',
                'HPP Spare-Part',
                'Beban Operasional',
                'Belanja Stok Gudang',
                'Laba Kotor (Akrual)',
                'Arus Kas Bersih',
            ],
            $rows,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function orderProfitabilityDataset(AnalyticsPeriod $period, User $viewer): array
    {
        $profitRows = $this->financialService->profitabilityRowsForExport($period, $viewer);
        $rows = [];

        foreach ($profitRows as $row) {
            $rows[] = [
                $row['ticket_number'],
                $row['service_name'],
                $row['customer_label'],
                $row['status'],
                $row['estimated_total'],
                $row['invoiced_total'],
                $row['cash_received'],
                $row['outstanding_receivables'],
                $row['spare_part_cogs'],
                $row['direct_expenses'],
                $row['gross_profit'],
                $row['margin_pct'] ?? '',
            ];
        }

        return [
            [
                'No Tiket',
                'Layanan',
                'Pelanggan',
                'Status',
                'Estimasi',
                'Tertagih',
                'Kas Diterima',
                'Piutang',
                'HPP Spare-Part',
                'Beban Langsung',
                'Laba Kotor',
                'Margin (%)',
            ],
            $rows,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function servicesDataset(AnalyticsPeriod $period): array
    {
        $report = $this->serviceSalesService->summary($period);
        $rows = [];

        foreach ($report['services'] as $service) {
            $rows[] = [
                $service['code'],
                $service['name'],
                $service['service_mode'],
                $service['requests_count'],
                $service['previous_requests_count'],
                $service['demand_change_pct'] ?? '',
                $service['completed_count'],
                $service['cancelled_count'],
                $service['invoiced_revenue'],
                $service['spare_part_cogs'],
                $service['direct_expenses'],
                $service['gross_profit'],
                $service['margin_pct'] ?? '',
            ];
        }

        return [
            [
                'Kode',
                'Nama Layanan',
                'Mode',
                'Order Periode Ini',
                'Order Periode Lalu',
                'Perubahan Permintaan (%)',
                'Selesai',
                'Dibatalkan',
                'Pendapatan Tertagih',
                'HPP Spare-Part',
                'Beban Langsung',
                'Laba Kotor',
                'Margin (%)',
            ],
            $rows,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function workforceDataset(AnalyticsPeriod $period, User $viewer): array
    {
        $report = $this->workforceService->summary($period, $viewer);
        $rows = [];

        foreach ($report['technicians'] as $tech) {
            $rows[] = [
                $tech['name'],
                $tech['specialty'],
                $tech['availability_label_id'],
                $tech['assigned_jobs_period'],
                $tech['completed_jobs_period'],
                $tech['high_complexity_jobs_period'],
                $tech['avg_resolution_minutes_period'] ?? '',
                $tech['avg_work_session_minutes_period'] ?? '',
                $tech['total_work_hours_period'],
                $tech['sla_compliance_rate_period'] ?? '',
                $tech['reopened_jobs_period'],
                $tech['current_workload'],
            ];
        }

        return [
            [
                'Teknisi',
                'Spesialisasi',
                'Ketersediaan',
                'Ditugaskan',
                'Diselesaikan',
                'Order Kompleksitas Tinggi',
                'Rata-rata Resolusi (Menit)',
                'Rata-rata Sesi Kerja (Menit)',
                'Total Jam Kerja',
                'Kepatuhan SLA (%)',
                'Order Reopen',
                'Beban Aktif Saat Ini',
            ],
            $rows,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function inventoryDataset(AnalyticsPeriod $period, User $viewer): array
    {
        $report = $this->inventoryService->summary($period, $viewer);
        $rows = [];

        foreach ($report['items'] as $item) {
            $rows[] = [
                $item['sku'],
                $item['name'],
                $item['category'],
                $item['unit'],
                $item['quantity_on_hand'],
                $item['reorder_level'],
                $item['unit_cost'],
                $item['asset_value'],
                $item['outbound_units_period'],
                $item['turnover_ratio'],
                $item['days_since_last_outbound'],
                $item['velocity_class'],
            ];
        }

        return [
            [
                'SKU',
                'Nama Item',
                'Kategori',
                'Satuan',
                'Stok Tersedia',
                'Batas Reorder',
                'Harga Pokok (WAC)',
                'Nilai Aset Stok',
                'Keluar Periode Ini',
                'Rasio Turnover',
                'Hari Sejak Keluar Terakhir',
                'Klasifikasi Pergerakan',
            ],
            $rows,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function customersDataset(AnalyticsPeriod $period, User $viewer): array
    {
        $report = $this->customerService->summary($period, $viewer);
        $rows = [];

        foreach ($report['top_lifetime_segments'] as $customer) {
            $rows[] = [
                $customer['customer_label'],
                $customer['invoices_count'],
                $customer['lifetime_invoiced'],
                $customer['lifetime_paid'],
            ];
        }

        return [
            ['Pelanggan', 'Jumlah Invoice', 'Total Nilai Layanan Tertagih', 'Total Dibayar'],
            $rows,
        ];
    }
}
