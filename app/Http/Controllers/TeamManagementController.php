<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTeamMemberRequest;
use App\Http\Requests\UpdateTeamMemberRequest;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class TeamManagementController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage-team');

        $role = $request->string('role')->trim()->value();
        $filter = $request->string('filter')->trim()->value();
        $search = $request->string('search')->trim()->value();

        $allowedRoles = ['operator', 'admin', 'supervisor', 'technician', 'warehouse', 'customer'];
        $activeRole = in_array($role, $allowedRoles, true) ? $role : '';
        $activeFilter = in_array($filter, ['active', 'inactive', 'missing_phone'], true) ? $filter : '';

        $membersQuery = User::query()
            ->withCount(['orderAssignments as active_assignments_count' => fn ($query) => $query->whereNull('released_at')])
            ->withCount('receivedTechnicianReviews')
            ->withAvg('receivedTechnicianReviews as rating_average', 'rating');

        if ($activeRole !== '') {
            $membersQuery->where('role', $activeRole);
        }

        if ($activeFilter === 'active') {
            $membersQuery->where('is_active', true);
        } elseif ($activeFilter === 'inactive') {
            $membersQuery->where('is_active', false);
        } elseif ($activeFilter === 'missing_phone') {
            $membersQuery->where(function ($query): void {
                $query->whereNull('phone')->orWhere('phone', '');
            });
        }

        if ($search !== '') {
            $membersQuery->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('specialty', 'like', "%{$search}%");
            });
        }

        return Inertia::render('operations/team', [
            'role' => $request->user()->role,
            'filters' => [
                'role' => $activeRole,
                'filter' => $activeFilter,
                'search' => $search,
            ],
            'roleCounts' => User::query()
                ->select('role', DB::raw('count(*) as total'))
                ->groupBy('role')
                ->pluck('total', 'role'),
            'rolePermissions' => [
                [
                    'role' => 'operator',
                    'label' => 'Superadmin',
                    'scope' => 'Kontrol penuh sistem & seluruh role',
                    'capabilities' => ['Ubah semua role termasuk Superadmin', 'Pengaturan ADI, layanan & sistem', 'Verifikasi keuangan & audit'],
                ],
                [
                    'role' => 'admin',
                    'label' => 'Admin',
                    'scope' => 'Operasional harian & kelola tim',
                    'capabilities' => ['Kelola tiket & penugasan', 'Kelola tim non-operator', 'Catat & verifikasi keuangan'],
                ],
                [
                    'role' => 'supervisor',
                    'label' => 'Supervisor',
                    'scope' => 'Pengawasan mutu & SLA layanan',
                    'capabilities' => ['Pantau antrean & tiket berisiko', 'Evaluasi performa teknisi', 'Lihat laporan & inventaris'],
                ],
                [
                    'role' => 'technician',
                    'label' => 'Teknisi',
                    'scope' => 'Eksekusi servis & pembaruan progres',
                    'capabilities' => ['Proses tiket yang ditugaskan', 'Kirim progres & minta sparepart', 'Lihat rating terverifikasi'],
                ],
                [
                    'role' => 'warehouse',
                    'label' => 'Gudang',
                    'scope' => 'Stok sparepart & pergerakan barang',
                    'capabilities' => ['Kelola master barang & restock', 'Catat stok masuk/keluar', 'Pantau stok menipis'],
                ],
                [
                    'role' => 'customer',
                    'label' => 'Customer',
                    'scope' => 'Portal pelanggan & pelacakan tiket',
                    'capabilities' => ['Buat pesanan layanan', 'Pantau status tiket & biaya', 'Beri ulasan setelah selesai'],
                ],
            ],
            'members' => $membersQuery
                ->orderByRaw("CASE role WHEN 'operator' THEN 1 WHEN 'admin' THEN 2 WHEN 'supervisor' THEN 3 WHEN 'technician' THEN 4 WHEN 'warehouse' THEN 5 ELSE 6 END")
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'phone' => $user->phone,
                    'avatar_url' => $user->avatar_url,
                    'specialty' => $user->specialty,
                    'bio' => $user->bio,
                    'show_phone_publicly' => $user->show_phone_publicly,
                    'is_active' => $user->is_active,
                    'active_assignments_count' => $user->active_assignments_count,
                    'reviews_count' => (int) $user->getAttribute('received_technician_reviews_count'),
                    'rating_average' => $user->getAttribute('rating_average') === null
                        ? null
                        : round((float) $user->getAttribute('rating_average'), 2),
                    'is_self' => $user->id === $request->user()->id,
                    'can_edit' => $user->id !== $request->user()->id
                        && ($request->user()->role === 'operator' || $user->role !== 'operator'),
                ]),
            'openOrders' => ServiceOrder::query()->whereNotIn('status', ['completed', 'closed', 'cancelled'])->count(),
        ]);
    }

    public function store(StoreTeamMemberRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, $validated): void {
            $member = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'role' => $validated['role'],
                'specialty' => $validated['specialty'] ?? null,
                'password' => Hash::make($validated['password']),
                'email_verified_at' => now(),
                'is_active' => $request->boolean('is_active', true),
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $request->user()->id,
                'event' => 'team.member_created',
                'auditable_type' => User::class,
                'auditable_id' => (string) $member->id,
                'before' => null,
                'after' => json_encode($member->only(['name', 'email', 'role', 'is_active'])),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return back()->with('success', 'Anggota tim baru berhasil ditambahkan.');
    }

    public function update(UpdateTeamMemberRequest $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $before = $user->only(['role', 'phone', 'specialty', 'is_active', 'show_phone_publicly']);
            $user->update($request->validated());

            DB::table('audit_logs')->insert([
                'actor_id' => $request->user()->id,
                'event' => 'team.member_updated',
                'auditable_type' => User::class,
                'auditable_id' => (string) $user->id,
                'before' => json_encode($before),
                'after' => json_encode($user->only(['role', 'phone', 'specialty', 'is_active', 'show_phone_publicly'])),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return back()->with('success', 'Profil dan akses anggota berhasil diperbarui.');
    }
}
