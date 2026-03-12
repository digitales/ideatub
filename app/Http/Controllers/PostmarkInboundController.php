<?php

namespace App\Http\Controllers;

use App\Services\PostmarkInboundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PostmarkInboundController extends Controller
{
    private const BODY_LOG_MAX_LENGTH = 2000;

    public function handle(Request $request)
    {
        $payload = $request->all();
        if (! is_array($payload) || $payload === []) {
            return response()->json(['error' => 'Invalid payload'], 422);
        }

        if (config('services.postmark_inbound.log_emails', false)) {
            Log::info('Postmark inbound email received', [
                'payload' => $this->sanitizePayloadForLog($payload),
            ]);
        }

        try {
            app(PostmarkInboundService::class)->process($payload);

            return response('', 200);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Processing failed'], 503);
        }
    }

    /**
     * Sanitize payload for logging: strip attachment content, truncate body.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayloadForLog(array $payload): array
    {
        $sanitized = $payload;
        if (isset($sanitized['TextBody']) && is_string($sanitized['TextBody'])) {
            $sanitized['TextBody'] = mb_strlen($sanitized['TextBody']) > self::BODY_LOG_MAX_LENGTH
                ? mb_substr($sanitized['TextBody'], 0, self::BODY_LOG_MAX_LENGTH).'...[truncated]'
                : $sanitized['TextBody'];
        }
        if (isset($sanitized['HtmlBody']) && is_string($sanitized['HtmlBody'])) {
            $sanitized['HtmlBody'] = mb_strlen($sanitized['HtmlBody']) > self::BODY_LOG_MAX_LENGTH
                ? mb_substr($sanitized['HtmlBody'], 0, self::BODY_LOG_MAX_LENGTH).'...[truncated]'
                : $sanitized['HtmlBody'];
        }
        if (isset($sanitized['Attachments']) && is_array($sanitized['Attachments'])) {
            $sanitized['Attachments'] = array_map(function (mixed $att): array {
                $a = is_array($att) ? $att : [];
                unset($a['Content']);

                return $a;
            }, $sanitized['Attachments']);
        }

        return $sanitized;
    }
}
