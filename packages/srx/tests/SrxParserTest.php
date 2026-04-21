<?php

declare(strict_types=1);

namespace CatFramework\Srx\Tests;

use CatFramework\Core\Exception\SegmentationException;
use CatFramework\Srx\SrxParser;
use PHPUnit\Framework\TestCase;

class SrxParserTest extends TestCase
{
    private SrxParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SrxParser();
    }

    // --- default SRX ---

    public function test_default_srx_file_exists(): void
    {
        $this->assertFileExists(SrxParser::defaultSrxPath());
    }

    public function test_default_srx_parses_without_error(): void
    {
        $ruleSet = $this->parser->parse(SrxParser::defaultSrxPath());
        $this->assertNotNull($ruleSet);
    }

    public function test_default_srx_contains_english_rules(): void
    {
        $ruleSet = $this->parser->parse(SrxParser::defaultSrxPath());
        $english = $ruleSet->rulesFor('en-US');

        $this->assertNotEmpty($english->rules);
        $this->assertSame('English', $english->name);
    }

    public function test_default_srx_contains_hindi_rules(): void
    {
        $ruleSet = $this->parser->parse(SrxParser::defaultSrxPath());
        $hindi   = $ruleSet->rulesFor('hi-IN');

        $this->assertSame('Hindi', $hindi->name);
        $this->assertNotEmpty($hindi->rules);
    }

    public function test_default_srx_contains_urdu_rules(): void
    {
        $ruleSet = $this->parser->parse(SrxParser::defaultSrxPath());
        $urdu    = $ruleSet->rulesFor('ur-PK');

        $this->assertSame('Urdu', $urdu->name);
    }

    // --- language map resolution ---

    public function test_resolves_en_US_to_english(): void
    {
        $ruleSet = $this->parser->parse(SrxParser::defaultSrxPath());
        $this->assertSame('English', $ruleSet->rulesFor('en-US')->name);
    }

    public function test_resolves_hi_IN_to_hindi(): void
    {
        $ruleSet = $this->parser->parse(SrxParser::defaultSrxPath());
        $this->assertSame('Hindi', $ruleSet->rulesFor('hi-IN')->name);
    }

    public function test_resolves_zh_CN_to_chinese_japanese(): void
    {
        $ruleSet = $this->parser->parse(SrxParser::defaultSrxPath());
        $this->assertSame('ChineseJapanese', $ruleSet->rulesFor('zh-CN')->name);
    }

    public function test_resolves_unknown_code_to_default(): void
    {
        $ruleSet = $this->parser->parse(SrxParser::defaultSrxPath());
        $this->assertSame('Default', $ruleSet->rulesFor('xx-XX')->name);
    }

    public function test_resolution_is_case_insensitive(): void
    {
        $ruleSet = $this->parser->parse(SrxParser::defaultSrxPath());
        $this->assertSame('English', $ruleSet->rulesFor('EN-US')->name);
        $this->assertSame('English', $ruleSet->rulesFor('en-us')->name);
    }

    // --- parse errors ---

    public function test_throws_on_missing_file(): void
    {
        $this->expectException(SegmentationException::class);
        $this->parser->parse('/no/such/file.srx');
    }

    // --- custom SRX ---

    public function test_parses_custom_srx_with_single_rule(): void
    {
        $srx = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <srx version="2.0" xmlns="http://www.lisa.org/srx20">
          <header/>
          <body>
            <languagerules>
              <languagerule languagerulename="Test">
                <rule break="yes">
                  <beforebreak>[\.\?!]+</beforebreak>
                  <afterbreak>\s+\p{Lu}</afterbreak>
                </rule>
              </languagerule>
            </languagerules>
            <maprules>
              <languagemap languagepattern=".*" languagerulename="Test"/>
            </maprules>
          </body>
        </srx>
        XML;

        $path = sys_get_temp_dir() . '/cat_srx_test_' . uniqid() . '.srx';
        file_put_contents($path, $srx);

        $ruleSet = $this->parser->parse($path);
        $rule    = $ruleSet->rulesFor('en-US');

        $this->assertSame('Test', $rule->name);
        $this->assertCount(1, $rule->rules);
        $this->assertTrue($rule->rules[0]->break);
        $this->assertSame('[\.\?!]+', $rule->rules[0]->before);
    }

    public function test_no_break_rule_parsed_correctly(): void
    {
        $srx = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <srx version="2.0" xmlns="http://www.lisa.org/srx20">
          <header/>
          <body>
            <languagerules>
              <languagerule languagerulename="Test">
                <rule break="no">
                  <beforebreak>Mr\.</beforebreak>
                  <afterbreak>\s</afterbreak>
                </rule>
              </languagerule>
            </languagerules>
            <maprules>
              <languagemap languagepattern=".*" languagerulename="Test"/>
            </maprules>
          </body>
        </srx>
        XML;

        $path = sys_get_temp_dir() . '/cat_srx_test_' . uniqid() . '.srx';
        file_put_contents($path, $srx);

        $ruleSet = $this->parser->parse($path);
        $rule    = $ruleSet->rulesFor('en-US');

        $this->assertFalse($rule->rules[0]->break);
    }
}
