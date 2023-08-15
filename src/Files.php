<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

use function closedir;
use function copy;
use function error_get_last;
use function fclose;
use function file_get_contents;
use function fopen;
use function fwrite;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function opendir;
use function readdir;
use function realpath;
use function rmdir;
use function unlink;

final class Files {
    /**
     * @template T
     * @param T|false $value
     * @return T
     */
    public static function tryFalse($value, string $action) {
        if ($value === false) {
            $err = error_get_last();
            $message = $err !== null ? (": " . $err["message"]) : "";
            throw Terminal::fatal("Failed to $action" . $message);
        }

        return $value;
    }

    public static function copy(string $src, string $dest) : void {
        self::tryFalse(@copy($src, $dest), "copy $src to $dest");
    }

    public static function delete(string $src) : void {
        self::tryFalse(@unlink($src), "delete $src");
    }

    public static function mkdir(string $dir) : void {
        self::tryFalse(@mkdir($dir, 0770, true), "create directory $dir");
    }

    public static function read(string $path) : string {
        return self::tryFalse(@file_get_contents($path), "read file $path");
    }

    public static function realpath(string $path) : string {
        return self::tryFalse(@realpath($path), "canonicalize $path");
    }

    /**
     * @param iterable<string> $data
     */
    public static function write(string $path, iterable $data) : void {
        $fd = self::tryFalse(@fopen($path, "wb"), "open file $path");

        try {
            foreach ($data as $buf) {
                self::tryFalse(@fwrite($fd, $buf), "write file $path");
            }
        } finally {
            self::tryFalse(@fclose($fd), "close file $path");
        }
    }

    public static function recursiveCopy(string $src, string $dest) : void {
        self::mkdir($dest);

        $directory = self::tryFalse(@opendir($src), "open directory $src");

        while (($entry = readdir($directory)) !== false) {
            if ($entry === "." || $entry === "..") {
                continue;
            }

            $path = $src . "/" . $entry;

            if (is_link($path)) {
                throw Terminal::fatal("Found symbolic link at $path");
            }

            if (is_file($path)) {
                self::copy($path, $dest . "/" . $entry);
            } elseif (is_dir($path)) {
                self::recursiveCopy($path, $dest . "/" . $entry);
            } else {
                throw Terminal::fatal("Unsupported file type at $path");
            }
        }
    }

    public static function recursiveDelete(string $src) : void {
        $directory = self::tryFalse(@opendir($src), "open directory $src");

        while (($entry = readdir($directory)) !== false) {
            if ($entry === "." || $entry === "..") {
                continue;
            }

            $path = $src . "/" . $entry;

            if (is_file($path)) {
                self::delete($path);
            } elseif (is_dir($path)) {
                self::recursiveDelete($path);
            } else {
                throw Terminal::fatal("Unsupported file type at $path");
            }
        }
        closedir($directory);

        rmdir($src);
    }
}
