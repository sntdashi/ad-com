<?php

use App\Actions\Orders\UpdateOrderStatus;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\OrderAssignment;
use App\Models\OrderStatusEvent;
use App\Models\OrderWorkEvidence;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\TechnicianAvailabilityLog;
use App\Models\TechnicianWorkSession;
use App\Models\User;
use App\Services\Workforce\WorkforceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Queue::fake();
});

function makeTechnician(array $attributes = []): User
{
    return User::factory()->create([
        'role' => 'technician',
        'is_active' => true,
        ...$attributes,
    ]);
}

it('enforces authorization and prevents manually marking available during active field work', function (): void {
    $customer = User::factory()->create(['role' => 'customer']);
    $techA = makeTechnician(['availability_status' => 'available']);
    $techB = makeTechnician(['availability_status' => 'available']);
    $supervisor = User::factory()->create(['role' => 'supervisor']);

    // Customer cannot update availability
    $this->actingAs($customer)
        ->patch(route('technicians.availability.update', $techA), [
            'availability_status' => 'unavailable',
        ])
        ->assertForbidden();

    // Technician cannot modify another technician
    $this->actingAs($techB)
        ->patch(route('technicians.availability.update', $techA), [
            'availability_status' => 'unavailable',
        ])
        ->assertForbidden();

    // Technician can update their own availability with note & log
    $this->actingAs($techA)
        ->patch(route('technicians.availability.update', $techA), [
            'availability_status' => 'on_leave',
            'availability_note' => 'Cuti tahunan',
        ])
        ->assertRedirect();

    expect($techA->fresh()->availability_status)->toBe('on_leave')
        ->and($techA->fresh()->availability_note)->toBe('Cuti tahunan')
        ->and(TechnicianAvailabilityLog::where('user_id', $techA->id)->where('new_status', 'on_leave')->exists())->toBeTrue();

    // Supervisor marks back to available when idle
    $this->actingAs($supervisor)
        ->patch(route('technicians.availability.update', $techA), [
            'availability_status' => 'available',
        ])
        ->assertRedirect();

    expect($techA->fresh()->availability_status)->toBe('available');

    // Assign active in_progress order -> moving to in_progress marks busy & blocks manual available
    $service = Service::factory()->create();
    $order = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'assigned_technician_id' => $techA->id,
        'status' => 'on_the_way',
    ]);
    OrderAssignment::create([
        'service_order_id' => $order->id,
        'user_id' => $techA->id,
        'assignment_role' => 'technician',
        'assigned_at' => now(),
    ]);

    app(UpdateOrderStatus::class)->handle($order, $supervisor, 'in_progress', null, null);
    expect($techA->fresh()->availability_status)->toBe('busy');

    $this->actingAs($supervisor)
        ->patch(route('technicians.availability.update', $techA), [
            'availability_status' => 'available',
        ])
        ->assertSessionHasErrors('availability_status');

    // Completing order automatically releases technician back to available
    app(UpdateOrderStatus::class)->handle($order->fresh(), $supervisor, 'verification', null, null);
    app(UpdateOrderStatus::class)->handle($order->fresh(), $supervisor, 'waiting_payment', null, null);
    app(UpdateOrderStatus::class)->handle($order->fresh(), $supervisor, 'completed', null, null);
    expect($techA->fresh()->availability_status)->toBe('available');
});

