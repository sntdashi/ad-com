<?php

use App\Models\ServiceOrder;
use App\Models\TechnicianReview;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows verified technician performance aggregates to operators', function () {
    $operator = User::factory()->withTwoFactor()->create(['role' => 'operator']);
    $technician = User::factory()->create(['name' => 'Raka Wijaya', 'role' => 'technician']);
    $customer = User::factory()->create();
    $order = ServiceOrder::factory()->for($customer, 'customer')->create(['status' => 'completed']);
    TechnicianReview::factory()->for($order)->for($technician, 'technician')->for($customer, 'customer')->create([
        'rating' => 2,
        'quality_rating' => 3,
        'communication_rating' => 4,
        'punctuality_rating' => 2,
    ]);

    $this->actingAs($operator)
        ->get(route('technicians.performance'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/technician-performance')
            ->where('summary.reviews', 1)
            ->where('summary.average', 2)
            ->where('summary.needs_attention', 1)
            ->where('technicians.0.name', 'Raka Wijaya')
            ->where('technicians.0.rating_average', 2)
            ->where('technicians.0.quality_average', 3)
            ->where('recentReviews.0.ticket_number', $order->ticket_number));
});

it('limits a technician performance page to their own ratings', function () {
    $technician = User::factory()->withTwoFactor()->create(['name' => 'Teknisi Saya', 'role' => 'technician']);
    $otherTechnician = User::factory()->create(['name' => 'Teknisi Lain', 'role' => 'technician']);
    TechnicianReview::factory()->for($technician, 'technician')->create();
    TechnicianReview::factory()->for($otherTechnician, 'technician')->create();

    $this->actingAs($technician)
        ->get(route('technicians.performance'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('technicians', 1)
            ->where('technicians.0.name', 'Teknisi Saya')
            ->has('recentReviews', 1));
});

it('forbids customers from opening technician evaluation analytics', function () {
    $customer = User::factory()->create();

    $this->actingAs($customer)
        ->get(route('technicians.performance'))
        ->assertForbidden();
});
