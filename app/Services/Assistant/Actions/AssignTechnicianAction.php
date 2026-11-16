<?php

namespace App\Services\Assistant\Actions;

use App\Actions\Orders\AssignOrderTeam;
use App\Models\AssistantActionProposal;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Workforce\WorkforceService;
use Illuminate\Support\Facades\Gate;

class AssignTechnicianAction implements AssistantActionHandler
{
    public function __construct(
        private readonly AssignOrderTeam $assignOrderTeam,
        private readonly WorkforceService $workforce,
    ) {}

    public function name(): string
    {
        return 'assign_technician';
    }

    public function label(): string
    {
        return 'Tugaskan Teknisi (PIC & Tim)';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function authorize(User $actor, array $payload): ?string
    {
        if (! $actor->is_active || ! in_array($actor->role, ['operator', 'admin', 'supervisor'], true)) {
            return 'Akses ditolak. Penugasan teknisi hanya diizinkan untuk Operator, Admin Operasional, dan Supervisor.';
        }

        $order = $this->resolveOrder($payload, []);
        if ($order instanceof ServiceOrder && ! Gate::forUser($actor)->allows('updateTeam', $order)) {
            return "Akses ditolak. Anda tidak memiliki izin menugaskan teknisi pada tiket {$order->ticket_number}.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array{route?: string|null, order_id?: string|null, period?: string|null, section?: string|null, start_date?: string|null, end_date?: string|null}  $context
     * @return array{
     *     valid: bool,
     *     payload: array<string, mixed>,
     *     errors: array<string, string>,
     *     missing_fields: list<string>,
     *     message: string|null
     * }
     */
    public function validateAndNormalize(User $actor, array $rawPayload, array $context = []): array
    {
        $order = $this->resolveOrder($rawPayload, $context);
        if (! $order instanceof ServiceOrder) {
            return [
                'valid' => false,
                'payload' => [],
                'errors' => ['service_order_id' => 'Nomor tiket tidak ditemukan.'],
                'missing_fields' => ['ticket_number'],
                'message' => 'Sebutkan nomor tiket yang ingin ditugaskan teknisinya (contoh: "Tugaskan teknisi Andi untuk ADC-20261116-0001").',
            ];
        }

        $match = $this->resolveTechnicianMatch($rawPayload);
        if ($match['ambiguous'] !== []) {
            $ambiguousNames = implode(', ', array_map(
                static fn (User $candidate): string => $candidate->name,
                $match['ambiguous'],
            ));

            return [
                'valid' => false,
                'payload' => ['service_order_id' => $order->id],
                'errors' => ['technician_ids' => 'Nama teknisi ambigu dan cocok dengan lebih dari satu teknisi aktif.'],
                'missing_fields' => ['technician'],
                'message' => "Nama teknisi cocok dengan lebih dari satu teknisi aktif ({$ambiguousNames}). Sebutkan nama lengkap teknisi yang dimaksud untuk tiket {$order->ticket_number}.",
            ];
        }

        $technicians = $match['technicians'];
        if ($technicians === []) {
            $activeNames = User::query()
                ->where('role', 'technician')
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(8)
                ->pluck('name')
                ->implode(', ');

            return [
                'valid' => false,
                'payload' => ['service_order_id' => $order->id],
                'errors' => ['technician_ids' => 'Teknisi aktif tidak ditemukan.'],
                'missing_fields' => ['technician'],
                'message' => 'Siap. Saya perlu mengetahui nama teknisi aktif yang akan ditugaskan ke '.$order->ticket_number.'. Teknisi aktif tersedia: '.($activeNames !== '' ? $activeNames : 'belum ada teknisi aktif').'.',
            ];
        }

        foreach ($technicians as $technician) {
            $conflict = $this->workforce->detectAssignmentConflict($technician, $order);
            if ($conflict !== null) {
                return [
                    'valid' => false,
                    'payload' => ['service_order_id' => $order->id],
                    'errors' => ['technician_ids' => $conflict],
                    'missing_fields' => [],
                    'message' => "Penugasan untuk {$technician->name} gagal validasi: {$conflict}",
                ];
            }
        }

        /** @var list<int> $technicianIds */
        $technicianIds = array_map(fn (User $tech): int => (int) $tech->id, $technicians);

        $leadId = isset($rawPayload['lead_technician_id']) && is_numeric($rawPayload['lead_technician_id'])
            ? (int) $rawPayload['lead_technician_id']
            : $technicianIds[0];

        if (! in_array($leadId, $technicianIds, true)) {
            $leadId = $technicianIds[0];
        }

        return [
            'valid' => true,
            'payload' => [
                'service_order_id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'technician_ids' => $technicianIds,
                'lead_technician_id' => $leadId,
            ],
            'errors' => [],
            'missing_fields' => [],
            'message' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{
     *     title: string,
     *     target: string,
     *     proposed_changes: list<array{label: string, before?: string|null, after: string}>,
     *     financial_impact: array{label: string, amount: int|null, formatted: string|null, breakdown?: list<array{label: string, value: string}>}|null,
     *     warnings: list<string>,
     *     summary_text: string
     * }
     */
    public function buildPreview(User $actor, array $normalizedPayload): array
    {
        $order = ServiceOrder::query()->with('assignedTechnician')->findOrFail((string) $normalizedPayload['service_order_id']);
        /** @var list<int> $techIds */
        $techIds = $normalizedPayload['technician_ids'];
        $leadId = (int) $normalizedPayload['lead_technician_id'];

        $proposedTechs = User::query()->whereKey($techIds)->get()->keyBy('id');
        $leadTech = $proposedTechs->get($leadId);

        $previousLeadName = $order->assignedTechnician instanceof User
            ? $order->assignedTechnician->name
            : 'Belum ada PIC';

        $previousTeamNames = $order->activeTechnicianAssignments()
            ->with('user')
            ->get()
            ->map(fn ($assignment): string => $assignment->user instanceof User ? $assignment->user->name : '')
            ->filter()
            ->implode(', ');

        $proposedTeamNames = $proposedTechs->pluck('name')->implode(', ');

        $changes = [
            [
                'label' => 'PIC Utama',
                'before' => $previousLeadName,
                'after' => $leadTech instanceof User ? $leadTech->name : (string) $leadId,
            ],
            [
                'label' => 'Tim Teknisi',
                'before' => $previousTeamNames !== '' ? $previousTeamNames : 'Kosong',
                'after' => $proposedTeamNames,
            ],
        ];

        $warnings = [
            'Teknisi yang ditugaskan akan menerima notifikasi dan status ketersediaan tim akan disinkronkan.',
        ];
        foreach ($proposedTechs as $tech) {
            if ($tech->active_assignments_count >= 2) {
                $warnings[] = "{$tech->name} saat ini sudah menangani {$tech->active_assignments_count} penugasan aktif.";
            }
        }

        return [
            'title' => 'Pratinjau Penugasan Teknisi',
            'target' => "{$order->ticket_number} — {$order->customer_name}",
            'proposed_changes' => $changes,
            'financial_impact' => null,
            'warnings' => $warnings,
            'summary_text' => "Saya telah menyiapkan pratinjau penugasan {$proposedTeamNames} (PIC: ".($leadTech instanceof User ? $leadTech->name : $leadId).") untuk tiket {$order->ticket_number}. Tekan Konfirmasi untuk menyimpan penugasan.",
        ];
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{
     *     reference: string,
     *     summary: array<string, mixed>,
     *     reply: string,
     *     actions: list<array{label: string, href: string}>
     * }
     */
    public function execute(
        User $actor,
        array $normalizedPayload,
        AssistantActionProposal $proposal,
        ?string $ipAddress = null,
    ): array {
        $order = ServiceOrder::query()->findOrFail((string) $normalizedPayload['service_order_id']);
        /** @var list<int> $techIds */
        $techIds = $normalizedPayload['technician_ids'];
        $leadId = (int) $normalizedPayload['lead_technician_id'];

        $this->assignOrderTeam->handle(
            order: $order,
            actor: $actor,
            technicianIds: $techIds,
            leadTechnicianId: $leadId,
            ipAddress: $ipAddress,
        );

        $leadName = User::query()->whereKey($leadId)->value('name') ?? (string) $leadId;

        return [
            'reference' => $order->ticket_number,
            'summary' => [
                'order_id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'lead_technician_id' => $leadId,
                'technician_ids' => $techIds,
            ],
            'reply' => "Teknisi berhasil ditugaskan untuk tiket {$order->ticket_number} (PIC: {$leadName}).",
            'actions' => [
                ['label' => "Lihat Tiket {$order->ticket_number}", 'href' => route('orders.show', $order)],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array{technicians: list<User>, ambiguous: list<User>}
     */
    private function resolveTechnicianMatch(array $rawPayload): array
    {
        if (is_array($rawPayload['technician_ids'] ?? null) && $rawPayload['technician_ids'] !== []) {
            $ids = array_values(array_unique(array_map('intval', $rawPayload['technician_ids'])));

            return [
                'technicians' => array_values(
                    User::query()
                        ->where('role', 'technician')
                        ->where('is_active', true)
                        ->whereIn('id', $ids)
                        ->get()
                        ->all(),
                ),
                'ambiguous' => [],
            ];
        }

        $hint = is_string($rawPayload['technician_name'] ?? null)
            ? trim($rawPayload['technician_name'])
            : (is_string($rawPayload['raw_message'] ?? null) ? $rawPayload['raw_message'] : '');

        if ($hint === '') {
            return ['technicians' => [], 'ambiguous' => []];
        }

        $activeTechs = User::query()
            ->where('role', 'technician')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $resolved = [];
        $resolvedIds = [];
        $tokenGroups = [];
        $lowerHint = strtolower($hint);

        foreach ($activeTechs as $tech) {
            $fullName = strtolower(trim($tech->name));
            if ($fullName !== '' && str_contains($lowerHint, $fullName)) {
                $resolved[] = $tech;
                $resolvedIds[(int) $tech->id] = true;

                continue;
            }

            if ($tech->username && str_contains($lowerHint, strtolower($tech->username))) {
                $resolved[] = $tech;
                $resolvedIds[(int) $tech->id] = true;

                continue;
            }

            $tokens = array_filter(explode(' ', $fullName), fn (string $token): bool => strlen($token) >= 3 && $token !== 'teknisi');
            foreach ($tokens as $token) {
                if (preg_match('/\b'.preg_quote($token, '/').'\b/', $lowerHint) === 1) {
                    $tokenGroups[$token][] = $tech;

                    break;
                }
            }
        }

        $ambiguous = [];
        foreach ($tokenGroups as $candidates) {
            $unresolvedCandidates = array_values(array_filter(
                $candidates,
                static fn (User $candidate): bool => ! isset($resolvedIds[(int) $candidate->id]),
            ));

            if (count($unresolvedCandidates) > 1) {
                foreach ($unresolvedCandidates as $candidate) {
                    $ambiguous[(int) $candidate->id] = $candidate;
                }

                continue;
            }

            foreach ($unresolvedCandidates as $candidate) {
                $resolved[] = $candidate;
                $resolvedIds[(int) $candidate->id] = true;
            }
        }

        if ($ambiguous !== []) {
            return [
                'technicians' => [],
                'ambiguous' => array_values($ambiguous),
            ];
        }

        return [
            'technicians' => $resolved,
            'ambiguous' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array{route?: string|null, order_id?: string|null, period?: string|null, section?: string|null, start_date?: string|null, end_date?: string|null}  $context
     */
    private function resolveOrder(array $rawPayload, array $context): ?ServiceOrder
    {
        if (is_string($rawPayload['service_order_id'] ?? null) && trim($rawPayload['service_order_id']) !== '') {
            $order = ServiceOrder::query()->find(trim($rawPayload['service_order_id']));
            if ($order !== null) {
                return $order;
            }
        }

        $ticket = $rawPayload['ticket_number'] ?? null;
        if (! is_string($ticket) || trim($ticket) === '') {
            $rawMessage = is_string($rawPayload['raw_message'] ?? null) ? $rawPayload['raw_message'] : '';
            if (preg_match('/\b(ADC-[A-Z0-9-]+)\b/i', $rawMessage, $matches) === 1) {
                $ticket = strtoupper($matches[1]);
            }
        }

        if (is_string($ticket) && trim($ticket) !== '') {
            $byTicket = ServiceOrder::query()->where('ticket_number', strtoupper(trim($ticket)))->first();
            if ($byTicket !== null) {
                return $byTicket;
            }
        }

        if (is_string($context['order_id'] ?? null) && trim($context['order_id']) !== '') {
            return ServiceOrder::query()->find(trim($context['order_id']));
        }

        return null;
    }
}
