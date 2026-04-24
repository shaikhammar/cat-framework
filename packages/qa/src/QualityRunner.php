<?php

declare(strict_types=1);

namespace CatFramework\Qa;

use CatFramework\Core\Contract\DocumentQualityCheckInterface;
use CatFramework\Core\Contract\QualityCheckInterface;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\SegmentPair;

final class QualityRunner
{
    /** @var QualityCheckInterface[] */
    private array $checks = [];

    /** @var DocumentQualityCheckInterface[] */
    private array $documentChecks = [];

    public function register(QualityCheckInterface $check): void
    {
        $this->checks[] = $check;
    }

    public function registerDocumentCheck(DocumentQualityCheckInterface $check): void
    {
        $this->documentChecks[] = $check;
    }

    /**
     * Run all registered per-pair checks against every segment pair in the document.
     *
     * @return QualityIssue[]
     */
    public function run(BilingualDocument $document): array
    {
        $issues = [];

        foreach ($document->getSegmentPairs() as $pair) {
            foreach ($this->runOnPair($pair, $document->sourceLanguage, $document->targetLanguage) as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Run all registered checks against a single segment pair.
     *
     * @return QualityIssue[]
     */
    public function runOnPair(SegmentPair $pair, string $sourceLanguage, string $targetLanguage): array
    {
        $issues = [];

        foreach ($this->checks as $check) {
            foreach ($check->check($pair, $sourceLanguage, $targetLanguage) as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Run all registered document-level checks against the full document.
     *
     * @return QualityIssue[]
     */
    public function runOnDocument(BilingualDocument $document): array
    {
        $issues = [];

        foreach ($this->documentChecks as $check) {
            foreach ($check->checkDocument($document, $document->sourceLanguage, $document->targetLanguage) as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }
}
