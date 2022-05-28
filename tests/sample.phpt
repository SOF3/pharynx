<?php

declare(strict_types=1);

namespace Foo\Bar;

use function var_dump;
use Qux;
use Corge\Grault;
use some\long\path;
use const PHP_OS;

/** This is not part of header */
final class FinalClass extends Qux

{
	use Grault\First;
	use Qux;

	const SOMETHING = 1;
}

abstract class AbstractClazz implements path\Impl {}

interface Itf extends Grault\Second {}

trait Treit {
	public function hasKeyword() {
		var_dump(PHP_OS, "f{d{$this}", new class extends Qux{}, AbstractClazz::class);
	}
}
