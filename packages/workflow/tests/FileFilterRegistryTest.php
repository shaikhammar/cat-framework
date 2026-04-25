<?php

declare(strict_types=1);

namespace CatFramework\Workflow\Tests;

use CatFramework\Core\Contract\FileFilterInterface;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Workflow\Exception\WorkflowException;
use CatFramework\Workflow\FileFilterRegistry;
use PHPUnit\Framework\TestCase;

final class FileFilterRegistryTest extends TestCase
{
    private function makeFilter(string $extension): FileFilterInterface
    {
        return new class($extension) implements FileFilterInterface {
            public function __construct(private readonly string $ext) {}

            public function supports(string $filePath, ?string $mimeType = null): bool
            {
                return str_ends_with(strtolower($filePath), $this->ext);
            }

            public function extract(string $filePath, string $sourceLanguage, string $targetLanguage): BilingualDocument
            {
                return new BilingualDocument($sourceLanguage, $targetLanguage, basename($filePath), 'text/plain');
            }

            public function rebuild(BilingualDocument $document, string $outputPath): void {}

            public function getSupportedExtensions(): array
            {
                return [$this->ext];
            }
        };
    }

    public function test_returns_first_matching_filter(): void
    {
        $registry = new FileFilterRegistry();
        $txtFilter  = $this->makeFilter('.txt');
        $docxFilter = $this->makeFilter('.docx');

        $registry->register($txtFilter);
        $registry->register($docxFilter);

        $this->assertSame($txtFilter, $registry->getFilter('/path/to/file.txt'));
    }

    public function test_selects_by_extension(): void
    {
        $registry = new FileFilterRegistry();
        $txtFilter  = $this->makeFilter('.txt');
        $docxFilter = $this->makeFilter('.docx');

        $registry->register($txtFilter);
        $registry->register($docxFilter);

        $this->assertSame($docxFilter, $registry->getFilter('/path/to/document.docx'));
    }

    public function test_throws_when_no_filter_matches(): void
    {
        $registry = new FileFilterRegistry();
        $registry->register($this->makeFilter('.txt'));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No filter found for:');

        $registry->getFilter('/path/to/file.pdf');
    }

    public function test_throws_for_empty_registry(): void
    {
        $this->expectException(WorkflowException::class);
        (new FileFilterRegistry())->getFilter('/any/file.txt');
    }
}
