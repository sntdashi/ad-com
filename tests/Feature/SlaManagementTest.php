<?php

use App\Actions\Orders\SendOrderMessage;
use App\Actions\Orders\UpdateOrderStatus;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Sla\SlaConfig;
use App\Services\Sla\SlaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

it('calculates SLA deadlines based on priority and risk level', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-16 09:00:00'));

    $service = Service::factory()->create(['is_active' => true]);

    $normalOrder = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'urgency' => 'normal',
        'risk_level' => 'low',
    ]);

    $soonOrder = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'urgency' => 'soon',
        'risk_level' => 'medium',
    ]);

    $highRiskOrder = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'urgency' => 'normal',
        'risk_level' => 'high',
    ]);

    expect($normalOrder->response_due_at?->toDateTimeString())->toBe('2026-11-16 11:00:00')
        ->and($normalOrder->resolution_due_at?->toDateTimeString())->toBe('2026-11-17 09:00:00')
        ->and($soonOrder->response_due_at?->toDateTimeString())->toBe('2026-11-16 10:00:00')
        ->and($soonOrder->resolution_due_at?->toDateTimeString())->toBe('2026-11-16 17:00:00')
        ->and($highRiskOrder->response_due_at?->toDateTimeString())->toBe('2026-11-16 09:30:00')
        ->and($highRiskOrder->resolution_due_at?->toDateTimeString())->toBe('2026-11-16 13:00:00');

    Carbon::setTestNow();
});

it('calculates SLA deadlines across business hours, non-working days, and holidays when enabled', function (): void {
    SystemSetting::putMany([
        'sla_business_hours_enabled' => '1',
        'sla_work_start_time' => '08:00',
        'sla_work_end_time' => '17:00',
        'sla_working_days' => '1,2,3,4,5', // Monday–Friday only
        'sla_holidays' => '2026-11-16',    // Monday is a holiday
        'sla_normal_response_minutes' => '120',
        'sla_normal_resolution_minutes' => '600', // 10 working hours (1 day + 1 hour)
    ]);

    // Created Saturday 2026-11-14 at 15:30 (non-working weekend, Monday is holiday -> starts Tuesday 2026-11-17 at 08:00)
    $createdAt = Carbon::parse('2026-11-14 15:30:00');
    $slaService = app(SlaService::class);
    $deadlines = $slaService->calculateDeadlines($createdAt, 'normal', 'low');

    expect($deadlines['response_due_at']->toDateTimeString())->toBe('2026-11-17 10:00:00')
        ->and($deadlines['resolution_due_at']->toDateTimeString())->toBe('2026-11-18 09:00:00');
});

