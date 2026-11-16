<?php

use App\Models\FinancialTransaction;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function mobileOrderToken(User $user): string
{
    return $user->createToken('Android', ['mobile', 'role:'.$user->role, 'auth:2fa'], now()->addDay())->plainTextToken;
}

it('lists only active public services', function () {
    $service = Service::factory()->create();
    Service::factory()->create(['is_active' => false]);

    $this->getJson(route('api.v1.services.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $service->id);
});

it('returns 401 for unauthenticated orders and 403 for unverified customers', function () {
    $this->getJson(route('api.v1.orders.index'))->assertUnauthorized();
    $customer = User::factory()->unverified()->create();

    $this->withToken(mobileOrderToken($customer))->getJson(route('api.v1.orders.index'))->assertForbidden();
});

it('creates a customer owned order and timeline without trusting injected operational fields', function () {
    $customer = User::factory()->create();
    $service = Service::factory()->create();
    $other = User::factory()->create();

    $response = $this->withToken(mobileOrderToken($customer))->postJson(route('api.v1.orders.store'), [
        'service_id' => $service->id, 'customer_name' => 'Dina Putri', 'phone' => '081234567890',
        'address' => 'Jalan Pendidikan Nomor 10', 'complaint' => 'Jaringan internet putus setiap pagi sejak minggu lalu.',
        'service_mode' => 'onsite', 'urgency' => 'normal', 'privacy_accepted' => true,
        'customer_id' => $other->id, 'status' => 'completed', 'assigned_technician_id' => $other->id,
        'estimated_total' => 1,
    ])->assertCreated()->assertJsonPath('data.status', 'new')->assertJsonPath('data.technician', null)
        ->assertJsonCount(1, 'data.timeline')->assertJsonMissingPath('data.tracking_token_hash');

    $this->assertDatabaseHas('service_orders', [
        'id' => $response->json('data.id'), 'customer_id' => $customer->id, 'phone' => '6281234567890',
        'status' => 'new', 'estimated_total' => null,
    ]);
});

it('rejects incomplete bookings and does not persist orders', function () {
    $customer = User::factory()->create();

    $this->withToken(mobileOrderToken($customer))->postJson(route('api.v1.orders.store'), [])
        ->assertUnprocessable()->assertJsonValidationErrors(['service_id', 'phone', 'complaint', 'privacy_accepted']);
    $this->assertDatabaseCount('service_orders', 0);
});

it('scopes customer order lists and all nested endpoints to the ticket owner', function () {
    $customer = User::factory()->create();
    $own = ServiceOrder::factory()->for($customer, 'customer')->create();
    $other = ServiceOrder::factory()->for(User::factory(), 'customer')->create();
    $this->withToken(mobileOrderToken($customer));

    $this->getJson(route('api.v1.orders.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    $this->getJson(route('api.v1.orders.show', $other))->assertNotFound();
    $this->getJson(route('api.v1.orders.messages.index', $other))->assertNotFound();
    $this->postJson(route('api.v1.orders.messages.store', $other), ['body' => 'Bocor'])->assertNotFound();
    $this->patchJson(route('api.v1.orders.status.update', $other), ['status' => 'completed'])->assertNotFound();
    $this->postJson(route('api.v1.orders.reviews.store', $other), [])->assertNotFound();
    $this->assertDatabaseCount('order_messages', 0);
});

it('exposes only assigned tickets to a technician and denies released assignments', function () {
    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    $active = ServiceOrder::factory()->create();
    $active->assignments()->create(['user_id' => $technician->id, 'assignment_role' => 'technician', 'assigned_at' => now()]);
    $released = ServiceOrder::factory()->create();
    $released->assignments()->create(['user_id' => $technician->id, 'assignment_role' => 'technician', 'assigned_at' => now(), 'released_at' => now()]);
    $this->withToken(mobileOrderToken($technician));

    $this->getJson(route('api.v1.orders.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
    $this->getJson(route('api.v1.orders.show', $released))->assertNotFound();
    $this->postJson(route('api.v1.orders.store'), [])->assertForbidden();
});

it('hides internal timeline costs and nonconsenting technician phone numbers from customers', function () {
    $customer = User::factory()->create();
    $technician = User::factory()->create(['role' => 'technician', 'phone' => '6281234567890', 'show_phone_publicly' => false]);
    $order = ServiceOrder::factory()->for($customer, 'customer')->create(['assigned_technician_id' => $technician->id]);
    $order->statusEvents()->create(['status' => 'new', 'title' => 'Rahasia internal', 'visibility' => 'internal', 'occurred_at' => now()]);
    $order->statusEvents()->create(['status' => 'new', 'title' => 'Terlihat pelanggan', 'visibility' => 'customer', 'occurred_at' => now()]);
    FinancialTransaction::factory()->create(['service_order_id' => $order->id, 'type' => 'expense', 'amount' => 999000, 'status' => 'verified']);
    FinancialTransaction::factory()->create(['service_order_id' => $order->id, 'type' => 'income', 'amount' => 125000, 'status' => 'verified']);

    $this->withToken(mobileOrderToken($customer))->getJson(route('api.v1.orders.show', $order))
        ->assertOk()->assertJsonCount(1, 'data.timeline')->assertJsonPath('data.timeline.0.title', 'Terlihat pelanggan')
        ->assertJsonPath('data.technician.phone', null)->assertJsonPath('data.finance.verified_income', 125000);

    $this->actingAs($customer, 'web')->get(route('orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page->where('order.timeline.0.title', 'Terlihat pelanggan')->has('order.timeline', 1));
});

it('routes chat to the assigned PIC and paginates older messages without losing history', function () {
    $customer = User::factory()->create();
    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    $order = ServiceOrder::factory()->for($customer, 'customer')->create(['assigned_technician_id' => $technician->id]);
    for ($index = 1; $index <= 55; $index++) {
        $order->messages()->create(['sender_id' => $technician->id, 'recipient_id' => $customer->id, 'body' => 'Pesan '.$index, 'created_at' => now()->addSeconds($index)]);
    }
    $this->withToken(mobileOrderToken($customer));
    $latest = $this->getJson(route('api.v1.orders.messages.index', $order))->assertOk()->assertJsonCount(50, 'data')
        ->assertJsonPath('data.0.body', 'Pesan 6')->assertJsonPath('data.49.body', 'Pesan 55');
    $this->getJson(route('api.v1.orders.messages.index', ['order' => $order, 'before_id' => $latest->json('meta.oldest_id')]))
        ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.body', 'Pesan 1');

    $response = $this->postJson(route('api.v1.orders.messages.store', $order), ['body' => 'Terima kasih', 'sender_id' => $technician->id])
        ->assertCreated()->assertJsonPath('data.is_mine', true);

    $this->assertDatabaseHas('order_messages', ['id' => $response->json('data.id'), 'sender_id' => $customer->id, 'recipient_id' => $technician->id]);
});

it('returns 422 when chat has no assigned PIC or the message is blank', function () {
    $customer = User::factory()->create();
    $order = ServiceOrder::factory()->for($customer, 'customer')->create();
    $this->withToken(mobileOrderToken($customer));

    $this->postJson(route('api.v1.orders.messages.store', $order), ['body' => 'Halo'])->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->postJson(route('api.v1.orders.messages.store', $order), ['body' => '   '])->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->assertDatabaseCount('order_messages', 0);
});

it('permits only sequential technician progress and reserves completion for verification', function () {
    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    $order = ServiceOrder::factory()->create(['assigned_technician_id' => $technician->id, 'status' => 'scheduled']);
    $this->withToken(mobileOrderToken($technician));

    $this->patchJson(route('api.v1.orders.status.update', $order), ['status' => 'completed'])->assertUnprocessable();
    $this->patchJson(route('api.v1.orders.status.update', $order), ['status' => 'on_the_way', 'note' => 'Menuju lokasi'])
        ->assertOk()->assertJsonPath('data.status', 'on_the_way');
    $this->patchJson(route('api.v1.orders.status.update', $order), ['status' => 'on_the_way'])->assertUnprocessable();

    expect($order->refresh()->status)->toBe('on_the_way');
    expect($order->statusEvents()->count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['event' => 'service_order.status_updated', 'auditable_id' => $order->id]);
});

it('forbids customers from changing ticket status', function () {
    $customer = User::factory()->create();
    $order = ServiceOrder::factory()->for($customer, 'customer')->create();

    $this->withToken(mobileOrderToken($customer))->patchJson(route('api.v1.orders.status.update', $order), ['status' => 'completed'])->assertForbidden();
    expect($order->refresh()->status)->toBe('new');
});

it('allows one editable verified review per historically assigned technician after completion', function () {
    $customer = User::factory()->create();
    $technician = User::factory()->create(['role' => 'technician']);
    $other = User::factory()->create(['role' => 'technician']);
    $order = ServiceOrder::factory()->for($customer, 'customer')->create(['status' => 'completed']);
    $order->assignments()->create(['user_id' => $technician->id, 'assignment_role' => 'technician', 'assigned_at' => now(), 'released_at' => now()]);
    $data = ['technician_id' => $technician->id, 'rating' => 4, 'quality_rating' => 4, 'communication_rating' => 5, 'punctuality_rating' => 4, 'comment' => 'Rapi'];
    $this->withToken(mobileOrderToken($customer));

    $this->postJson(route('api.v1.orders.reviews.store', $order), $data)->assertCreated()->assertJsonPath('data.rating', 4);
    $this->postJson(route('api.v1.orders.reviews.store', $order), [...$data, 'rating' => 2])->assertOk()->assertJsonPath('data.rating', 2);
    $this->postJson(route('api.v1.orders.reviews.store', $order), [...$data, 'technician_id' => $other->id])->assertUnprocessable();
    $this->postJson(route('api.v1.orders.reviews.store', $order), [...$data, 'rating' => 6])->assertUnprocessable();

    $this->assertDatabaseCount('technician_reviews', 1);
    $this->assertDatabaseHas('technician_reviews', ['service_order_id' => $order->id, 'customer_id' => $customer->id, 'technician_id' => $technician->id, 'rating' => 2]);
    $order->update(['status' => 'in_progress']);
    $this->postJson(route('api.v1.orders.reviews.store', $order), $data)->assertForbidden();
});
