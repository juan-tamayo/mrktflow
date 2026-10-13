<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Support;

use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CartOwner
{
    private function __construct(
        public ?string $customerId,
        public ?string $guestTokenHash,
    ) {}

    public static function customer(string $customerId): self
    {
        if (! Str::isUuid($customerId)) {
            throw new InvalidArgumentException('Invalid cart owner.');
        }

        return new self($customerId, null);
    }

    public static function guest(string $token): self
    {
        if ($token === '' || strlen($token) > 128) {
            throw new InvalidArgumentException('Invalid cart owner.');
        }

        return new self(null, hash('sha256', $token));
    }

    public static function fromRequest(Request $request): self
    {
        $principal = Auth::guard('customer')->user();
        if ($principal instanceof CustomerAccount) {
            $customer = CustomerAccount::query()->find($principal->id);
            if ($customer !== null && $customer->is_active
                && $request->session()->get('customer_session_version') === $customer->session_version) {
                return self::customer((string) $customer->id);
            }
        }
        $token = $request->session()->get('guest_identity');
        if (! is_string($token) || $token === '' || strlen($token) > 128) {
            $token = (string) Str::uuid();
            $request->session()->put('guest_identity', $token);
        }

        return self::guest($token);
    }

    public function principalScope(): string
    {
        if ($this->customerId !== null) {
            return 'customer:'.$this->customerId;
        }
        if ($this->guestTokenHash === null) {
            throw new InvalidArgumentException('Invalid cart owner.');
        }

        return 'guest:'.$this->guestTokenHash;
    }
}
