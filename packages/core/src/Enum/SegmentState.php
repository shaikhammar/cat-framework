<?php

declare(strict_types=1);

namespace CatFramework\Core\Enum;

enum SegmentState: string
{
    /** No translation yet. */
    case INITIAL = 'initial';

    /** Translator has entered a translation. */
    case TRANSLATED = 'translated';

    /** Reviewer has approved the translation. */
    case REVIEWED = 'reviewed';

    /** Final, locked state. */
    case FINAL = 'final';
}
