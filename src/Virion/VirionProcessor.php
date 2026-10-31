<?php

declare(strict_types=1);

namespace SOFe\Pharynx\Virion;

use ParseError;
use RuntimeException;
use SOFe\Pharynx\Files;
use SOFe\Pharynx\PhpFile;
use SOFe\Pharynx\Processor;
use SOFe\Pharynx\Terminal;

use function implode;
use function is_string;
use function preg_match;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function token_get_all;

/**
 * Moves the namespaces of a bundled library under the plugin's namespace
 * (the epitope) and rewrites every reference to them.
 */
final class VirionProcessor implements Processor {
    /**
     * @param list<string> $antigens the namespace roots to move
     * @param ?list<string> $sourceRoots the library's source folders, used to drop its files outside the antigens
     * @param bool $allowTopLevel whether an antigen may be a single namespace segment, as plain Composer libraries often are
     */
    public function __construct(private array $antigens, private string $epitope, private ?string $shared, private ?array $sourceRoots, bool $allowTopLevel = false) {
        $pattern = $allowTopLevel ? '#^[a-zA-Z0-9_]+(\\\\[a-zA-Z0-9_]+)*$#' : '#^[a-zA-Z0-9_]+(\\\\[a-zA-Z0-9_]+)+$#';
        foreach ($antigens as $antigen) {
            if (!preg_match($pattern, $antigen)) {
                throw Terminal::fatal("\"$antigen\" is not a valid namespace root. Namespace root must have at least two path components, e.g. `SOF3\\Pharynx`.");
            }
        }
        if (str_ends_with($epitope, "\\")) {
            throw new RuntimeException("epitope must not end with a backslash");
        }
    }

    /**
     * @param PhpFile[] $files
     */
    public function process(array &$files) : void {
        $newFiles = [];
        foreach ($files as $file) {
            if ($this->matchesAntigen($file->namespace)) {
                $file->namespace = $this->epitope . "\\" . $file->namespace;
            } elseif ($this->shared !== null && self::matches($file->namespace, $this->shared)) {
                // no need to change
            } elseif ($this->sourceRoots !== null && !self::matches($file->namespace, $this->epitope)) {
                // validate that classes from virions are all under antigen
                $isFromVirion = false;
                foreach ($this->sourceRoots as $sourceRoot) {
                    if (str_starts_with(Files::realpath($file->originalPath), Files::realpath($sourceRoot))) {
                        $isFromVirion = true;
                        break;
                    }
                }

                if ($isFromVirion) {
                    $roots = implode(", ", $this->antigens);
                    Terminal::print("Cannot include {$file->originalPath} for virion because {$file->namespace} is not under the namespace root $roots or the shared namespace root {$this->shared}", true);
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
        $afterNamespaceKeyword = false;
        foreach ($tokens as $i => $token) {
            if (!$hasHeader && $i === 0 && $token[0] === T_OPEN_TAG) {
                continue;
            }

            if (is_string($token)) {
                $output .= $token;
                $afterNamespaceKeyword = false;
            } else {
                [$tokenId, $tokenString] = $token;
                if ($tokenId === T_NAME_QUALIFIED && $this->matchesAntigen($tokenString)) {
                    $tokenString = $this->epitope . "\\" . $tokenString;
                } elseif ($tokenId === T_NAME_FULLY_QUALIFIED && $this->matchesAntigen(substr($tokenString, 1))) {
                    $tokenString = "\\" . $this->epitope . $tokenString;
                } elseif ($tokenId === T_STRING && $afterNamespaceKeyword && $this->matchesAntigen($tokenString)) {
                    // `namespace Foo;` declares a single-segment namespace as a plain name.
                    $tokenString = $this->epitope . "\\" . $tokenString;
                }
                $output .= $tokenString;

                if ($tokenId === T_NAMESPACE) {
                    $afterNamespaceKeyword = true;
                } elseif ($tokenId !== T_WHITESPACE && $tokenId !== T_COMMENT && $tokenId !== T_DOC_COMMENT) {
                    $afterNamespaceKeyword = false;
                }
            }
        }

        return $output;
    }

    private function matchesAntigen(string $name) : bool {
        foreach ($this->antigens as $antigen) {
            if (self::matches($name, $antigen)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $token, string $prefix) : bool {
        return $prefix === $token || str_starts_with($token, $prefix . "\\");
    }
}
