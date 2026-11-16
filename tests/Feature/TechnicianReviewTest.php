<?php

use App\Models\OrderAssignment;
use App\Models\ServiceOrder;
use App\Models\TechnicianReview;
use App\Models\User;

it('stores one verified review per technician on a completed customer order', function () {
    $customer = User::factory()->create();
    $technician = User::factory()->create(['role' => 'technician']);
    $order = ServiceOrder::factory()->for($customer, 'customer')->create([
        'assigned_technician_id' => $technician->id,
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    OrderAssignment::factory()->for($order)->for($technician)->create();

    $response = $this->actingAs($customer)->post(route('orders.technician-reviews.store', $order), [
        'technician_id' => $technician->id,
        'rating' => 5,
        'quality_rating' => 5,
        'communication_rating' => 4,
        'punctuality_rating' => 5,
        'tags' => ['rapi', 'solutif'],
        'comment' => 'Hasil pemasangan rapi dan penjelasannya jelas.',
    ]);

    $response->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('technician_reviews', [
        'service_order_id' => $order->id,
        'technician_id' => $technician->id,
        'customer_id' => $customer->id,
        'rating' => 5,
        'communication_rating' => 4,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $customer->id,
        'event' => 'technician_review.created',
        'auditable_type' => TechnicianReview::class,
    ]);
});

it('updates an existing review without creating a duplicate', function () {
    $customer = User::factory()->create();
    $technician = User::factory()->create(['role' => 'technician']);
    $order = ServiceOrder::factory()->for($customer, 'customer')->create([
        'assigned_technician_id' => $technician->id,
        'status' => 'completed',
    ]);
    $review = TechnicianReview::factory()->for($order)->for($technician, 'technician')->for($customer, 'customer')->create([
        'rating' => 3,
    ]);

    $response = $this->actingAs($customer)->post(route('orders.technician-reviews.store', $order), [
        'technician_id' => $technician->id,
        'rating' => 4,
        'quality_rating' => 4,
        'communication_rating' => 5,
        'punctuality_rating' => 4,
        'tags' => ['komunikatif'],
        'comment' => 'Lebih baik setelah pengecekan ulang.',
    ]);

    $response->assertRedirect()->assertSessionHasNoErrors();
    expect($review->refresh()->rating)->toBe(4)
        ->and(TechnicianReview::query()->where('service_order_id', $order->id)->count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $customer->id,
        'event' => 'technician_review.updated',
        'auditable_id' => (string) $review->id,
    ]);
});

it('forbids reviews from another customer and before the order is completed', function () {
    $owner = User::factory()->create();
    $otherCustomer = User::factory()->create();
    $technician = User::factory()->create(['role' => 'technician']);
    $order = ServiceOrder::factory()->for($owner, 'customer')->create([
        'assigned_technician_id' => $technician->id,
        'status' => 'in_progress',
    ]);
    $payload = [
        'technician_id' => $technician->id,
        'rating' => 5,
        'quality_rating' => 5,
        'communication_rating' => 5,
        'punctuality_rating' => 5,
    ];

    $this->actingAs($otherCustomer)
        ->post(route('orders.technician-reviews.store', $order), $payload)
        ->assertForbidden();
    $this->actingAs($owner)
        ->post(route('orders.technician-reviews.store', $order), $payload)
        ->assertForbidden();

    expect(TechnicianReview::query()->count())->toBe(0);
});

it('rejects a technician who did not work on the order and invalid scores', function () {
    $customer = User::factory()->create();
    $assignedTechnician = User::factory()->create(['role' => 'technician']);
    $otherTechnician = User::factory()->create(['role' => 'technician']);
    $order = ServiceOrder::factory()->for($customer, 'customer')->create([
        'assigned_technician_id' => $assignedTechnician->id,
        'status' => 'completed',
    ]);

    $response = $this->actingAs($customer)
        ->from(route('orders.show', $order))
        ->post(route('orders.technician-reviews.store', $order), [
            'technician_id' => $otherTechnician->id,
            'rating' => 6,
            'quality_rating' => 0,
            'communication_rating' => 5,
            'punctuality_rating' => 5,
        ]);

    $response
        ->assertRedirect(route('orders.show', $order))
        ->assertSessionHasErrors(['technician_id', 'rating', 'quality_rating']);
    expect(TechnicianReview::query()->count())->toBe(0);
});
