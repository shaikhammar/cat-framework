<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

interface WorkflowRunnerInterface
{
    public function process(string $filePath, string $targetLang): WorkflowResult;
}
