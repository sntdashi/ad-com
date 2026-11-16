<?php

namespace App\Services\Analytics;

use App\Models\User;

class AnalyticsAccess
{
    /** @var list<string> */
    public const ALL_SECTIONS = [
        'executive',
        'financial',
        'services',
        'workforce',
        'inventory',
        'customers',
        'insights',
    ];

    /** @var list<string> */
    public const ALL_EXPORT_TYPES = [
        'executive',
        'financial',
        'order-profitability',
        'services',
        'workforce',
        'inventory',
        'customers',
    ];

    public function canAccessHub(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return in_array($user->role, ['operator', 'admin', 'supervisor', 'technician', 'warehouse'], true);
    }

    /**
     * Ordered section slugs accessible to the authenticated viewer.
     *
     * @return list<string>
     */
    public function allowedSections(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }

        return match ($user->role) {
            'operator', 'admin' => self::ALL_SECTIONS,
            'supervisor' => ['executive', 'financial', 'services', 'workforce', 'customers', 'insights'],
            'technician' => ['workforce', 'insights'],
            'warehouse' => ['inventory', 'insights'],
            default => [],
        };
    }

    public function canViewSection(User $user, string $section): bool
    {
        return in_array($section, $this->allowedSections($user), true);
    }

    public function defaultSection(User $user): string
    {
        $sections = $this->allowedSections($user);

        return $sections[0] ?? 'workforce';
    }

    /**
     * Whether the viewer may see executive or operational financial summaries (quotes/invoices/profitability).
     */
    public function canViewFinancialSummary(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return in_array($user->role, ['operator', 'admin', 'supervisor'], true);
    }

    /**
     * Whether the viewer may access raw expense/ledger breakdowns and full financial exports.
     */
    public function canViewRawFinanceLedger(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return in_array($user->role, ['operator', 'admin'], true);
    }

    /**
     * Whether the viewer may view customer PII (masked/hidden for aggregate oversight roles).
     */
    public function canViewCustomerPii(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return in_array($user->role, ['operator', 'admin'], true);
    }

    /**
     * Technicians are strictly scoped to their own performance & assigned workload.
     */
    public function scopedTechnicianId(User $user): ?int
    {
        return $user->role === 'technician' ? (int) $user->id : null;
    }

    /**
     * Export report keys permitted for this user.
     *
     * @return list<string>
     */
    public function allowedExportTypes(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }

        return match ($user->role) {
            'operator', 'admin' => self::ALL_EXPORT_TYPES,
            'supervisor' => ['executive', 'order-profitability', 'services', 'workforce'],
            'warehouse' => ['inventory'],
            default => [],
        };
    }

    public function canExportType(User $user, string $type): bool
    {
        return in_array($type, $this->allowedExportTypes($user), true);
    }

    /**
     * Insight categories permitted for the viewer.
     *
     * @return list<string>
     */
    public function allowedInsightCategories(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }

        return match ($user->role) {
            'operator', 'admin' => ['sla', 'service', 'inventory', 'finance', 'workforce', 'customer'],
            'supervisor' => ['sla', 'service', 'workforce', 'finance', 'customer'],
            'technician' => ['workforce'],
            'warehouse' => ['inventory'],
            default => [],
        };
    }

    /**
     * Mask or format customer labels depending on role PII visibility.
     */
    public function formatCustomerLabel(?string $name, ?string $email, User $viewer, ?int $customerId = null): string
    {
        $cleanName = trim((string) $name);
        if ($this->canViewCustomerPii($viewer)) {
            if ($cleanName !== '' && $email !== null && $email !== '') {
                return "{$cleanName} ({$email})";
            }

            return $cleanName !== '' ? $cleanName : ($email ?? 'Pelanggan');
        }

        if ($customerId !== null) {
            return sprintf('Pelanggan #%d', $customerId);
        }

        if ($cleanName === '') {
            return 'Pelanggan';
        }

        return mb_substr($cleanName, 0, 1).'***';
    }

    /**
     * @return array{
     *     role: string,
     *     allowed_sections: list<string>,
     *     default_section: string,
     *     allowed_exports: list<string>,
     *     can_view_financial_summary: bool,
     *     can_view_raw_finance_ledger: bool,
     *     can_view_customer_pii: bool,
     *     scoped_technician_id: int|null
     * }
     */
    public function toCapabilityArray(User $user): array
    {
        return [
            'role' => $user->role,
            'allowed_sections' => $this->allowedSections($user),
            'default_section' => $this->defaultSection($user),
            'allowed_exports' => $this->allowedExportTypes($user),
            'can_view_financial_summary' => $this->canViewFinancialSummary($user),
            'can_view_raw_finance_ledger' => $this->canViewRawFinanceLedger($user),
            'can_view_customer_pii' => $this->canViewCustomerPii($user),
            'scoped_technician_id' => $this->scopedTechnicianId($user),
        ];
    }
}
