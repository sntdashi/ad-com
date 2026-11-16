<?php

namespace App\Http\Controllers;

use App\Actions\Orders\SendOrderMessage;
use App\Http\Requests\StoreOrderMessageRequest;
use App\Models\ServiceOrder;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\RedirectResponse;

class OrderMessageController extends Controller
{
    public function store(StoreOrderMessageRequest $request, ServiceOrder $order, SendOrderMessage $send): RedirectResponse
    {
        $sender = $request->user();
        $send->handle($order, $sender, $request->string('body')->toString());

        $recipients = $sender->role === 'customer'
            ? array_filter([$order->assignedTechnician])
            : array_filter([$order->customer]);

        if ($recipients !== []) {
            app(NotificationDispatcher::class)->notifyUsers(
                $recipients,
                'order.message_received',
                "Pesan baru di tiket {$order->ticket_number}",
                "{$sender->name}: ".mb_strimwidth($request->string('body')->toString(), 0, 120, '...'),
                route('orders.show', $order),
                ['ticket_number' => $order->ticket_number],
                false,
            );
        }

        return back()->with('success', 'Pesan terkirim ke percakapan tiket.');
    }
}
