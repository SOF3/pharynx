<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

use Generator;
use Phar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function bin2hex;
use function file_exists;
use function is_dir;
use function random_bytes;
use function realpath;
use function rename;
use function str_repeat;
use function str_replace;
use function substr;
use function substr_count;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class Main {
    public static function main() : void {
        global $argv;

        if (!isset($argv[2])) {
            throw Terminal::fatal("Usage: php $argv[0] <plugin directory> <output phar>");
        }

        $input = $argv[1];
        $output = $argv[2];

        if (!is_dir($input)) {
            throw Terminal::fatal("$input is not a directory");
        }

        $input = realpath($input);
        if ($input === false) {
            throw Terminal::fatal("Cannot canonicalize input directory");
        }

        if (!file_exists($input . "/plugin.yml")) {
            throw Terminal::fatal("$input/plugin.yml does not exist");
        }

        if (file_exists($output)) {
            // move it to a new file first, to avoid race conditions
            $newFile = tempnam(sys_get_temp_dir(), "rmf");
            if ($newFile === false) {
                throw Terminal::fatal("Cannot create temp file");
            }

            rename($output, $newFile);
            unlink($newFile);
        }

        $tmp = sys_get_temp_dir() . "/" . bin2hex(random_bytes(4));
        Files::mkdir($tmp);

        Files::copy($input . "/plugin.yml", $tmp . "/plugin.yml");
        Files::recursiveCopy($input . "/resources", $tmp . "/resources");
        Files::mkdir($tmp . "/src");

        $phar = new Phar($output);
        $phar->setStub("<?php __HALT_COMPILER();");

        $files = [];
        foreach (["src", "gen"] as $sourceRoot) {
            if (file_exists($input . "/" . $sourceRoot)) {
                self::parseFiles($input . "/" . $sourceRoot, $files);
            }
        }

        foreach ($files as $file) {
            $nsDir = $tmp . "/src/" . str_replace("\\", "/", $file->namespace);
            if (!file_exists($nsDir)) {
                Files::mkdir($nsDir);
            }

            foreach ($file->items as $item) {
                self::populateFile($nsDir . "/" . $item->name . ".php", $file, $item);
            }
        }

        $phar->buildFromDirectory($tmp);

        Files::recursiveDelete($tmp);
    }

    /**
     * @param list<PhpFile> $files
     */
    private static function parseFiles(string $src, array &$files) : void {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::CURRENT_AS_PATHNAME)) as $file) {
            if (substr($file, -4) !== ".php") {
                continue;
            }

            $files[] = PhpFile::parse($file);
        }
    }

    private static function populateFile(string $path, PhpFile $file, PsrItem $item) : void {
        Files::write($path, (function() use ($file, $item) : Generator {
            yield $file->header;
            $headerLines = substr_count($file->header, "\n");

            // try to pad the file to the correct number of lines
            $padding = $item->startingLine - $headerLines;
            if ($padding > 0) {
                yield str_repeat("\n", $padding);
            } elseif ($padding < 0) {
                Terminal::print("Assumption failed: statringLine $item->startingLine < headerLines $headerLines");
            }

            yield $item->code;
        })());
    }
}
