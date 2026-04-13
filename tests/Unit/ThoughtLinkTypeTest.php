<?php

use App\Enums\ThoughtLinkType;

test('all spec link types exist', function () {
    expect(ThoughtLinkType::RelatesTo->value)->toBe('relates_to')
        ->and(ThoughtLinkType::SpawnedFrom->value)->toBe('spawned_from')
        ->and(ThoughtLinkType::Supports->value)->toBe('supports')
        ->and(ThoughtLinkType::Contradicts->value)->toBe('contradicts')
        ->and(ThoughtLinkType::Supersedes->value)->toBe('supersedes');
});
