<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Operations\Support\NotificationCatalog;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use InvalidArgumentException;

final class StoreNotificationMessage extends Mailable
{
    public function __construct(
        private readonly string $deliveryId,
        private readonly string $template,
        private readonly string $heading,
        private readonly string $body,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly ?string $unsubscribeUrl,
        private readonly bool $test,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            subject: ($this->test ? 'Test: ' : '').$this->heading,
        );
    }

    public function headers(): Headers
    {
        $domain = strrchr($this->fromEmail, '@');
        if ($domain === false) {
            throw new InvalidArgumentException('A valid sender email is required.');
        }

        return new Headers(messageId: $this->deliveryId.'@'.substr($domain, 1), text: $this->unsubscribeUrl !== null ? [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ] : []);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.store-notification', with: [
            'heading' => $this->heading,
            'body' => $this->body,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'policyClass' => NotificationCatalog::policyClass($this->template),
            'test' => $this->test,
        ]);
    }
}
