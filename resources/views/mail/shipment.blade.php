<section>
    <h2>Order shipped</h2>
    <p>Order {{ $order['number'] }}.@if($shipment['carrier'] !== '') Carrier {{ $shipment['carrier'] }}.@endif @if($shipment['tracking_number'] !== '') Tracking {{ $shipment['tracking_number'] }}.@endif</p>
    @if($shipment['tracking_url'] !== null)
        <p><a href="{{ $shipment['tracking_url'] }}">Track your shipment</a></p>
    @endif
    @foreach($shipment['lines'] as $line)
        <p>{{ $line['title'] }} · {{ $line['sku'] }} · qty {{ $line['quantity'] }}</p>
    @endforeach
</section>
