<?php

namespace App\Support;

use App\Models\Thought;

/**
 * Canonical mapping for thought-type navigation: menu labels, routes, and resolving a thought to a type key.
 */
final class ThoughtTypeNavigation
{
    /**
     * Single source of truth for canonical keys, labels, route names, and accepted stored aliases.
     *
     * @var array<string, array{collection_label: string, thought_label: string, route_name: string, stored_values: list<string>, show_in_stream_nav?: bool}>
     */
    private const TYPE_DEFINITIONS = [
        'jira' => [
            'collection_label' => 'Jira',
            'thought_label' => 'Jira',
            'route_name' => 'idea.stream.jira',
            'stored_values' => ['jira'],
            'show_in_stream_nav' => false,
        ],
        'email' => [
            'collection_label' => 'Emails',
            'thought_label' => 'Email',
            'route_name' => 'idea.stream.emails',
            'stored_values' => ['email', 'emails'],
        ],
        'research' => [
            'collection_label' => 'Research',
            'thought_label' => 'Research',
            'route_name' => 'idea.stream.research',
            'stored_values' => ['research'],
        ],
        'plan' => [
            'collection_label' => 'Plans',
            'thought_label' => 'Plan',
            'route_name' => 'idea.stream.plans',
            'stored_values' => ['plan', 'plans'],
        ],
        'meeting' => [
            'collection_label' => 'Meetings',
            'thought_label' => 'Meeting',
            'route_name' => 'idea.stream.meetings',
            'stored_values' => ['meeting', 'meetings'],
        ],
        'article' => [
            'collection_label' => 'Articles',
            'thought_label' => 'Article',
            'route_name' => 'idea.stream.articles',
            'stored_values' => ['article', 'articles'],
        ],
        'video' => [
            'collection_label' => 'Videos',
            'thought_label' => 'Video',
            'route_name' => 'idea.stream.videos',
            'stored_values' => ['video'],
            'show_in_stream_nav' => true,
        ],
    ];

    /**
     * @return list<string>
     */
    public static function orderedNavTypes(): array
    {
        return array_keys(self::TYPE_DEFINITIONS);
    }

    /**
     * @return list<string>
     */
    public static function orderedStreamNavTypes(): array
    {
        return array_values(array_filter(
            self::orderedNavTypes(),
            fn (string $key): bool => self::showInStreamNav($key)
        ));
    }

    public static function showInStreamNav(string $canonicalType): bool
    {
        $key = self::normalizeTypeKey($canonicalType);
        if ($key === null || ! isset(self::TYPE_DEFINITIONS[$key])) {
            return false;
        }

        return self::TYPE_DEFINITIONS[$key]['show_in_stream_nav'] ?? true;
    }

    public static function collectionLabel(string $canonicalType): string
    {
        return self::definitionValue($canonicalType, 'collection_label');
    }

    public static function thoughtDisplayLabel(string $canonicalType): string
    {
        return self::definitionValue($canonicalType, 'thought_label');
    }

    public static function documentTitle(string $canonicalType): string
    {
        $label = self::collectionLabel($canonicalType);

        return $label !== '' ? $label.' — IdeaTub' : 'Stream — IdeaTub';
    }

    public static function routeName(string $canonicalType): ?string
    {
        $key = self::normalizeTypeKey($canonicalType);
        if ($key === null) {
            return null;
        }

        return self::TYPE_DEFINITIONS[$key]['route_name'] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function storedValuesForCollection(string $canonicalType): array
    {
        $key = self::normalizeTypeKey($canonicalType);
        if ($key === null) {
            return [];
        }

        return self::TYPE_DEFINITIONS[$key]['stored_values'];
    }

    public static function isAvailable(string $canonicalType): bool
    {
        $key = self::normalizeTypeKey($canonicalType);
        if ($key === null || ! isset(self::TYPE_DEFINITIONS[$key])) {
            return false;
        }
        if ($key === 'jira') {
            return (bool) config('services.jira.enabled', true);
        }

        return true;
    }

    /**
     * Normalize stored or URL fragments to a canonical type key, or null if unknown.
     */
    public static function normalizeTypeKey(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = mb_strtolower(trim($value));
        if ($v === '') {
            return null;
        }

        foreach (self::TYPE_DEFINITIONS as $canonicalKey => $definition) {
            if (in_array($v, $definition['stored_values'], true)) {
                return $canonicalKey;
            }
        }

        return null;
    }

    /**
     * Resolve a thought to a routable canonical type: jira/email from source; research/plan from metadata.type.
     */
    public static function resolveThoughtToTypeKey(Thought $thought): ?string
    {
        $sourceKey = self::normalizeTypeKey($thought->source);
        if ($sourceKey === 'jira') {
            return 'jira';
        }
        if ($sourceKey === 'email') {
            return 'email';
        }
        if ($sourceKey === 'article') {
            return 'article';
        }
        if ($sourceKey === 'video') {
            return 'video';
        }

        $metadata = $thought->metadata;
        $typeRaw = is_array($metadata) ? ($metadata['type'] ?? null) : null;
        $metaType = self::normalizeTypeKey(is_string($typeRaw) ? $typeRaw : null);
        if ($metaType === 'research') {
            return 'research';
        }
        if ($metaType === 'plan') {
            return 'plan';
        }
        if ($metaType === 'meeting') {
            return 'meeting';
        }
        if ($metaType === 'video') {
            return 'video';
        }

        return null;
    }

    private static function definitionValue(string $canonicalType, string $field): string
    {
        $key = self::normalizeTypeKey($canonicalType);

        return $key !== null ? (self::TYPE_DEFINITIONS[$key][$field] ?? '') : '';
    }
}
