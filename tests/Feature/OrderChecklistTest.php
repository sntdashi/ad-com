<?php

use App\Models\ChecklistTemplate;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;

test('assigned technician can update checklist item and customer sees progress', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $technician = User::factory()->create(['role' => 'technician']);
    $otherTechnician = User::factory()->create(['role' => 'technician']);

    $service = Service::create([
        'code' => 'SRV-CHK',
        'slug' => 'servis-checklist',
        'name' => 'Servis Checklist Test',
        'category' => 'Hardware',
        'service_mode' => 'onsite',
        'risk_level' => 'medium',
        'pricing_label' => 'Mulai Rp 100.000',
        'base_price_min' => 100000,
        'base_price_max' => 200000,
        'warranty_days' => 14,
        'is_active' => true,
        'summary' => 'Test summary',
    ]);

    $template = ChecklistTemplate::create([
        'service_id' => $service->id,
        'item_key' => 'lan-wifi-check',
        'title' => 'Uji konektivitas LAN & Wi-Fi',
        'standard_code' => 'SOP-NET-01',
        'requires_evidence' => false,
        'sort_order' => 1,
    ]);

    $order = ServiceOrder::create([
        'ticket_number' => 'ADC-CHK-001',
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'assigned_technician_id' => $technician->id,
        'customer_name' => $customer->name,
        'phone' => '08123456789',
        'address' => 'Jl. Merdeka 1',
        'service_mode' => 'onsite',
        'complaint' => 'Wi-Fi putus',
        'status' => 'in_progress',
        'risk_level' => 'medium',
        'urgency' => 'normal',
        'tracking_token_hash' => hash('sha256', 'chk-token'),
        'access_code_hash' => bcrypt('123456'),
    ]);

    $this->actingAs($otherTechnician)
        ->patch(route('orders.checklist.update', [$order, $template]), [
            'result' => 'passed',
        ])
        ->assertForbidden();

    $this->actingAs($customer)
        ->patch(route('orders.checklist.update', [$order, $template]), [
            'result' => 'passed',
        ])
        ->assertForbidden();

    $this->actingAs($technician)
        ->patch(route('orders.checklist.update', [$order, $template]), [
            'result' => 'passed',
            'notes' => 'Stabil 100 Mbps',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('order_checklist_results', [
        'service_order_id' => $order->id,
        'checklist_template_id' => $template->id,
        'completed_by' => $technician->id,
        'result' => 'passed',
        'notes' => 'Stabil 100 Mbps',
    ]);

    $this->actingAs($customer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canUpdateChecklist', false)
            ->where('order.checklist.0.result', 'passed')
            ->where('order.checklist.0.completed_by', $technician->name)
        );
});