it('pauses SLA when waiting for customer and shifts deadlines forward upon resume', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-16 08:00:00'));

    $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
    $technician = User::factory()->create(['role' => 'technician', 'is_active' => true]);
    $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
    $service = Service::factory()->create(['is_active' => true]);

    $order = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'assigned_technician_id' => $technician->id,
        'urgency' => 'normal',
        'risk_level' => 'low',
        'status' => 'new',
    ]);

    $initialResolutionDue = $order->resolution_due_at?->copy();

    // At 08:15, operator moves ticket to needs_info -> records first response AND auto-pauses SLA
    Carbon::setTestNow(Carbon::parse('2026-11-16 08:15:00'));
    app(UpdateOrderStatus::class)->handle($order, $operator, 'needs_info', 'Mohon foto serial number perangkat', '127.0.0.1');

    $order->refresh();
    $slaService = app(SlaService::class);
    $pausedSnapshot = $slaService->evaluate($order);

    expect($order->first_responded_at?->toDateTimeString())->toBe('2026-11-16 08:15:00')
        ->and($order->sla_paused_at?->toDateTimeString())->toBe('2026-11-16 08:15:00')
        ->and($pausedSnapshot['status'])->toBe('paused')
        ->and($pausedSnapshot['label'])->toBe('Paused');

    // Customer replies 2 hours later at 10:15 via ticket message -> auto-resumes SLA and shifts resolution deadline by 2 hours
    Carbon::setTestNow(Carbon::parse('2026-11-16 10:15:00'));
    app(SendOrderMessage::class)->handle($order, $customer, 'Berikut nomor seri perangkatnya: SN-998877.');

    $order->refresh();
    $resumedSnapshot = $slaService->evaluate($order);

    expect($order->sla_paused_at)->toBeNull()
        ->and($order->sla_paused_seconds)->toBe(7200)
        ->and($order->resolution_due_at?->toDateTimeString())->toBe($initialResolutionDue?->copy()->addHours(2)->toDateTimeString())
        ->and($resumedSnapshot['status'])->toBe('on_track')
        ->and(DB::table('audit_logs')->where('auditable_id', $order->id)->where('event', 'service_order.sla_paused')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('auditable_id', $order->id)->where('event', 'service_order.sla_resumed')->exists())->toBeTrue()
        ->and(DB::table('notifications')->where('notifiable_id', $technician->id)->where('data', 'like', '%sla.resumed%')->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('detects approaching deadline, breached, and completed SLA states accurately', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-16 08:00:00'));

    $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
    $service = Service::factory()->create(['is_active' => true]);

    // Normal priority: response target = 120 mins (due 10:00), resolution target = 1440 mins (due next day 08:00)
    $order = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'urgency' => 'normal',
        'risk_level' => 'low',
        'status' => 'new',
    ]);

    $slaService = app(SlaService::class);

    // At 08:30 -> On Track
    Carbon::setTestNow(Carbon::parse('2026-11-16 08:30:00'));
    expect($slaService->evaluate($order)['status'])->toBe('on_track')
        ->and($slaService->evaluate($order)['label'])->toBe('On Track');

    // At 09:40 -> 20 minutes remaining out of 120 mins (<= 20% remaining) -> Approaching Deadline
    Carbon::setTestNow(Carbon::parse('2026-11-16 09:40:00'));
    $approaching = $slaService->evaluate($order);
    expect($approaching['status'])->toBe('approaching_deadline')
        ->and($approaching['label'])->toBe('Approaching Deadline')
        ->and($approaching['approaching_response'])->toBeTrue();

    // At 10:05 -> past 10:00 response deadline without response -> Breached
    Carbon::setTestNow(Carbon::parse('2026-11-16 10:05:00'));
    $breached = $slaService->evaluate($order);
    expect($breached['status'])->toBe('breached')
        ->and($breached['label'])->toBe('Breached')
        ->and($breached['response_breached'])->toBeTrue();

    // Complete another order within SLA targets -> Completed & compliant
    Carbon::setTestNow(Carbon::parse('2026-11-16 08:00:00'));
    $compliantOrder = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'urgency' => 'normal',
        'risk_level' => 'low',
        'status' => 'verification',
        'first_responded_at' => Carbon::parse('2026-11-16 08:20:00'),
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00'));
    app(UpdateOrderStatus::class)->handle($compliantOrder, $operator, 'completed', 'Pekerjaan tuntas.', '127.0.0.1');

    $compliantOrder->refresh();
    $completedEval = $slaService->evaluate($compliantOrder);
    expect($completedEval['status'])->toBe('completed')
        ->and($completedEval['label'])->toBe('Completed')
        ->and($completedEval['compliant'])->toBeTrue()
        ->and($completedEval['first_response_minutes'])->toBe(20.0)
        ->and($completedEval['resolution_minutes'])->toBe(240.0);

    Carbon::setTestNow();
});

