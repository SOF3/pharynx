<?php

declare(strict_types=1);

namespace Foo\Bar;

use function var_dump;
use Qux;
use Corge\Grault;
use some\long\path;
use const PHP_OS;

/** This is not part of header */
enum BasicEnum {
	case A;
	case B;
	case C;
}

enum BackedEnum : string {
	case Hello = 'H';
	case World = 'W';
}
