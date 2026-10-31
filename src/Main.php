<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

use Generator;
use Phar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function bin2hex;
use function dirname;
use function file_exists;
use function filter_var;
use function getenv;
use function ini_get;
use function is_dir;
use function is_file;
use function proc_close;
use function proc_open;
use function random_bytes;
use function str_repeat;
use function str_replace;
use function substr;
use function substr_count;
use function sys_get_temp_dir;

final class Main {
    private const RELAUNCHED_ENV = "PHARYNX_RELAUNCHED";

    public static function main() : void {
        self::ensureWritablePhar();

        $args = Args::parse();

        if ($args->outputPhar !== null && file_exists($args->outputPhar)) {
            Terminal::print("Removing old $args->outputPhar", $args->verbose);
            Files::delete($args->outputPhar);
        }
        if ($args->outputPhar !== null && !is_dir(dirname($args->outputPhar))) {
            Files::mkdir(dirname($args->outputPhar));
        }

        $outputDir = $args->outputDir ?? sys_get_temp_dir() . "/" . bin2hex(random_bytes(4));
        if (is_dir($outputDir)) {
            Terminal::print("Removing old $args->outputDir", $args->verbose);
            Files::recursiveDelete($outputDir);
        }

        Files::mkdir($outputDir);

        foreach ($args->inputFiles as [$name, $path]) {
            if (is_file($path)) {
                Files::copy($path, $outputDir . "/" . $name);
            } elseif (is_dir($path)) {
                Files::recursiveCopy($path, $outputDir . "/" . $name);
            } else {
                throw Terminal::fatal("Unsupported file type at $path");
            }
        }

        Files::mkdir($outputDir . "/" . $args->outputSourceRoot);

        $files = [];
        foreach ($args->sourceRoots as $sourceRoot) {
            self::parseFiles($sourceRoot, $files, $args->verbose);
        }

        foreach ($args->processors as $processor) {
            $processor->process($files);
        }

        foreach ($files as $file) {
            $nsDir = $outputDir . "/" . $args->outputSourceRoot . "/" . str_replace("\\", "/", $file->namespace);
            if (!file_exists($nsDir)) {
                Files::mkdir($nsDir);
            }

            foreach ($file->items as $item) {
                self::populateFile($nsDir . "/" . $item->name . ".php", $file, $item);
            }
        }

        if ($args->outputPhar !== null) {
            $phar = new Phar($args->outputPhar);
            $phar->setStub("<?php __HALT_COMPILER();");
            $phar->buildFromDirectory($outputDir);
        }

        if ($args->outputDir === null) {
            Terminal::print("Removing temp directory", $args->verbose);
            Files::recursiveDelete($outputDir);
        } else {
            Terminal::print("Output directory created at $args->outputDir", true);
        }

        if ($args->outputPhar !== null) {
            Terminal::print("Generated phar at $args->outputPhar", true);
        }
    }

    /**
     * PHP only lets php.ini or the command line turn off phar.readonly, so
     * when it is on, run this command again with `-dphar.readonly=0`.
     */
    private static function ensureWritablePhar() : void {
        if (!filter_var(ini_get("phar.readonly"), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        if (getenv(self::RELAUNCHED_ENV) === "1") {
            throw Terminal::fatal("phar.readonly is still on. Run pharynx with `php -dphar.readonly=0`.");
        }

        /** @var list<string> $argv */
        $argv = $_SERVER["argv"] ?? [];
        $env = getenv();
        $env[self::RELAUNCHED_ENV] = "1";
        $process = proc_open([PHP_BINARY, "-dphar.readonly=0", ...$argv], [STDIN, STDOUT, STDERR], $pipes, null, $env);
        if ($process === false) {
            throw Terminal::fatal("phar.readonly is on and pharynx could not restart itself. Run it with `php -dphar.readonly=0`.");
        }
        exit(proc_close($process));
    }

    /**
     * @param list<PhpFile> $files
     */
    private static function parseFiles(string $src, array &$files, bool $verbose) : void {
        if (is_file($src)) {
            $phpFile = PhpFile::parse($src, $verbose);
            $files[] = $phpFile;
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::CURRENT_AS_PATHNAME)) as $file) {
            if (substr($file, -4) !== ".php") {
                continue;
            }

            $phpFile = PhpFile::parse($file, $verbose);
            $files[] = $phpFile;
        }
    }

    private static function populateFile(string $path, PhpFile $file, PsrItem $item) : void {
        Files::write($path, (function() use ($file, $item) : Generator {
            yield $file->header;
            $headerLines = substr_count($file->header, "\n");

            // try to pad the file to the correct number of lines
            $padding = $item->startingLine - $headerLines;
            if ($padding > 0) {
                yield str_repeat("\n", $padding);
            } elseif ($padding < 0) {
                Terminal::print("Assumption failed: startingLine $item->startingLine < headerLines $headerLines", true);
            }

            yield $item->code;
        })());
    }
}
