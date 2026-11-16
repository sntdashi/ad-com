<?php

use App\Models\InventoryItem;
use App\Models\InventoryRestockRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function createRestockItemFixture(): InventoryItem
{
    return InventoryItem::create([
        'sku' => 'SKU-SSD-512',
        'name' => 'NVMe SSD 512GB',
        'category' => 'Storage',
        'unit' => 'pcs',
        'quantity_on_hand' => 2,
        'reorder_level' => 5,
        'unit_cost' => 450000,
        'selling_price' => 650000,
        'supplier_name' => 'PT Nusantara Komponen',
        'is_active' => true,
    ]);
}

it('previews and confirms create_inventory_restock_request for warehouse without mutating physical stock', function () {
    $item = createRestockItemFixture();
    $warehouse = User::factory()->create(['role' => 'warehouse', 'name' => 'Staf Gudang']);
    $operator = User::factory()->create(['role' => 'operator', 'name' => 'Operator Utama']);

    $response = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock SKU-SSD-512 sebanyak 10 pcs prioritas tinggi',
        ])
        ->assertOk();

    $proposal = $response->json('action_proposal');
    expect($proposal)->not->toBeNull()
        ->and($proposal['action_name'])->toBe('create_inventory_restock_request')
        ->and($proposal['preview']['financial_impact']['amount'])->toBe(4500000)
        ->and(InventoryRestockRequest::query()->count())->toBe(0);

    $confirmResponse = $this->actingAs($warehouse)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'executed');

    expect($confirmResponse->json('action_proposal.result_reference'))->toStartWith('RST-')
        ->and(InventoryRestockRequest::query()->count())->toBe(1)
        ->and($item->fresh()->quantity_on_hand)->toBe(2);

    $restock = InventoryRestockRequest::query()->firstOrFail();
    expect($restock->inventory_item_id)->toBe($item->id)
        ->and($restock->requested_quantity)->toBe(10)
        ->and($restock->estimated_total_cost)->toBe(4500000)
        ->and($restock->priority)->toBe('high')
        ->and($restock->status)->toBe('pending')
        ->and(DB::table('audit_logs')->where('auditable_id', (string) $restock->id)->where('event', 'inventory.restock_requested')->exists())->toBeTrue()
        ->and(DB::table('notifications')->where('notifiable_id', $operator->id)->exists())->toBeTrue();
});

it('denies supervisor, technician, and customer from creating restock requests', function () {
    createRestockItemFixture();

    foreach (['supervisor', 'technician', 'customer'] as $role) {
        $actor = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($actor)
            ->postJson(route('assistant.messages.store'), [
                'message' => 'Ajukan restock SKU-SSD-512 sebanyak 5 pcs',
            ])
            ->assertOk();

        expect($response->json('action_proposal'))->toBeNull()
            ->and($response->json('reply'))->toContain('hanya diizinkan');
    }

    expect(InventoryRestockRequest::query()->count())->toBe(0);
});

it('rejects non-positive quantities and preserves read-only low stock queries', function () {
    createRestockItemFixture();
    $warehouse = User::factory()->create(['role' => 'warehouse']);

    $invalid = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock SKU-SSD-512 sebanyak 0 pcs',
        ])
        ->assertOk();

    expect($invalid->json('action_proposal'))->toBeNull()
        ->and($invalid->json('reply'))->toContain('jumlah minimal 1');

    // Read-only low stock query must still route to inventory_summary, NOT create an action proposal
    $readOnly = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Barang inventaris apa yang perlu segera restock?',
        ])
        ->assertOk();

    expect($readOnly->json('action_proposal'))->toBeNull()
        ->and(InventoryRestockRequest::query()->count())->toBe(0);
});

