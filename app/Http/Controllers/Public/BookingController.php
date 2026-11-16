<?php

namespace App\Http\Controllers\Public;

use App\Actions\Orders\CreateServiceOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceOrderRequest;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('booking', [
            'services' => Service::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'code', 'slug', 'name', 'summary', 'pricing_label', 'service_mode', 'risk_level', 'icon']),
            'selectedServiceSlug' => $request->string('service')->toString(),
        ]);
    }

    public function store(StoreServiceOrderRequest $request, CreateServiceOrder $create): RedirectResponse
    {
        $result = $create->handle($request->safe()->except('privacy_accepted'), $request->user());

        return to_route('track.token', ['token' => $result['tracking_token']])
            ->with('access_code', $result['access_code'])
            ->with('order_created', true)
            ->with('ticket_number', $result['order']->ticket_number);
    }
}
