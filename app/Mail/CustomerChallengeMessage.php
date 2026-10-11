<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

final class CustomerChallengeMessage extends Mailable
{
    public function __construct(private readonly string $deliveryId, private readonly string $purpose, #[\SensitiveParameter] private readonly string $token, private readonly string $fromEmail, private readonly string $fromName, private readonly string $baseUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address($this->fromEmail, $this->fromName), subject: $this->purpose === 'verify_email' ? 'Confirm your email address' : 'Reset your account password');
    }

    public function headers(): Headers
    {
        $domain = strrchr($this->fromEmail, '@');
        if ($domain === false) {
            throw new \InvalidArgumentException('A valid sender email is required.');
        }

        return new Headers(messageId: $this->deliveryId.'@'.substr($domain, 1));
    }

    public function content(): Content
    {
        $verification = $this->purpose === 'verify_email';

        return new Content(view: 'mail.customer-challenge', with: [
            'title' => $verification ? 'Confirm your email address' : 'Reset your account password',
            'instruction' => $verification ? 'Sign in to your customer account, then confirm your email using the link or code below.' : 'Choose a new password using the link or code below.',
            'url' => $this->baseUrl.($verification ? '/account/verify#' : '/account/reset-password#').$this->token,
            'token' => $this->token, 'expiry' => $verification ? '24 hours' : 'one hour',
        ]);
    }
}
