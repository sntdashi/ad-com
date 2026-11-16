<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class TrackOrderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('track');
    }

    public function lookup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ticket_number' => ['required', 'string', 'max:24'],
            'access_code' => ['required', 'string', 'size:8'],
        ]);

        $order = ServiceOrder::query()
            ->where('ticket_number', strtoupper($validated['ticket_number']))
            ->first();

        if (! $order || ! hash_equals($order->access_code_hash, hash('sha256', strtoupper($validated['access_code'])))) {
            return back()->withErrors([
                'ticket_number' => 'Nomor tiket atau kode akses tidak cocok.',
            ])->onlyInput('ticket_number');
        }

        return redirect(URL::temporarySignedRoute(
            'track.signed',
            now()->addMinutes(30),
            ['order' => $order->id],
        ));
    }

    public function token(Request $request, string $token): Response
    {
        $order = ServiceOrder::query()
            ->where('tracking_token_hash', hash('sha256', $token))
            ->firstOrFail();

        return $this->renderOrder($request, $order);
    }

    public function signed(Request $request, ServiceOrder $order): Response
    {
        abort_unless($request->hasValidSignature(), 403);

        return $this->renderOrder($request, $order);
    }

    private function renderOrder(Request $request, ServiceOrder $order): Response
    {
        $order->load([
            'service:id,name,code,warranty_days',
            'assignedTechnician:id,name,phone,avatar_url,specialty,bio,show_phone_publicly',
            'activeTechnicianAssignments.user:id,name,avatar_url,specialty',
            'supervisor:id,name',
            'statusEvents' => fn ($query) => $query->where('visibility', 'customer'),
            'estimates' => fn ($query) => $query->with('items')->latest('version'),
            'financialTransactions' => fn ($query) => $query->where('type', 'income')->latest('occurred_at'),
        ]);

        $latestEstimate = $order->estimates->first();
        $technicianIds = $order->activeTechnicianAssignments
            ->pluck('user_id')
            ->when($order->assigned_technician_id !== null, fn ($ids) => $ids->push($order->assigned_technician_id))
            ->unique()
            ->values();
        $technicianRatings = User::query()
            ->whereKey($technicianIds)
            ->withAvg('receivedTechnicianReviews as rating_average', 'rating')
            ->withCount('receivedTechnicianReviews')
            ->get()
            ->keyBy('id');

        return Inertia::render('track-show', [
            'created' => (bool) $request->session()->get('order_created', false),
            'accessCode' => $request->session()->get('access_code'),
            'order' => [
                'ticket_number' => $order->ticket_number,
                'status' => $order->status,
                'status_label' => $this->statusLabel($order->status),
                'customer_name' => $order->customer_name,
                'service' => $order->service->name,
                'service_code' => $order->service->code,
                'complaint' => $order->complaint,
                'preferred_date' => $order->preferred_date?->translatedFormat('d F Y'),
                'preferred_window' => $this->windowLabel($order->preferred_window),
                'scheduled_at' => $order->scheduled_at?->translatedFormat('d M Y, H.i'),
                'technician' => $order->assignedTechnician ? [
                    'name' => $order->assignedTechnician->name,
                    'phone' => $order->assignedTechnician->show_phone_publicly ? $order->assignedTechnician->phone : null,
                    'avatar_url' => $order->assignedTechnician->avatar_url,
                    'specialty' => $order->assignedTechnician->specialty,
                    'bio' => $order->assignedTechnician->bio,
                    'rating_average' => $this->nullableAverage(
                        $technicianRatings->get($order->assignedTechnician->id)?->getAttribute('rating_average'),
                    ),
                    'reviews_count' => (int) ($technicianRatings->get($order->assignedTechnician->id)?->getAttribute('received_technician_reviews_count') ?? 0),
                ] : null,
                'technician_team' => $order->activeTechnicianAssignments
                    ->map(fn ($assignment) => [
                        'name' => $assignment->user->name,
                        'avatar_url' => $assignment->user->avatar_url,
                        'specialty' => $assignment->user->specialty,
                        'rating_average' => $this->nullableAverage(
                            $technicianRatings->get($assignment->user_id)?->getAttribute('rating_average'),
                        ),
                        'reviews_count' => (int) ($technicianRatings->get($assignment->user_id)?->getAttribute('received_technician_reviews_count') ?? 0),
                    ])
                    ->values(),
                'supervisor' => $order->supervisor?->name,
                'risk_level' => $order->risk_level,
                'estimated_total' => $order->estimated_total,
                'created_at' => $order->created_at->translatedFormat('d M Y, H.i'),
                'timeline' => $order->statusEvents->map(fn ($event) => [
                    'status' => $event->status,
                    'title' => $event->title,
                    'description' => $event->description,
                    'occurred_at' => $event->occurred_at->translatedFormat('d M Y, H.i'),
                ]),
                'estimate' => $latestEstimate ? [
                    'version' => $latestEstimate->version,
                    'status' => $latestEstimate->status,
                    'total' => $latestEstimate->total,
                    'valid_until' => $latestEstimate->valid_until?->translatedFormat('d M Y'),
                    'items' => $latestEstimate->items->map(fn ($item) => [
                        'label' => $item->label,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'total' => $item->total,
                    ]),
                ] : null,
                'payment' => [
                    'verified' => (int) $order->financialTransactions->where('status', 'verified')->sum('amount'),
                    'pending' => (int) $order->financialTransactions->where('status', 'pending')->sum('amount'),
                    'last_method' => $order->financialTransactions->first()?->payment_method,
                    'last_reference' => $order->financialTransactions->first()?->reference,
                ],
            ],
        ]);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'new' => 'Permintaan diterima',
            'needs_info' => 'Perlu info tambahan',
            'triaged' => 'Selesai ditriase',
            'waiting_estimate' => 'Menunggu estimasi',
            'waiting_approval' => 'Menunggu persetujuan',
            'scheduled' => 'Sudah dijadwalkan',
            'on_the_way' => 'Tim menuju lokasi',
            'in_progress' => 'Sedang dikerjakan',
            'verification' => 'Verifikasi supervisor',
            'waiting_payment' => 'Menunggu pembayaran',
            'completed' => 'Selesai',
            'warranty' => 'Dalam masa garansi',
            'closed' => 'Ditutup',
            'reopened' => 'Dibuka kembali',
            'cancelled' => 'Dibatalkan',
            default => 'Diproses',
        };
    }

    private function nullableAverage(mixed $average): ?float
    {
        return $average === null ? null : round((float) $average, 2);
    }

    private function windowLabel(?string $window): ?string
    {
        return match ($window) {
            'morning' => '08.00–10.00',
            'midday' => '10.00–12.00',
            'afternoon' => '13.00–15.30',
            default => null,
        };
    }
}
