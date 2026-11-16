<?php

namespace App\Services\Assistant;

use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\AssistantOperationalData;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class AssistantToolRegistry
{
    public function __construct(
        private AssistantOperationalData $operationalData,
        private UserContextResolver $contextResolver,
        private AssistantIntentClassifier $intentClassifier,
        private AnalyticsToolExecutor $analyticsTools,
    ) {}

    /**
     * Return formal schemas for all Phase 4 controlled read-only tools.
     *
     * @return array<string, array{type: 'read', reasoning_eligible: bool, description: string, rules: array<string, list<string>>}>
     */
    public function toolDefinitions(): array
    {
        return AnalyticsToolExecutor::schemas();
    }

    /**
     * Execute a role-appropriate read-only operational query if authorized by Policies/Gates.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{
     *     authorized: bool,
     *     tool: string,
     *     reasoning_eligible?: bool,
     *     message: string,
     *     actions?: list<array{label: string, href: string}>,
     *     evidence?: array<string, mixed>|null
     * }|null
     */
    public function handleOperationalQuery(string $normalizedMessage, ?User $user, array $messages = []): ?array
    {
        $decision = $this->intentClassifier->classify($normalizedMessage, $messages, $user);

        if ($decision['tool'] === null) {
            return null;
        }

        return $this->executeTool($decision['tool'], $user, $normalizedMessage, $decision['tool_params']);
    }

    /**
     * Execute one of the registered read-only backend tools with strict schema validation & authorization.
     *
     * @param  array<string, mixed>  $params
     * @return array{
     *     authorized: bool,
     *     tool: string,
     *     reasoning_eligible?: bool,
     *     message: string,
     *     actions?: list<array{label: string, href: string}>,
     *     evidence?: array<string, mixed>|null
     * }|null
     */
    public function executeTool(string $tool, ?User $user, string $normalizedMessage = '', array $params = []): ?array
    {
        $schemas = AnalyticsToolExecutor::schemas();
        if (isset($schemas[$tool])) {
            $validator = Validator::make($params, $schemas[$tool]['rules']);
            if ($validator->fails()) {
                return [
                    'authorized' => false,
                    'tool' => $tool,
                    'reasoning_eligible' => false,
                    'message' => 'Parameter permintaan analitik tidak valid. Gunakan rentang tanggal (YYYY-MM-DD) atau preset periode yang didukung.',
                    'actions' => [],
                    'evidence' => null,
                ];
            }

            /** @var array<string, mixed> $validatedParams */
            $validatedParams = $validator->validated();
            $result = $this->analyticsTools->execute($tool, $user, $validatedParams, $normalizedMessage);
            if ($result !== null && $user !== null) {
                $this->recordAudit(
                    $user,
                    $tool,
                    $result['authorized'] ? 'assistant.tool_executed' : 'assistant.tool_denied',
                    $validatedParams,
                );
            }

            return $result;
        }

        return match ($tool) {
            'get_current_user' => $this->runGetCurrentUser($user, $normalizedMessage),
            'get_technician_count' => $this->runGetTechnicianCount($user, $normalizedMessage),
            'list_technicians' => $this->runListTechnicians($user, $normalizedMessage),
            'get_available_technicians' => $this->runGetAvailableTechnicians($user),
            'get_user_count' => $this->runGetUserCount($user),
            'list_users' => $this->runListUsers($user),
            'get_customer_count' => $this->runGetCustomerCount($user),
            'list_customers' => $this->runListCustomers($user, $normalizedMessage),
            'get_customer_detail' => $this->runGetCustomerDetail($user, $normalizedMessage),
            'get_customer_summary' => $this->runGetCustomerSummary($user),
            'get_service_count' => $this->runGetServiceCount($user),
            'list_services' => $this->runListServices($user),
            'get_ticket_count' => $this->runGetTicketCount($user),
            'get_operational_summary' => $this->runGetOperationalSummary($user),
            'platform_summary' => $this->runPlatformSummary($user),
            'finance_summary' => $this->runFinanceSummary($user, $normalizedMessage),
            'inventory_summary' => $this->runInventorySummary($user),
            'unassigned_queue_summary' => $this->runUnassignedQueue($user),
            'user_orders_summary' => $this->runUserOrders($user),
            default => null,
        };
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runGetCurrentUser(?User $user, string $normalizedMessage): array
    {
        if ($user === null) {
            return [
                'authorized' => true,
                'tool' => 'get_current_user',
                'message' => 'Anda saat ini mengakses AD Com sebagai tamu (belum terautentikasi). Silakan masuk ke akun AD Com agar saya dapat menampilkan nama dan peran sesi Anda.',
                'actions' => [['label' => 'Masuk ke Akun', 'href' => route('login')]],
            ];
        }

        $context = $this->contextResolver->resolve($user);
        $this->recordAudit($user, 'get_current_user');

        $message = $user->role === 'customer'
            ? "Anda masuk sebagai {$context['name']} dengan peran {$context['role_label']} (customer). {$context['role_description']}"
            : "Anda masuk sebagai {$context['name']} dengan peran {$context['role_label']} ({$context['role']}). {$context['role_description']}";

        if (preg_match('/\b(email|alamat email|profil|detail)\b/', $normalizedMessage) === 1 || $user->role !== 'customer') {
            $message .= " Email terautentikasi: {$user->email}.";
        }

        return [
            'authorized' => true,
            'tool' => 'get_current_user',
            'message' => $message,
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runGetTechnicianCount(?User $user, string $normalizedMessage): array
    {
        $counts = $this->operationalData->technicianCounts();
        if ($user !== null) {
            $this->recordAudit($user, 'get_technician_count');
        }

        $asksAvailable = preg_match('/\b(tersedia|available|kosong|siap)\b/', $normalizedMessage) === 1;
        $asksActive = preg_match('/\b(aktif|active)\b/', $normalizedMessage) === 1;
        $asksTotal = preg_match('/\b(total|semua|seluruh)\b/', $normalizedMessage) === 1 || (! $asksActive && ! $asksAvailable);

        $parts = [];
        if ($asksTotal) {
            $parts[] = "total {$counts['total']} teknisi";
        }
        if ($asksActive) {
            $parts[] = "{$counts['active']} teknisi aktif";
        }
        if ($asksAvailable) {
            $parts[] = "{$counts['available']} teknisi tersedia";
        }

        return [
            'authorized' => true,
            'tool' => 'get_technician_count',
            'message' => 'Saat ini terdapat '.implode(', ', $parts)." ({$counts['active']} teknisi aktif, {$counts['available']} teknisi tersedia yang tidak sedang ditugaskan pada tiket berjalan).",
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runListTechnicians(?User $user, string $normalizedMessage = ''): array
    {
        if ($user === null || ! in_array($user->role, ['operator', 'admin', 'supervisor', 'technician', 'warehouse'], true)) {
            return [
                'authorized' => false,
                'tool' => 'list_technicians',
                'message' => 'Maaf, daftar nama teknisi internal hanya dapat diakses oleh pengguna internal AD Com yang terautentikasi.',
            ];
        }

        $activeOnly = preg_match('/\b(aktif|active)\b/', $normalizedMessage) === 1
            && preg_match('/\b(nonaktif|tidak aktif)\b/', $normalizedMessage) === 0;

        $techs = $this->operationalData->listTechnicians();
        if ($activeOnly) {
            $techs = $techs->where('is_active', true)->values();
        }
        $this->recordAudit($user, 'list_technicians');

        if ($techs->isEmpty()) {
            return [
                'authorized' => true,
                'tool' => 'list_technicians',
                'message' => 'Belum ada akun teknisi yang terdaftar di database.',
            ];
        }

        $list = $techs->map(function (array $t): string {
            $status = ! $t['is_active'] ? 'nonaktif' : ($t['available'] ? 'tersedia' : 'sedang bertugas');

            return "{$t['name']} ({$status})";
        })->implode(', ');

        $prefix = $activeOnly ? 'Daftar teknisi aktif' : 'Daftar teknisi terdaftar';

        return [
            'authorized' => true,
            'tool' => 'list_technicians',
            'message' => "{$prefix} ({$techs->count()} orang): {$list}.",
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runGetAvailableTechnicians(?User $user): array
    {
        if ($user === null || ! in_array($user->role, ['operator', 'admin', 'supervisor', 'technician', 'warehouse'], true)) {
            return [
                'authorized' => false,
                'tool' => 'get_available_technicians',
                'message' => 'Maaf, daftar teknisi yang tersedia hanya dapat diakses oleh peran internal operasional.',
            ];
        }

        $available = $this->operationalData->availableTechnicians();
        $this->recordAudit($user, 'get_available_technicians');

        if ($available->isEmpty()) {
            return [
                'authorized' => true,
                'tool' => 'get_available_technicians',
                'message' => 'Saat ini semua teknisi aktif sedang ditugaskan pada tiket berjalan (0 teknisi tersedia).',
            ];
        }

        $names = $available->pluck('name')->implode(', ');

        return [
            'authorized' => true,
            'tool' => 'get_available_technicians',
            'message' => "Teknisi aktif yang tersedia (tidak sedang ditugaskan pada tiket berjalan, {$available->count()} orang): {$names}.",
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runGetUserCount(?User $user): array
    {
        if ($user === null || ! in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return [
                'authorized' => false,
                'tool' => 'get_user_count',
                'message' => 'Maaf, statistik total pengguna hanya dapat diakses oleh Supervisor, Admin Operasional, atau Superadmin.',
            ];
        }

        $summary = $this->operationalData->userSummary();
        $this->recordAudit($user, 'get_user_count');

        $roleBreakdown = collect($summary['by_role'])
            ->map(fn (int $count, string $role): string => "{$role}: {$count}")
            ->implode(', ');

        return [
            'authorized' => true,
            'tool' => 'get_user_count',
            'message' => "Total seluruh akun pengguna di database: {$summary['total']} akun ({$summary['active']} aktif). Rincian seluruh role: {$roleBreakdown}.",
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runListUsers(?User $user): array
    {
        if ($user === null || ! in_array($user->role, ['operator', 'admin'], true)) {
            return [
                'authorized' => false,
                'tool' => 'list_users',
                'message' => 'Maaf, daftar seluruh pengguna hanya dapat diakses oleh Admin Operasional atau Superadmin.',
            ];
        }

        $users = $this->operationalData->listUsers();
        $this->recordAudit($user, 'list_users');

        $list = $users->map(fn (array $u): string => "{$u['name']} ({$u['role']})")->implode(', ');

        return [
            'authorized' => true,
            'tool' => 'list_users',
            'message' => "Daftar pengguna terdaftar ({$users->count()} akun): {$list}.",
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runGetCustomerCount(?User $user): array
    {
        if ($user === null || ! in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return [
                'authorized' => false,
                'tool' => 'get_customer_count',
                'message' => 'Maaf, statistik jumlah customer hanya dapat diakses oleh Supervisor, Admin Operasional, atau Superadmin.',
            ];
        }

        $summary = $this->operationalData->customerSummary();
        $this->recordAudit($user, 'get_customer_count');

        return [
            'authorized' => true,
            'tool' => 'get_customer_count',
            'message' => "Terdapat {$summary['total']} customer terdaftar ({$summary['active']} aktif).",
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runListCustomers(?User $user, string $normalizedMessage = ''): array
    {
        if ($user === null || ! in_array($user->role, ['operator', 'admin'], true)) {
            return [
                'authorized' => false,
                'tool' => 'list_customers',
                'message' => 'Maaf, daftar customer hanya dapat diakses oleh Admin Operasional atau Superadmin.',
            ];
        }

        $activeOnly = preg_match('/\b(aktif|active)\b/', $normalizedMessage) === 1
            && preg_match('/\b(nonaktif|tidak aktif)\b/', $normalizedMessage) === 0;

        $customers = $this->operationalData->listCustomers(activeOnly: $activeOnly);
        $this->recordAudit($user, 'list_customers');

        if ($customers->isEmpty()) {
            return [
                'authorized' => true,
                'tool' => 'list_customers',
                'message' => 'Belum ada customer yang terdaftar di sistem AD Com.',
            ];
        }

        $list = $customers->map(fn (array $customer): string => $customer['is_active']
            ? $customer['name']
            : "{$customer['name']} (nonaktif)")->implode(', ');

        $prefix = $activeOnly ? 'Daftar customer aktif' : 'Daftar customer terdaftar';

        return [
            'authorized' => true,
            'tool' => 'list_customers',
            'message' => "{$prefix} ({$customers->count()} akun): {$list}.",
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runGetCustomerDetail(?User $user, string $normalizedMessage): array
    {
        if ($user === null || ! in_array($user->role, ['operator', 'admin'], true)) {
            return [
                'authorized' => false,
                'tool' => 'get_customer_detail',
                'message' => 'Maaf, detail customer hanya dapat diakses oleh Admin Operasional atau Superadmin.',
            ];
        }

        $nameQuery = trim((string) preg_replace(
            '/\b(detail|profil|info|informasi|data|akun|customer|pelanggan|tentang|untuk|siapa|itu|tampilkan|lihat|cek)\b/u',
            ' ',
            $normalizedMessage
        ));
        $nameQuery = trim((string) preg_replace('/\s+/u', ' ', $nameQuery));

        $this->recordAudit($user, 'get_customer_detail');

        if ($nameQuery === '') {
            return [
                'authorized' => true,
                'tool' => 'get_customer_detail',
                'message' => 'Sebutkan nama customer yang ingin dilihat detailnya (contoh: "detail customer Siti Rahma").',
            ];
        }

        $detail = $this->operationalData->customerDetailByName($nameQuery);

        if ($detail === null) {
            return [
                'authorized' => true,
                'tool' => 'get_customer_detail',
                'message' => "Customer dengan nama \"{$nameQuery}\" tidak ditemukan.",
            ];
        }

        $status = $detail['is_active'] ? 'aktif' : 'nonaktif';

        return [
            'authorized' => true,
            'tool' => 'get_customer_detail',
            'message' => "Detail customer: {$detail['name']} (status: {$status}, jumlah pesanan: {$detail['orders_count']}).",
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runGetCustomerSummary(?User $user): array
    {
        if ($user === null || ! in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return [
                'authorized' => false,
                'tool' => 'get_customer_summary',
                'message' => 'Maaf, ringkasan data customer hanya dapat diakses oleh Supervisor, Admin Operasional, atau Superadmin.',
            ];
        }

        $summary = $this->operationalData->customerSummary();
        $this->recordAudit($user, 'get_customer_summary');

        return [
            'authorized' => true,
            'tool' => 'get_customer_summary',
            'message' => "Ringkasan customer AD Com: {$summary['total']} total ({$summary['active']} aktif, {$summary['inactive']} nonaktif), dengan {$summary['with_active_orders']} customer memiliki pesanan aktif.",
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runGetServiceCount(?User $user): array
    {
        $summary = $this->operationalData->serviceSummary();
        if ($user !== null) {
            $this->recordAudit($user, 'get_service_count');
        }

        if ($summary['active'] === 0) {
            return [
                'authorized' => true,
                'tool' => 'get_service_count',
                'message' => "Belum ada layanan aktif yang tersedia di katalog AD Com (total terdaftar: {$summary['total']}).",
            ];
        }

        $detail = $summary['inactive'] > 0
            ? " ({$summary['active']} aktif dan {$summary['inactive']} nonaktif)."
            : ' (semuanya aktif).';

        return [
            'authorized' => true,
            'tool' => 'get_service_count',
            'message' => "Saat ini terdapat {$summary['active']} layanan aktif di katalog AD Com dari total {$summary['total']} layanan terdaftar{$detail}",
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runListServices(?User $user): array
    {
        $services = $this->operationalData->listServices(activeOnly: true);
        if ($user !== null) {
            $this->recordAudit($user, 'list_services');
        }

        if ($services->isEmpty()) {
            return [
                'authorized' => true,
                'tool' => 'list_services',
                'message' => 'Belum ada layanan aktif yang tersedia di katalog AD Com.',
            ];
        }

        $list = $services->map(fn (array $s): string => "{$s['name']} ({$s['code']}, {$s['pricing_label']})")->implode('; ');

        return [
            'authorized' => true,
            'tool' => 'list_services',
            'message' => "Layanan aktif AD Com ({$services->count()} layanan): {$list}.",
            'actions' => [['label' => 'Buat pesanan', 'href' => route('booking.create', absolute: false)]],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runGetTicketCount(?User $user): array
    {
        if ($user !== null && in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            $summary = $this->operationalData->ticketSummary();
            $this->recordAudit($user, 'get_ticket_count');

            return [
                'authorized' => true,
                'tool' => 'get_ticket_count',
                'message' => "Terdapat total {$summary['total']} tiket di sistem ({$summary['active']} tiket aktif/berjalan, {$summary['unassigned']} belum memiliki PIC teknisi, dan {$summary['completed']} selesai/ditutup).",
                'actions' => [['label' => 'Buka Pesanan', 'href' => route('orders.index')]],
            ];
        }

        if ($user !== null && in_array($user->role, ['customer', 'technician'], true)) {
            $ownOrders = $this->runUserOrders($user);
            if ($ownOrders !== null) {
                return [
                    ...$ownOrders,
                    'tool' => 'get_ticket_count',
                ];
            }
        }

        return [
            'authorized' => false,
            'tool' => 'get_ticket_count',
            'message' => 'Maaf, statistik total tiket operasional hanya dapat diakses oleh Supervisor, Admin Operasional, atau Superadmin.',
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runGetOperationalSummary(?User $user): array
    {
        if ($user === null || ! in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return [
                'authorized' => false,
                'tool' => 'get_operational_summary',
                'message' => 'Maaf, ringkasan operasional hanya tersedia untuk Supervisor, Admin Operasional, dan Superadmin.',
            ];
        }

        $activeOrders = ServiceOrder::query()->whereNotIn('status', ['completed', 'closed', 'cancelled'])->count();
        $unassigned = ServiceOrder::query()
            ->whereNull('assigned_technician_id')
            ->whereNotIn('status', ['completed', 'closed', 'cancelled'])
            ->count();
        $counts = $this->operationalData->technicianCounts();
        $services = $this->operationalData->serviceSummary();
        $this->recordAudit($user, 'get_operational_summary');

        return [
            'authorized' => true,
            'tool' => 'get_operational_summary',
            'message' => "Ringkasan operasional: {$activeOrders} tiket berjalan ({$unassigned} belum ada PIC), {$counts['available']}/{$counts['active']} teknisi tersedia, dan {$services['active']} layanan aktif.",
            'actions' => [['label' => 'Buka Pesanan', 'href' => route('orders.index')]],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runPlatformSummary(?User $user): array
    {
        if ($user === null || $user->role !== 'operator') {
            return [
                'authorized' => false,
                'tool' => 'platform_summary',
                'message' => 'Ringkasan kendali platform penuh hanya tersedia untuk Superadmin.',
            ];
        }

        $activeOrders = ServiceOrder::query()->whereNotIn('status', ['completed', 'closed', 'cancelled'])->count();
        $lowStock = InventoryItem::query()->where('is_active', true)->whereColumn('quantity_on_hand', '<=', 'reorder_level')->count();
        $pendingFinance = FinancialTransaction::query()->where('status', 'pending')->count();
        $counts = $this->operationalData->technicianCounts();
        $this->recordAudit($user, 'platform_summary');

        return [
            'authorized' => true,
            'tool' => 'platform_summary',
            'message' => "Ringkasan platform: {$activeOrders} tiket aktif, {$counts['available']}/{$counts['active']} teknisi tersedia, {$lowStock} stok kritis, dan {$pendingFinance} transaksi pending.",
            'actions' => [['label' => 'Pusat Kendali Superadmin', 'href' => route('admin.control-center')]],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runFinanceSummary(?User $user, string $normalizedMessage = ''): array
    {
        if ($user === null || ! Gate::forUser($user)->allows('viewAny', FinancialTransaction::class)) {
            if ($user !== null && $user->role === 'supervisor') {
                return [
                    'authorized' => false,
                    'tool' => 'finance_summary',
                    'message' => 'Maaf, rincian keuangan dan pemasukan terverifikasi memerlukan tingkat akses yang lebih tinggi (hanya dapat diakses oleh Admin Operasional dan Superadmin). Sebagai Supervisor, Anda memiliki akses pada pengawasan tiket dan estimasi teknis.',
                ];
            }

            return [
                'authorized' => false,
                'tool' => 'finance_summary',
                'message' => 'Maaf, data keuangan memerlukan tingkat akses yang lebih tinggi dan hanya dapat diakses oleh Admin Operasional dan Superadmin.',
            ];
        }

        $this->recordAudit($user, 'finance_summary');

        // 1. Date range or monthly financial query ("Pemasukan bulan ini berapa?", "Kalau bulan lalu?", "Pemasukan hari ini")
        $period = $this->resolveFinancePeriod($normalizedMessage);
        if ($period !== null) {
            $stats = $this->operationalData->financeIncomeByDateRange($period['start'], $period['end']);

            return [
                'authorized' => true,
                'tool' => 'finance_summary',
                'message' => "Total pemasukan terverifikasi pada {$period['label']}: Rp ".number_format($stats['verified_income'], 0, ',', '.')
                    ." ({$stats['verified_income_count']} transaksi terverifikasi)."
                    .' Pengeluaran terverifikasi: Rp '.number_format($stats['verified_expense'], 0, ',', '.')
                    ."; transaksi pending: {$stats['pending_count']} (Rp ".number_format($stats['pending_income'], 0, ',', '.')
                    ."); ditolak/void: {$stats['rejected_count']}.",
                'actions' => [['label' => 'Buka Keuangan', 'href' => route('finance.index')]],
            ];
        }

        // 2. Income by transaction category ("Pemasukan per kategori", "Pemasukan kategori service_payment")
        if (preg_match('/\b(kategori)\b/', $normalizedMessage) === 1) {
            $categoryFilter = null;
            if (preg_match('/\bkategori\s+([a-z0-9_-]+)\b/', $normalizedMessage, $matches) === 1
                && ! in_array($matches[1], ['apa', 'saja', 'berapa', 'transaksi', 'pemasukan', 'pendapatan', 'yang', 'ada'], true)) {
                $categoryFilter = $matches[1];
            }

            $categories = $this->operationalData->financeIncomeByCategory($categoryFilter);
            if ($categories->isEmpty()) {
                return [
                    'authorized' => true,
                    'tool' => 'finance_summary',
                    'message' => 'Belum ada transaksi pemasukan terverifikasi untuk kategori tersebut di database keuangan AD Com.',
                    'actions' => [['label' => 'Buka Keuangan', 'href' => route('finance.index')]],
                ];
            }

            $breakdown = $categories->map(
                fn (array $item): string => "{$item['category']}: Rp ".number_format($item['total_amount'], 0, ',', '.')." ({$item['transactions_count']} transaksi terverifikasi)"
            )->implode('; ');
            $totalVerified = (int) $categories->sum('total_amount');

            return [
                'authorized' => true,
                'tool' => 'finance_summary',
                'message' => 'Pemasukan terverifikasi berdasarkan kategori: '.$breakdown.'. Total pemasukan terverifikasi: Rp '.number_format($totalVerified, 0, ',', '.').'.',
                'actions' => [['label' => 'Buka Keuangan', 'href' => route('finance.index')]],
            ];
        }

        $summary = $this->operationalData->financeSummary();

        // 3. Pending transactions query ("Berapa transaksi pending?", "Total transaksi menunggu verifikasi")
        if (preg_match('/\b(pending|menunggu verifikasi)\b/', $normalizedMessage) === 1
            && preg_match('/\b(ringkasan|laporan|saldo)\b/', $normalizedMessage) === 0) {
            return [
                'authorized' => true,
                'tool' => 'finance_summary',
                'message' => "Saat ini terdapat {$summary['pending_count']} transaksi berstatus pending (menunggu verifikasi operator) dengan total nominal Rp "
                    .number_format($summary['pending_amount'], 0, ',', '.')
                    .'. Transaksi pending belum dihitung sebagai pemasukan terverifikasi (total pemasukan terverifikasi saat ini: Rp '
                    .number_format($summary['verified_income'], 0, ',', '.').').',
                'actions' => [['label' => 'Buka Keuangan', 'href' => route('finance.index')]],
            ];
        }

        // 4. Rejected / void transactions query ("Berapa transaksi ditolak?", "Total transaksi rejected")
        if (preg_match('/\b(ditolak|rejected|void|batal)\b/', $normalizedMessage) === 1) {
            return [
                'authorized' => true,
                'tool' => 'finance_summary',
                'message' => "Saat ini terdapat {$summary['rejected_count']} transaksi berstatus ditolak/void dengan total nominal Rp "
                    .number_format($summary['rejected_amount'], 0, ',', '.')
                    .'. Transaksi yang ditolak tidak dihitung dalam pemasukan terverifikasi (total pemasukan terverifikasi saat ini: Rp '
                    .number_format($summary['verified_income'], 0, ',', '.').').',
                'actions' => [['label' => 'Buka Keuangan', 'href' => route('finance.index')]],
            ];
        }

        // 5. Total transaction count query ("Berapa jumlah transaksi?", "Ada berapa transaksi keuangan?")
        if (preg_match('/\b(jumlah transaksi|berapa transaksi|ada berapa transaksi|total transaksi|banyak transaksi)\b/', $normalizedMessage) === 1) {
            return [
                'authorized' => true,
                'tool' => 'finance_summary',
                'message' => "Terdapat total {$summary['total_transactions']} transaksi tercatat di database keuangan AD Com: "
                    ."{$summary['verified_transactions']} terverifikasi, {$summary['pending_count']} pending (menunggu verifikasi), dan {$summary['rejected_count']} ditolak/void. "
                    .'Total pemasukan terverifikasi: Rp '.number_format($summary['verified_income'], 0, ',', '.').'.',
                'actions' => [['label' => 'Buka Keuangan', 'href' => route('finance.index')]],
            ];
        }

        // 6. Default: Total verified income & full financial summary
        return [
            'authorized' => true,
            'tool' => 'finance_summary',
            'message' => 'Ringkasan keuangan terverifikasi: Pemasukan Rp '.number_format($summary['verified_income'], 0, ',', '.')
                ." ({$summary['verified_income_count']} transaksi terverifikasi)"
                .', Pengeluaran Rp '.number_format($summary['verified_expense'], 0, ',', '.')
                .', Saldo Rp '.number_format($summary['balance'], 0, ',', '.')
                .". Saat ini ada {$summary['pending_count']} transaksi menunggu verifikasi (Rp ".number_format($summary['pending_amount'], 0, ',', '.')
                .") dan {$summary['rejected_count']} transaksi ditolak/void.",
            'actions' => [['label' => 'Buka Keuangan', 'href' => route('finance.index')]],
        ];
    }

    /**
     * Resolve a date range or monthly period from a normalized financial query.
     *
     * @return array{label: string, start: CarbonInterface, end: CarbonInterface}|null
     */
    private function resolveFinancePeriod(string $normalizedMessage): ?array
    {
        $now = Carbon::now();

        if (preg_match('/\b(\d{4})\s+(\d{2})\s+(\d{2})\b.*\b(\d{4})\s+(\d{2})\s+(\d{2})\b/', $normalizedMessage, $m) === 1) {
            $start = Carbon::createFromDate((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
            $end = Carbon::createFromDate((int) $m[4], (int) $m[5], (int) $m[6])->endOfDay();

            return [
                'label' => 'periode '.$start->format('Y-m-d').' s/d '.$end->format('Y-m-d'),
                'start' => $start,
                'end' => $end,
            ];
        }

        if (preg_match('/\bbulan (lalu|kemarin|sebelumnya)\b/', $normalizedMessage) === 1) {
            $prev = $now->copy()->subMonthNoOverflow();

            return [
                'label' => 'bulan lalu ('.$prev->translatedFormat('F Y').')',
                'start' => $prev->copy()->startOfMonth(),
                'end' => $prev->copy()->endOfMonth(),
            ];
        }

        if (preg_match('/\bbulan (ini|sekarang)\b/', $normalizedMessage) === 1) {
            return [
                'label' => 'bulan ini ('.$now->translatedFormat('F Y').')',
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth(),
            ];
        }

        $months = [
            'januari' => 1,
            'februari' => 2,
            'maret' => 3,
            'april' => 4,
            'mei' => 5,
            'juni' => 6,
            'juli' => 7,
            'agustus' => 8,
            'september' => 9,
            'oktober' => 10,
            'november' => 11,
            'desember' => 12,
        ];

        foreach ($months as $monthName => $monthNumber) {
            if (preg_match('/\b'.$monthName.'(?:\s+(\d{4}))?\b/', $normalizedMessage, $m) === 1) {
                $year = isset($m[1]) ? (int) $m[1] : $now->year;
                $target = Carbon::createFromDate($year, $monthNumber, 1);

                return [
                    'label' => 'bulan '.$target->translatedFormat('F Y'),
                    'start' => $target->copy()->startOfMonth(),
                    'end' => $target->copy()->endOfMonth(),
                ];
            }
        }

        if (preg_match('/\bhari ini\b/', $normalizedMessage) === 1) {
            return [
                'label' => 'hari ini ('.$now->translatedFormat('d M Y').')',
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ];
        }

        if (preg_match('/\bminggu (lalu|kemarin)\b/', $normalizedMessage) === 1) {
            $prevWeek = $now->copy()->subWeek();

            return [
                'label' => 'minggu lalu',
                'start' => $prevWeek->copy()->startOfWeek(),
                'end' => $prevWeek->copy()->endOfWeek(),
            ];
        }

        if (preg_match('/\bminggu ini\b/', $normalizedMessage) === 1) {
            return [
                'label' => 'minggu ini',
                'start' => $now->copy()->startOfWeek(),
                'end' => $now->copy()->endOfWeek(),
            ];
        }

        if (preg_match('/\btahun (lalu|kemarin)\b/', $normalizedMessage) === 1) {
            $prevYear = $now->copy()->subYear();

            return [
                'label' => 'tahun lalu ('.$prevYear->year.')',
                'start' => $prevYear->copy()->startOfYear(),
                'end' => $prevYear->copy()->endOfYear(),
            ];
        }

        if (preg_match('/\btahun ini\b/', $normalizedMessage) === 1) {
            return [
                'label' => 'tahun ini ('.$now->year.')',
                'start' => $now->copy()->startOfYear(),
                'end' => $now->copy()->endOfYear(),
            ];
        }

        if (preg_match('/\bkemarin\b/', $normalizedMessage) === 1) {
            $yesterday = $now->copy()->subDay();

            return [
                'label' => 'kemarin ('.$yesterday->translatedFormat('d M Y').')',
                'start' => $yesterday->copy()->startOfDay(),
                'end' => $yesterday->copy()->endOfDay(),
            ];
        }

        return null;
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runInventorySummary(?User $user): array
    {
        if ($user === null || ! Gate::forUser($user)->allows('viewAny', InventoryItem::class)) {
            return [
                'authorized' => false,
                'tool' => 'inventory_summary',
                'message' => 'Maaf, data stok dan inventaris gudang hanya tersedia untuk peran internal operasional.',
            ];
        }

        $totalItems = InventoryItem::query()->where('is_active', true)->count();
        $lowStockItems = InventoryItem::query()
            ->where('is_active', true)
            ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
            ->orderBy('quantity_on_hand')
            ->limit(5)
            ->get(['name', 'quantity_on_hand', 'unit']);
        $lowCount = InventoryItem::query()
            ->where('is_active', true)
            ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
            ->count();
        $this->recordAudit($user, 'inventory_summary');

        $names = $lowStockItems->map(fn (InventoryItem $item): string => "{$item->name} ({$item->quantity_on_hand} {$item->unit})")->implode(', ');
        $detail = $lowCount > 0 ? " Item kritis: {$names}." : ' Semua stok dalam kondisi aman.';

        return [
            'authorized' => true,
            'tool' => 'inventory_summary',
            'message' => "Terdapat {$totalItems} jenis item aktif dengan {$lowCount} item berstatus stok kritis.{$detail}",
            'actions' => [['label' => 'Buka Inventaris', 'href' => route('inventory.index')]],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}
     */
    private function runUnassignedQueue(?User $user): array
    {
        if ($user === null || ! in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return [
                'authorized' => false,
                'tool' => 'unassigned_queue_summary',
                'message' => 'Maaf, antrean tiket tanpa teknisi hanya dapat dilihat oleh Supervisor, Admin, atau Superadmin.',
            ];
        }

        $unassigned = ServiceOrder::query()
            ->whereNull('assigned_technician_id')
            ->whereNotIn('status', ['completed', 'closed', 'cancelled'])
            ->count();
        $counts = $this->operationalData->technicianCounts();
        $this->recordAudit($user, 'unassigned_queue_summary');

        return [
            'authorized' => true,
            'tool' => 'unassigned_queue_summary',
            'message' => "Ada {$unassigned} tiket aktif yang belum memiliki PIC teknisi. Saat ini tersedia {$counts['available']} dari {$counts['active']} teknisi aktif.",
            'actions' => [['label' => 'Lihat Tiket Tanpa PIC', 'href' => route('orders.index', ['group' => 'unassigned'])]],
        ];
    }

    /**
     * @return array{authorized: bool, tool: string, message: string, actions?: list<array{label: string, href: string}>}|null
     */
    private function runUserOrders(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $query = ServiceOrder::query()->whereNotIn('status', ['closed', 'cancelled']);
        if ($user->role === 'customer') {
            $query->where('customer_id', $user->id);
        } elseif ($user->role === 'technician') {
            $query->where(function (Builder $q) use ($user): void {
                $q->where('assigned_technician_id', $user->id)
                    ->orWhereHas('activeTechnicianAssignments', fn (Builder $a) => $a->where('user_id', $user->id));
            });
        }

        $orders = $query->latest()->limit(5)->get(['id', 'ticket_number', 'status', 'customer_name']);
        $this->recordAudit($user, 'user_orders_summary');

        if ($orders->isEmpty()) {
            return [
                'authorized' => true,
                'tool' => 'user_orders_summary',
                'message' => 'Belum ada tiket aktif yang terhubung dengan akun Anda saat ini.',
                'actions' => [['label' => 'Buka Dashboard', 'href' => route('dashboard')]],
            ];
        }

        $summary = $orders->map(fn (ServiceOrder $order): string => "{$order->ticket_number} ({$order->status})")->implode(', ');

        return [
            'authorized' => true,
            'tool' => 'user_orders_summary',
            'message' => "Tiket aktif Anda ({$orders->count()} terbaru): {$summary}.",
            'actions' => [['label' => 'Lihat Semua Tiket', 'href' => route('orders.index')]],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function recordAudit(User $user, string $tool, string $event = 'assistant.tool_executed', array $params = []): void
    {
        $safeParams = collect($params)
            ->only(['period', 'start_date', 'end_date', 'category', 'severity', 'ticket_number', 'order_id'])
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->all();

        DB::table('audit_logs')->insert([
            'actor_id' => $user->id,
            'event' => $event,
            'auditable_type' => User::class,
            'auditable_id' => (string) $user->id,
            'before' => null,
            'after' => json_encode(array_filter([
                'tool' => $tool,
                'role' => $user->role,
                'params' => $safeParams !== [] ? $safeParams : null,
            ], fn (mixed $v): bool => $v !== null)),
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}
