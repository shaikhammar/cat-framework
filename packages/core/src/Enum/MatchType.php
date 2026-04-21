<?php

declare(strict_types=1);

namespace CatFramework\Core\Enum;

enum MatchType: string
{
    /** 100% match including inline code positions. */
    case EXACT = 'exact';

    /** 100% plain text match, but inline codes differ in position or type. */
    case EXACT_TEXT = 'exact_text';

    /** Partial text match (score < 1.0). */
    case FUZZY = 'fuzzy';

    /** Result from machine translation, not from TM. */
    case MT = 'mt';
}
