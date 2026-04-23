<?php

namespace Tests\Unit\Services\Import;

use App\Services\Import\MicrositeFilename;
use PHPUnit\Framework\TestCase;

class MicrositeFilenameTest extends TestCase
{
    public function test_parses_sort_key_and_page_segment_from_basename(): void
    {
        $this->assertSame(0, MicrositeFilename::parseSortKeyFromBasename('00-summary'));
        $this->assertSame(2, MicrositeFilename::parseSortKeyFromBasename('2-foo'));
        $this->assertSame(10, MicrositeFilename::parseSortKeyFromBasename('10-bar'));
    }

    public function test_matcher_accepts_and_rejects_examples_from_spec(): void
    {
        $this->assertTrue(MicrositeFilename::isValidPageBasename('1-intro'));
        $this->assertTrue(MicrositeFilename::isValidPageBasename('00-summary'));
        $this->assertTrue(MicrositeFilename::isValidPageBasename('12_findings'));
        $this->assertTrue(MicrositeFilename::isValidPageBasename('2-foo'));
        $this->assertFalse(MicrositeFilename::isValidPageBasename('00'));
        $this->assertFalse(MicrositeFilename::isValidPageBasename('narrative'));
    }

    public function test_basename_collision_detection(): void
    {
        $a = MicrositeFilename::pagePathSegmentFromBasename('00-a');
        $b = MicrositeFilename::pagePathSegmentFromBasename('00-b');
        $this->assertFalse(MicrositeFilename::hasDuplicatePathSegments([$a, $b]));
        $this->assertTrue(MicrositeFilename::hasDuplicatePathSegments([$a, $a]));
    }

    public function test_parse_sort_key_returns_int_max_for_invalid_basename(): void
    {
        $this->assertSame(\PHP_INT_MAX, MicrositeFilename::parseSortKeyFromBasename('narrative'));
    }

    public function test_sorted_site_rows_from_relative_paths_orders_by_numeric_key_then_basename(): void
    {
        $rows = [
            ['relative_path' => 'site/2-b.md'],
            ['relative_path' => 'site/00-a.md'],
            ['relative_path' => 'site/1-z.md'],
        ];
        $sorted = MicrositeFilename::sortedSiteRowsFromRelativePaths($rows);
        $this->assertSame(
            ['00-a', '1-z', '2-b'],
            array_map(fn (array $r) => $r['basename'], $sorted)
        );
    }

    public function test_sorted_site_rows_from_relative_paths_skips_invalid_basename(): void
    {
        $rows = [
            ['relative_path' => 'a/00-ok.md'],
            ['relative_path' => 'a/notes.md'],
        ];
        $sorted = MicrositeFilename::sortedSiteRowsFromRelativePaths($rows);
        $this->assertCount(1, $sorted);
        $this->assertSame('00-ok', $sorted[0]['basename']);
    }
}