it('extracts all real multi-segment and legacy SKUs with case and punctuation normalization', function (string $sku, string $name, string $prompt) {
    $item = InventoryItem::create([
        'sku' => $sku,
        'name' => $name,
        'category' => 'Hardware',
        'unit' => 'pcs',
        'quantity_on_hand' => 3,
        'reorder_level' => 10,
        'unit_cost' => 120000,
        'selling_price' => 180000,
        'supplier_name' => 'PT Uji Multi SKU',
        'is_active' => true,
    ]);
    $warehouse = User::factory()->create(['role' => 'warehouse']);

    $response = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => $prompt,
        ])
        ->assertOk();

    $proposal = $response->json('action_proposal');
    expect($proposal)->not->toBeNull()
        ->and($proposal['action_name'])->toBe('create_inventory_restock_request')
        ->and($proposal['status'])->toBe('awaiting_confirmation')
        ->and($proposal['preview']['target'])->toBe("{$item->sku} — {$item->name}")
        ->and(InventoryRestockRequest::query()->count())->toBe(0)
        ->and($item->fresh()->quantity_on_hand)->toBe(3);

    $skuChange = collect($proposal['preview']['proposed_changes'])->firstWhere('label', 'SKU / Item');
    $qtyChange = collect($proposal['preview']['proposed_changes'])->firstWhere('label', 'Jumlah Diminta');
    expect($skuChange['after'] ?? null)->toBe("{$item->sku} — {$item->name}")
        ->and($qtyChange['after'] ?? null)->toBe('5 pcs')
        ->and($proposal['preview']['financial_impact']['amount'])->toBe(600000);
})->with([
    'PC-SSD-512 exact' => ['PC-SSD-512', 'SSD SATA 512GB', 'Ajukan restock PC-SSD-512 sebanyak 5 pcs'],
    'NET-RJ45-100 exact' => ['NET-RJ45-100', 'Konektor RJ45', 'Ajukan restock NET-RJ45-100 sebanyak 5 pcs'],
    'OPS-TIES-100 exact' => ['OPS-TIES-100', 'Cable Ties', 'Ajukan restock OPS-TIES-100 sebanyak 5 pcs'],
    'NET-CAT6-305 exact' => ['NET-CAT6-305', 'Kabel UTP Cat6', 'Ajukan restock NET-CAT6-305 sebanyak 5 pcs'],
    'CCTV-CAM-2MP exact' => ['CCTV-CAM-2MP', 'Kamera CCTV 2MP', 'Ajukan restock CCTV-CAM-2MP sebanyak 5 pcs'],
    'legacy SKU-prefixed' => ['SKU-LEGACY-09', 'Adaptor Legacy', 'Ajukan restock SKU-LEGACY-09 sebanyak 5 pcs'],
    'legacy two-segment' => ['HD-120', 'Harddisk 120GB', 'Ajukan restock HD-120 sebanyak 5 pcs'],
    'lowercase case normalization' => ['PC-SSD-512', 'SSD SATA 512GB', 'Ajukan restock pc-ssd-512 sebanyak 5 pcs'],
    'mixed case NET-CAT6-305' => ['NET-CAT6-305', 'Kabel UTP Cat6', 'Ajukan restock Net-Cat6-305 sebanyak 5 pcs'],
    'parentheses and trailing comma' => ['CCTV-CAM-2MP', 'Kamera CCTV 2MP', 'Ajukan restock (cctv-cam-2mp), sebanyak 5 pcs.'],
    'semicolon and surrounding whitespace' => ['NET-RJ45-100', 'Konektor RJ45', '  Ajukan restock   NET-RJ45-100;  sebanyak 5 pcs  '],
    'quoted uppercase/lowercase mixed' => ['OPS-TIES-100', 'Cable Ties', 'Minta restock "ops-ties-100". sebanyak 5 pcs'],
]);

it('falls back safely to name or raw message without breaking unknown or ambiguous SKUs', function () {
    $ssd512 = InventoryItem::create([
        'sku' => 'PC-SSD-512',
        'name' => 'SSD SATA 512GB',
        'category' => 'Storage',
        'unit' => 'pcs',
        'quantity_on_hand' => 2,
        'reorder_level' => 5,
        'unit_cost' => 480000,
        'selling_price' => 690000,
        'supplier_name' => 'PT Storage',
        'is_active' => true,
    ]);
    $ssd1tb = InventoryItem::create([
        'sku' => 'PC-SSD-1TB',
        'name' => 'SSD SATA 1TB',
        'category' => 'Storage',
        'unit' => 'pcs',
        'quantity_on_hand' => 4,
        'reorder_level' => 5,
        'unit_cost' => 850000,
        'selling_price' => 1150000,
        'supplier_name' => 'PT Storage',
        'is_active' => true,
    ]);
    $warehouse = User::factory()->create(['role' => 'warehouse']);

    // Unknown SKU must never pick an arbitrary item
    $unknown = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock UNKNOWN-SKU-999 sebanyak 5 pcs',
        ])
        ->assertOk();

    expect($unknown->json('action_proposal'))->toBeNull()
        ->and($unknown->json('reply'))->toContain('SKU atau nama item inventaris aktif');

    // Truncated / partial SKU without prefix must not guess PC-SSD-512
    $truncated = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock SSD-512 sebanyak 5 pcs',
        ])
        ->assertOk();

    expect($truncated->json('action_proposal'))->toBeNull();

    // Ambiguous keyword match must list candidates and avoid arbitrary selection
    $ambiguousName = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock SSD sebanyak 5 pcs',
        ])
        ->assertOk();

    expect($ambiguousName->json('action_proposal'))->toBeNull()
        ->and($ambiguousName->json('reply'))->toContain('PC-SSD-512')
        ->and($ambiguousName->json('reply'))->toContain('PC-SSD-1TB');

    // Multiple SKU tokens in one request must be rejected as ambiguous
    $ambiguousSkus = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock PC-SSD-512 atau PC-SSD-1TB sebanyak 5 pcs',
        ])
        ->assertOk();

    expect($ambiguousSkus->json('action_proposal'))->toBeNull()
        ->and($ambiguousSkus->json('reply'))->toContain('beberapa item yang cocok');

    // Name-based fallback must still succeed even if another non-matching batch token appears first
    $nameFallback = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock batch V-02 SSD SATA 512GB sebanyak 3 pcs',
        ])
        ->assertOk();

    expect($nameFallback->json('action_proposal.action_name'))->toBe('create_inventory_restock_request')
        ->and($nameFallback->json('action_proposal.preview.target'))->toBe("{$ssd512->sku} — {$ssd512->name}")
        ->and($ssd1tb->fresh()->quantity_on_hand)->toBe(4);
});

