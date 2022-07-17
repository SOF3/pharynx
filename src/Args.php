<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

use RuntimeException;
use function array_unshift;
use function basename;
use function count;
use function getopt;
use function is_array;
use function is_dir;
use function rtrim;
use function strlen;
use function strpos;
use function substr;

final class Args {
    /**
     * @param array{string, string}[] $inputFiles
     * @param string[] $sourceRoots
     */
    private function __construct(
        public array $inputFiles,
        public array $sourceRoots,
        public string $outputSourceRoot,
        public ?string $outputDir,
        public ?string $outputPhar,
        public bool $verbose,
    ) {
    }

    public static function parse() : self {
        $opts = getopt("vi:f:s:r:o:p::", []);

        $inputDir = isset($opts["i"]) ? $opts["i"] : null;
        if (is_array($inputDir)) {
            throw self::cliUsage();
        }
        /** @var ?string $inputDir */

        /** @var string[] $inputFilesRaw */
        $inputFilesRaw = isset($opts["f"]) ? (array) $opts["f"] : [];

        /** @var string[] $sourceRoots */
        $sourceRoots = isset($opts["s"]) ? (array) $opts["s"] : [];

        $outputSourceRoot = isset($opts["r"]) ? $opts["r"] : null;
        if (is_array($outputSourceRoot)) {
            throw self::cliUsage();
        }
        /** @var ?string $outputSourceRoot */

        $outputDir = isset($opts["o"]) ? $opts["o"] : null;
        if (is_array($outputDir)) {
            throw self::cliUsage();
        }
        /** @var ?string $outputDir */

        $outputPhar = isset($opts["p"]) ? $opts["p"] : null;
        if (is_array($outputPhar)) {
            throw self::cliUsage();
        }
        /** @var null|false|string $outputPhar */

        $inputFiles = [];
        foreach ($inputFilesRaw as $inputFile) {
            $inputFile = rtrim($inputFile, "/\\");

            $offset = 0;
            while (true) {
                $pos = strpos($inputFile, ":", $offset);
                if ($pos === false) {
                    break;
                }

                // Windows absolute path support
                if ($pos + 1 < strlen($inputFile) && $inputFile[$pos + 1] === "\\") {
                    $offset = $pos + 1;
                    continue;
                }

                break;
            }

            if ($pos === false) {
                $name = basename($inputFile);
            } else {
                $name = substr($inputFile, $pos);
                $inputFile = substr($inputFile, $pos + 1);
            }

            $inputFiles[] = [$name, $inputFile];
        }

        if ($inputDir !== null) {
            $inputDir = rtrim($inputDir, "/\\");
            array_unshift($inputFiles, ["plugin.yml", "$inputDir/plugin.yml"]);
            if (is_dir("resources")) {
                array_unshift($inputFiles, ["resources", "$inputDir/resources"]);
            }
            array_unshift($sourceRoots, "$inputDir/src");
        }

        if ($outputSourceRoot === null) {
            $outputSourceRoot = "src";
        }

        if ($outputDir !== null) {
            $outputDir = rtrim($outputDir, "/\\");
        }

        if ($outputDir === null && ($outputPhar === false || $outputPhar === null)) {
            $outputPhar = "output.phar";
        } elseif ($outputPhar === false) {
            $outputPhar = $outputDir . ".phar";
        }

        if (count($inputFiles) + count($sourceRoots) === 0) {
            throw self::cliUsage();
        }

        return new self(
            inputFiles: $inputFiles,
            sourceRoots: $sourceRoots,
            outputSourceRoot: $outputSourceRoot,
            outputDir: $outputDir,
            outputPhar: $outputPhar,
            verbose: isset($opts["v"]),
        );
    }

    public static function cliUsage() : RuntimeException {
        echo "USAGE\n";
        echo "  -v           : Enable verbose output.\n";
        echo "  -i PATH      : Equivalent to `-f plugin.yml:PATH/plugin.yml -f PATH/resources -s PATH/src`.\n";
        echo "  -f NAME:PATH : Copy the file or directory at PATH to output/NAME.\n";
        echo "                 `:` is not considered as a separator if immediately followed by a backslash.\n";
        echo "                 Can be passed multiple times.\n";
        echo "  -s PATH      : Use the directory at PATH as a source root.\n";
        echo "                 Can be passed multiple times\n";
        echo "  -r NAME      : The path of the source root in the output.\n";
        echo "                 Default `src`.\n";
        echo "  -o PATH      : Store the output in directory form at PATH.\n";
        echo "  -p[=PATH]    : Pack the output in phar form at PATH.\n";
        echo "                 If no value is given, uses the path in `-o` followed by `.phar`.\n";
        echo "                 If neither -o nor -p are passed, or only `-p` is passed but without values,\n";
        echo "                 `-p output.phar` is assumed.\n";
        echo "\n";
        echo "EXAMPLES\n";
        echo "  Package a plugin phar:\n";
        echo "  $ php pharynx.phar -i path/to/your/plugin -p my-plugin.phar\n";
        echo "\n";
        echo "  Bundle output to a directory without building phar\n";
        echo "  $ php pharynx.phar -i path/to/your/plugin -o output\n";
        echo "\n";
        echo "  Package a plugin phar to output.phar, along with generated files in a gen directory:\n";
        echo "  $ php pharynx.phar -i path/to/your/plugin -s path/to/gen\n";
        exit(1);
    }
}
