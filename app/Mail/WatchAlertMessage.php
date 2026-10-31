<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use InvalidArgumentException;

/**
 * One watch alert (SHOP-04). Every value is read from current catalog
 * state by DeliverWatchAlert in its send-time claim — this message
 * renders what the variant costs and whether it can be bought now,
 * never a price frozen when the triggering event fired.
 */
final class WatchAlertMessage extends Mailable
{
    /** @param array{amount: int, currency: string, exponent: int, formatted: string} $money */
    public function __construct(
        private readonly string $intentId,
        private readonly string $alertType,
        private readonly string $title,
        private readonly array $money,
        private readonly string $productUrl,
        private readonly string $unsubscribeUrl,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            subject: ($this->alertType === 'price' ? 'Price drop: ' : 'Back in stock: ').$this->title,
        );
    }

    public function headers(): Headers
    {
        $domain = strrchr($this->fromEmail, '@');
        if ($domain === false) {
            throw new InvalidArgumentException('A valid sender email is required.');
        }

        return new Headers(messageId: $this->intentId.'@'.substr($domain, 1));
    }

    public function content(): Content
    {
        $stock = $this->alertType === 'stock';

        return new Content(view: 'mail.watch-alert', with: [
            'alertType' => $this->alertType,
            'title' => $this->title,
            'heading' => $stock ? 'Back in stock' : 'Price drop',
            'bodyText' => $stock
                ? $this->title.' is available again at '.$this->money['formatted'].'.'
                : $this->title.' is now '.$this->money['formatted'].'.',
            'money' => $this->money,
            'productUrl' => $this->productUrl,
            'unsubscribeUrl' => $this->unsubscribeUrl,
        ]);
    }
}
