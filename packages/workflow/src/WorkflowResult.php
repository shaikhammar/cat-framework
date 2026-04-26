<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\QualityIssue;

final class WorkflowResult
{
    public function __construct(
        public readonly BilingualDocument $document,
        /** @var QualityIssue[] */
        public readonly array $qaIssues,
        public readonly TmMatchStats $matchStats,
        public readonly ?string $xliffPath,    // null when writeXliff = false
        public readonly array $timings,        // keys: 'extract','segment','tm','terminology','mt','qa','xliff','store'
        public readonly ?string $storeFileId,  // null when no SegmentStore is configured
    ) {}
}