it('dispatches SLA notifications without duplicates across repeated monitor runs', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-16 08:00:00'));

    $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
    $technician = User::factory()->create(['role' => 'technician', 'is_active' => true]);
    $service = Service::factory()->create(['is_active' => true]);

    $order = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'assigned_technician_id' => $technician->id,
        'urgency' => 'normal',
        'risk_level' => 'low',
        'status' => 'new',
    ]);

    // Move to 09:45 (approaching first response deadline of 10:00)
    Carbon::setTestNow(Carbon::parse('2026-11-16 09:45:00'));
    $this->artisan('sla:monitor')->assertSuccessful();
    $this->artisan('sla:monitor')->assertSuccessful(); // Second run must not duplicate

    $approachingCount = DB::table('notifications')
        ->where('notifiable_id', $technician->id)
        ->where('data', 'like', '%sla.approaching_response%')
        ->count();
    expect($approachingCount)->toBe(1);

    // Move to 10:10 (breached first response deadline)
    Carbon::setTestNow(Carbon::parse('2026-11-16 10:10:00'));
    $this->artisan('sla:monitor')->assertSuccessful();
    $this->artisan('sla:monitor')->assertSuccessful(); // Second run must not duplicate

    $breachCount = DB::table('notifications')
        ->where('notifiable_id', $technician->id)
        ->where('data', 'like', '%sla.breached%')
        ->count();
    expect($breachCount)->toBe(1);

    Carbon::setTestNow();
});

it('enforces role-based access control for SLA actions and configuration', function (): void {
    $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
    $technician = User::factory()->create(['role' => 'technician', 'is_active' => true]);
    $service = Service::factory()->create(['is_active' => true]);

    $order = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'assigned_technician_id' => $technician->id,
        'status' => 'in_progress',
        'first_responded_at' => now(),
    ]);

    // Customer cannot pause or modify SLA
    $this->actingAs($customer)
        ->patch(route('orders.sla.update', $order), [
            'action' => 'pause',
            'reason' => 'Coba jeda dari pelanggan',
        ])
        ->assertForbidden();

    // Technician cannot manually pause SLA
    $this->actingAs($technician)
        ->patch(route('orders.sla.update', $order), [
            'action' => 'pause',
            'reason' => 'Coba jeda dari teknisi',
        ])
        ->assertForbidden();

    // Admin can pause and resume SLA
    $this->actingAs($admin)
        ->patch(route('orders.sla.update', $order), [
            'action' => 'pause',
            'reason' => 'Menunggu persetujuan suku cadang dari pelanggan',
        ])
        ->assertRedirect();

    expect($order->fresh()?->sla_paused_at)->not->toBeNull();

    $this->actingAs($admin)
        ->patch(route('orders.sla.update', $order), [
            'action' => 'resume',
        ])
        ->assertRedirect();

    expect($order->fresh()?->sla_paused_at)->toBeNull();

    // Superadmin (operator) can update SLA settings in SystemSettingsController
    $this->actingAs($operator)
        ->put(route('system-settings.update'), [
            'business_name' => 'AD Com · TEFA TKJ',
            'business_tagline' => 'Portal layanan teknologi TKJ.',
            'warranty_default_days' => 14,
            'sla_normal_response_minutes' => 90,
            'sla_normal_resolution_minutes' => 720,
            'sla_business_hours_enabled' => true,
            'sla_work_start_time' => '08:00',
            'sla_work_end_time' => '16:30',
        ])
        ->assertRedirect();

    $config = SlaConfig::get();
    expect($config['normal_response_minutes'])->toBe(90)
        ->and($config['normal_resolution_minutes'])->toBe(720)
        ->and($config['business_hours_enabled'])->toBeTrue()
        ->and($config['work_end_time'])->toBe('16:30');
});

it('exposes role-appropriate SLA metrics on dashboard, queue, and ticket detail', function (): void {
    $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
    $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
    $service = Service::factory()->create(['is_active' => true]);

    $order = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'status' => 'needs_info',
    ]);

    // Operator sees aggregate SLA metrics on dashboard
    $this->actingAs($operator)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('sla.paused', 1)
            ->has('sla.compliance_rate')
        );

    // Customer does not receive internal aggregate SLA metrics on dashboard
    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('sla', null)
        );

    // Operator can filter queue by sla_paused and manage SLA on ticket detail
    $this->actingAs($operator)
        ->get(route('orders.index', ['group' => 'sla_paused']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('operations/orders')
            ->has('orders.data', 1)
        );

    $this->actingAs($operator)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('orders/show')
            ->where('canManageSla', true)
            ->where('order.sla.status', 'paused')
        );

    // Customer sees SLA status on their ticket detail but canManageSla is false
    $this->actingAs($customer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('orders/show')
            ->where('canManageSla', false)
            ->where('order.sla.status', 'paused')
        );
});
