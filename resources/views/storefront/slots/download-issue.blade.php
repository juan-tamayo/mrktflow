{{-- Platform component (slot: downloads.issue). Issuing a download grant
     is a download-authorization action owned by core; a theme cannot
     replace it. Included once per downloadable file by the downloads
     template with $order, $download and $guest in scope. --}}
<form method="post" action="{{ $guest ? '/orders/'.$order['id'].'/downloads/'.$download['entitlement_id'].'/grants' : '/account/downloads/'.$download['entitlement_id'].'/grants' }}" data-platform-slot="downloads.issue">
    @csrf
    <button type="submit" class="text-link">Download</button>
</form>
