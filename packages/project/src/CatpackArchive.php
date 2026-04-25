<?php

declare(strict_types=1);

namespace CatFramework\Project;

use CatFramework\Project\Exception\ManifestException;
use CatFramework\Project\Model\ProjectManifest;
use ZipArchive;

final class CatpackArchive
{
    private function __construct(
        private readonly ZipArchive $zip,
        private readonly ProjectManifest $manifest,
    ) {}

    public static function create(string $outputPath, ProjectManifest $manifest): self
    {
        $zip = new ZipArchive();
        $zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('catproject.json', self::manifestToJson($manifest));

        return new self($zip, $manifest);
    }

    public static function open(string $catpackPath): self
    {
        $zip = new ZipArchive();

        if ($zip->open($catpackPath) !== true) {
            throw new ManifestException("Cannot open catpack: {$catpackPath}");
        }

        $json = $zip->getFromName('catproject.json');

        if ($json === false) {
            throw new ManifestException("catproject.json not found in archive: {$catpackPath}");
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new ManifestException("Invalid catproject.json in archive: {$catpackPath}");
        }

        $manifest = ProjectLoader::parseArray($data, dirname($catpackPath));

        return new self($zip, $manifest);
    }

    public function addSourceFile(string $filePath, ?string $archiveName = null): void
    {
        $this->zip->addFile($filePath, 'source/' . ($archiveName ?? basename($filePath)));
    }

    public function addTm(string $dbPath, string $archiveName): void
    {
        $entryName = 'tm/' . $archiveName;
        $this->zip->addFile($dbPath, $entryName);
        $this->zip->setCompressionName($entryName, ZipArchive::CM_STORE);
    }

    public function addGlossary(string $dbPath, string $archiveName): void
    {
        $entryName = 'glossaries/' . $archiveName;
        $this->zip->addFile($dbPath, $entryName);
        $this->zip->setCompressionName($entryName, ZipArchive::CM_STORE);
    }

    public function addXliff(string $xliffPath, string $archiveName): void
    {
        $this->zip->addFile($xliffPath, 'xliff/' . $archiveName);
    }

    public function getManifest(): ProjectManifest
    {
        return $this->manifest;
    }

    public function extractTo(string $directory): void
    {
        $this->zip->extractTo($directory);
    }

    public function save(): void
    {
        $this->zip->close();
    }

    private static function manifestToJson(ProjectManifest $manifest): string
    {
        $data = [
            'name'        => $manifest->name,
            'sourceLang'  => $manifest->sourceLang,
            'targetLangs' => $manifest->targetLangs,
            'tm'          => array_map(
                fn(object $tm) => ['path' => $tm->path, 'readOnly' => $tm->readOnly],
                $manifest->tm,
            ),
            'glossaries'  => array_map(
                fn(object $g) => ['path' => $g->path, 'readOnly' => $g->readOnly],
                $manifest->glossaries,
            ),
        ];

        if ($manifest->mt !== null) {
            $data['mt'] = [
                'adapter'       => $manifest->mt->adapter,
                'apiKey'        => $manifest->mt->apiKey,
                'fillThreshold' => $manifest->mt->fillThreshold,
            ];
        }

        $data['qa'] = [
            'checks'         => $manifest->qa->checks,
            'failOnSeverity' => $manifest->qa->failOnSeverity,
        ];

        $data['filters'] = [
            'docx' => $manifest->filters->docx,
            'xlsx' => $manifest->filters->xlsx,
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
