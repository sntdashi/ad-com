<?php

namespace App\Actions\Orders;

use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateServiceOrder
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{order: ServiceOrder, tracking_token: string, access_code: string}
     */
    public function handle(array $data, ?User $customer): array
    {
        $trackingToken = Str::random(64);
        $accessCode = Str::upper(Str::random(8));
        $order = DB::transaction(function () use ($data, $customer, $trackingToken, $accessCode): ServiceOrder {
            $service = Service::query()->where('is_active', true)->whereKey($data['service_id'])->firstOrFail();
            $order = ServiceOrder::query()->create([
                ...Arr::only($data, ['service_id', 'customer_name', 'phone', 'email', 'address', 'district', 'service_mode', 'complaint', 'preferred_date', 'preferred_window', 'urgency']),
                'customer_id' => $customer?->id,
                'ticket_number' => $this->generateTicketNumber(),
                'tracking_token_hash' => hash('sha256', $trackingToken),
                'access_code_hash' => hash('sha256', $accessCode),
                'risk_level' => 'unassessed',
                'status' => 'new',
                'service_mode' => $data['service_mode'] ?: $service->service_mode,
            ]);
            $order->statusEvents()->create([
                'status' => 'new',
                'title' => 'Permintaan berhasil dicatat',
                'description' => 'Tim AD Com akan memeriksa detail kebutuhanmu pada jam operasional.',
                'visibility' => 'customer',
                'occurred_at' => now(),
            ]);

            return $order;
        });

        $staffRecipients = User::query()
            ->whereIn('role', ['operator', 'admin', 'supervisor'])
            ->where('is_active', true)
            ->get();

        app(NotificationDispatcher::class)->notifyUsers(
            $staffRecipients,
            'order.created',
            "Tiket baru {$order->ticket_number}",
            "{$order->customer_name} mengajukan pesanan layanan baru.",
            route('orders.show', $order),
            ['ticket_number' => $order->ticket_number],
        );

        return ['order' => $order, 'tracking_token' => $trackingToken, 'access_code' => $accessCode];
    }

    private function generateTicketNumber(): string
    {
        do {
            $ticket = 'ADC-'.now()->format('ym').'-'.random_int(1000, 9999);
        } while (ServiceOrder::query()->where('ticket_number', $ticket)->exists());

        return $ticket;
    }
}
