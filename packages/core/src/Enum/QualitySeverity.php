<?php

declare(strict_types=1);

namespace CatFramework\Core\Enum;

enum QualitySeverity: string
{
    /** Must fix before delivery (e.g., missing tag). */
    case ERROR = 'error';

    /** Should review (e.g., number format mismatch). */
    case WARNING = 'warning';

    /** Suggestion, non-blocking (e.g., double space). */
    case INFO = 'info';
}
