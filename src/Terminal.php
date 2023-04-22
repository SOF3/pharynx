<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

use Throwable;

use function date;

final class Terminal {
    public static function fatal(string $message) : Throwable {
        self::print($message, true);
        exit(1);
    }

    public static function print(string $message, bool $shouldPrint) : void {
        if ($shouldPrint) {
            echo date("[H:i:s] "), $message, PHP_EOL;
        }
    }
}
