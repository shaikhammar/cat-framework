<?php

declare(strict_types=1);

namespace CatFramework\Project;

use CatFramework\Project\Exception\ManifestException;
use CatFramework\Project\Model\FilterConfig;
use CatFramework\Project\Model\GlossaryConfig;
use CatFramework\Project\Model\MtConfig;
use CatFramework\Project\Model\ProjectManifest;
use CatFramework\Project\Model\QaConfig;
use CatFramework\Project\Model\TmConfig;

final class ProjectLoader
{
    public function __construct(private readonly string $manifestPath) {}

    public function getManifest(): ProjectManifest
    {
        if (!is_file($this->manifestPath)) {
            throw new ManifestException("Manifest file not found: {$this->manifestPath}");
        }

        $json = file_get_contents($this->manifestPath);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new ManifestException("Invalid JSON in manifest: {$this->manifestPath}");
        }

        $data = self::resolveEnvVars($data);

        return self::parseArray($data, dirname($this->manifestPath));
    }

    public static function parseArray(array $data, string $basePath): ProjectManifest
    {
        foreach (['name', 'sourceLang', 'targetLangs'] as $field) {
            if (!isset($data[$field])) {
                throw new ManifestException("Missing required field '{$field}' in manifest");
            }
        }

        if (!is_string($data['name']) || $data['name'] === '') {
            throw new ManifestException("Field 'name' must be a non-empty string");
        }

        if (!is_string($data['sourceLang'])) {
            throw new ManifestException("Field 'sourceLang' must be a string");
        }

        if (!is_array($data['targetLangs']) || $data['targetLangs'] === []) {
            throw new ManifestException("Field 'targetLangs' must be a non-empty array");
        }

        $tm = [];
        foreach ($data['tm'] ?? [] as $entry) {
            $tm[] = new TmConfig((string) $entry['path'], (bool) ($entry['readOnly'] ?? false));
        }

        $glossaries = [];
        foreach ($data['glossaries'] ?? [] as $entry) {
            $glossaries[] = new GlossaryConfig((string) $entry['path'], (bool) ($entry['readOnly'] ?? false));
        }

        $mt = null;
        if (isset($data['mt'])) {
            $mt = new MtConfig(
                (string) $data['mt']['adapter'],
                (string) ($data['mt']['apiKey'] ?? ''),
                (float) ($data['mt']['fillThreshold'] ?? 0.0),
            );
        }

        $qaData = $data['qa'] ?? [];
        $qa = new QaConfig(
            (array) ($qaData['checks'] ?? []),
            isset($qaData['failOnSeverity']) ? (string) $qaData['failOnSeverity'] : null,
        );

        $filterData = $data['filters'] ?? [];
        $filters = new FilterConfig(
            (array) ($filterData['docx'] ?? []),
            (array) ($filterData['xlsx'] ?? []),
        );

        return new ProjectManifest(
            name: $data['name'],
            sourceLang: $data['sourceLang'],
            targetLangs: $data['targetLangs'],
            tm: $tm,
            glossaries: $glossaries,
            mt: $mt,
            qa: $qa,
            filters: $filters,
            basePath: $basePath,
        );
    }

    public static function resolvePath(string $basePath, string $relative): string
    {
        if (str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative)) {
            return $relative;
        }

        return rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $relative;
    }

    private static function resolveEnvVars(array $data): array
    {
        array_walk_recursive($data, static function (mixed &$value): void {
            if (is_string($value)) {
                $value = preg_replace_callback(
                    '/\$\{([A-Z_][A-Z0-9_]*)\}/',
                    static fn(array $m): string => getenv($m[1]) !== false ? (string) getenv($m[1]) : $m[0],
                    $value,
                );
            }
        });

        return $data;
    }
}
