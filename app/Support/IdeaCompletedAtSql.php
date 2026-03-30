<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Cross-database SQL for ordering completed ideas by metadata.completed_at.
 */
final class IdeaCompletedAtSql
{
    /**
     * Expression that parses JSON metadata.completed_at to a comparable datetime, or null.
     * Assumes a `metadata` column on the query's table.
     */
    public static function parsedCompletedAtExpression(?string $driver = null): string
    {
        if ($driver === 'pgsql') {
            $raw = "metadata->>'completed_at'";
            $year = "substring({$raw} from 1 for 4)::int";
            $month = "substring({$raw} from 6 for 2)::int";
            $day = "substring({$raw} from 9 for 2)::int";
            $validDate = "({$month} BETWEEN 1 AND 12 AND {$day} >= 1 AND {$day} <= CASE "
                ."WHEN {$month} IN (1, 3, 5, 7, 8, 10, 12) THEN 31 "
                ."WHEN {$month} IN (4, 6, 9, 11) THEN 30 "
                ."WHEN {$month} = 2 THEN CASE "
                ."WHEN (({$year} % 4 = 0 AND {$year} % 100 != 0) OR {$year} % 400 = 0) THEN 29 "
                .'ELSE 28 END '
                .'ELSE 0 END)';
            $normalizedRaw = "(CASE WHEN right({$raw}, 1) = 'Z' THEN left({$raw}, char_length({$raw}) - 1) || '+00:00' ELSE {$raw} END)";
            $validTime = '(substring('.$raw.' from 12 for 2)::int BETWEEN 0 AND 23 '
                .'AND substring('.$raw.' from 15 for 2)::int BETWEEN 0 AND 59 '
                .'AND substring('.$raw.' from 18 for 2)::int BETWEEN 0 AND 59)';
            $validTimezone = "(right({$raw}, 1) = 'Z' OR (substring({$raw} from char_length({$raw}) - 5 for 1) IN ('+', '-') "
                ."AND substring({$raw} from char_length({$raw}) - 4 for 2)::int BETWEEN 0 AND 23 "
                ."AND substring({$raw} from char_length({$raw}) - 1 for 2)::int BETWEEN 0 AND 59))";

            return '(CASE '
                ."WHEN {$raw} IS NULL OR btrim({$raw}) = '' THEN NULL "
                ."WHEN {$raw} ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN CASE "
                ."WHEN {$validDate} THEN ({$raw} || 'T00:00:00+00:00')::timestamptz "
                .'ELSE NULL END '
                ."WHEN {$raw} ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}:[0-9]{2}(\\.[0-9]+)?(Z|[+-][0-9]{2}:[0-9]{2})$' THEN CASE "
                ."WHEN {$validDate} AND {$validTime} AND {$validTimezone} THEN ({$normalizedRaw})::timestamptz "
                .'ELSE NULL END '
                .'ELSE NULL END)';
        }

        if ($driver === 'sqlite') {
            $raw = "json_extract(metadata, '$.completed_at')";
            $year = "CAST(substr({$raw}, 1, 4) AS INTEGER)";
            $month = "CAST(substr({$raw}, 6, 2) AS INTEGER)";
            $day = "CAST(substr({$raw}, 9, 2) AS INTEGER)";
            $validDate = "({$month} BETWEEN 1 AND 12 AND {$day} >= 1 AND {$day} <= CASE "
                ."WHEN {$month} IN (1, 3, 5, 7, 8, 10, 12) THEN 31 "
                ."WHEN {$month} IN (4, 6, 9, 11) THEN 30 "
                ."WHEN {$month} = 2 THEN CASE "
                ."WHEN (({$year} % 4 = 0 AND {$year} % 100 != 0) OR {$year} % 400 = 0) THEN 29 "
                .'ELSE 28 END '
                .'ELSE 0 END)';
            $normalizedRaw = "(CASE WHEN substr({$raw}, -1, 1) = 'Z' "
                ."THEN substr({$raw}, 1, length({$raw}) - 1) || '+00:00' "
                ."ELSE {$raw} END)";
            $validTime = "(CAST(substr({$raw}, 12, 2) AS INTEGER) BETWEEN 0 AND 23 "
                ."AND CAST(substr({$raw}, 15, 2) AS INTEGER) BETWEEN 0 AND 59 "
                ."AND CAST(substr({$raw}, 18, 2) AS INTEGER) BETWEEN 0 AND 59)";
            $validTimezone = "(substr({$raw}, -1, 1) = 'Z' "
                ."OR (substr({$raw}, -6, 1) IN ('+', '-') "
                ."AND CAST(substr({$raw}, -5, 2) AS INTEGER) BETWEEN 0 AND 23 "
                ."AND CAST(substr({$raw}, -2, 2) AS INTEGER) BETWEEN 0 AND 59))";
            $fractionZulu = "substr({$raw}, 21, length({$raw}) - 21)";
            $fractionOffset = "substr({$raw}, 21, length({$raw}) - 26)";
            $validFractionZulu = '('.$fractionZulu." != '' AND ".self::sqliteDigitsOnlyExpression($fractionZulu).')';
            $validFractionOffset = '('.$fractionOffset." != '' AND ".self::sqliteDigitsOnlyExpression($fractionOffset).')';

            return '(CASE '
                ."WHEN {$raw} IS NULL OR {$raw} = '' THEN NULL "
                ."WHEN {$raw} GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]' THEN CASE "
                ."WHEN {$validDate} THEN datetime({$raw} || 'T00:00:00+00:00') "
                .'ELSE NULL END '
                ."WHEN ({$raw} GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9][T ][0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z' "
                ."OR {$raw} GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9][T ][0-9][0-9]:[0-9][0-9]:[0-9][0-9].[0-9]*Z') THEN CASE "
                ."WHEN {$validDate} AND {$validTime} AND {$validTimezone} "
                ."AND ({$raw} NOT GLOB '*.*Z' OR {$validFractionZulu}) "
                ."THEN datetime({$normalizedRaw}) ELSE NULL END "
                ."WHEN ({$raw} GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9][T ][0-9][0-9]:[0-9][0-9]:[0-9][0-9][+-][0-9][0-9]:[0-9][0-9]' "
                ."OR {$raw} GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9][T ][0-9][0-9]:[0-9][0-9]:[0-9][0-9].[0-9]*[+-][0-9][0-9]:[0-9][0-9]') THEN CASE "
                ."WHEN {$validDate} AND {$validTime} AND {$validTimezone} "
                ."AND ({$raw} NOT GLOB '*.*[+-][0-9][0-9]:[0-9][0-9]' OR {$validFractionOffset}) "
                ."THEN datetime({$normalizedRaw}) ELSE NULL END "
                .'ELSE NULL END)';
        }

        throw new InvalidArgumentException(sprintf(
            'Unsupported database driver [%s] for completed idea ordering.',
            $driver ?? 'null'
        ));
    }

    /**
     * Order completed ideas: rows with a parsed completed_at first (newest first),
     * then legacy completed rows without completed_at, then `updated_at` DESC, `id` DESC.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function applyCompletedIdeaOrdering(Builder $query): Builder
    {
        $parsed = self::parsedCompletedAtExpression($query->getConnection()->getDriverName());
        $wrapped = '('.$parsed.')';

        return $query
            ->orderByRaw('(CASE WHEN '.$wrapped.' IS NOT NULL THEN 1 ELSE 0 END) DESC')
            ->orderByRaw($wrapped.' DESC')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    private static function sqliteDigitsOnlyExpression(string $expression): string
    {
        $digitsRemoved = $expression;

        foreach (range(0, 9) as $digit) {
            $digitsRemoved = "replace({$digitsRemoved}, '{$digit}', '')";
        }

        return "{$digitsRemoved} = ''";
    }
}
