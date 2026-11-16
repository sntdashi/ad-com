<?php

use App\Models\InventoryItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;

test('adi enforces strict role boundaries across all roles and guest', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $technician = User::factory()->create(['role' => 'technician']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $supervisor = User::factory()->create(['role' => 'supervisor']);
    $admin = User::factory()->create(['role' => 'admin']);
    $operator = User::factory()->create(['role' => 'operator']);

    $service = Service::create([
        'code' => 'SRV-AI',
        'slug' => 'servis-ai',
        'name' => 'Servis AI Test',
        'category' => 'Jaringan',
        'service_mode' => 'onsite',
        'risk_level' => 'low',
        'pricing_label' => 'Mulai Rp 100.000',
        'base_price_min' => 100000,
        'base_price_max' => 200000,
        'warranty_days' => 14,
        'is_active' => true,
        'summary' => 'Test summary',
    ]);

    ServiceOrder::create([
        'ticket_number' => 'ADC-AI-001',
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'assigned_technician_id' => $technician->id,
        'customer_name' => $customer->name,
        'phone' => '08123456789',
        'address' => 'Jl. Merdeka 1',
        'service_mode' => 'onsite',
        'complaint' => 'Router mati',
        'status' => 'scheduled',
        'risk_level' => 'low',
        'urgency' => 'normal',
        'tracking_token_hash' => hash('sha256', 'ai-1'),
        'access_code_hash' => bcrypt('123456'),
    ]);

    InventoryItem::create([
        'sku' => 'SKU-AI-01',
        'name' => 'Konektor RJ45',
        'category' => 'Jaringan',
        'unit' => 'pcs',
        'quantity_on_hand' => 2,
        'reorder_level' => 10,
        'unit_cost' => 5000,
        'storage_location' => 'Rak A1',
        'is_active' => true,
    ]);

    $this->postJson(route('assistant.messages.store'), ['message' => 'Bagaimana laporan keuangan?'])
        ->assertOk()
        ->assertSee('Admin Operasional');

    $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Apa saja tiket saya?'])
        ->assertOk()
        ->assertSee('ADC-AI-001');

    $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Cek stok gudang sekarang'])
        ->assertOk()
        ->assertSee('peran internal operasional');

    $this->actingAs($technician)
        ->postJson(route('assistant.messages.store'), ['message' => 'Apa tugas saya sekarang?'])
        ->assertOk()
        ->assertSee('ADC-AI-001');

    $this->actingAs($technician)
        ->postJson(route('assistant.messages.store'), ['message' => 'Berapa omzet keuangan?'])
        ->assertOk()
        ->assertSee('Admin Operasional');

    $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), ['message' => 'Cek stok kritis gudang'])
        ->assertOk()
        ->assertSee('Konektor RJ45');

    $this->actingAs($supervisor)
        ->postJson(route('assistant.messages.store'), ['message' => 'Berapa tiket tanpa PIC?'])
        ->assertOk()
        ->assertSee('belum memiliki PIC teknisi');

    $this->actingAs($admin)
        ->postJson(route('assistant.messages.store'), ['message' => 'Ringkasan keuangan saat ini'])
        ->assertOk()
        ->assertSee('Ringkasan keuangan terverifikasi');

    $this->actingAs($admin)
        ->postJson(route('assistant.messages.store'), ['message' => 'Tampilkan ringkasan platform'])
        ->assertOk()
        ->assertSee('Superadmin');

    $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Tampilkan ringkasan platform'])
        ->assertOk()
        ->assertSee('Ringkasan platform:');

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $operator->id,
        'event' => 'assistant.tool_executed',
    ]);
});

test('adi refuses out of scope coding requests', function () {
    $this->postJson(route('assistant.messages.store'), ['message' => 'Buatkan kode python untuk scraping website'])
        ->assertOk()
        ->assertSee('di luar lingkup layanan AD Com');
});
