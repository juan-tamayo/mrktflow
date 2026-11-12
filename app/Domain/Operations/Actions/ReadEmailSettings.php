<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Support\CustomerMailConfiguration;
use App\Domain\Operations\Support\NotificationCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ReadEmailSettings
{
    /**
     * @return array{sender: array{email: string|null, name: string|null}, mailerReady: bool, exactlyOnce: false, disclaimer: string, templates: list<array{key: string, label: string, policyClass: string, purpose: ?string}>, preview: array{template: string, html: string}}
     */
    public function page(?string $template = null): array
    {
        $key = $template !== null && isset(NotificationCatalog::TEMPLATES[$template]) ? $template : 'order-receipt';
        $ready = app(CustomerMailConfiguration::class)->ready();
        $raw = DB::table('store_settings')->where('id', 1)->value('values');
        $settings = is_string($raw) ? json_decode($raw, true) : null;
        $senderEmail = null;
        $senderName = null;
        if (is_array($settings) && ! Validator::make($settings, [
            'sender_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'sender_name' => ['required', 'string', 'max:120'],
        ])->fails()) {
            $senderEmail = $settings['sender_email'];
            $senderName = $settings['sender_name'];
        }
        $templates = [];
        foreach (NotificationCatalog::TEMPLATES as $name => $definition) {
            $templates[] = [
                'key' => $name,
                'label' => $definition['label'],
                'policyClass' => $definition['class'],
                'purpose' => $definition['purpose'],
            ];
        }

        return [
            'sender' => ['email' => $senderEmail, 'name' => $senderName],
            'mailerReady' => $ready !== null,
            'exactlyOnce' => false,
            'disclaimer' => NotificationCatalog::DISCLAIMER,
            'templates' => $templates,
            'preview' => [
                'template' => $key,
                'html' => app(PreviewNotification::class)->html($key),
            ],
        ];
    }
}