it('scopes workforce overview and filters accurately by availability, workload, specialty, and SLA risk', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00'));

    $customer = User::factory()->create(['role' => 'customer']);
    $admin = User::factory()->create(['role' => 'admin']);

    $netTech = makeTechnician([
        'name' => 'Budi Network',
        'specialty' => 'Jaringan & Mikrotik',
        'availability_status' => 'available',
    ]);
    $cctvTech = makeTechnician([
        'name' => 'Andi CCTV',
        'specialty' => 'CCTV & Keamanan',
        'availability_status' => 'on_leave',
    ]);

    $service = Service::factory()->create();
    // Overdue breached SLA order for netTech
    $riskOrder = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'assigned_technician_id' => $netTech->id,
        'status' => 'scheduled',
        'urgency' => 'soon',
        'risk_level' => 'high',
        'created_at' => now()->subHours(10),
        'resolution_due_at' => now()->subHour(),
    ]);
    OrderAssignment::create([
        'service_order_id' => $riskOrder->id,
        'user_id' => $netTech->id,
        'assignment_role' => 'technician',
        'assigned_at' => now()->subHours(9),
    ]);

    $this->actingAs($customer)
        ->get(route('workforce.index'))
        ->assertForbidden();

    // Admin sees both technicians and summary
    $this->actingAs($admin)
        ->get(route('workforce.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/workforce')
            ->has('technicians', 2)
            ->where('summary.sla_risk', 1)
        );

    // Filter by availability + assignment=sla_risk + specialty
    $this->actingAs($admin)
        ->get(route('workforce.index', [
            'availability' => 'available',
            'assignment' => 'sla_risk',
            'specialty' => 'Jaringan',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('technicians', 1)
            ->where('technicians.0.id', $netTech->id)
            ->where('technicians.0.pending_assignments_count', 1)
            ->where('technicians.0.sla_risk_assignments_count', 1)
        );

    // Technician sees only self
    $this->actingAs($cctvTech)
        ->get(route('workforce.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('technicians', 1)
            ->where('technicians.0.id', $cctvTech->id)
        );

    Carbon::setTestNow();
});

it('produces explainable smart dispatch recommendations, blocks conflicting assignments, and sends assignment notifications', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-16 09:00:00'));

    $operator = User::factory()->create(['role' => 'operator']);
    $service = Service::factory()->create([
        'name' => 'Instalasi Jaringan Kantor',
    ]);

    $bestTech = makeTechnician([
        'name' => 'Rafi Jaringan',
        'specialty' => 'Jaringan & Fiber',
        'availability_status' => 'available',
    ]);
    $leaveTech = makeTechnician([
        'name' => 'Dedi Cuti',
        'availability_status' => 'on_leave',
    ]);
    $busyScheduleTech = makeTechnician([
        'name' => 'Hendra Bentrok',
        'availability_status' => 'available',
    ]);

    $conflictOrder = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'assigned_technician_id' => $busyScheduleTech->id,
        'status' => 'scheduled',
        'scheduled_at' => Carbon::parse('2026-11-16 14:00:00'),
    ]);
    OrderAssignment::create([
        'service_order_id' => $conflictOrder->id,
        'user_id' => $busyScheduleTech->id,
        'assignment_role' => 'technician',
        'assigned_at' => now(),
    ]);

    $targetOrder = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'status' => 'triaged',
        'urgency' => 'soon',
        'scheduled_at' => Carbon::parse('2026-11-16 14:30:00'),
    ]);

    $recommendations = app(WorkforceService::class)->recommendForOrder($targetOrder);

    expect($recommendations[0]['id'])->toBe($bestTech->id)
        ->and($recommendations[0]['eligible'])->toBeTrue()
        ->and($recommendations[0]['reasons'])->not->toBeEmpty();

    $leaveRec = collect($recommendations)->firstWhere('id', $leaveTech->id);
    $busyRec = collect($recommendations)->firstWhere('id', $busyScheduleTech->id);
    expect($leaveRec['eligible'])->toBeFalse()
        ->and($busyRec['eligible'])->toBeFalse();

    // Conflicting schedule assignment blocked
    $this->actingAs($operator)
        ->patch(route('orders.team.update', $targetOrder), [
            'technician_ids' => [$busyScheduleTech->id],
            'lead_technician_id' => $busyScheduleTech->id,
        ])
        ->assertSessionHasErrors('technician_ids');

    // Assigning bestTech succeeds & notifies
    $this->actingAs($operator)
        ->patch(route('orders.team.update', $targetOrder), [
            'technician_ids' => [$bestTech->id],
            'lead_technician_id' => $bestTech->id,
        ])
        ->assertRedirect();

    expect($targetOrder->fresh()->assigned_technician_id)->toBe($bestTech->id);

    $assignedLogs = DB::table('notifications')->where('data', 'like', '%"order.assigned"%')->count();
    expect($assignedLogs)->toBeGreaterThanOrEqual(1);

    // Reassigning to another eligible technician dispatches order.assignment_changed to released tech
    $secondTech = makeTechnician(['availability_status' => 'available']);
    $this->actingAs($operator)
        ->patch(route('orders.team.update', $targetOrder), [
            'technician_ids' => [$secondTech->id],
            'lead_technician_id' => $secondTech->id,
        ])
        ->assertRedirect();

    expect(DB::table('notifications')->where('data', 'like', '%"order.assignment_changed"%')->count())->toBeGreaterThanOrEqual(1);
    if ($bestTech->phone) {
        Queue::assertPushed(SendWhatsAppNotificationJob::class);
    }

    Carbon::setTestNow();
});

