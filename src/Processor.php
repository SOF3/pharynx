<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

interface Processor {
    /**
     * @param PhpFile[] $files
     */
    function process(array &$files) : void;
}