it('requires explicit quantity on multi-segment SKUs and preserves stock through cancel, confirm, and replay protection', function () {
    $item = InventoryItem::create([
        'sku' => 'NET-CAT6-305',
        'name' => 'Kabel UTP Cat6',
        'category' => 'Networking',
        'unit' => 'roll',
        'quantity_on_hand' => 6,
        'reorder_level' => 10,
        'unit_cost' => 950000,
        'selling_price' => 1350000,
        'supplier_name' => 'PT Kabel Jaya',
        'is_active' => true,
    ]);
    $warehouse = User::factory()->create(['role' => 'warehouse']);

    // Missing quantity must prompt instead of inventing a quantity
    $missingQty = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock NET-CAT6-305',
        ])
        ->assertOk();

    expect($missingQty->json('action_proposal'))->toBeNull()
        ->and($missingQty->json('reply'))->toContain('NET-CAT6-305')
        ->and($missingQty->json('reply'))->toContain('jumlah unit restock')
        ->and(InventoryRestockRequest::query()->count())->toBe(0);

    // Follow-up creates a preview without executing
    $previewForCancel = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock NET-CAT6-305 sebanyak 4 roll',
        ])
        ->assertOk();

    $cancelProp = $previewForCancel->json('action_proposal');
    expect($cancelProp)->not->toBeNull()
        ->and($cancelProp['preview']['target'])->toBe('NET-CAT6-305 — Kabel UTP Cat6')
        ->and(InventoryRestockRequest::query()->count())->toBe(0);

    // Cancellation creates no restock request and preserves stock
    $this->actingAs($warehouse)
        ->postJson(route('assistant.actions.cancel', ['proposal' => $cancelProp['action_id']]))
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    expect(InventoryRestockRequest::query()->count())->toBe(0)
        ->and($item->fresh()->quantity_on_hand)->toBe(6);

    // Confirmation creates exactly one restock request and leaves physical stock untouched
    $previewForConfirm = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock NET-CAT6-305 sebanyak 4 roll',
        ])
        ->assertOk();

    $confirmProp = $previewForConfirm->json('action_proposal');
    expect(InventoryRestockRequest::query()->count())->toBe(0);

    $this->actingAs($warehouse)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $confirmProp['action_id']]), [
            'payload_hash' => $confirmProp['payload_hash'],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'executed');

    expect(InventoryRestockRequest::query()->count())->toBe(1)
        ->and($item->fresh()->quantity_on_hand)->toBe(6);

    $restock = InventoryRestockRequest::query()->firstOrFail();
    expect($restock->inventory_item_id)->toBe($item->id)
        ->and($restock->requested_quantity)->toBe(4)
        ->and($restock->estimated_total_cost)->toBe(3800000);

    // Replayed confirmation must be rejected with HTTP 409 without creating a duplicate row
    $this->actingAs($warehouse)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $confirmProp['action_id']]), [
            'payload_hash' => $confirmProp['payload_hash'],
        ])
        ->assertStatus(409)
        ->assertJsonPath('status', 'executed');

    expect(InventoryRestockRequest::query()->count())->toBe(1)
        ->and($item->fresh()->quantity_on_hand)->toBe(6);
});
