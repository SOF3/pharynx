<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

use Composer\Console\Application;
use SOFe\Pharynx\Virion\VirionProcessor;
use Symfony\Component\Console\Input\ArgvInput;

use function array_push;
use function array_unshift;
use function basename;
use function bin2hex;
use function chdir;
use function count;
use function getcwd;
use function getopt;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function preg_match;
use function random_bytes;
use function rtrim;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function substr_count;
use function version_compare;
use function yaml_parse_file;

final class Args {
    /** @var array<string, true> for virion processor deduplication when transitive dependency is repeated */
    private array $inferredComposer = [];

    /**
     * @param array{string, string}[] $inputFiles
     * @param string[] $sourceRoots
     * @param Processor[] $processors
     */
    private function __construct(
        public array $inputFiles,
        public array $sourceRoots,
        public string $outputSourceRoot,
        public ?string $outputDir,
        public ?string $outputPhar,
        public bool $verbose,
        public array $processors,
    ) {
    }

    public static function parse() : self {
        $opts = getopt("vc::i:f:s:r:o:p::a:e:", []);

        /** @var string[] $inputFilesRaw */
        $inputFilesRaw = isset($opts["f"]) ? (array) $opts["f"] : [];

        /** @var string[] $sourceRoots */
        $sourceRoots = isset($opts["s"]) ? (array) $opts["s"] : [];

        $outputSourceRoot = isset($opts["r"]) ? $opts["r"] : null;
        if (is_array($outputSourceRoot)) {
            Terminal::print("Error: `-r` can only be passed once.", true);
            throw self::cliUsage();
        }
        /** @var ?string $outputSourceRoot */

        $outputDir = isset($opts["o"]) ? $opts["o"] : null;
        if (is_array($outputDir)) {
            Terminal::print("Error: `-o` can only be passed once.", true);
            throw self::cliUsage();
        }
        /** @var ?string $outputDir */

        $outputPhar = isset($opts["p"]) ? $opts["p"] : null;
        if (is_array($outputPhar)) {
            Terminal::print("Error: `-p` can only be passed once.", true);
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

        /** @var Processor[] $processors */
        $processors = [];

        $epitope = "libs\\_" . bin2hex(random_bytes(8));

        $inputDir = isset($opts["i"]) ? $opts["i"] : null;
        if (is_array($inputDir)) {
            Terminal::print("Error: `-i` can only be passed once.", true);
            throw self::cliUsage();
        }
        /** @var ?string $inputDir */

        $composer = isset($opts["c"]) ? $opts["c"] : null;
        if (is_array($composer)) {
            Terminal::print("Error: `-c` can only be passed once.", true);
            throw self::cliUsage();
        }

        if ($inputDir !== null && is_file($inputDir . "/plugin.yml")) {
            $pluginYml = yaml_parse_file($inputDir . "/plugin.yml");
            if (isset($pluginYml["main"])) {
                $main = $pluginYml["main"];
                $lastNsSep = strrpos($main, "\\");
                if ($lastNsSep !== false) {
                    $epitope = substr($main, 0, $lastNsSep + 1) . $epitope;
                }
            }
        }

        /** @var string[] $antigens */
        $antigens = isset($opts["a"]) ? (array) $opts["a"] : [];

        foreach ($antigens as $antigen) {
            $processors[] = new VirionProcessor($antigen, $epitope, null, null);
        }

        $args = new self(
            inputFiles: $inputFiles,
            sourceRoots: $sourceRoots,
            outputSourceRoot: $outputSourceRoot,
            outputDir: $outputDir,
            outputPhar: $outputPhar,
            verbose: isset($opts["v"]),
            processors: $processors,
        );

        if ($inputDir !== null) {
            $inputDir = rtrim($inputDir, "/\\");
            array_unshift($args->inputFiles, ["plugin.yml", "$inputDir/plugin.yml"]);
            if (is_dir("$inputDir/resources")) {
                array_unshift($args->inputFiles, ["resources", "$inputDir/resources"]);
            }

            if ($composer === null) {
                array_unshift($args->sourceRoots, "$inputDir/src");
            }
            // else, sourceRoots will be populated in inferComposerArgs instead
        }

        if ($composer !== null) {
            if ($composer === false) {
                if ($inputDir === null) {
                    Terminal::print("Error: `-c` must provide a value if `-i` is not passed.", true);
                    throw self::cliUsage();
                }

                $composer = $inputDir;
            }

            $oldCwd = Files::tryFalse(getcwd(), "get current workdir");
            Files::tryFalse(chdir($composer), "chdir to $composer");
            Terminal::print("Running `composer install` for plugin", true);
            $composerApp = new Application();
            $composerApp->setAutoExit(false);
            $composerApp->run(new ArgvInput(["composer", "install", "--ignore-platform-reqs"]));
            Files::tryFalse(chdir($oldCwd), "chdir back to $oldCwd");

            $depDedup = [];
            $args->inferComposerArgs($composer . "/vendor", $composer, null, $depDedup, $epitope);
        }

        if (count($args->inputFiles) + count($args->sourceRoots) === 0) {
            Terminal::print("Error: No sources or inputs provided.", true);
            throw self::cliUsage();
        }

        return $args;
    }

    public static function cliUsage() : never {
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
        echo "  -a ANTIGEN   : The virion antigens to shade.\n";
        echo "                 Can be passed multiple times\n";
        echo "  -c[=PATH]    : Infer source paths and antigens from composer.\n";
        echo "                 Assumes that `composer install` was already run.\n";
        echo "                 If a value is not provided, uses the same path as `-i`.\n";
        echo "                 Otherwise, PATH should be the path to the directory containing composer.json.\n";
        echo "\n";
        echo "EXAMPLES\n";
        echo "  Package a plugin phar:\n";
        echo "  $ php pharynx.phar -i path/to/your/plugin -p my-plugin.phar\n";
        echo "\n";
        echo "  Package a plugin phar with composer virion dependencies:\n";
        echo "  $ php pharynx.phar -i path/to/your/plugin -c -p my-plugin.phar\n";
        echo "\n";
        echo "  Bundle output to a directory without building phar\n";
        echo "  $ php pharynx.phar -i path/to/your/plugin -o output\n";
        echo "\n";
        echo "  Package a plugin phar to output.phar, along with generated files in a gen directory:\n";
        echo "  $ php pharynx.phar -i path/to/your/plugin -s path/to/gen\n";
        exit(1);
    }

    /**
     * @param array<string, true> $depDedup
     */
    private function inferComposerArgs(string $vendorPath, string $path, ?string $name, array &$depDedup, string $epitope) : void {
        if ($name !== null) {
            if (isset($this->inferredComposer[$name])) {
                return;
            }

            $this->inferredComposer[$name] = true;
        }

        $cjString = Files::read($path . "/composer.json");
        $cj = json_decode($cjString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw Terminal::fatal("Failed to parse $path/composer.json as JSON: " . json_last_error_msg());
        }

        if ($name !== null && !isset($cj["extra"]["virion"])) {
            Terminal::print("Notice: skipping non-virion dependency $path", true);
            return;
        }

        Terminal::print("Info: including source paths from $name", $this->verbose);

        $packageSourceRoots = [];
        if (isset($cj["autoload"])) {
            foreach ($cj["autoload"]["psr-0"] ?? [] as $srcs) {
                foreach ((array) $srcs as $src) {
                    $packageSourceRoots[] = $path . "/" . $src;
                }
            }
            foreach ($cj["autoload"]["psr-4"] ?? [] as $srcs) {
                foreach ((array) $srcs as $src) {
                    $packageSourceRoots[] = $path . "/" . $src;
                }
            }
            foreach ($cj["autoload"]["classmap"] ?? [] as $src) {
                $packageSourceRoots[] = $path . "/" . $src;
            }
            foreach ($cj["autoload"]["files"] ?? [] as $src) {
                $packageSourceRoots[] = $path . "/" . $src;
            }
        }
        array_push($this->sourceRoots, ...$packageSourceRoots);

        foreach ($cj["require"] ?? [] as $depName => $_) {
            if (self::isPlatformPackage($depName)) {
                continue;
            }

            if (substr_count($depName, "/") !== 1) {
                throw Terminal::fatal("invalid dependency $depName, only two parts expected, referenced from $path/composer.json");
            }

            if (isset($depDedup[$depName])) {
                continue;
            }
            $depDedup[$depName] = true;

            $this->inferComposerArgs($vendorPath, "$vendorPath/$depName", $depName, $depDedup, $epitope);
        }

        if (isset($cj["extra"]["virion"])) {
            $virion = $cj["extra"]["virion"];

            if (!is_array($virion)) {
                throw Terminal::fatal("$path/composer.json has an invalid extra.virion");
            }

            if (!isset($virion["spec"])) {
                throw Terminal::fatal("$path/composer.json does not declare extra.virion.spec");
            }

            $specVersion = $virion["spec"];
            if (version_compare($specVersion, "3.1", ">")) {
                throw Terminal::fatal("$path/composer.json requires a new virion spec version $specVersion which is not supported by this version of pharynx");
            }
            if (version_compare($specVersion, "3.0", "<")) {
                throw Terminal::fatal("$path/composer.json requires an old virion spec version $specVersion which is not supported by this version of pharynx");
            }

            if (!isset($virion["namespace-root"])) {
                throw Terminal::fatal("$path/composer.json does not declare extra.virion.namespace-root");
            }

            $antigen = $virion["namespace-root"];
            $shared = $virion["shared-namespace-root"] ?? null;
            $this->processors[] = new VirionProcessor($antigen, $epitope, $shared, $packageSourceRoots);
            Terminal::print("Info: namespace root $antigen will be shaded", $this->verbose);
        } elseif ($name !== null) { // not root
            Terminal::print("Warning: dependency {$name} does not declare a namespace root, please consider declaring it in parent libraries", true);
        }
    }

    private static function isPlatformPackage(string $depName) : bool {
        return preg_match('#^(php|(ext|php|lib|composer)-[^/]+)$#', $depName, $_) === 1;
    }
}
