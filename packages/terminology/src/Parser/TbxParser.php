<?php

declare(strict_types=1);

namespace CatFramework\Terminology\Parser;

use CatFramework\Core\Exception\TerminologyException;
use CatFramework\Core\Model\TermEntry;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parses TBX v2 (ISO 30042) files into TermEntry objects.
 *
 * TBX structure: <martif> → <text> → <body> → <termEntry> (concept)
 *   Each <termEntry> has one or more <langSet xml:lang="..."> children.
 *   Each <langSet> has one or more <tig> (term information group) children.
 *   Each <tig> contains a <term> element with the term text.
 *
 * This parser pairs source and target language sections within each concept
 * to produce flat TermEntry objects.
 */
class TbxParser
{
    /**
     * @return TermEntry[]
     * @throws TerminologyException
     */
    public function parseFile(string $path): array
    {
        if (!file_exists($path)) {
            throw new TerminologyException("TBX file not found: {$path}");
        }

        $xml = file_get_contents($path);
        if ($xml === false) {
            throw new TerminologyException("Cannot read TBX file: {$path}");
        }

        return $this->parseString($xml);
    }

    /**
     * @return TermEntry[]
     * @throws TerminologyException
     */
    public function parseString(string $xml): array
    {
        $dom = new DOMDocument();

        libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if (!$loaded || !empty($errors)) {
            $msg = !empty($errors) ? $errors[0]->message : 'unknown error';
            throw new TerminologyException("TBX XML parse error: {$msg}");
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('xml', 'http://www.w3.org/XML/1998/namespace');

        // Support both <martif> (TBX v2) and <tbx> (TBX-Basic) root elements.
        $conceptNodes = $xpath->query('//termEntry | //conceptEntry');
        if ($conceptNodes === false || $conceptNodes->length === 0) {
            return [];
        }

        $entries = [];
        foreach ($conceptNodes as $conceptNode) {
            assert($conceptNode instanceof DOMElement);
            $entries = array_merge($entries, $this->parseConcept($conceptNode, $xpath));
        }

        return $entries;
    }

    /** @return TermEntry[] */
    private function parseConcept(DOMElement $concept, DOMXPath $xpath): array
    {
        // Collect all language sections keyed by language code.
        $langSections = [];

        // TBX v2 uses <langSet>, TBX-Basic uses <langSec>.
        $langNodes = $xpath->query('langSet | langSec', $concept);
        if ($langNodes === false) {
            return [];
        }

        foreach ($langNodes as $langNode) {
            assert($langNode instanceof DOMElement);
            $lang = $langNode->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang')
                ?: $langNode->getAttribute('xml:lang');

            if ($lang === '') {
                continue;
            }

            $langSections[$lang] = $this->extractLangSection($langNode, $xpath);
        }

        if (count($langSections) < 2) {
            return [];
        }

        // Build cross-product: every source language paired with every target language.
        $entries = [];
        $langs = array_keys($langSections);

        foreach ($langs as $sourceLang) {
            foreach ($langs as $targetLang) {
                if ($sourceLang === $targetLang) {
                    continue;
                }

                foreach ($langSections[$sourceLang] as $sourceTerm) {
                    foreach ($langSections[$targetLang] as $targetTerm) {
                        $entries[] = new TermEntry(
                            sourceTerm: $sourceTerm['term'],
                            targetTerm: $targetTerm['term'],
                            sourceLanguage: $sourceLang,
                            targetLanguage: $targetLang,
                            definition: $sourceTerm['definition'] ?? $targetTerm['definition'] ?? null,
                            domain: $sourceTerm['domain'] ?? $targetTerm['domain'] ?? null,
                            forbidden: $sourceTerm['forbidden'] || $targetTerm['forbidden'],
                        );
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * @return array<int, array{term: string, definition: ?string, domain: ?string, forbidden: bool}>
     */
    private function extractLangSection(DOMElement $langNode, DOMXPath $xpath): array
    {
        $terms = [];

        // TBX v2 uses <tig> containing <term>; TBX-Basic uses <termSec> containing <term>.
        $tigNodes = $xpath->query('tig | termSec | ntig/termGrp', $langNode);
        if ($tigNodes === false || $tigNodes->length === 0) {
            return [];
        }

        foreach ($tigNodes as $tig) {
            assert($tig instanceof DOMElement);

            $termNodes = $xpath->query('term', $tig);
            if ($termNodes === false || $termNodes->length === 0) {
                continue;
            }

            $termText = trim($termNodes->item(0)->textContent);
            if ($termText === '') {
                continue;
            }

            $definition = $this->extractDescrip($tig, $xpath, 'definition');
            $domain = $this->extractDescrip($tig, $xpath, 'subjectField');

            // Also check the parent langSet for definition/domain if not in tig.
            $definition ??= $this->extractDescrip($langNode, $xpath, 'definition');
            $domain ??= $this->extractDescrip($langNode, $xpath, 'subjectField');

            $forbidden = $this->extractAdminStatus($tig, $xpath);

            $terms[] = [
                'term' => $termText,
                'definition' => $definition,
                'domain' => $domain,
                'forbidden' => $forbidden,
            ];
        }

        return $terms;
    }

    private function extractDescrip(DOMElement $node, DOMXPath $xpath, string $type): ?string
    {
        $nodes = $xpath->query(
            "descrip[@type=\"{$type}\"] | descripGrp/descrip[@type=\"{$type}\"]",
            $node
        );

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim($nodes->item(0)->textContent);
        return $value !== '' ? $value : null;
    }

    private function extractAdminStatus(DOMElement $tig, DOMXPath $xpath): bool
    {
        $nodes = $xpath->query('termNote[@type="administrativeStatus"]', $tig);
        if ($nodes === false || $nodes->length === 0) {
            return false;
        }

        $status = mb_strtolower(trim($nodes->item(0)->textContent));
        return in_array($status, ['deprecatedterm', 'supersededterm'], true);
    }
}
