<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Support;

use InvalidArgumentException;

final class OrderStates
{
    /**
     * @return array{financial_status: string, fulfillment_status: string, download_status: string}
     */
    public static function initial(bool $paid, bool $hasPhysical, bool $hasDigital): array
    {
        return [
            'financial_status' => $paid ? 'paid' : 'unpaid',
            'fulfillment_status' => $hasPhysical ? 'unfulfilled' : 'not_required',
            'download_status' => ! $hasDigital ? 'not_required' : ($paid ? 'entitled' : 'locked'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function paidColumns(object $order): array
    {
        $download = self::string($order, 'download_status');

        return [
            'financial_status' => 'paid',
            'download_status' => $download === 'locked' ? 'entitled' : $download,
        ];
    }

    public static function assertFinancial(string $status): void
    {
        if (! in_array($status, ['unpaid', 'paid', 'unknown'], true)) {
            throw new InvalidArgumentException('Invalid financial status.');
        }
    }

    private static function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
