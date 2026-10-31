@extends('default-theme::layout', [
    'pageKey' => 'compare',
    'pageTitle' => 'Compare items · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css', 'themes/default/src/compareTray.ts', 'themes/default/src/comparisonTable.ts'],
    'mainId' => 'compare-main',
    'mainClass' => 'theme-checkout',
    'skipLabel' => 'Skip to comparison',
    'footerLabel' => 'Compare items',
])
@section('content')
            <h1>Compare items<span class="accent">.</span></h1>
            @if($error !== null)
                <p class="theme-purchase-note">{{ $error }}</p>
            @elseif($comparison !== null)
              <div id="comparison-table" data-comparison="{{ json_encode($comparison, JSON_THROW_ON_ERROR) }}" data-candidates="{{ json_encode($candidates ?? [], JSON_THROW_ON_ERROR) }}" data-endpoint="/store-api/v1/compare" data-csrf="{{ csrf_token() }}" data-cart-revision="{{ $cartRevision ?? 1 }}">
                @foreach($comparison['removed'] as $removed)
                    <p class="theme-purchase-note">{{ $removed['message'] }}</p>
                @endforeach
                @if($comparison['specialized_attributes_omitted'])
                    <p class="theme-purchase-note">{{ $comparison['omitted_reason'] }}</p>
                @endif
                <table class="compare-table">
                    <thead>
                        <tr>
                            <th scope="col">Detail</th>
                            @foreach($comparison['selections'] as $selection)
                                <th scope="col">
                                    <a href="{{ $selection['url'] }}">{{ $selection['title'] }}</a>
                                    @if($selection['variant_label'] !== 'Standard')
                                        · {{ $selection['variant_label'] }}
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($comparison['rows'] as $row)
                            <tr @if($row['differs']) class="compare-differs" @endif>
                                <th scope="row">{{ $row['label'] }}</th>
                                @foreach($row['cells'] as $cellIndex => $cell)
                                    <td data-column="{{ $comparison['selections'][$cellIndex]['title'] }}">{{ $cell['display'] }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="theme-purchase-note">Prices and availability are shown as they are right now. “Not specified” means the product does not provide that detail.</p>
              </div>
            @endif
@endsection
