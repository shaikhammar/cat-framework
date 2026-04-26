<?php

declare(strict_types=1);

namespace CatFramework\Project\Exception;

use CatFramework\Core\Enum\SegmentStatus;

class InvalidStatusTransitionException extends \RuntimeException
{
    public function __construct(SegmentStatus $from, SegmentStatus $to)
    {
        parent::__construct(
            "Cannot transition segment from '{$from->value}' to '{$to->value}': '{$from->value}' is a terminal status."
        );
    }
}
