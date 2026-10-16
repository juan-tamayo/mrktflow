<section>
    <h2>Order receipt</h2>
    <p>Order {{ $order['number'] }}. Total {{ $order['totals']['grand_total']['formatted'] }}.</p>
    @foreach($order['lines'] as $line)
        <p>{{ $line['title'] }} · {{ $line['sku'] }} · {{ $line['total']['formatted'] }}</p>
    @endforeach
</section>