it('tracks work session lifecycle independently from SLA timers and blocks invalid transitions', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-16 09:00:00'));

    $service = Service::factory()->create();
    $customer = User::factory()->create(['role' => 'customer']);
    $supervisor = User::factory()->create(['role' => 'supervisor']);
    $tech = makeTechnician(['availability_status' => 'available']);
    $otherTech = makeTechnician(['availability_status' => 'available']);

    $order = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'supervisor_id' => $supervisor->id,
        'assigned_technician_id' => $tech->id,
        'status' => 'in_progress',
        'sla_paused_seconds' => 0,
    ]);
    OrderAssignment::create([
        'service_order_id' => $order->id,
        'user_id' => $tech->id,
        'assignment_role' => 'technician',
        'assigned_at' => now(),
    ]);

    // Unassigned tech cannot start a work session
    $this->actingAs($otherTech)
        ->post(route('orders.work-sessions.store', $order), ['notes' => 'Tidak berhak'])
        ->assertForbidden();

    // Start at 09:00
    $this->actingAs($tech)
        ->post(route('orders.work-sessions.store', $order), ['notes' => 'Mulai pengecekan'])
        ->assertRedirect();

    $session = TechnicianWorkSession::where('service_order_id', $order->id)->firstOrFail();
    expect($session->status)->toBe('in_progress')
        ->and($tech->fresh()->availability_status)->toBe('busy');

    // Duplicate concurrent session on another ticket is blocked
    $otherOrder = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'assigned_technician_id' => $tech->id,
        'status' => 'scheduled',
    ]);
    $this->actingAs($tech)
        ->post(route('orders.work-sessions.store', $otherOrder), [])
        ->assertSessionHasErrors('session');

    // Pause at 09:30 (30 min worked)
    Carbon::setTestNow(Carbon::parse('2026-11-16 09:30:00'));
    $this->actingAs($tech)
        ->patch(route('orders.work-sessions.update', [$order, $session]), [
            'action' => 'pause',
            'notes' => 'Menunggu suku cadang',
        ])
        ->assertRedirect();

    expect($session->fresh()->status)->toBe('paused');

    // Pausing an already paused session fails validation
    $this->actingAs($tech)
        ->patch(route('orders.work-sessions.update', [$order, $session]), [
            'action' => 'pause',
        ])
        ->assertSessionHasErrors('session');

    // Resume at 09:45 (15 min paused)
    Carbon::setTestNow(Carbon::parse('2026-11-16 09:45:00'));
    $this->actingAs($tech)
        ->patch(route('orders.work-sessions.update', [$order, $session]), [
            'action' => 'resume',
            'notes' => 'Lanjut pemasangan',
        ])
        ->assertRedirect();

    expect($session->fresh()->status)->toBe('in_progress')
        ->and($session->fresh()->paused_seconds)->toBe(900);

    // Complete at 10:15 (75 min gross - 15 min pause = 60 min / 3600s net work)
    Carbon::setTestNow(Carbon::parse('2026-11-16 10:15:00'));
    $this->actingAs($tech)
        ->patch(route('orders.work-sessions.update', [$order, $session]), [
            'action' => 'complete',
            'notes' => 'Pekerjaan beres',
        ])
        ->assertRedirect();

    $session->refresh();
    expect($session->status)->toBe('completed')
        ->and($session->paused_seconds)->toBe(900)
        ->and($session->duration_seconds)->toBe(3600)
        ->and($order->fresh()->sla_paused_seconds)->toBe(0); // SLA timer remains completely separate

    expect(DB::table('notifications')->where('data', 'like', '%"work.started"%')->count())->toBeGreaterThanOrEqual(1);
    expect(DB::table('notifications')->where('data', 'like', '%"work.completed"%')->count())->toBeGreaterThanOrEqual(1);

    Carbon::setTestNow();
});

