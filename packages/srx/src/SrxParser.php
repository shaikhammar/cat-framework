<?php

declare(strict_types=1);

namespace CatFramework\Srx;

use CatFramework\Core\Exception\SegmentationException;
use DOMDocument;
use DOMXPath;

class SrxParser
{
    public function parse(string $filePath): SegmentationRuleSet
    {
        if (!file_exists($filePath)) {
            throw new SegmentationException("SRX file not found: {$filePath}");
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->load($filePath);
        libxml_clear_errors();

        if (!$loaded) {
            throw new SegmentationException("Failed to parse SRX file: {$filePath}");
        }

        $xpath = new DOMXPath($dom);

        $languageRules = [];
        foreach ($xpath->query('//*[local-name()="languagerule"]') as $langRuleEl) {
            $name  = $langRuleEl->getAttribute('languagerulename');
            $rules = [];

            foreach ($xpath->query('*[local-name()="rule"]', $langRuleEl) as $ruleEl) {
                $isBreak = strtolower($ruleEl->getAttribute('break')) !== 'no';

                $beforeNodes = $xpath->query('*[local-name()="beforebreak"]', $ruleEl);
                $afterNodes  = $xpath->query('*[local-name()="afterbreak"]', $ruleEl);

                $before = $beforeNodes->length > 0 ? trim($beforeNodes->item(0)->textContent) : '';
                $after  = $afterNodes->length  > 0 ? trim($afterNodes->item(0)->textContent)  : '';

                $rules[] = new SegmentationRule($isBreak, $before, $after);
            }

            $languageRules[$name] = new LanguageRule($name, $rules);
        }

        $mapRules = [];
        foreach ($xpath->query('//*[local-name()="languagemap"]') as $mapEl) {
            $mapRules[] = [
                'pattern' => $mapEl->getAttribute('languagepattern'),
                'rule'    => $mapEl->getAttribute('languagerulename'),
            ];
        }

        return new SegmentationRuleSet($languageRules, $mapRules);
    }

    /** Returns the path to the bundled default SRX file. */
    public static function defaultSrxPath(): string
    {
        return dirname(__DIR__) . '/data/default.srx';
    }
}
