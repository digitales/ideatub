<?php

namespace App\View\Research;

use App\Models\Thought;
use App\View\Presenters\Comments\ResearchCommentsPresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ResearchContentCommentsViewData
{
    /**
     * @param  list<ResearchContentSectionItem>  $sectionItems
     */
    private function __construct(
        public readonly bool $hasComments,
        public readonly string $commentsMode,
        public readonly ?string $commentsFormAction,
        public readonly bool $commentsShowControls,
        public readonly array $sectionItems,
    ) {}

    public static function none(): self
    {
        return new self(
            hasComments: false,
            commentsMode: 'owner',
            commentsFormAction: null,
            commentsShowControls: true,
            sectionItems: [],
        );
    }

    /**
     * @param  Collection<int, object>  $sections  Objects with optional id, thought, content_html
     */
    public static function forOwner(
        ResearchCommentsPresenter $presenter,
        Collection $sections,
        ?string $formActionOverride = null,
    ): self {
        $formAction = $formActionOverride ?? route('comments.store');

        $items = [];
        foreach ($sections as $section) {
            $id = isset($section->id) ? (string) $section->id : null;
            $contentHtml = $section->content_html ?? '';
            $thought = isset($section->thought) && $section->thought instanceof Thought
                ? $section->thought
                : null;

            if ($thought === null) {
                $items[] = new ResearchContentSectionItem(
                    id: $id,
                    contentHtml: is_string($contentHtml) ? $contentHtml : '',
                    thought: null,
                    mobileSummary: null,
                    mobileThreadInclude: null,
                    sidebarThreadInclude: null,
                );

                continue;
            }

            $allowed = $presenter->canCommentOnSection($thought);
            $disabledMessage = $allowed ? null : 'Comments are disabled.';

            $mobileThread = $presenter->threadIncludeForSection(
                $thought,
                $formAction,
                'owner',
                true,
                'Section comments',
                $disabledMessage,
            );
            $sidebarThread = $presenter->threadIncludeForSection(
                $thought,
                $formAction,
                'owner',
                true,
                'Comments',
                $disabledMessage,
            );

            $count = count($mobileThread['rows']);
            $items[] = new ResearchContentSectionItem(
                id: $id,
                contentHtml: is_string($contentHtml) ? $contentHtml : '',
                thought: $thought,
                mobileSummary: [
                    'count' => $count,
                    'label' => Str::plural('comment', $count),
                ],
                mobileThreadInclude: $mobileThread,
                sidebarThreadInclude: $sidebarThread,
            );
        }

        return new self(
            hasComments: true,
            commentsMode: 'owner',
            commentsFormAction: $formAction,
            commentsShowControls: true,
            sectionItems: $items,
        );
    }
}
