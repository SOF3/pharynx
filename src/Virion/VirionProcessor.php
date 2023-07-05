<?php

declare(strict_types=1);

namespace SOFe\Pharynx\Virion;

use ParseError;
use RuntimeException;
use SOFe\Pharynx\Processor;
use SOFe\Pharynx\Terminal;

use function is_string;
use function preg_match;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function token_get_all;

final class VirionProcessor implements Processor {
    private string $antigen;

    public function __construct(string $antigen, private string $epitope) {
        if (!preg_match('#^[a-zA-Z0-9_]+(\\\\[a-zA-Z0-9_]+)+$#', $antigen, $matches)) {
            echo "\"$antigen\" is not a valid class name";
            exit(1);
        }
        if (str_ends_with($epitope, "\\")) {
            throw new RuntimeException("epitope must not end with a backslash");
        }

        $this->antigen = $antigen;
    }

    public function process(array &$files) : void {
        foreach ($files as $file) {
            if ($this->matches($file->namespace)) {
                $file->namespace = $this->epitope . "\\" . $file->namespace;
            }

            $file->header = $this->replace($file->header, $file->originalPath);

            foreach ($file->items as $item) {
                $item->code = $this->replace($item->code, $file->originalPath);
            }
        }
    }

    private function replace(string &$code, string $originalPath) : string {
        $hasHeader = str_starts_with($code, "<?php");
        $parsedCode = $code;
        if (!$hasHeader) {
            $parsedCode = "<?php\n$code";
        }

        try {
            $tokens = token_get_all($parsedCode, TOKEN_PARSE);
        } catch(ParseError $e) {
            throw Terminal::fatal("Syntax error: {$e->getMessage()} on {$originalPath}:{$e->getLine()}");
        }

        $output = "";
        foreach ($tokens as $i => $token) {
            if (!$hasHeader && $i === 0 && $token[0] === T_OPEN_TAG) {
                continue;
            }

            if (is_string($token)) {
                $output .= $token;
            } else {
                [$tokenId, $tokenString] = $token;
                if ($tokenId === T_NAME_QUALIFIED && $this->matches($tokenString)) {
                    $tokenString = $this->epitope . "\\" . $tokenString;
                } elseif ($tokenId === T_NAME_FULLY_QUALIFIED && $this->matches(substr($tokenString, 1))) {
                    $tokenString = "\\" . $this->epitope . $tokenString;
                }
                $output .= $tokenString;
            }
        }

        return $output;
    }

    private function matches(string $token) : bool {
        return $this->antigen === $token || str_starts_with($token, $this->antigen . "\\");
    }
}
