<?php

namespace App\View\Presenters\Email;

class NewsletterResearchStatusPresenter
{
    private const LABELS = [
        'research_queued' => 'Research queued',
        'research_completed' => 'Research ready',
        'research_partial' => 'Partial research',
        'research_skipped' => 'Research skipped',
        'research_failed' => 'Research failed',
    ];

    /**
     * @param  array{status: string, research_thought_id: string|null, skip_reason: string, show_research_link: bool, show_skip_info: bool}  $payload
     */
    private function __construct(
        private readonly string $status,
        private readonly ?string $researchThoughtId,
        private readonly string $skipReason,
        private readonly bool $showResearchLink,
        private readonly bool $showSkipInfo,
        private readonly string $domIdSuffix,
    ) {}

    /**
     * @param  array{status: string, research_thought_id: string|null, skip_reason: string, show_research_link: bool, show_skip_info: bool}|null  $payload
     */
    public static function fromArray(?array $payload, string $domIdSuffix): ?self
    {
        if ($payload === null) {
            return null;
        }

        $status = $payload['status'] ?? '';
        if (! is_string($status) || $status === '') {
            return null;
        }

        $researchId = $payload['research_thought_id'] ?? null;
        $researchId = is_string($researchId) || $researchId === null ? $researchId : (string) $researchId;

        $skipReason = $payload['skip_reason'] ?? '';
        $skipReason = is_string($skipReason) ? $skipReason : '';

        return new self(
            $status,
            $researchId,
            $skipReason,
            (bool) ($payload['show_research_link'] ?? false),
            (bool) ($payload['show_skip_info'] ?? false),
            $domIdSuffix,
        );
    }

    public function status(): string
    {
        return $this->status;
    }

    public function label(): string
    {
        return self::LABELS[$this->status]
            ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    public function researchThoughtId(): ?string
    {
        return $this->researchThoughtId;
    }

    public function skipReason(): string
    {
        return $this->skipReason;
    }

    public function showsResearchLink(): bool
    {
        return $this->showResearchLink;
    }

    public function showsSkipInfo(): bool
    {
        return $this->showSkipInfo;
    }

    public function skipReasonPopoverId(): string
    {
        return 'email-research-skip-reason-'.$this->domIdSuffix;
    }
}
