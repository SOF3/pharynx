<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

final class PsrItem {
    public function __construct(
        /** The line number (starting from 0) of the first character of `code` */
        public int $startingLine,
        /** The short name (without namespace) of the item */
        public string $name,
        public string $code,
    ) {
    }
}
