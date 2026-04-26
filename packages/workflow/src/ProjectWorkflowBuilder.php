<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

use CatFramework\Core\Contract\MachineTranslationInterface;
use CatFramework\Core\Contract\TerminologyProviderInterface;
use CatFramework\Mt\DeepL\DeepLAdapter;
use CatFramework\Project\Model\ProjectManifest;
use CatFramework\Qa\Check\DoubleSpaceCheck;
use CatFramework\Qa\Check\EmptyTranslationCheck;
use CatFramework\Qa\Check\NumberConsistencyCheck;
use CatFramework\Qa\Check\SegmentConsistencyCheck;
use CatFramework\Qa\Check\TagConsistencyCheck;
use CatFramework\Qa\Check\TerminologyConsistencyCheck;
use CatFramework\Qa\Check\WhitespaceCheck;
use CatFramework\Qa\QualityRunner;
use CatFramework\Segmentation\SrxSegmentationEngine;
use CatFramework\Terminology\Provider\SqliteTerminologyProvider;
use CatFramework\TranslationMemory\SqliteTranslationMemory;
use CatFramework\Workflow\Exception\WorkflowException;
use CatFramework\Xliff\XliffWriter;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory as GuzzleHttpFactory;
use PDO;

final class ProjectWorkflowBuilder
{
    private const array KNOWN_MT_ADAPTERS = ['deepl', 'google'];

    public function __construct(private readonly ProjectManifest $manifest) {}

    public function build(string $targetLang, FileFilterRegistry $registry): WorkflowRunner
    {
        $terminology = $this->buildTerminology();

        return new WorkflowRunner(
            fileFilterRegistry: $registry,
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: $this->manifest->sourceLang,
            translationMemory: $this->buildTm(),
            terminologyProvider: $terminology,
            mtAdapter: $this->buildMt(),
            qaRunner: $this->buildQa($terminology),
            options: $this->buildOptions(),
        );
    }

    private function buildTm(): ?SqliteTranslationMemory
    {
        if ($this->manifest->tm === []) {
            return null;
        }

        $config = $this->manifest->tm[0];
        $pdo    = new PDO('sqlite:' . $config->path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new SqliteTranslationMemory($pdo);
    }

    private function buildTerminology(): ?SqliteTerminologyProvider
    {
        if ($this->manifest->glossaries === []) {
            return null;
        }

        return new SqliteTerminologyProvider($this->manifest->glossaries[0]->path);
    }

    private function buildMt(): ?MachineTranslationInterface
    {
        if ($this->manifest->mt === null) {
            return null;
        }

        $adapterName = $this->manifest->mt->adapter;

        if (!in_array($adapterName, self::KNOWN_MT_ADAPTERS, true)) {
            throw new WorkflowException(
                "Unknown MT adapter: {$adapterName}. Supported: " . implode(', ', self::KNOWN_MT_ADAPTERS)
            );
        }

        if ($adapterName === 'google') {
            throw new WorkflowException(
                "Google adapter requires a project ID that is not in the manifest. " .
                "Instantiate GoogleTranslateAdapter directly and pass it to WorkflowRunner."
            );
        }

        if (!class_exists(GuzzleClient::class)) {
            throw new WorkflowException(
                "HTTP client required for MT — install guzzlehttp/guzzle"
            );
        }

        $httpClient  = new GuzzleClient();
        $httpFactory = new GuzzleHttpFactory();

        return new DeepLAdapter(
            $httpClient,
            $httpFactory,
            $httpFactory,
            $this->manifest->mt->apiKey,
        );
    }

    private function buildQa(?TerminologyProviderInterface $terminology): ?QualityRunner
    {
        if ($this->manifest->qa->checks === []) {
            return null;
        }

        $runner = new QualityRunner();

        $pairChecks = [
            'EmptyTranslationCheck'       => fn() => new EmptyTranslationCheck(),
            'TagConsistencyCheck'         => fn() => new TagConsistencyCheck(),
            'NumberConsistencyCheck'      => fn() => new NumberConsistencyCheck(),
            'WhitespaceCheck'             => fn() => new WhitespaceCheck(),
            'DoubleSpaceCheck'            => fn() => new DoubleSpaceCheck(),
            'TerminologyConsistencyCheck' => fn() => new TerminologyConsistencyCheck($terminology),
        ];

        $docChecks = [
            'SegmentConsistencyCheck' => fn() => new SegmentConsistencyCheck(),
        ];

        foreach ($this->manifest->qa->checks as $checkName) {
            if (isset($pairChecks[$checkName])) {
                $runner->register($pairChecks[$checkName]());
            } elseif (isset($docChecks[$checkName])) {
                $runner->registerDocumentCheck($docChecks[$checkName]());
            } else {
                throw new WorkflowException("Unknown QA check: {$checkName}");
            }
        }

        return $runner;
    }

    private function buildOptions(): WorkflowOptions
    {
        $options = WorkflowOptions::defaults();
        $options->qaFailOnSeverity = $this->manifest->qa->failOnSeverity;

        if ($this->manifest->mt !== null) {
            $options->mtFillThreshold = $this->manifest->mt->fillThreshold;
        }

        return $options;
    }
}
