<?php

declare(strict_types=1);

chdir(__DIR__);

function println(string $message) : void {
    echo date("[H:i:s] ") . $message . PHP_EOL;
}

function fatal(string $message) : void {
    println("Error: " . $message);
    exit(1);
}

function execCommand(string ...$args) : int {
    system(implode(" ", array_map("escapeshellarg", $args)), $code);
    return $code;
}

function execComposer(string ...$args) : int {
    static $composerPhar = null;

    if ($composerPhar === null) {
        $composerPhar = getenv("COMPOSER_PATH");

        if ($composerPhar === false) {
            $separator = PHP_OS_FAMILY === "Windows" ? ";" : ":";
            $paths = explode($separator, getenv("PATH"));

            foreach ($paths as $path) {
                if ($path !== "" && is_file($path . "/composer")) {
                    $composerPhar = $path . "/composer";
                    break;
                }

                if ($path !== "" && is_file($path . "/composer.phar")) {
                    $composerPhar = $path . "/composer.phar";
                    break;
                }
            }

            if ($composerPhar === false) {
                fatal("Cannot detect composer path, specify with \$COMPOSER_PATH env var");
            }
        }
    }

    return execCommand(PHP_BINARY, $composerPhar, ...$args);
}

function recursiveCopy(string $src, string $dest) : void {
    if (!mkdir($dest, 0700, true)) {
        fatal("Failed to create directory $dest");
    }

    $directory = opendir($src);
    while (($entry = readdir($directory)) !== false) {
        if ($entry === "." || $entry === "..") {
            continue;
        }

        $path = $src . "/" . $entry;

        if (is_link($path)) {
            fatal("Found symbolic link at $path");
        }

        if (is_file($path)) {
            if (!copy($path, $dest . "/" . $entry)) {
                fatal("Failed to copy $path to $dest/$entry");
            }
        } elseif (is_dir($path)) {
            recursiveCopy($path, $dest . "/" . $entry);
        } else {
            fatal("Unsupported file type at $path");
        }
    }
}

function recursiveDelete(string $src) : void {
    $directory = opendir($src);
    while (($entry = readdir($directory)) !== false) {
        if ($entry === "." || $entry === "..") {
            continue;
        }

        $path = $src . "/" . $entry;

        if (is_file($path)) {
            if (!unlink($path)) {
                fatal("Failed to unlink $path");
            }
        } elseif (is_dir($path)) {
            recursiveDelete($path);
        } else {
            fatal("Unsupported file type at $path");
        }
    }
    closedir($directory);

    rmdir($src);
}

if (file_exists("pharynx.phar")) {
    println("Removing old pharynx.phar");

    // move it to a new file first, to avoid race conditions
    $newFile = tempnam(sys_get_temp_dir(), "rmf");
    rename("pharynx.phar", $newFile);
    unlink($newFile);
}

println("Installing dependencies");
if (execComposer("install", "--no-dev") !== 0) {
    fatal("composer install failed");
}

// Do not copy the useless binaries
if (file_exists("vendor/bin")) {
    recursiveDelete("vendor/bin");
}

$tmp = sys_get_temp_dir() . "/" . bin2hex(random_bytes(4));
if (!mkdir($tmp)) {
    fatal("Failed creating tmp dir at $tmp");
}
println("Packing files to temp directory $tmp");
recursiveCopy("src", $tmp . "/src");
recursiveCopy("vendor", $tmp . "/vendor");

$phar = new Phar("pharynx.phar");
$phar->setStub(<<<'EOF'
    #!/usr/bin/env php
    <?php
    if (PHP_MAJOR_VERSION < 8) {
        echo "PHP 8.0 or above is required dueo to lexer changes\n";
        exit(1);
    }
    require "phar://" . __FILE__ . "/vendor/autoload.php";
    \SOFe\Pharynx\Main::main($argv);
    __HALT_COMPILER();
    EOF);
$phar->buildFromDirectory($tmp);

println("rm -r $tmp");
recursiveDelete($tmp);
