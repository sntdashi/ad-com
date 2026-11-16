<?php

use App\Models\InventoryItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\TechnicianReview;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('returns 401 for protected mobile tools without a bearer token', function (string $route) {
    $this->getJson(route($route))->assertUnauthorized();
})->with(['api.v1.inventory.index', 'api.v1.technician.performance']);

it('returns only active inventory and omits internal cost from the technician response', function () {
    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    $item = InventoryItem::factory()->create(['name' => 'Kabel LAN', 'quantity_on_hand' => 2, 'reorder_level' => 5]);
    InventoryItem::factory()->create(['is_active' => false]);
    $token = $technician->createToken('Phone', ['mobile', 'role:technician', 'auth:2fa'], now()->addDay())->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.inventory.index'))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $item->id)
        ->assertJsonPath('data.0.is_low_stock', true)->assertJsonMissingPath('data.0.unit_cost');
});

it('returns 403 when a customer requests technician tools', function (string $route) {
    $user = User::factory()->create();
    $token = $user->createToken('Phone', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;

    $this->withToken($token)->getJson(route($route))->assertForbidden();
})->with(['api.v1.inventory.index', 'api.v1.technician.performance']);

it('requires email verification before accessing technician tools', function (string $route) {
    $technician = User::factory()->withTwoFactor()->unverified()->create(['role' => 'technician']);
    $token = $technician->createToken('Phone', ['mobile', 'role:technician', 'auth:2fa'], now()->addDay())->plainTextToken;

    $this->withToken($token)->getJson(route($route))
        ->assertForbidden()->assertJsonPath('code', 'email_not_verified');
})->with(['api.v1.inventory.index', 'api.v1.technician.performance']);

it('paginates inventory without skipping records and rejects invalid pages', function () {
    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    InventoryItem::factory()->count(31)->create(['name' => 'Kabel LAN']);
    $token = $technician->createToken('Phone', ['mobile', 'role:technician', 'auth:2fa'], now()->addDay())->plainTextToken;

    $firstPage = $this->withToken($token)->getJson(route('api.v1.inventory.index'))
        ->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.total', 31);
    $secondPage = $this->withToken($token)->getJson(route('api.v1.inventory.index', ['page' => 2]))
        ->assertOk()->assertJsonCount(1, 'data');

    expect(collect($firstPage->json('data'))->pluck('id'))->not->toContain($secondPage->json('data.0.id'));

    $this->withToken($token)->getJson(route('api.v1.inventory.index', ['page' => 0]))
        ->assertUnprocessable()->assertJsonValidationErrors('page');
});

it('calculates only the signed in technician performance and counts each completed order once', function () {
    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    $customer = User::factory()->create();
    $order = ServiceOrder::factory()->for($customer, 'customer')->create(['assigned_technician_id' => $technician->id, 'status' => 'completed']);
    $order->assignments()->create(['user_id' => $technician->id, 'assignment_role' => 'technician', 'assigned_at' => now()]);
    TechnicianReview::factory()->for($order, 'serviceOrder')->for($technician, 'technician')->for($customer, 'customer')
        ->create(['rating' => 2, 'quality_rating' => 3, 'communication_rating' => 4, 'punctuality_rating' => 5]);
    TechnicianReview::factory()->create(['rating' => 5]);
    $token = $technician->createToken('Phone', ['mobile', 'role:technician', 'auth:2fa'], now()->addDay())->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.technician.performance'))
        ->assertOk()->assertJsonPath('data.reviews_count', 1)->assertJsonPath('data.rating_average', 2)
        ->assertJsonPath('data.quality_average', 3)->assertJsonPath('data.communication_average', 4)
        ->assertJsonPath('data.punctuality_average', 5)->assertJsonPath('data.completed_orders_count', 1)
        ->assertJsonPath('data.low_rating_reviews_count', 1)->assertJsonCount(1, 'data.recent_reviews')
        ->assertJsonMissingPath('data.recent_reviews.0.customer_id');
});

it('returns null averages for a technician who has not been rated', function () {
    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    $token = $technician->createToken('Phone', ['mobile', 'role:technician', 'auth:2fa'], now()->addDay())->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.technician.performance'))
        ->assertOk()->assertJsonPath('data.rating_average', null)->assertJsonPath('data.reviews_count', 0);
});

it('uses the existing ADI guide from a bearer request without needing a web session', function () {
    config()->set('services.openai.key', null);
    Http::preventStrayRequests();
    Service::factory()->create(['slug' => 'instalasi-cctv', 'name' => 'Instalasi CCTV']);
    $user = User::factory()->create();
    $token = $user->createToken('Phone', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;

    $this->withToken($token)->postJson(route('api.v1.assistant.messages.store'), ['message' => 'Ingin pasang CCTV'])
        ->assertOk()->assertJsonPath('mode', 'guide')->assertJsonPath('services.0.name', 'Instalasi CCTV');

    Http::assertNothingSent();
});

it('applies the ADI identity and scope rules through the mobile endpoint', function (string $message, string $expected) {
    config()->set('services.openai.key', null);
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $token = $user->createToken('Phone', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;

    $response = $this->withToken($token)->postJson(route('api.v1.assistant.messages.store'), ['message' => $message]);

    $response->assertOk()->assertJsonPath('mode', 'guide');
    expect($response->json('reply'))->toContain($expected);
    Http::assertNothingSent();
})->with([
    'identity' => ['Siapa kamu?', 'Saya ADI, asisten virtual AD Com'],
    'scope' => ['Buatin program CCTV dong', 'di luar lingkup layanan AD Com'],
    'unrecognized topic' => ['Apa itu helikopter?', 'di luar layanan AD Com'],
]);
