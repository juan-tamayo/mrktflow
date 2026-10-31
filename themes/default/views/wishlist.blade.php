@extends('default-theme::layout', [
    'pageKey' => 'wishlist',
    'pageTitle' => 'Saved items · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css'],
    'mainId' => 'wishlist-main',
    'mainClass' => 'theme-checkout',
    'skipLabel' => 'Skip to saved items',
    'footerLabel' => 'Saved items',
])
@section('content')
            <h1>Saved items<span class="accent">.</span></h1>
            @if(session('status'))
                <p class="theme-purchase-note">{{ session('status') }}</p>
            @endif
            @if(count($wishlist['items']) === 0)
                <p class="theme-purchase-note">You have not saved any items yet. Saving an item keeps it here so you can find it again later.</p>
            @else
                <ul class="product-list">
                    @foreach($wishlist['items'] as $item)
                        <li>
                            @if($item['state'] === 'listed')
                                <p><a href="{{ $item['url'] }}">{{ $item['title'] }}</a> · {{ $item['sku'] }} · {{ $item['money']['formatted'] }}</p>
                                <p class="theme-purchase-note">{{ ['in_stock' => 'In stock', 'out_of_stock' => 'Out of stock', 'unavailable' => 'Unavailable'][$item['availability']] ?? 'Unavailable' }} · Saved {{ \Illuminate\Support\Carbon::parse($item['saved_at'])->toFormattedDateString() }}</p>
                            @else
                                <p>This item is no longer available.</p>
                                <p class="theme-purchase-note">Saved {{ \Illuminate\Support\Carbon::parse($item['saved_at'])->toFormattedDateString() }}</p>
                            @endif
                            <form method="post" action="/wishlist/items/{{ $item['id'] }}/delete">
                                @csrf
                                <button type="submit" class="text-link">Remove</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="theme-purchase-note">Prices and availability are shown as they are right now, not as they were when you saved the item. Saving an item never subscribes you to email alerts.</p>
@endsection