it('validates, privately stores, authorizes, and confirms work evidences', function (): void {
    Storage::fake('local');

    $customer = User::factory()->create(['role' => 'customer']);
    $otherCustomer = User::factory()->create(['role' => 'customer']);
    $tech = makeTechnician();
    $service = Service::factory()->create();

    $order = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'assigned_technician_id' => $tech->id,
        'status' => 'in_progress',
    ]);
    OrderAssignment::create([
        'service_order_id' => $order->id,
        'user_id' => $tech->id,
        'assignment_role' => 'technician',
        'assigned_at' => now(),
    ]);

    // Invalid MIME rejected
    $this->actingAs($tech)
        ->post(route('orders.evidences.store', $order), [
            'stage' => 'before',
            'photo' => UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors('photo');

    // Valid before image stored privately
    $image = UploadedFile::fake()->create('before.jpg', 120, 'image/jpeg');
    $this->actingAs($tech)
        ->post(route('orders.evidences.store', $order), [
            'stage' => 'before',
            'photo' => $image,
            'caption' => 'Kondisi kabel sebelum dirapikan',
        ])
        ->assertRedirect();

    $evidence = OrderWorkEvidence::where('service_order_id', $order->id)->firstOrFail();
    Storage::disk('local')->assertExists($evidence->file_path);

    // Unrelated customer cannot stream evidence file (ServiceOrderPolicy uses denyAsNotFound)
    $this->actingAs($otherCustomer)
        ->get(route('orders.evidences.file', [$order, $evidence]))
        ->assertNotFound();

    // Order customer can view evidence file and confirm it
    $this->actingAs($customer)
        ->get(route('orders.evidences.file', [$order, $evidence]))
        ->assertOk();

    $this->actingAs($customer)
        ->patch(route('orders.evidences.confirm', [$order, $evidence]), [
            'confirmation_note' => 'Sudah rapi sesuai kesepakatan',
        ])
        ->assertRedirect();

    expect($evidence->fresh()->customer_confirmed_at)->not->toBeNull()
        ->and($evidence->fresh()->confirmation_note)->toBe('Sudah rapi sesuai kesepakatan');
});

it('computes real database-backed technician performance metrics including reopened jobs and SLA compliance', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00'));

    $supervisor = User::factory()->create(['role' => 'supervisor']);
    $tech = makeTechnician(['name' => 'Teknisi Terukur']);
    $service = Service::factory()->create();

    $completedOrder = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'assigned_technician_id' => $tech->id,
        'status' => 'completed',
        'created_at' => Carbon::parse('2026-11-16 09:00:00'),
        'resolution_due_at' => Carbon::parse('2026-11-16 15:00:00'),
        'completed_at' => Carbon::parse('2026-11-16 11:00:00'),
    ]);
    OrderAssignment::create([
        'service_order_id' => $completedOrder->id,
        'user_id' => $tech->id,
        'assignment_role' => 'technician',
        'assigned_at' => Carbon::parse('2026-11-16 09:10:00'),
    ]);
    OrderStatusEvent::create([
        'service_order_id' => $completedOrder->id,
        'status' => 'reopened',
        'title' => 'Dibuka kembali',
        'visibility' => 'internal',
        'occurred_at' => Carbon::parse('2026-11-16 10:30:00'),
    ]);

    TechnicianWorkSession::create([
        'service_order_id' => $completedOrder->id,
        'technician_id' => $tech->id,
        'status' => 'completed',
        'started_at' => Carbon::parse('2026-11-16 09:30:00'),
        'completed_at' => Carbon::parse('2026-11-16 10:15:00'),
        'paused_seconds' => 0,
        'duration_seconds' => 2700,
    ]);

    $this->actingAs($supervisor)
        ->get(route('technicians.performance'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/technician-performance')
            ->where('technicians.0.id', $tech->id)
            ->where('technicians.0.work.completed_jobs_count', 1)
            ->where('technicians.0.work.avg_resolution_minutes', 120)
            ->where('technicians.0.work.avg_work_session_minutes', 45)
            ->where('technicians.0.work.sla_compliance_rate', 100)
            ->where('technicians.0.work.reopened_jobs_count', 1)
        );

    Carbon::setTestNow();
});
