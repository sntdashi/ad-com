<?php

namespace App\Http\Controllers;

use App\Models\CustomerAsset;
use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\ServiceOrder;
use App\Models\TechnicianReview;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\AssistantOperationalData;
use App\Services\Sla\SlaConfig;
use App\Services\Sla\SlaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AssistantOperationalData $operationalData, SlaService $slaService): Response
    {
        Gate::authorize('viewAny', ServiceOrder::class);

        $user = $request->user();
        $query = ServiceOrder::query();

        if ($user->role === 'customer') {
            $query->where('customer_id', $user->id);
        } elseif ($user->role === 'technician') {
            $query->where(function (Builder $technicianOrders) use ($user): void {
                $technicianOrders
                    ->where('assigned_technician_id', $user->id)
                    ->orWhereHas('activeTechnicianAssignments', function (Builder $assignments) use ($user): void {
                        $assignments->where('user_id', $user->id);
                    });
            });
        } elseif ($user->role === 'warehouse') {
            $query->whereRaw('1 = 0');
        }

        $slaConfig = SlaConfig::get();
        $now = now();
        $canViewSlaMetrics = in_array($user->role, ['operator', 'admin', 'supervisor', 'technician'], true);

        return Inertia::render('dashboard', [
            'role' => $user->role,
            'signals' => $this->signalsFor($user, $operationalData),
            'stats' => [
                'active' => (clone $query)->whereNotIn('status', ['completed', 'closed', 'cancelled'])->count(),
                'waiting' => (clone $query)->whereIn('status', ['new', 'needs_info', 'waiting_approval'])->count(),
                'scheduled' => (clone $query)->whereIn('status', ['scheduled', 'on_the_way'])->count(),
                'completed' => (clone $query)->whereIn('status', ['completed', 'warranty', 'closed'])->count(),
            ],
            'sla' => $canViewSlaMetrics ? $slaService->metricsForQuery(clone $query, $now) : null,
            'orders' => (clone $query)
                ->with([
                    'service:id,name,code',
                    'assignedTechnician:id,name',
                    'activeTechnicianAssignments.user:id,name',
                ])
                ->latest()
                ->limit(12)
                ->get()
                ->map(fn (ServiceOrder $order) => [
                    'id' => $order->id,
                    'ticket_number' => $order->ticket_number,
                    'customer_name' => $order->customer_name,
                    'service' => $order->service->name,
                    'service_code' => $order->service->code,
                    'status' => $order->status,
                    'risk_level' => $order->risk_level,
                    'preferred_date' => $order->preferred_date?->format('Y-m-d'),
                    'scheduled_at' => $order->scheduled_at?->translatedFormat('d M Y, H.i'),
                    'estimated_total' => $order->estimated_total,
                    'technician' => $order->assignedTechnician?->name,
                    'technician_team' => $order->activeTechnicianAssignments
                        ->pluck('user.name')
                        ->filter()
                        ->whenEmpty(function ($technicians) use ($order) {
                            return $order->assignedTechnician
                                ? $technicians->push($order->assignedTechnician->name)
                                : $technicians;
                        })
                        ->values(),
                    'created_at' => $order->created_at->diffForHumans(),
                    'href' => route('orders.show', $order),
                    'sla' => $slaService->evaluate($order, $now, $slaConfig),
                ]),
        ]);
    }

    /** @return list<array{label: string, value: int|string, detail: string, href: string|null}> */
    private function signalsFor(User $user, AssistantOperationalData $operationalData): array
    {
        $role = $user->role;

        if (in_array($role, ['operator', 'admin'], true)) {
            $technicianCounts = $operationalData->technicianCounts();

            return [
                [
                    'label' => 'Tiket tanpa PIC',
                    'value' => ServiceOrder::query()->whereNull('assigned_technician_id')->whereNotIn('status', ['completed', 'closed', 'cancelled'])->count(),
                    'detail' => 'Perlu penugasan tim',
                    'href' => route('orders.index', ['group' => 'unassigned']),
                ],
                [
                    'label' => 'Transaksi pending',
                    'value' => FinancialTransaction::query()->where('status', 'pending')->count(),
                    'detail' => 'Menunggu verifikasi operator',
                    'href' => route('finance.index'),
                ],
                [
                    'label' => 'Stok kritis',
                    'value' => InventoryItem::query()->where('is_active', true)->whereColumn('quantity_on_hand', '<=', 'reorder_level')->count(),
                    'detail' => 'Di bawah batas restock',
                    'href' => route('inventory.index'),
                ],
                [
                    'label' => 'Akun tim aktif',
                    'value' => User::query()->where('is_active', true)->whereIn('role', ['operator', 'admin', 'supervisor', 'technician', 'warehouse'])->count(),
                    'detail' => 'Seluruh unit operasional',
                    'href' => route('team.index'),
                ],
                [
                    'label' => 'Total teknisi',
                    'value' => $technicianCounts['total'],
                    'detail' => 'Akun berperan sebagai teknisi',
                    'href' => route('technicians.performance'),
                ],
                [
                    'label' => 'Teknisi aktif',
                    'value' => $technicianCounts['active'],
                    'detail' => "Dari {$technicianCounts['total']} akun teknisi",
                    'href' => route('technicians.performance'),
                ],
                [
                    'label' => 'Teknisi tersedia',
                    'value' => $technicianCounts['available'],
                    'detail' => 'Tidak sedang menangani tiket berjalan',
                    'href' => route('technicians.performance'),
                ],
                [
                    'label' => 'Klaim garansi aktif',
                    'value' => WarrantyClaim::query()->whereIn('status', WarrantyClaim::ACTIVE_STATUSES)->count(),
                    'detail' => 'Menunggu verifikasi / tindak lanjut',
                    'href' => route('warranties.index'),
                ],
            ];
        }

        if ($role === 'supervisor') {
            return [
                [
                    'label' => 'Butuh verifikasi',
                    'value' => ServiceOrder::query()->where('status', 'verification')->count(),
                    'detail' => 'Hasil kerja menunggu review',
                    'href' => route('orders.index', ['status' => 'verification']),
                ],
                [
                    'label' => 'Rating perlu tindak lanjut',
                    'value' => $this->lowRatingsCount(),
                    'detail' => 'Nilai ≤2 dalam 30 hari',
                    'href' => route('technicians.performance'),
                ],
                [
                    'label' => 'Risiko tinggi aktif',
                    'value' => ServiceOrder::query()->where('risk_level', 'high')->whereNotIn('status', ['completed', 'closed', 'cancelled'])->count(),
                    'detail' => 'Prioritas kendali keselamatan',
                    'href' => route('orders.index', ['group' => 'high_risk']),
                ],
                [
                    'label' => 'Klaim & garansi segera berakhir',
                    'value' => WarrantyClaim::query()->whereIn('status', WarrantyClaim::ACTIVE_STATUSES)->count()
                        + Warranty::query()->where('status', 'active')->where('expires_at', '>', now())->where('expires_at', '<=', now()->addDays(7))->count(),
                    'detail' => 'Pantau kelayakan klaim & perpanjangan',
                    'href' => route('warranties.index'),
                ],
            ];
        }

        if ($role === 'warehouse') {
            return [
                [
                    'label' => 'Jenis barang aktif',
                    'value' => InventoryItem::query()->where('is_active', true)->count(),
                    'detail' => 'Katalog inventaris',
                    'href' => route('inventory.index'),
                ],
                [
                    'label' => 'Stok kritis',
                    'value' => InventoryItem::query()->where('is_active', true)->whereColumn('quantity_on_hand', '<=', 'reorder_level')->count(),
                    'detail' => 'Perlu pengadaan',
                    'href' => route('inventory.index'),
                ],
            ];
        }

        if ($role === 'technician') {
            $average = $user->receivedTechnicianReviews()->avg('rating');

            return [
                [
                    'label' => 'Rating saya',
                    'value' => $average === null ? '—' : number_format((float) $average, 1),
                    'detail' => $user->receivedTechnicianReviews()->count().' ulasan terverifikasi',
                    'href' => route('technicians.performance'),
                ],
            ];
        }

        if ($role === 'customer') {
            return [
                [
                    'label' => 'Perangkat terdaftar',
                    'value' => CustomerAsset::query()->where('customer_id', $user->id)->count(),
                    'detail' => 'Lihat riwayat & detail perangkat',
                    'href' => route('devices.index'),
                ],
                [
                    'label' => 'Garansi aktif',
                    'value' => Warranty::query()
                        ->where('customer_id', $user->id)
                        ->where('status', 'active')
                        ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                        ->count(),
                    'detail' => 'Cakupan layanan & spare-part Anda',
                    'href' => route('devices.index'),
                ],
                [
                    'label' => 'Menunggu penilaian',
                    'value' => ServiceOrder::query()
                        ->whereBelongsTo($user, 'customer')
                        ->whereIn('status', ['completed', 'warranty', 'closed'])
                        ->whereDoesntHave('technicianReviews')
                        ->count(),
                    'detail' => 'Bantu evaluasi teknisi',
                    'href' => route('dashboard'),
                ],
            ];
        }

        return [];
    }

    private function lowRatingsCount(): int
    {
        return TechnicianReview::query()
            ->where('rating', '<=', 2)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
    }
}
