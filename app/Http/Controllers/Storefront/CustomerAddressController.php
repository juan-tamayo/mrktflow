<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Identity\Actions\CustomerAddresses;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Platform\Support\SetupChoices;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CustomerAddressController
{
    public function index(Request $request, CustomerAddresses $addresses, SetupChoices $choices): View
    {
        return view('system.customer-addresses', ['addresses' => $addresses->all($this->actor($request)), 'countries' => $choices->countries(), 'address' => null]);
    }

    public function edit(Request $request, string $addressId, CustomerAddresses $addresses, SetupChoices $choices): View
    {
        $address = collect($addresses->all($this->actor($request)))->firstWhere('id', $addressId);
        abort_unless($address !== null, 404);

        return view('system.customer-address-edit', ['address' => $address, 'countries' => $choices->countries()]);
    }

    public function save(Request $request, CustomerAddresses $addresses, ?string $addressId = null): RedirectResponse
    {
        $input = $request->validate(['address' => ['required', 'array:label,recipient,line1,line2,city,region,postal_code,country_code,version']]);
        $addresses->save($this->actor($request), $input['address'], $addressId);

        return redirect('/account/addresses')->with('status', 'Your address is saved.');
    }

    public function remove(Request $request, string $addressId, CustomerAddresses $addresses): RedirectResponse
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:4294967295']]);
        $addresses->remove($this->actor($request), $addressId, (int) $input['version']);

        return redirect('/account/addresses')->with('status', 'Your address is removed.');
    }

    private function actor(Request $request): CustomerAccount
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof CustomerAccount, 403);

        return $customer;
    }
}
