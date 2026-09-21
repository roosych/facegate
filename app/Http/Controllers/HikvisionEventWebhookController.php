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

        // Stamp before parsing: a heartbeat carries no event data but still proves the
        // terminal's push config is alive, which is exactly what the monitoring page needs
        // in order to tell "quiet door" apart from "push silently stopped".
        $terminal->forceFill(['last_push_at' => now()])->save();

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
                    // A JSON-encoded string field is the whole envelope. A key already parsed
                    // into an array (JSON request body) is the event itself, and the envelope
                    // that carries its dateTime is the request as a whole.
                    return $this->unwrapEvent(is_string($raw) ? $decoded : $request->all());
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

        $event = $this->unwrapEvent($body);

        return $event !== [] ? $event : null;
    }

    /**
     * Returns the per-event object nested in a push envelope, or the envelope itself when
     * nothing is nested (a bare heartbeat).
     *
     * The envelope's own dateTime is the terminal's clock at the moment of the event, and the
     * nested object carries no timestamp of its own. It is copied onto the event so the ingest
     * step records the terminal's time — dropping it here left the server's receipt time as the
     * only timestamp, which drifts from the device whenever the two clocks disagree.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function unwrapEvent(array $envelope): array
    {
        foreach (self::EVENT_KEYS as $key) {
            if (isset($envelope[$key]) && is_array($envelope[$key])) {
                $event = $envelope[$key];

                if (! isset($event['time']) && ! isset($event['dateTime']) && isset($envelope['dateTime'])) {
                    $event['dateTime'] = $envelope['dateTime'];
                }

                return $event;
            }
        }

        return $envelope;
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

        $event = $this->unwrapEvent($decoded);

        return $event !== [] ? $event : null;
    }
}
