<?php

namespace App\Services\Assistant\Actions;

use App\Models\AssistantActionProposal;
use App\Models\InventoryItem;
use App\Models\InventoryRestockRequest;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateInventoryRestockRequestAction implements AssistantActionHandler
{
    public function name(): string
    {
        return 'create_inventory_restock_request';
    }

    public function label(): string
    {
        return 'Buat Permintaan Restock Inventaris';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function authorize(User $actor, array $payload): ?string
    {
        if (! Gate::forUser($actor)->allows('create', InventoryItem::class)) {
            return 'Akses ditolak. Pengajuan restock inventaris hanya diizinkan untuk Staf Gudang (Warehouse), Admin Operasional, dan Superadmin.';
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
        $match = $this->resolveInventoryMatch($rawPayload);
        if ($match['ambiguous'] !== []) {
            $options = implode(', ', array_map(
                static fn (InventoryItem $candidateItem): string => "{$candidateItem->sku} ({$candidateItem->name})",
                array_slice($match['ambiguous'], 0, 5),
            ));

            return [
                'valid' => false,
                'payload' => [],
                'errors' => ['inventory_item_id' => 'Beberapa SKU/item inventaris cocok dengan permintaan Anda.'],
                'missing_fields' => ['inventory_item'],
                'message' => "Siap. Saya menemukan beberapa item yang cocok: {$options}. Sebutkan SKU spesifik item yang ingin diajukan restock beserta jumlahnya.",
            ];
        }

        $item = $match['item'];
        if (! $item instanceof InventoryItem) {
            return [
                'valid' => false,
                'payload' => [],
                'errors' => ['inventory_item_id' => 'Item inventaris aktif tidak ditemukan.'],
                'missing_fields' => ['inventory_item'],
                'message' => 'Siap. Saya perlu mengetahui SKU atau nama item inventaris aktif serta jumlah restock yang diminta (contoh: "Ajukan restock SKU-SSD sebanyak 10 pcs").',
            ];
        }

        $openRequest = InventoryRestockRequest::query()
            ->where('inventory_item_id', $item->id)
            ->whereIn('status', ['pending', 'approved', 'ordered'])
            ->first();

        if ($openRequest instanceof InventoryRestockRequest) {
            return [
                'valid' => false,
                'payload' => ['inventory_item_id' => $item->id],
                'errors' => [
                    'inventory_item_id' => "Sudah terdapat permintaan restock aktif ({$openRequest->request_number}, status: {$openRequest->status}).",
                ],
                'missing_fields' => [],
                'message' => "Pengajuan restock untuk {$item->sku} ({$item->name}) diblokir karena masih ada permintaan {$openRequest->request_number} berstatus {$openRequest->status}. Selesaikan atau batalkan permintaan tersebut terlebih dahulu.",
            ];
        }

        $quantity = $rawPayload['requested_quantity'] ?? null;
        if ($quantity === null || $quantity === '') {
            return [
                'valid' => false,
                'payload' => ['inventory_item_id' => $item->id],
                'errors' => ['requested_quantity' => 'Jumlah unit restock wajib disebutkan secara eksplisit.'],
                'missing_fields' => ['requested_quantity'],
                'message' => "Siap. Saya perlu mengetahui jumlah unit restock untuk {$item->sku} ({$item->name}) — stok saat ini {$item->quantity_on_hand} {$item->unit} (reorder level {$item->reorder_level}). Contoh: \"Ajukan restock {$item->sku} sebanyak 10 {$item->unit}\".",
            ];
        }
        $quantityAutoSuggested = false;

        $priority = is_string($rawPayload['priority'] ?? null)
            ? strtolower(trim($rawPayload['priority']))
            : ($item->quantity_on_hand <= $item->reorder_level ? 'high' : 'normal');

        $candidate = [
            'inventory_item_id' => $item->id,
            'requested_quantity' => is_numeric($quantity) ? (int) $quantity : 0,
            'priority' => $priority,
            'notes' => is_string($rawPayload['notes'] ?? null) && trim($rawPayload['notes']) !== ''
                ? trim($rawPayload['notes'])
                : null,
        ];

        $validator = Validator::make($candidate, [
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'requested_quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'priority' => ['required', 'in:normal,high,urgent'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            $errors = [];
            $missingFields = [];
            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors[$field] = (string) ($messages[0] ?? 'Tidak valid.');
                $missingFields[] = $field;
            }

            return [
                'valid' => false,
                'payload' => $candidate,
                'errors' => $errors,
                'missing_fields' => array_values(array_unique($missingFields)),
                'message' => 'Jumlah atau prioritas permintaan restock tidak valid (jumlah minimal 1 dan maksimal 10.000).',
            ];
        }

        $validated = $validator->validated();
        $validated['quantity_auto_suggested'] = $quantityAutoSuggested;

        return [
            'valid' => true,
            'payload' => $validated,
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
        $item = InventoryItem::query()->findOrFail((int) $normalizedPayload['inventory_item_id']);
        $quantity = (int) $normalizedPayload['requested_quantity'];
        $estimatedCost = $quantity * $item->unit_cost;
        $priority = (string) $normalizedPayload['priority'];
        $autoSuggested = (bool) ($normalizedPayload['quantity_auto_suggested'] ?? false);

        $changes = [
            ['label' => 'SKU / Item', 'before' => null, 'after' => "{$item->sku} — {$item->name}"],
            ['label' => 'Stok Saat Ini', 'before' => null, 'after' => "{$item->quantity_on_hand} {$item->unit} (Reorder level: {$item->reorder_level})"],
            ['label' => $autoSuggested ? 'Jumlah Usulan Restock' : 'Jumlah Diminta', 'before' => null, 'after' => "{$quantity} {$item->unit}"],
            ['label' => 'Prioritas', 'before' => null, 'after' => strtoupper($priority)],
        ];

        if ($item->supplier_name !== null && $item->supplier_name !== '') {
            $changes[] = ['label' => 'Supplier Referensi', 'before' => null, 'after' => $item->supplier_name];
        }

        $warnings = [
            'Permintaan restock mencatat kebutuhan pengadaan dan menotifikasi tim gudang/admin; stok fisik baru bertambah saat barang diterima.',
        ];

        $hasOpenRequest = InventoryRestockRequest::query()
            ->where('inventory_item_id', $item->id)
            ->whereIn('status', ['pending', 'approved', 'ordered'])
            ->exists();
        if ($hasOpenRequest) {
            $warnings[] = "Saat ini sudah terdapat permintaan restock yang masih terbuka untuk {$item->sku}.";
        }

        $formattedCost = 'Rp '.number_format($estimatedCost, 0, ',', '.');

        return [
            'title' => 'Pratinjau Pengajuan Restock Inventaris',
            'target' => "{$item->sku} — {$item->name}",
            'proposed_changes' => $changes,
            'financial_impact' => [
                'label' => 'Estimasi Nilai Pembelian',
                'amount' => $estimatedCost,
                'formatted' => $formattedCost,
                'breakdown' => [
                    ['label' => 'Harga Satuan Referensi', 'value' => 'Rp '.number_format($item->unit_cost, 0, ',', '.')],
                    ['label' => 'Kuantitas', 'value' => "{$quantity} {$item->unit}"],
                ],
            ],
            'warnings' => $warnings,
            'summary_text' => "Saya telah menyiapkan pratinjau pengajuan restock {$item->name} ({$item->sku}) sebanyak {$quantity} {$item->unit} dengan estimasi {$formattedCost}. Konfirmasi untuk mencatat permintaan.",
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
        $restock = DB::transaction(function () use ($actor, $normalizedPayload, $ipAddress): InventoryRestockRequest {
            $item = InventoryItem::query()
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail((int) $normalizedPayload['inventory_item_id']);

            $openRequest = InventoryRestockRequest::query()
                ->where('inventory_item_id', $item->id)
                ->whereIn('status', ['pending', 'approved', 'ordered'])
                ->first();
            if ($openRequest instanceof InventoryRestockRequest) {
                throw ValidationException::withMessages([
                    'inventory_item_id' => "Permintaan restock aktif {$openRequest->request_number} ({$openRequest->status}) sudah ada untuk {$item->sku}.",
                ]);
            }

            $quantity = (int) $normalizedPayload['requested_quantity'];
            $estimatedCost = $quantity * $item->unit_cost;

            $restockRequest = InventoryRestockRequest::query()->create([
                'request_number' => $this->generateRequestNumber(),
                'inventory_item_id' => $item->id,
                'requested_by' => $actor->id,
                'requested_quantity' => $quantity,
                'estimated_total_cost' => $estimatedCost,
                'priority' => (string) $normalizedPayload['priority'],
                'status' => 'pending',
                'supplier_name' => $item->supplier_name,
                'notes' => is_string($normalizedPayload['notes'] ?? null) ? $normalizedPayload['notes'] : null,
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'inventory.restock_requested',
                'auditable_type' => InventoryRestockRequest::class,
                'auditable_id' => (string) $restockRequest->id,
                'before' => null,
                'after' => json_encode([
                    'request_number' => $restockRequest->request_number,
                    'inventory_item_id' => $item->id,
                    'sku' => $item->sku,
                    'requested_quantity' => $quantity,
                    'estimated_total_cost' => $estimatedCost,
                    'priority' => $restockRequest->priority,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $restockRequest->load('item');
        });

        $recipients = User::query()
            ->whereIn('role', ['operator', 'admin', 'warehouse'])
            ->where('is_active', true)
            ->where('id', '!=', $actor->id)
            ->get();

        if ($recipients->isNotEmpty()) {
            $item = $restock->item;
            $itemName = $item instanceof InventoryItem ? $item->name : 'Item Inventaris';
            app(NotificationDispatcher::class)->notifyUsers(
                $recipients,
                'inventory.restock_requested',
                "Permintaan restock {$restock->request_number}",
                "{$actor->name} mengajukan restock {$itemName} sebanyak {$restock->requested_quantity} unit.",
                route('inventory.index'),
                ['request_number' => $restock->request_number],
            );
        }

        return [
            'reference' => $restock->request_number,
            'summary' => [
                'restock_request_id' => $restock->id,
                'request_number' => $restock->request_number,
                'inventory_item_id' => $restock->inventory_item_id,
                'requested_quantity' => $restock->requested_quantity,
                'estimated_total_cost' => $restock->estimated_total_cost,
                'status' => $restock->status,
            ],
            'reply' => "Permintaan restock {$restock->request_number} untuk {$restock->requested_quantity} unit berhasil dicatat (status: pending).",
            'actions' => [
                ['label' => 'Buka Daftar Inventaris', 'href' => route('inventory.index')],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array{item: ?InventoryItem, ambiguous: list<InventoryItem>}
     */
    private function resolveInventoryMatch(array $rawPayload): array
    {
        if (isset($rawPayload['inventory_item_id']) && is_numeric($rawPayload['inventory_item_id'])) {
            $item = InventoryItem::query()->where('is_active', true)->whereKey((int) $rawPayload['inventory_item_id'])->first();

            return ['item' => $item, 'ambiguous' => []];
        }

        $explicitSku = is_string($rawPayload['sku'] ?? null) ? trim($rawPayload['sku']) : '';
        if ($explicitSku !== '') {
            $bySku = InventoryItem::query()
                ->where('is_active', true)
                ->whereRaw('LOWER(sku) = ?', [strtolower($explicitSku)])
                ->first();

            if ($bySku instanceof InventoryItem) {
                return ['item' => $bySku, 'ambiguous' => []];
            }
        }

        $candidateSkus = [];
        if (is_array($rawPayload['sku_candidates'] ?? null)) {
            foreach ($rawPayload['sku_candidates'] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $candidateSkus[] = strtolower(trim($candidate));
                }
            }
        }

        $hint = strtolower(trim(implode(' ', array_filter([
            is_string($rawPayload['item_name'] ?? null) ? $rawPayload['item_name'] : null,
            is_string($rawPayload['raw_message'] ?? null) ? $rawPayload['raw_message'] : null,
        ]))));

        if ($hint === '' && $candidateSkus === []) {
            return ['item' => null, 'ambiguous' => []];
        }

        $items = InventoryItem::query()->where('is_active', true)->orderBy('id')->get();
        $skuMatches = [];
        $nameMatches = [];

        foreach ($items as $item) {
            $itemSku = strtolower(trim($item->sku));
            $itemName = strtolower(trim($item->name));

            if ($itemSku !== '' && (
                in_array($itemSku, $candidateSkus, true)
                || ($hint !== '' && preg_match('/(?<![a-z0-9._-])'.preg_quote($itemSku, '/').'(?![a-z0-9._-])/', $hint) === 1)
            )) {
                $skuMatches[] = $item;
            }

            if ($itemName !== '' && $hint !== '' && str_contains($hint, $itemName)) {
                $nameMatches[] = $item;
            }
        }

        if (count($skuMatches) === 1) {
            return ['item' => $skuMatches[0], 'ambiguous' => []];
        }

        if (count($skuMatches) > 1) {
            return ['item' => null, 'ambiguous' => $skuMatches];
        }

        if (count($nameMatches) === 1) {
            return ['item' => $nameMatches[0], 'ambiguous' => []];
        }

        if (count($nameMatches) > 1) {
            return ['item' => null, 'ambiguous' => $nameMatches];
        }

        if ($explicitSku === '' && $hint !== '') {
            $ambiguousMatches = $this->findAmbiguousKeywordMatches($hint, array_values($items->all()));
            if (count($ambiguousMatches) > 1) {
                return ['item' => null, 'ambiguous' => $ambiguousMatches];
            }
        }

        return ['item' => null, 'ambiguous' => []];
    }

    /**
     * Detect ambiguous partial item queries when multiple active items share the requested keywords.
     *
     * @param  list<InventoryItem>  $items
     * @return list<InventoryItem>
     */
    private function findAmbiguousKeywordMatches(string $hint, array $items): array
    {
        $stripped = preg_replace(
            '/(?:sebanyak|qty|jumlah)\s*[:=]?\s*-?\d+\b|\b-?\d+\s*(?:pcs|unit|buah|pack|box)\b/i',
            ' ',
            $hint,
        );
        $rawTokens = preg_split('/[^a-z0-9._-]+/i', (string) $stripped) ?: [];
        $stopWords = [
            'adi', 'tolong', 'mohon', 'minta', 'buat', 'buatkan', 'bikin', 'ajukan', 'kirim', 'order',
            'request', 'create', 'create_inventory_restock_request', 'restock', 'isi', 'ulang', 'stok',
            'stock', 'pengadaan', 'barang', 'item', 'inventaris', 'inventori', 'sku', 'kode', 'yang',
            'untuk', 'pada', 'dengan', 'dan', 'atau', 'di', 'ke', 'dari', 'sebanyak', 'qty', 'jumlah',
            'pcs', 'unit', 'buah', 'pack', 'box', 'prioritas', 'normal', 'tinggi', 'high', 'urgent',
            'mendesak', 'segera', 'catatan', 'note', 'notes',
        ];

        $searchTokens = [];
        foreach ($rawTokens as $token) {
            $clean = strtolower(trim($token, "._- \t\n\r\0\x0B"));
            if ($clean === '' || strlen($clean) < 2 || ctype_digit($clean) || in_array($clean, $stopWords, true)) {
                continue;
            }
            $searchTokens[] = $clean;
        }
        $searchTokens = array_values(array_unique($searchTokens));

        if ($searchTokens === []) {
            return [];
        }

        $matched = [];
        foreach ($items as $item) {
            $haystack = strtolower($item->sku.' '.$item->name);
            $allMatch = true;
            foreach ($searchTokens as $token) {
                if (! str_contains($haystack, $token)) {
                    $allMatch = false;

                    break;
                }
            }

            if ($allMatch) {
                $matched[] = $item;
            }
        }

        return $matched;
    }

    private function generateRequestNumber(): string
    {
        do {
            $candidate = 'RST-'.now()->format('Ymd').'-'.Str::upper(Str::random(4));
        } while (InventoryRestockRequest::query()->where('request_number', $candidate)->exists());

        return $candidate;
    }
}
