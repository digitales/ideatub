<?php

namespace App\Support;

use App\Models\Thought;

/**
 * Canonical mapping for thought-type navigation: menu labels, routes, and resolving a thought to a type key.
 */
final class ThoughtTypeNavigation
{
    /** @var list<string> */
    private const ORDERED_TYPES = ['jira', 'email', 'research', 'plan'];

    /** @var array<string, string> Collection / menu labels (plural where spec requires). */
    private const COLLECTION_LABELS = [
        'jira' => 'Jira',
        'email' => 'Emails',
        'research' => 'Research',
        'plan' => 'Plans',
    ];

    /** @var array<string, string> Per-thought badge labels (singular for email/plan). */
    private const THOUGHT_LABELS = [
        'jira' => 'Jira',
        'email' => 'Email',
        'research' => 'Research',
        'plan' => 'Plan',
    ];

    /** @var array<string, string> */
    private const ROUTE_NAMES = [
        'jira' => 'idea.stream.jira',
        'email' => 'idea.stream.emails',
        'research' => 'idea.stream.research',
        'plan' => 'idea.stream.plans',
    ];

    /**
     * @return list<string>
     */
    public static function orderedNavTypes(): array
    {
        return self::ORDERED_TYPES;
    }

    public static function collectionLabel(string $canonicalType): string
    {
        $key = self::normalizeTypeKey($canonicalType);

        return $key !== null ? (self::COLLECTION_LABELS[$key] ?? '') : '';
    }

    public static function thoughtDisplayLabel(string $canonicalType): string
    {
        $key = self::normalizeTypeKey($canonicalType);

        return $key !== null ? (self::THOUGHT_LABELS[$key] ?? '') : '';
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

        return self::ROUTE_NAMES[$key] ?? null;
    }

    public static function isAvailable(string $canonicalType): bool
    {
        $key = self::normalizeTypeKey($canonicalType);
        if ($key === null || ! isset(self::ROUTE_NAMES[$key])) {
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

        return match ($v) {
            'jira' => 'jira',
            'email', 'emails' => 'email',
            'research' => 'research',
            'plan', 'plans' => 'plan',
            default => null,
        };
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

        $metaType = self::normalizeTypeKey(is_string($thought->metadata['type'] ?? null) ? $thought->metadata['type'] : null);
        if ($metaType === 'research') {
            return 'research';
        }
        if ($metaType === 'plan') {
            return 'plan';
        }

        return null;
    }
}
