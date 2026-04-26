<?php

declare(strict_types=1);

namespace CatFramework\Core\Enum;

enum SegmentStatus: string
{
    /** No translation entered yet. */
    case Untranslated = 'untranslated';

    /** Partial translation or MT/fuzzy-TM fill — needs human review. */
    case Draft = 'draft';

    /** Translator has confirmed the translation. */
    case Translated = 'translated';

    /** Second translator or editor has reviewed the translation. */
    case Reviewed = 'reviewed';

    /** Final, locked. No further edits expected. */
    case Approved = 'approved';

    /** Reviewed and sent back for correction. */
    case Rejected = 'rejected';
}
