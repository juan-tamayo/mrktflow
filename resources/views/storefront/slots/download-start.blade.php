{{-- Platform component (slot: download.start). Starting a download spends
     allowance through a platform endpoint with the grant's start token;
     a theme cannot replace it. Rendered by the download grant template
     when the grant is issued and a start token is in scope. --}}
@if($start_token !== null)
    <div data-platform-slot="download.start">
        <p class="theme-purchase-note">Starting spends one of your downloads and opens a one-hour window in which you can download and resume this file. The start link itself expires in five minutes.</p>
        <form method="post" action="{{ $start_url }}">
            @csrf
            <input type="hidden" name="token" value="{{ $start_token }}">
            <button type="submit" class="text-link">Start download</button>
        </form>
    </div>
@else
    <p class="theme-purchase-note">This grant's start token is no longer available in this browser session. Request a fresh download from your downloads area — requesting one spends nothing.</p>
@endif
