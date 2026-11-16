<?php

namespace App\Services\Assistant;

use App\Models\User;

class UserContextResolver
{
    /**
     * @param  array{route?: string|null, order_id?: string|null}  $pageContext
     * @return array{
     *     is_authenticated: bool,
     *     user_id: int|null,
     *     name: string|null,
     *     role: string,
     *     role_label: string,
     *     role_description: string,
     *     allowed_scopes: list<string>,
     *     page_context: array{route: string|null, order_id: string|null}
     * }
     */
    public function resolve(?User $user, array $pageContext = []): array
    {
        $role = $user !== null ? $user->role : 'guest';

        return [
            'is_authenticated' => $user !== null,
            'user_id' => $user?->id,
            'name' => $user?->name,
            'role' => $role,
            'role_label' => match ($role) {
                'operator' => 'Superadmin',
                'admin' => 'Admin Operasional',
                'supervisor' => 'Supervisor',
                'technician' => 'Teknisi',
                'warehouse' => 'Gudang',
                'customer' => 'Pelanggan',
                default => 'Tamu',
            },
            'role_description' => match ($role) {
                'operator' => 'Peran ini memiliki akses kendali penuh atas operasional platform AD Com, manajemen pengguna dan teknisi, katalog layanan, inventaris, keuangan, konfigurasi sistem, serta log audit.',
                'admin' => 'Peran ini bertugas mengelola operasional harian AD Com, antrean pesanan layanan, penugasan teknisi, inventaris, serta verifikasi transaksi keuangan.',
                'supervisor' => 'Peran ini bertugas mengawasi pelaksanaan pekerjaan teknis, memantau antrean tiket layanan, serta mengevaluasi kesiapan dan kinerja tim teknisi.',
                'technician' => 'Peran ini merupakan anggota tim teknisi khusus AD Com yang menangani pemeriksaan, pengerjaan teknis, dan pembaruan progres tiket layanan yang ditugaskan.',
                'warehouse' => 'Peran ini bertugas mengelola stok suku cadang, pencatatan mutasi keluar-masuk barang, dan pemantauan stok kritis di gudang AD Com.',
                'customer' => 'Peran ini adalah pengguna layanan AD Com yang dapat membuat pesanan layanan jaringan, CCTV, atau komputer serta memantau progres tiket miliknya.',
                default => 'Anda belum masuk ke akun AD Com dan saat ini mengakses portal sebagai pengunjung publik.',
            },
            'allowed_scopes' => match ($role) {
                'operator' => ['services', 'orders', 'team', 'inventory', 'finance', 'system', 'audit'],
                'admin' => ['services', 'orders', 'team', 'inventory', 'finance'],
                'supervisor' => ['services', 'orders', 'team'],
                'technician' => ['services', 'assigned_orders', 'checklists'],
                'warehouse' => ['services', 'inventory'],
                'customer' => ['services', 'own_orders'],
                default => ['services', 'public_tracking'],
            },
            'page_context' => [
                'route' => isset($pageContext['route']) ? (string) $pageContext['route'] : null,
                'order_id' => isset($pageContext['order_id']) ? (string) $pageContext['order_id'] : null,
            ],
        ];
    }
}
