<?php

namespace App\Services\Notifications;

class NotificationCatalog
{
    /**
     * Authoritative notification category definitions and role access boundaries.
     *
     * @var array<string, array{
     *     label: string,
     *     description: string,
     *     mandatory: bool,
     *     roles: list<string>
     * }>
     */
    public const CATEGORIES = [
        'orders' => [
            'label' => 'Pesanan & Tiket',
            'description' => 'Pembuatan tiket baru, perubahan status pesanan, penugasan, dan pesan percakapan tiket.',
            'mandatory' => false,
            'roles' => ['operator', 'admin', 'supervisor', 'technician', 'customer'],
        ],
        'sla' => [
            'label' => 'SLA & Eskalasi',
            'description' => 'Peringatan mendekati tenggat SLA, pelanggaran SLA, jeda, dan kelanjutan timer SLA.',
            'mandatory' => false,
            'roles' => ['operator', 'admin', 'supervisor', 'technician'],
        ],
        'workforce' => [
            'label' => 'Workforce & Pengerjaan',
            'description' => 'Mulai pengerjaan, selesai sesi kerja teknisi, bukti pengerjaan, dan status ketersediaan.',
            'mandatory' => false,
            'roles' => ['operator', 'admin', 'supervisor', 'technician', 'customer'],
        ],
        'inventory' => [
            'label' => 'Inventaris & Spare-Part',
            'description' => 'Stok kritis, stok habis, permintaan, pengeluaran, dan pengembalian spare-part.',
            'mandatory' => false,
            'roles' => ['operator', 'admin', 'supervisor', 'technician', 'warehouse'],
        ],
        'warranty' => [
            'label' => 'Garansi & Klaim',
            'description' => 'Aktivasi garansi, pengingat masa berlaku, kedaluwarsa, pengajuan, dan tinjauan klaim.',
            'mandatory' => false,
            'roles' => ['operator', 'admin', 'supervisor', 'customer'],
        ],
        'finance' => [
            'label' => 'Keuangan & Verifikasi',
            'description' => 'Penawaran biaya, penerbitan tagihan, pembayaran, pembatalan, dan verifikasi transaksi keuangan.',
            'mandatory' => false,
            'roles' => ['operator', 'admin', 'supervisor', 'customer'],
        ],
        'security' => [
            'label' => 'Keamanan & Akun Wajib',
            'description' => 'Peringatan perubahan akses akun, keamanan autentikasi, dan notifikasi kebijakan wajib.',
            'mandatory' => true,
            'roles' => ['operator', 'admin', 'supervisor', 'technician', 'warehouse', 'customer'],
        ],
    ];

    /**
     * Resolve category slug for an event key.
     */
    public static function categoryForEvent(string $eventKey): string
    {
        return match (true) {
            str_starts_with($eventKey, 'sla.') => 'sla',
            str_starts_with($eventKey, 'work.') => 'workforce',
            str_starts_with($eventKey, 'inventory.') => 'inventory',
            str_starts_with($eventKey, 'warranty.') => 'warranty',
            str_starts_with($eventKey, 'finance.') => 'finance',
            str_starts_with($eventKey, 'security.') => 'security',
            default => 'orders',
        };
    }

    /**
     * Derive operational severity/priority for an event key.
     */
    public static function priorityForEvent(string $eventKey, ?string $override = null): string
    {
        if (in_array($override, ['normal', 'high', 'critical'], true)) {
            return $override;
        }

        return match ($eventKey) {
            'sla.breached', 'security.alert', 'security.role_changed' => 'critical',
            'inventory.low_stock',
            'sla.approaching_response',
            'sla.approaching_resolution',
            'warranty.claim_submitted',
            'warranty.approaching_expiration',
            'warranty.expired',
            'finance.invoice_cancelled' => 'high',
            default => 'normal',
        };
    }

    /**
     * Whether a category or event is mandatory and cannot be disabled by user preferences.
     */
    public static function isMandatoryCategory(string $category): bool
    {
        return (bool) (self::CATEGORIES[$category]['mandatory'] ?? false);
    }

    /**
     * Categories permitted for the given user role.
     *
     * @return array<string, array{label: string, description: string, mandatory: bool, roles: list<string>}>
     */
    public static function categoriesForRole(string $role): array
    {
        return array_filter(
            self::CATEGORIES,
            static fn (array $meta): bool => in_array($role, $meta['roles'], true),
        );
    }
}
