<form method="post" action="{{ $address ? '/account/addresses/'.$address['id'] : '/account/addresses' }}" class="account-card">
    @csrf
    @if($address)<input type="hidden" name="address[version]" value="{{ $address['version'] }}">@endif
    @include('system.account-errors')
    @foreach(['label' => ['Address label', 60, 'off'], 'recipient' => ['Recipient', 120, 'shipping name'], 'line1' => ['Street address', 200, 'shipping address-line1'], 'line2' => ['Apartment or suite (optional)', 200, 'shipping address-line2'], 'city' => ['City', 120, 'shipping address-level2'], 'region' => ['Region or state (optional)', 120, 'shipping address-level1'], 'postal_code' => ['Postal code (optional)', 32, 'shipping postal-code']] as $field => $options)
        <div class="setup-field"><label for="address-{{ $field }}">{{ $options[0] }}</label><input id="address-{{ $field }}" name="address[{{ $field }}]" value="{{ $address[$field] ?? '' }}" maxlength="{{ $options[1] }}" autocomplete="{{ $options[2] }}" @required(in_array($field, ['label','recipient','line1','city'], true))></div>
    @endforeach
    <div class="setup-field"><label for="address-country">Country</label><select id="address-country" name="address[country_code]" autocomplete="shipping country" required><option value="">Choose a country</option>@foreach($countries as $country)<option value="{{ $country['code'] }}" @selected(($address['country_code'] ?? '') === $country['code'])>{{ $country['label'] }}</option>@endforeach</select></div>
    <button type="submit" class="primary-button">Save address</button>
</form>
