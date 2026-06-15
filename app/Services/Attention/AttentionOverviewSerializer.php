<?php

namespace App\Services\Attention;

use App\DataTransferObjects\AttentionItemData;
use App\DataTransferObjects\AttentionOverviewData;
use App\DataTransferObjects\AttentionSectionData;

final class AttentionOverviewSerializer
{
    public function toArray(AttentionOverviewData $overview): array
    {
        return [
            'total_count' => $overview->totalCount(),
            'sections' => array_map(
                fn (AttentionSectionData $section): array => $this->sectionToArray($section),
                $overview->sections,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionToArray(AttentionSectionData $section): array
    {
        return [
            'key' => $section->key,
            'title' => $section->title,
            'description' => $section->description,
            'items' => array_map(
                fn (AttentionItemData $item): array => $this->itemToArray($item),
                $section->items,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemToArray(AttentionItemData $item): array
    {
        return [
            'kind' => $item->kind,
            'severity' => $item->severity,
            'title' => $item->title,
            'subtitle' => $item->subtitle,
            'href' => $item->href,
            'meta' => $item->meta,
            'source_ref' => $item->sourceRef,
            'commitment_id' => $item->commitmentId,
        ];
    }
}
