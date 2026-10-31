@extends('default-theme::layout', [
    'pageKey' => 'account-order',
    'pageTitle' => 'Order '.$order['number'].' · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css'],
    'mainId' => 'order-main',
    'mainClass' => 'theme-checkout',
    'skipLabel' => 'Skip to order',
    'footerLabel' => 'Account',
])
@section('content')
            <h1>Order {{ $order['number'] }}<span class="accent">.</span></h1>
            <p class="theme-purchase-note">{{ $order['email'] }} · status {{ $order['status'] }} · financial {{ $order['financial_status'] }}</p>
            <h2>Receipt</h2>
            <p class="theme-purchase-note">Frozen totals from checkout. Grand {{ $order['receipt']['grand_total']['formatted'] }}.</p>
            <ul class="product-list">
                @foreach($order['lines'] as $line)
                    <li>
                        <p>{{ $line['title'] }} · {{ $line['sku'] }} · qty {{ $line['quantity'] }} · {{ $line['total']['formatted'] }}</p>
                    </li>
                @endforeach
            </ul>
            <p>Merchandise {{ $order['totals']['merchandise']['formatted'] }} · discount {{ $order['totals']['discount']['formatted'] }} · shipping {{ $order['totals']['shipping']['formatted'] }} · tax {{ $order['totals']['tax']['formatted'] }} · {{ $order['totals']['grand_total']['formatted'] }}</p>
            @if($order['shipments'] !== [])
                <h2>Shipments</h2>
                <ul class="product-list">
                    @foreach($order['shipments'] as $shipment)
                        <li>
                            <p>Shipped {{ $shipment['created_at'] }}@if($shipment['carrier'] !== '') · {{ $shipment['carrier'] }}@endif @if($shipment['tracking_number'] !== '') · tracking {{ $shipment['tracking_number'] }}@endif</p>
                            @foreach($shipment['lines'] as $line)
                                <p>{{ $line['title'] }} · {{ $line['sku'] }} · qty {{ $line['quantity'] }}</p>
                            @endforeach
                            @if($shipment['tracking_url'] !== null)
                                <p><a class="text-link" href="{{ $shipment['tracking_url'] }}" target="_blank" rel="noopener noreferrer">Track this shipment</a></p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <h2>History</h2>
            <ul class="product-list">
                @foreach($order['timeline'] as $entry)
                    <li><p>{{ $entry['type'] }} · {{ $entry['created_at'] }} · {{ $entry['amount']['formatted'] }}</p></li>
                @endforeach
            </ul>
            @include('mail.order-receipt', ['order' => $order])
@endsection
