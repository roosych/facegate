<?php

namespace App\Http\Controllers;

use App\Models\HikvisionTerminal;
use App\Services\HikvisionEventIngestService;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class HikvisionEventWebhookController extends Controller
{
    /**
     * Event object names Hikvision may wrap the actual data in, per the terminal's own
     * capabilities (/ISAPI/Event/notification/httpHosts/capabilities): AccessControllerEvent
     * (normal door/card/face passes) and AlcoholDetectionEvent (a separate custom event type
     * this terminal advertises specifically for alcohol readings — undocumented shape).
     *
     * @var array<int, string>
     */
    private const EVENT_KEYS = ['AccessControllerEvent', 'AlcoholDetectionEvent'];

    /**
     * Receives events pushed in real time by a Hikvision terminal configured as a "listening
     * host" (PUT /ISAPI/Event/notification/httpHosts) — the push counterpart to the polling
     * done by FetchHikvisionEventsJob. No session/CSRF here (registered in routes/api.php);
     * the token path segment is the only guard, since the terminal can't send Laravel auth.
     */
    public function store(
        Request $request,
        HikvisionTerminal $terminal,
        string $token,
        RusGuardDatabaseService $rusGuardDb,
        HikvisionEventIngestService $ingestService
    ): Response {
        if (! hash_equals((string) config('hikvision.webhook_token'), $token)) {
            abort(403);
        }

        // Always log the raw payload, regardless of whether parsing below succeeds — the
        // exact shape of AlcoholDetectionEvent isn't documented anywhere, so this is how we
        // find out what the device actually sends.
        Log::info('Hikvision webhook received (raw)', [
            'terminal_id' => $terminal->id,
            'content_type' => $request->header('Content-Type'),
            'body' => $request->getContent(),
            'form_fields' => $request->except([]),
        ]);

        $eventData = $this->extractEventData($request);

        Log::info('Hikvision webhook parsed', [
            'terminal_id' => $terminal->id,
            'parsed' => $eventData,
        ]);

        if ($eventData !== null) {
            $alcoholRequired = isset($eventData['alcoholDetectionInfo'])
                ? $rusGuardDb->getEmployeesRequiringAlcoholTest()
                : [];

            $ingestService->ingest($terminal, $eventData, $alcoholRequired);
        }

        return response('OK', 200);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractEventData(Request $request): ?array
    {
        // Multipart form (device attaches a picture alongside the event field). The decoded
        // field is an outer wrapper (ipAddress, dateTime, eventType, ...) that, for anything
        // beyond a bare heartbeat, nests the actual per-event data (employeeNoString, cardNo,
        // alcoholDetectionInfo, ...) one level deeper under its own EVENT_KEYS key — same shape
        // the raw-JSON-body fallback below already unwraps.
        foreach (self::EVENT_KEYS as $key) {
            if ($request->has($key)) {
                $raw = $request->input($key);
                $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

                if (is_array($decoded)) {
                    foreach (self::EVENT_KEYS as $nestedKey) {
                        if (isset($decoded[$nestedKey]) && is_array($decoded[$nestedKey])) {
                            return $decoded[$nestedKey];
                        }
                    }

                    return $decoded;
                }
            }
        }

        $rawBody = $request->getContent();
        $contentType = (string) $request->header('Content-Type');

        if (str_contains($contentType, 'xml') || str_starts_with(ltrim($rawBody), '<')) {
            return $this->extractFromXml($rawBody);
        }

        $body = json_decode($rawBody, true);

        if (! is_array($body)) {
            return null;
        }

        foreach (self::EVENT_KEYS as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                return $body[$key];
            }
        }

        return $body !== [] ? $body : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractFromXml(string $xml): ?array
    {
        try {
            $sxe = simplexml_load_string($xml);
        } catch (Throwable) {
            return null;
        }

        if ($sxe === false) {
            return null;
        }

        $decoded = json_decode((string) json_encode($sxe), true);

        if (! is_array($decoded)) {
            return null;
        }

        foreach (self::EVENT_KEYS as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $decoded[$key];
            }
        }

        return $decoded !== [] ? $decoded : null;
    }
}
