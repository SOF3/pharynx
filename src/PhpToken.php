<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

use function token_name;

final class PhpToken {
    public function __construct(
        public ?int $id,
        public string $code,
    ) {
    }

    public function printId() : string {
        return $this->id !== null ? token_name($this->id) : "punctuation";
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo() : array {
        return [
            "id" => $this->printId(),
            "code" => $this->code,
        ];
    }
}
