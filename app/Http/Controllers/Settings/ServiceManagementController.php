<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SaveServiceRequest;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ServiceManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor !== null && $actor->is_active && $actor->role === 'operator', 403);

        return Inertia::render('settings/services', [
            'services' => Service::query()
                ->withCount('orders')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Service $service): array => [
                    'id' => $service->id,
                    'code' => $service->code,
                    'slug' => $service->slug,
                    'name' => $service->name,
                    'summary' => $service->summary,
                    'description' => $service->description,
                    'starting_price' => $service->starting_price,
                    'pricing_label' => $service->pricing_label,
                    'service_mode' => $service->service_mode,
                    'risk_level' => $service->risk_level,
                    'warranty_days' => $service->warranty_days,
                    'sort_order' => $service->sort_order,
                    'is_active' => $service->is_active,
                    'orders_count' => $service->orders_count,
                ]),
        ]);
    }

    public function store(SaveServiceRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $baseSlug = Str::slug($validated['name']);
        $slug = $baseSlug;
        $counter = 1;

        while (Service::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        DB::transaction(function () use ($request, $validated, $slug): void {
            $service = Service::query()->create([
                ...$validated,
                'slug' => $slug,
                'icon' => match ($validated['service_mode']) {
                    'remote' => 'wrench',
                    default => 'router',
                },
                'is_active' => $request->boolean('is_active', true),
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $request->user()->id,
                'event' => 'service.created',
                'auditable_type' => Service::class,
                'auditable_id' => (string) $service->id,
                'before' => null,
                'after' => json_encode($service->only(['code', 'name', 'starting_price', 'service_mode', 'is_active'])),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return back()->with('status', 'Layanan baru berhasil ditambahkan.');
    }

    public function update(SaveServiceRequest $request, Service $service): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, $service, $validated): void {
            $before = $service->only(['code', 'name', 'starting_price', 'pricing_label', 'service_mode', 'risk_level', 'warranty_days', 'sort_order', 'is_active']);

            $service->update([
                ...$validated,
                'is_active' => $request->boolean('is_active', $service->is_active),
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $request->user()->id,
                'event' => 'service.updated',
                'auditable_type' => Service::class,
                'auditable_id' => (string) $service->id,
                'before' => json_encode($before),
                'after' => json_encode($service->only(['code', 'name', 'starting_price', 'pricing_label', 'service_mode', 'risk_level', 'warranty_days', 'sort_order', 'is_active'])),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return back()->with('status', 'Detail layanan berhasil diperbarui.');
    }
}
