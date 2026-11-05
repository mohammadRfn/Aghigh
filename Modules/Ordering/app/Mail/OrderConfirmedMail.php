<?php

declare(strict_types=1);

namespace Modules\Ordering\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\Ordering\Models\Order;

/** Built from the order id at send time (sent synchronously inside SendOrderConfirmation). */
final class OrderConfirmedMail extends Mailable
{
    public function __construct(public readonly string $orderId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Aghigh order is confirmed');
    }

    public function content(): Content
    {
        $order = Order::query()->with('items')->findOrFail($this->orderId);
        $lines = '';
        foreach ($order->items as $item) {
            $lines .= sprintf('<li>%s &times; %d = %d %s</li>', e($item->product_id), $item->quantity, $item->line_total_minor, e($item->currency));
        }

        return new Content(htmlString: sprintf(
            '<h1>Order confirmed</h1><p>Order %s is paid and confirmed.</p><ul>%s</ul><p>Total: %d %s (minor units)</p>',
            e($order->id), $lines, $order->total_minor, e($order->currency),
        ));
    }
}
