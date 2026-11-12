<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Support\NotificationCatalog;
use Illuminate\Support\Facades\View;

final class PreviewNotification
{
    public function html(string $template): string
    {
        $definition = NotificationCatalog::definition($template);
        $facts = $this->facts($template, $definition['label']);

        return View::make('mail.store-notification', [
            'heading' => $facts['heading'],
            'body' => $facts['body'],
            'unsubscribeUrl' => $definition['class'] === 'marketing' ? 'http://127.0.0.1:8000/preferences/unsubscribe?token=preview' : null,
            'policyClass' => $definition['class'],
            'test' => false,
        ])->render();
    }

    /**
     * @return array{heading: string, body: string}
     */
    public function facts(string $template, string $label): array
    {
        return match ($template) {
            'order-receipt' => ['heading' => 'Order receipt', 'body' => 'Example preview · Order MF-PREVIEW. Total USD 28.05.'],
            'shipment' => ['heading' => 'Order shipped', 'body' => 'Example preview · Order MF-PREVIEW shipped with carrier Example Post.'],
            'download-access' => ['heading' => 'Your download access', 'body' => 'Example preview · Confirm download access for order MF-PREVIEW.'],
            'refund' => ['heading' => 'Refund issued', 'body' => 'Example preview · A USD 10.00 refund was recorded for order MF-PREVIEW.'],
            'customer-challenge' => ['heading' => 'Confirm your email address', 'body' => 'Example preview · Confirm the address for this account. This preview contains no token.'],
            'newsletter' => ['heading' => 'Studio notes', 'body' => 'Example preview · This month in the workshop.'],
            'watch-alert' => ['heading' => 'Back in stock', 'body' => 'Example preview · The studio lamp is available again.'],
            'cart_recovery' => ['heading' => 'Your cart is waiting', 'body' => 'Example preview · Items in a cart are still available.'],
            default => ['heading' => $label, 'body' => 'Example preview for '.$label.'.'],
        };
    }
}
