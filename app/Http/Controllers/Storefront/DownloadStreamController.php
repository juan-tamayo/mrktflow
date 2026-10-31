<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Fulfillment\Actions\AuthorizeDownload;
use App\Domain\Fulfillment\Data\DownloadPrincipal;
use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streaming download delivery (FUL-05) for the account and guest areas.
 *
 * Every state change is a POST: the download area's form issues a grant,
 * the grant page's form starts the transfer. GETs only render the
 * owner-only grant status or stream bytes for an already-started transfer,
 * so scanners and prefetchers can neither spend an allowance nor consume a
 * start token. The stream answers bounded single-Range requests from the
 * private disk; it never redirects to a storage key.
 */
final class DownloadStreamController
{
    private const GRANT_KEY = 'guest_download_grants';

    public function issueAccount(Request $request, string $entitlementId, AuthorizeDownload $authorize): RedirectResponse
    {
        $principal = $this->accountPrincipal($request);
        $issued = $authorize->issue($principal, $entitlementId);

        return redirect('/account/download-grants/'.$issued['grant_id'])
            ->with('start_token', $issued['token']);
    }

    public function statusAccount(Request $request, string $grantId, AuthorizeDownload $authorize): Response
    {
        return $this->statusPage($request, $authorize, $this->accountPrincipal($request), $grantId, null);
    }

    public function startAccount(Request $request, string $grantId, AuthorizeDownload $authorize): RedirectResponse
    {
        $principal = $this->accountPrincipal($request);
        $authorize->start($principal, $grantId, (string) $request->input('token', ''));

        return redirect('/account/download-grants/'.$grantId);
    }

    public function streamAccount(Request $request, string $transferId, AuthorizeDownload $authorize): Response|StreamedResponse
    {
        return $this->stream($request, $authorize, $this->accountPrincipal($request), $transferId);
    }

    public function issueGuest(Request $request, string $orderId, string $entitlementId, AuthorizeDownload $authorize): RedirectResponse
    {
        $principal = $this->guestPrincipal($request, $orderId);
        $issued = $authorize->issue($principal, $entitlementId);

        return redirect('/orders/'.$orderId.'/download-grants/'.$issued['grant_id'])
            ->with('start_token', $issued['token']);
    }

    public function statusGuest(Request $request, string $orderId, string $grantId, AuthorizeDownload $authorize): Response
    {
        return $this->statusPage($request, $authorize, $this->guestPrincipal($request, $orderId), $grantId, $orderId);
    }

    public function startGuest(Request $request, string $orderId, string $grantId, AuthorizeDownload $authorize): RedirectResponse
    {
        $principal = $this->guestPrincipal($request, $orderId);
        $authorize->start($principal, $grantId, (string) $request->input('token', ''));

        return redirect('/orders/'.$orderId.'/download-grants/'.$grantId);
    }

    public function streamGuest(Request $request, string $orderId, string $transferId, AuthorizeDownload $authorize): Response|StreamedResponse
    {
        return $this->stream($request, $authorize, $this->guestPrincipal($request, $orderId), $transferId);
    }

    private function statusPage(Request $request, AuthorizeDownload $authorize, DownloadPrincipal $principal, string $grantId, ?string $orderId): Response
    {
        $status = $authorize->status($principal, $grantId);
        $base = $orderId === null ? '/account' : '/orders/'.$orderId;
        $token = $request->session()->get('start_token');
        $transfer = $status['transfer'];
        $transfer = is_array($transfer) ? $transfer : null;

        return response()->view('default-theme::download-grant', [
            'heading' => 'Download for order '.$status['order_number'],
            'status' => $status,
            'start_url' => $base.'/download-grants/'.$grantId.'/start',
            'stream_url' => $transfer !== null ? $base.'/download-transfers/'.$transfer['id'] : null,
            'back_url' => $orderId === null ? '/account/downloads' : '/orders/'.$orderId.'/downloads',
            'start_token' => $status['state'] === 'issued' && is_string($token) ? $token : null,
            'guest' => $orderId !== null,
        ])->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function stream(Request $request, AuthorizeDownload $authorize, DownloadPrincipal $principal, string $transferId): Response|StreamedResponse
    {
        $target = $authorize->beginStream($principal, $transferId);
        $size = $target['bytes'];
        [$start, $end, $partial, $unsatisfiable] = $this->range($request->header('Range'), $size);
        if ($unsatisfiable) {
            $authorize->endStream($transferId, $principal->sessionId);

            return response('', 416, [
                'Content-Range' => 'bytes */'.$size,
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'private, no-store',
            ]);
        }
        $length = $end - $start + 1;
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($target['filename'])) ?? 'download';
        $headers = [
            'Content-Type' => $target['content_type'],
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($partial) {
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
        }
        $key = $target['media_key'];
        $sessionId = $principal->sessionId;
        $response = new StreamedResponse(function () use ($authorize, $transferId, $sessionId, $key, $start, $length): void {
            try {
                $stream = Storage::disk('local')->readStream($key);
                if (is_resource($stream)) {
                    if ($start > 0) {
                        fseek($stream, $start);
                    }
                    $remaining = $length;
                    while ($remaining > 0 && ! feof($stream)) {
                        $chunk = fread($stream, (int) min(65536, $remaining));
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        echo $chunk;
                        $remaining -= strlen($chunk);
                        flush();
                    }
                    fclose($stream);
                }
            } finally {
                $authorize->endStream($transferId, $sessionId);
            }
        }, $partial ? 206 : 200, $headers);

        return $response;
    }

    /**
     * Parses one bounded Range header. A malformed or multi-range header
     * is ignored (full body); an in-format range that cannot be satisfied
     * is a 416.
     *
     * @return array{0: int, 1: int, 2: bool, 3: bool} [start, end, partial, unsatisfiable]
     */
    private function range(?string $header, int $size): array
    {
        if ($header === null || preg_match('/^bytes=(\d*)-(\d*)$/D', trim($header), $match) !== 1
            || ($match[1] === '' && $match[2] === '')) {
            return [0, $size - 1, false, false];
        }
        if ($match[1] === '') {
            $suffix = (int) $match[2];
            if ($suffix <= 0) {
                return [0, $size - 1, false, false];
            }

            return [max(0, $size - $suffix), $size - 1, true, false];
        }
        $start = (int) $match[1];
        if ($start >= $size) {
            return [0, $size - 1, false, true];
        }
        $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);
        if ($end < $start) {
            return [0, $size - 1, false, true];
        }

        return [$start, $end, true, false];
    }

    private function accountPrincipal(Request $request): DownloadPrincipal
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof CustomerAccount, 403);

        return DownloadPrincipal::account($customer, $request->session()->getId());
    }

    private function guestPrincipal(Request $request, string $orderId): DownloadPrincipal
    {
        $grants = $request->session()->get(self::GRANT_KEY, []);
        $expires = is_array($grants) && is_int($grants[$orderId] ?? null) ? $grants[$orderId] : null;
        abort_if($expires === null || $expires <= now()->getTimestamp(), 404);

        return DownloadPrincipal::guestOrder($orderId, $request->session()->getId());
    }
}
