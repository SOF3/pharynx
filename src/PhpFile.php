<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

use ParseError;

use function array_slice;
use function assert;
use function count;
use function defined;
use function is_array;
use function realpath;
use function substr_count;
use function token_get_all;
use function trim;

final class PhpFile {
    /**
     * @param PsrItem[] $items
     */
    public function __construct(
        public string $originalPath,
        public string $header,
        public string $namespace,
        public array $items,
    ) {
    }

    public static function parse(string $filePath, bool $verbose) : self {
        Terminal::print("Parsing $filePath", $verbose);

        $phpCode = Files::read($filePath);
        try {
            $rawTokens = token_get_all($phpCode, TOKEN_PARSE);
        } catch(ParseError $e) {
            $realpath = realpath($filePath);
            throw Terminal::fatal("Syntax error: {$e->getMessage()} on {$realpath}:{$e->getLine()}");
        }

        $tokens = [];

        foreach ($rawTokens as $rawToken) {
            if (is_array($rawToken)) {
                [$tokenId, $code, $_lineNo] = $rawToken;
            } else {
                $tokenId = null; // unclassified identifier
                $code = $rawToken;
            }

            $tokens[] = new PhpToken($tokenId, $code);
        }

        [$namespaceTokenOffset, $namespace] = self::findNamespace($filePath, $tokens);

        $headerOffset = self::findHeader($filePath, $tokens, $namespaceTokenOffset);
        $header = "";
        for ($i = 0; $i < $headerOffset; $i++) {
            $header .= $tokens[$i]->code;
        }
        $tokens = array_slice($tokens, $headerOffset);

        $lineNo = substr_count($header, "\n");
        $items = [];
        while (count($tokens) > 0) {
            $item = self::findNextItem($filePath, $tokens, $lineNo);
            if ($item !== null) {
                Terminal::print("Parsed item {$item->name} on line {$item->startingLine}", $verbose);
                $items[] = $item;
                $lineNo = $item->startingLine + substr_count($item->code, "\n");
            } else {
                $offset = 0;
                self::skipWhitespace($tokens, $outstanding, $offset);
                if ($outstanding !== null) {
                    throw Terminal::fatal("Error parsing $filePath: Trailing non-whitespace bytes {$outstanding->printId()}");
                }
                break;
            }
        }

        return new self($filePath, $header, $namespace, $items);
    }

    /**
     * @param PhpToken[] $tokens
     */
    private static function findHeader(string $filePath, array $tokens, int $namespaceTokenOffset) : int {
        $i = $namespaceTokenOffset;
        if (!self::seekPunct($tokens, $i, ";")) {
            throw Terminal::fatal("Error parsing $filePath: Cannot seek semicolon after namespace statement");
        }

        $lastUseToken = $i + 1;

        while ($i < count($tokens)) {
            self::skipWhitespace($tokens, $useToken, $i);
            if ($useToken === null || $useToken->id !== T_USE) {
                break;
            }

            $j = $i;
            self::skipWhitespace($tokens, $nameToken, $j);

            // This allows `use function`/`use const`
            if ($nameToken->id === T_FUNCTION || $nameToken->id === T_CONST) {
                self::skipWhitespace($tokens, $nameToken, $j);
            }

            if ($nameToken->id === T_NAME_QUALIFIED || $nameToken->id === T_STRING) {
                // we seek until `;`, ignoring whatever curly braces we encounter in between.
                // This function does not require `;` to immediately follow `T_NAME_QUALIFIED`/`T_STRING`.
                if (self::seekPunct($tokens, $j, ";")) {
                    $lastUseToken = $j + 1;
                }
            }

            $i = $lastUseToken;
        }

        return $lastUseToken;
    }

