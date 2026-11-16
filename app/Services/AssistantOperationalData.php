<?php

namespace App\Services;

use App\Models\FinancialTransaction;
use App\Models\OrderAssignment;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AssistantOperationalData
{
    /** @var list<string> */
    private const CLOSED_ORDER_STATUSES = ['completed', 'closed', 'cancelled'];

    /** @return array{total: int, active: int, available: int} */
    public function technicianCounts(): array
    {
        $busyIds = $this->busyTechnicianIds();
        $technicians = User::query()->where('role', 'technician');

        return [
            'total' => (clone $technicians)->count(),
            'active' => (clone $technicians)->where('is_active', true)->count(),
            'available' => (clone $technicians)
                ->where('is_active', true)
                ->whereNotIn('id', $busyIds)
                ->count(),
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string, is_active: bool, available: bool}>
     */
    public function listTechnicians(): Collection
    {
        $busySet = array_flip($this->busyTechnicianIds());

        return User::query()
            ->where('role', 'technician')
            ->orderBy('id')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (User $tech): array => [
                'id' => $tech->id,
                'name' => $tech->name,
                'is_active' => (bool) $tech->is_active,
                'available' => (bool) $tech->is_active && ! isset($busySet[$tech->id]),
            ]);
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    public function availableTechnicians(): Collection
    {
        return $this->listTechnicians()
            ->filter(fn (array $tech): bool => $tech['available'])
            ->values()
            ->map(fn (array $tech): array => [
                'id' => $tech['id'],
                'name' => $tech['name'],
            ]);
    }

    /**
     * @return array{total: int, active: int, by_role: array<string, int>}
     */
    public function userSummary(): array
    {
        $byRole = User::query()
            ->selectRaw('role, count(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return [
            'total' => User::query()->count(),
            'active' => User::query()->where('is_active', true)->count(),
            'by_role' => $byRole,
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string, role: string, is_active: bool}>
     */
    public function listUsers(int $limit = 30): Collection
    {
        return User::query()
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'role', 'is_active'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'is_active' => (bool) $user->is_active,
            ]);
    }

    /**
     * @return array{total: int, active: int, inactive: int, with_active_orders: int}
     */
    public function customerSummary(): array
    {
        $customers = User::query()->where('role', 'customer');
        $total = (clone $customers)->count();
        $active = (clone $customers)->where('is_active', true)->count();

        $withActiveOrders = (clone $customers)
            ->whereHas('customerOrders', fn (Builder $q) => $q->whereNotIn('status', self::CLOSED_ORDER_STATUSES))
            ->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => max(0, $total - $active),
            'with_active_orders' => $withActiveOrders,
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string, is_active: bool}>
     */
    public function listCustomers(int $limit = 30, bool $activeOnly = false): Collection
    {
        return User::query()
            ->where('role', 'customer')
            ->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'is_active'])
            ->map(fn (User $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'is_active' => (bool) $customer->is_active,
            ]);
    }

    /**
     * @return array{id: int, name: string, is_active: bool, orders_count: int}|null
     */
    public function customerDetailByName(string $nameQuery): ?array
    {
        $trimmed = trim($nameQuery);
        if ($trimmed === '') {
            return null;
        }

        $customer = User::query()
            ->where('role', 'customer')
            ->where('name', 'like', '%'.$trimmed.'%')
            ->withCount('customerOrders')
            ->first(['id', 'name', 'is_active']);

        if ($customer === null) {
            return null;
        }

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'is_active' => (bool) $customer->is_active,
            'orders_count' => (int) ($customer->customer_orders_count ?? 0),
        ];
    }

    /**
     * @return array{total: int, active: int, inactive: int}
     */
    public function serviceSummary(): array
    {
        $total = Service::query()->count();
        $active = Service::query()->where('is_active', true)->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => max(0, $total - $active),
        ];
    }

    /**
     * @return Collection<int, array{code: string, name: string, pricing_label: string, is_active: bool}>
     */
    public function listServices(bool $activeOnly = true): Collection
    {
        return Service::query()
            ->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['code', 'name', 'pricing_label', 'is_active'])
            ->map(fn (Service $service): array => [
                'code' => $service->code,
                'name' => $service->name,
                'pricing_label' => $service->pricing_label,
                'is_active' => (bool) $service->is_active,
            ]);
    }

    /**
     * @return array{total: int, active: int, unassigned: int, completed: int}
     */
    public function ticketSummary(): array
    {
        $total = ServiceOrder::query()->count();
        $active = ServiceOrder::query()
            ->whereNotIn('status', self::CLOSED_ORDER_STATUSES)
            ->count();
        $unassigned = ServiceOrder::query()
            ->whereNull('assigned_technician_id')
            ->whereNotIn('status', self::CLOSED_ORDER_STATUSES)
            ->count();
        $completed = ServiceOrder::query()
            ->whereIn('status', ['completed', 'closed'])
            ->count();

        return [
            'total' => $total,
            'active' => $active,
            'unassigned' => $unassigned,
            'completed' => $completed,
        ];
    }

    /**
     * @return array{
     *     verified_income: int,
     *     verified_income_count: int,
     *     verified_expense: int,
     *     verified_expense_count: int,
     *     balance: int,
     *     pending_count: int,
     *     pending_amount: int,
     *     pending_income_amount: int,
     *     rejected_count: int,
     *     rejected_amount: int,
     *     total_transactions: int,
     *     verified_transactions: int
     * }
     */
    public function financeSummary(): array
    {
        $verifiedIncomeQuery = FinancialTransaction::query()
            ->where('type', 'income')
            ->where('status', 'verified');
        $verifiedExpenseQuery = FinancialTransaction::query()
            ->where('type', 'expense')
            ->where('status', 'verified');
        $pendingQuery = FinancialTransaction::query()
            ->where('status', 'pending');
        $rejectedQuery = FinancialTransaction::query()
            ->whereIn('status', ['rejected', 'void']);

        $verifiedIncome = (int) (clone $verifiedIncomeQuery)->sum('amount');
        $verifiedExpense = (int) (clone $verifiedExpenseQuery)->sum('amount');

        return [
            'verified_income' => $verifiedIncome,
            'verified_income_count' => (clone $verifiedIncomeQuery)->count(),
            'verified_expense' => $verifiedExpense,
            'verified_expense_count' => (clone $verifiedExpenseQuery)->count(),
            'balance' => $verifiedIncome - $verifiedExpense,
            'pending_count' => (clone $pendingQuery)->count(),
            'pending_amount' => (int) (clone $pendingQuery)->sum('amount'),
            'pending_income_amount' => (int) (clone $pendingQuery)->where('type', 'income')->sum('amount'),
            'rejected_count' => (clone $rejectedQuery)->count(),
            'rejected_amount' => (int) (clone $rejectedQuery)->sum('amount'),
            'total_transactions' => FinancialTransaction::query()->count(),
            'verified_transactions' => FinancialTransaction::query()->where('status', 'verified')->count(),
        ];
    }

    /**
     * @return array{
     *     verified_income: int,
     *     verified_income_count: int,
     *     verified_expense: int,
     *     pending_income: int,
     *     pending_count: int,
     *     rejected_count: int,
     *     total_count: int
     * }
     */
    public function financeIncomeByDateRange(CarbonInterface $start, CarbonInterface $end): array
    {
        $base = FinancialTransaction::query()->whereBetween('occurred_at', [$start, $end]);

        return [
            'verified_income' => (int) (clone $base)->where('type', 'income')->where('status', 'verified')->sum('amount'),
            'verified_income_count' => (clone $base)->where('type', 'income')->where('status', 'verified')->count(),
            'verified_expense' => (int) (clone $base)->where('type', 'expense')->where('status', 'verified')->sum('amount'),
            'pending_income' => (int) (clone $base)->where('type', 'income')->where('status', 'pending')->sum('amount'),
            'pending_count' => (clone $base)->where('status', 'pending')->count(),
            'rejected_count' => (clone $base)->whereIn('status', ['rejected', 'void'])->count(),
            'total_count' => (clone $base)->count(),
        ];
    }

    /**
     * @return Collection<int, array{category: string, total_amount: int, transactions_count: int}>
     */
    public function financeIncomeByCategory(?string $categoryFilter = null): Collection
    {
        return FinancialTransaction::query()
            ->where('type', 'income')
            ->where('status', 'verified')
            ->when(
                $categoryFilter !== null && trim($categoryFilter) !== '',
                fn (Builder $q) => $q->where('category', 'like', '%'.trim((string) $categoryFilter).'%'),
            )
            ->selectRaw('category, SUM(amount) as total_amount, COUNT(*) as transactions_count')
            ->groupBy('category')
            ->orderByDesc('total_amount')
            ->get()
            ->map(fn (FinancialTransaction $row): array => [
                'category' => (string) $row->category,
                'total_amount' => (int) ($row->getAttribute('total_amount') ?? 0),
                'transactions_count' => (int) ($row->getAttribute('transactions_count') ?? 0),
            ]);
    }

    /**
     * @return list<int>
     */
    private function busyTechnicianIds(): array
    {
        $assignmentIds = OrderAssignment::query()
            ->where('assignment_role', 'technician')
            ->whereNull('released_at')
            ->whereHas('serviceOrder', fn (Builder $orders) => $orders->whereNotIn('status', self::CLOSED_ORDER_STATUSES))
            ->pluck('user_id')
            ->all();

        $leadIds = ServiceOrder::query()
            ->whereNotNull('assigned_technician_id')
            ->whereNotIn('status', self::CLOSED_ORDER_STATUSES)
            ->pluck('assigned_technician_id')
            ->all();

        $offDutyIds = User::query()
            ->where('role', 'technician')
            ->whereIn('availability_status', ['busy', 'unavailable', 'on_leave'])
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_map('intval', [...$assignmentIds, ...$leadIds, ...$offDutyIds])));
    }
}
