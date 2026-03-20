<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * SQL expression for comparing/ordering ideas by effective "age": valid Y-m-d
 * {@see metadata.logged_date}, else {@see created_at} date. Matches inbox
 * neglected selection so malformed values fall back consistently.
 */
final class IdeaEffectiveDateSql
{
    public static function expression(): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return <<<'SQL'
CASE
    WHEN metadata->>'logged_date' IS NOT NULL
        AND metadata->>'logged_date' ~ '^\d{4}-\d{2}-\d{2}$'
        AND to_char(to_date(metadata->>'logged_date', 'YYYY-MM-DD'), 'YYYY-MM-DD') = metadata->>'logged_date'
        THEN to_date(metadata->>'logged_date', 'YYYY-MM-DD')
    ELSE created_at::date
END
SQL;
        }

        return "COALESCE(date(json_extract(metadata, '$.logged_date')), date(created_at))";
    }
}
