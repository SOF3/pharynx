<?php

declare(strict_types=1);

namespace SOFe\Pharynx\Virion;

use ParseError;
use RuntimeException;
use SOFe\Pharynx\Files;
use SOFe\Pharynx\PhpFile;
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

    /**
     * @param ?list<string> $sourceRoots
     */
    public function __construct(string $antigen, private string $epitope, private ?string $shared, private ?array $sourceRoots) {
        if (!preg_match('#^[a-zA-Z0-9_]+(\\\\[a-zA-Z0-9_]+)+$#', $antigen, $matches)) {
            echo "\"$antigen\" is not a valid class name";
            exit(1);
        }
        if (str_ends_with($epitope, "\\")) {
            throw new RuntimeException("epitope must not end with a backslash");
        }

        $this->antigen = $antigen;
    }

    /**
     * @param PhpFile[] $files
     */
    public function process(array &$files) : void {
        $newFiles = [];
        foreach ($files as $file) {
            if (self::matches($file->namespace, $this->antigen)) {
                $file->namespace = $this->epitope . "\\" . $file->namespace;
            } elseif ($this->shared !== null && self::matches($file->namespace, $this->shared)) {
                // no need to change
            } elseif ($this->sourceRoots !== null) {
                // validate that classes from virions are all under antigen
                $isFromVirion = false;
                foreach ($this->sourceRoots as $sourceRoot) {
                    if (str_starts_with(Files::realpath($file->originalPath), Files::realpath($sourceRoot))) {
                        $isFromVirion = true;
                        break;
                    }
                }

                if ($isFromVirion) {
                    Terminal::print("Cannot include {$file->originalPath} for virion because {$file->namespace} is not under the namespace root {$this->antigen} or the shared namespace root {$this->shared}", true);
                    continue;
                }
            }

            $file->header = $this->replace($file->header, $file->originalPath);

            foreach ($file->items as $item) {
                $item->code = $this->replace($item->code, $file->originalPath);
            }

            $newFiles[] = $file;
        }

        $files = $newFiles;
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
                if ($tokenId === T_NAME_QUALIFIED && self::matches($tokenString, $this->antigen)) {
                    $tokenString = $this->epitope . "\\" . $tokenString;
                } elseif ($tokenId === T_NAME_FULLY_QUALIFIED && self::matches(substr($tokenString, 1), $this->antigen)) {
                    $tokenString = "\\" . $this->epitope . $tokenString;
                }
                $output .= $tokenString;
            }
        }

        return $output;
    }

    private static function matches(string $token, string $prefix) : bool {
        return $prefix === $token || str_starts_with($token, $prefix . "\\");
    }
}
