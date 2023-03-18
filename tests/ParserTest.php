<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

use PHPUnit\Framework\TestCase;
use function count;
use function file_get_contents;
use function rtrim;
use function strpos;
use function substr;
use function substr_count;
use function version_compare;

final class ParserTest extends TestCase {
    public function testParse() {
        $testData = file_get_contents(__DIR__ . "/sample.phpt");
        $actual = PhpFile::parse(__DIR__ . "/sample.phpt", true);

        self::assertEquals(__DIR__ . "/sample.phpt", $actual->originalPath);
        self::assertEquals("Foo\\Bar", $actual->namespace);

        $expectHeader = rtrim(substr($testData, 0, strpos($testData, "/** This is not part of header *")));
        self::assertEquals($expectHeader, $actual->header);

        self::assertEquals(4, count($actual->items));

        self::assertEquals("FinalClass", $actual->items[0]->name);
        self::assertEquals(self::findLineOfFirst($testData, "class FinalClass"), $actual->items[0]->startingLine + 1); // + 1 because of doccomment line

        self::assertEquals("AbstractClazz", $actual->items[1]->name);
        self::assertEquals(self::findLineOfFirst($testData, "class AbstractClazz"), $actual->items[1]->startingLine);

        self::assertEquals("Itf", $actual->items[2]->name);
        self::assertEquals(self::findLineOfFirst($testData, "interface Itf"), $actual->items[2]->startingLine);

        self::assertEquals("Treit", $actual->items[3]->name);
        self::assertEquals(self::findLineOfFirst($testData, "trait Treit"), $actual->items[3]->startingLine);
    }

    public function testParse8_1() {
        if (version_compare(PHP_VERSION, "8.1.0", "<")) {
            self::markTestSkipped("test was skipped: php version(" . PHP_VERSION . ") is lower than 8.1.0");
        }
        $testData = file_get_contents(__DIR__ . "/sample-8.1.phpt");
        $actual = PhpFile::parse(__DIR__ . "/sample-8.1.phpt", true);

        $expectHeader = rtrim(substr($testData, 0, strpos($testData, "/** This is not part of header *")));
        self::assertEquals($expectHeader, $actual->header);

        self::assertEquals(2, count($actual->items));

        self::assertEquals("BasicEnum", $actual->items[0]->name);
        self::assertEquals(self::findLineOfFirst($testData, "enum BasicEnum"), $actual->items[0]->startingLine + 1); // + 1 because of doccomment line

        self::assertEquals("BackedEnum", $actual->items[1]->name);
        self::assertEquals(self::findLineOfFirst($testData, "enum BackedEnum"), $actual->items[1]->startingLine);
    }

    private static function findLineOfFirst(string $haystack, string $needle) : ?int {
        $pos = strpos($haystack, $needle);
        if ($pos === false) {
            return null;
        }

        return substr_count($haystack, "\n", 0, $pos);
    }
}
