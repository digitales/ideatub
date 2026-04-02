<?php

namespace App\Http\Controllers;

use App\Jobs\RunVideoResearch;
use App\Models\Thought;
use App\Models\User;
use App\Services\Video\VideoCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class VideoController extends Controller
{
    /**
     * Store a top-level video thought from a YouTube URL (web capture).
     */
    public function store(Request $request, VideoCaptureService $videoCapture): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'youtube_url' => 'required|string|max:2048',
            'transcript' => 'nullable|string|max:65535',
            'research_now' => 'sometimes|boolean',
            'return_thought_id' => 'sometimes|nullable|uuid',
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $transcript = $validated['transcript'] ?? null;
        $returnThoughtId = $validated['return_thought_id'] ?? null;

        try {
            $thought = $videoCapture->capture($user, $validated['youtube_url'], $transcript);
        } catch (InvalidArgumentException) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Not a recognized YouTube URL.',
                    'errors' => ['youtube_url' => ['Not a recognized YouTube URL.']],
                ], 422);
            }

            return redirect()
                ->route('idea.ideas')
                ->withInput()
                ->withErrors(['youtube_url' => 'Not a recognized YouTube URL.']);
        } catch (\Throwable $e) {
            report($e);

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Unable to save video. Please try again.',
                ], 503);
            }

            return redirect()
                ->route('idea.ideas')
                ->withInput()
                ->with('error', 'Unable to save video. Please try again.');
        }

        $explicitResearch = $request->boolean('research_now');
        $thought->refresh();
        $intentMerged = ! empty($thought->metadata[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING]);

        if ($explicitResearch) {
            $meta = is_array($thought->metadata) ? $thought->metadata : [];
            $meta[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING] = true;
            $meta['research_pending'] = true;
            $thought->update([
                'metadata' => Thought::normalizeMetadataTags($meta),
            ]);
            $thought->refresh();
        } elseif ($intentMerged) {
            $meta = is_array($thought->metadata) ? $thought->metadata : [];
            $meta['research_pending'] = true;
            $thought->update([
                'metadata' => Thought::normalizeMetadataTags($meta),
            ]);
            $thought->refresh();
        }

        $researchRequested = $explicitResearch || $intentMerged;

        $warning = null;
        $queued = $videoCapture->queueTranscriptFetchIfPending($thought, $researchRequested);

        $thought->refresh();
        if (! $queued && data_get($thought->metadata, 'transcript_status') === VideoCaptureService::TRANSCRIPT_STATUS_PENDING) {
            if ($researchRequested) {
                $videoCapture->clearStalledResearchRequestMarkers($thought);
                $thought->refresh();
            }
            $warning = 'Transcript fetch could not be queued; the video was saved. Retry transcript fetch later if needed.';
        }

        if ($researchRequested && $videoCapture->transcriptFetchShouldNoop($thought)) {
            RunVideoResearch::dispatch($thought->id);
        }

        $returnToDetail =
            $returnThoughtId !== null
            && (string) $returnThoughtId === (string) $thought->id;

        if ($request->wantsJson()) {
            $transcriptProvided =
                $transcript !== null && trim($transcript) !== '';
            $payload = [
                'message' => $transcriptProvided && $returnToDetail
                    ? 'Transcript saved.'
                    : 'Video saved.',
                'redirect' => $returnToDetail
                    ? route('thoughts.show', $thought)
                    : route('idea.index'),
            ];
            if ($warning !== null) {
                $payload['warning'] = $warning;
            }

            return response()->json($payload);
        }

        $successMessage =
            $transcript !== null &&
            trim($transcript) !== '' &&
            $returnToDetail
                ? 'Transcript saved.'
                : 'Video saved.';

        $redirect = $returnToDetail
            ? redirect()
                ->route('thoughts.show', $thought)
                ->with('success', $successMessage)
            : redirect()
                ->route('idea.ideas')
                ->with('success', $successMessage);

        if ($warning !== null) {
            $redirect->with('warning', $warning);
        }

        return $redirect;
    }
}
