<?php

use App\Models\ChecklistTemplate;
use App\Models\FinancialTransaction;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('complete end to end lifecycle from customer booking to completion and finance verification', function () {
    Queue::fake();

    $operator = User::factory()->create(['role' => 'operator', 'phone' => '081234560001']);
    $admin = User::factory()->create(['role' => 'admin', 'phone' => '081234560002']);
    $supervisor = User::factory()->create(['role' => 'supervisor', 'phone' => '081234560003']);
    $technician = User::factory()->create(['role' => 'technician', 'phone' => '081234560004']);
    $customer = User::factory()->create(['role' => 'customer', 'phone' => '081234560005']);

    $service = Service::create([
        'code' => 'SRV-E2E',
        'slug' => 'servis-e2e',
        'name' => 'Servis End-to-End',
        'category' => 'Jaringan',
        'service_mode' => 'onsite',
        'risk_level' => 'medium',
        'pricing_label' => 'Mulai Rp 150.000',
        'base_price_min' => 150000,
        'base_price_max' => 300000,
        'warranty_days' => 14,
        'is_active' => true,
        'summary' => 'E2E service',
    ]);

    $template = ChecklistTemplate::create([
        'service_id' => $service->id,
        'item_key' => 'e2e-check',
        'title' => 'Verifikasi koneksi akhir',
        'standard_code' => 'SOP-E2E-01',
        'requires_evidence' => false,
        'sort_order' => 1,
    ]);

    // 1. Customer submits booking
    $this->actingAs($customer)
        ->post(route('booking.store'), [
            'service_id' => $service->id,
            'customer_name' => $customer->name,
            'phone' => '081234560005',
            'email' => $customer->email,
            'address' => 'Jl. Jenderal Sudirman 10',
            'district' => 'Pusat',
            'service_mode' => 'onsite',
            'complaint' => 'Wi-Fi lantai 2 mati total',
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_window' => 'morning',
            'urgency' => 'normal',
            'privacy_accepted' => true,
        ])
        ->assertRedirect();

    $order = ServiceOrder::query()->latest('created_at')->firstOrFail();
    expect($order->status)->toBe('new');
    expect($order->customer_id)->toBe($customer->id);

    // 2. Supervisor triages & schedules & assigns technician
    $this->actingAs($supervisor)
        ->patch(route('orders.status.update', $order), ['status' => 'triaged'])
        ->assertRedirect();

    $this->actingAs($supervisor)
        ->patch(route('orders.status.update', $order), ['status' => 'scheduled'])
        ->assertRedirect();

    $this->actingAs($supervisor)
        ->patch(route('orders.team.update', $order), [
            'technician_ids' => [$technician->id],
            'lead_technician_id' => $technician->id,
        ])
        ->assertRedirect();

    expect($order->fresh()->assigned_technician_id)->toBe($technician->id);

    // 3. Technician progresses order & completes checklist
    foreach (['on_the_way', 'in_progress'] as $nextStatus) {
        $this->actingAs($technician)
            ->patch(route('orders.status.update', $order), ['status' => $nextStatus])
            ->assertRedirect();
    }

    $this->actingAs($technician)
        ->patch(route('orders.checklist.update', [$order, $template]), [
            'result' => 'passed',
            'notes' => 'Normal 150 Mbps',
        ])
        ->assertRedirect();

    $this->actingAs($technician)
        ->patch(route('orders.status.update', $order), ['status' => 'verification'])
        ->assertRedirect();

    // 4. Supervisor completes order
    $this->actingAs($supervisor)
        ->patch(route('orders.status.update', $order), ['status' => 'completed'])
        ->assertRedirect();

    expect($order->fresh()->status)->toBe('completed');
    expect($order->fresh()->completed_at)->not->toBeNull();

    // 5. Admin records payment & Operator verifies
    $this->actingAs($admin)
        ->post(route('finance.store'), [
            'service_order_id' => $order->id,
            'type' => 'income',
            'category' => 'service_payment',
            'amount' => 175000,
            'payment_method' => 'qr',
            'description' => "Pembayaran {$order->ticket_number}",
            'occurred_at' => now()->toDateTimeString(),
        ])
        ->assertRedirect();

    $tx = FinancialTransaction::query()->where('service_order_id', $order->id)->firstOrFail();
    expect($tx->status)->toBe('pending');

    $this->actingAs($operator)
        ->patch(route('finance.transactions.verify', $tx), ['status' => 'verified'])
        ->assertRedirect();

    expect($tx->fresh()->status)->toBe('verified');

    // 6. Customer sees updated order & checklist progress
    $this->actingAs($customer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.status', 'completed')
            ->where('order.checklist.0.result', 'passed')
            ->where('order.finance.verified_income', 175000)
        );
});
