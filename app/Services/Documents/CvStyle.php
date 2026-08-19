<?php

namespace App\Services\Documents;

class CvStyle
{
    public const FONT_FAMILY = 'Helvetica, Arial, sans-serif';

    public const SIZE_NAME = '22px';

    public const SIZE_HEADING = '14px';

    public const SIZE_BODY = '11px';

    public const LINE_HEIGHT = '1.4';

    public const HEADING_SPACING_TOP = '18px';

    /**
     * CSS applied to the markdown-derived HTML before PDF export (Task 8).
     * One definition, referenced everywhere — never hand-touch a generated document (spec §2b).
     */
    public static function css(): string
    {
        return sprintf(
            'body { font-family: %1$s; font-size: %2$s; line-height: %3$s; color: #111; }'
            .'h1 { font-size: %4$s; font-weight: bold; margin: 0 0 4px; }'
            .'h2, h3 { font-size: %5$s; font-weight: bold; margin: %6$s 0 6px; }'
            .'p, li { font-size: %2$s; }'
            .'ul { margin: 0 0 8px; padding-left: 18px; }',
            self::FONT_FAMILY,
            self::SIZE_BODY,
            self::LINE_HEIGHT,
            self::SIZE_NAME,
            self::SIZE_HEADING,
            self::HEADING_SPACING_TOP,
        );
    }
}