    /**
     * @param PhpToken[] $tokens
     */
    private static function findNextItem(string $filePath, array &$tokens, int $startingLine) : ?PsrItem {
        // skip until the class/interface/trait/enum(>=8.1) keyword
        $start = null;
        $itemName = null;

        $startOffset = 0;
        self::skipWhitespace($tokens, $_, $startOffset);

        for ($i = $startOffset; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if ($token->id === T_CLASS || $token->id === T_INTERFACE || $token->id === T_TRAIT || (defined('T_ENUM') && $token->id === T_ENUM)) {
                self::skipWhitespace($tokens, $nameToken, $i);
                if ($nameToken->id !== T_STRING) {
                    throw Terminal::fatal("Error parsing $filePath: Expected T_STRING after T_CLASS/T_INTERFACE/T_TRAIT/T_EMUM");
                }
                $itemName = trim($nameToken->code);
                $start = $i;
                break;
            }
        }

        if ($start === null) {
            return null;
        }
        assert($itemName !== null);

        $i = $start;
        if (!self::seekPunct($tokens, $i, "{")) {
            throw Terminal::fatal("Error parsing $filePath: Cannot find open brace after item declaration");
        }
        $i += 1;
        $pairs = 1;

        /** @var ?int $until */
        $until = null;
        while ($i < count($tokens)) {
            $token = $tokens[$i];

            if ($token->id === null || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $punct = trim($token->code);
                $pairs += substr_count($punct, "{") - substr_count($punct, "}");
                if ($pairs === 0) {
                    $until = $i + 1;
                    break;
                }
            }

            $i += 1;
        }

        if ($until === null) {
            throw Terminal::fatal("Error parsing $filePath: Unexpected end of file with unclosed curly brace");
        }
        /** @var int $until */

        for ($i = 0; $i < $startOffset; $i++) {
            $startingLine += substr_count($tokens[$i]->code, "\n");
        }

        // code is $tokens[0..$until]
        $code = "";
        for ($i = $startOffset; $i < $until; $i++) {
            $code .= $tokens[$i]->code;
        }

        $tokens = array_slice($tokens, $until);

        return new PsrItem($startingLine, $itemName, $code);
    }

    /**
     * @param PhpToken[] $tokens
     * @return array{int, string}
     */
    private static function findNamespace(string $filePath, array $tokens) : array {
        $namespaceTokenOffset = 0;
        /** @var ?PhpToken $namespace */
        $namespace = null;
        for ($i = 0; $i + 2 < count($tokens); $i++) {
            if ($tokens[$i]->id === T_NAMESPACE) {
                if ($namespace !== null) {
                    throw Terminal::fatal("Multiple T_NAMESPACE encountered in $filePath, only one is allowed");
                }

                $j = $i;
                self::skipWhitespace($tokens, $namespace, $j);

                if ($namespace === null) {
                    throw Terminal::fatal("Error parsing $filePath: Expected path after T_NAMESPACE, got end of file");
                }
                if ($namespace->id !== T_NAME_QUALIFIED && $namespace->id !== T_STRING) {
                    throw Terminal::fatal("Error parsing $filePath: Expected path after T_NAMESPACE, got " . $namespace->printId());
                }

                $namespaceTokenOffset = $i;

                $semi = null;
                self::skipWhitespace($tokens, $semi, $j);

                if ($semi === null) {
                    throw Terminal::fatal("Error parsing $filePath: Expected semicolon after namespace, got end of file");
                }
                if (trim($semi->code) !== ";") {
                    if ($semi->code[0] === "{") {
                        throw Terminal::fatal("Error parsing $filePath: pharynx currently does not support multiple namespaces in the same file, please use `namespace xxx;` instead of `namespace xxx {}`.");
                    }
                    throw Terminal::fatal("Error parsing $filePath: Expected semicolon after namespace, got " . $semi->printId());
                }
            }
        }

        if ($namespace === null) {
            throw Terminal::fatal("Error parsing $filePath: Could not detect namespace statement");
        }

        return [$namespaceTokenOffset, $namespace->code];
    }

    /**
     * @param PhpToken[] $tokens
     */
    private static function skipWhitespace(array $tokens, ?PhpToken &$token, int &$j) : void {
        do {
            $j += 1;
            if ($j >= count($tokens)) {
                $token = null;
                return;
            }
            $token = $tokens[$j];
        } while ($token->id === T_WHITESPACE || $token->id === T_COMMENT);
    }

    /**
     * @param PhpToken[] $tokens
     */
    private static function seekPunct(array $tokens, int &$j, string $punct) : bool {
        while ($j < count($tokens)) {
            $token = $tokens[$j];
            if (trim($token->code) === $punct) {
                return true;
            }

            $j += 1;
        }

        return false;
    }
}
