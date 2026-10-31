<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_keys;
use function array_map;
use function realpath;
use function sort;

final class ComposerProjectTest extends TestCase {
    private const PROJECT = __DIR__ . "/fixtures/composer-project";
    private const VENDOR = __DIR__ . "/fixtures/composer-project-packages";
    private const EPITOPE = "Example\\Plugin\\libs\\_test";

    public function testServerPackagesFollowTheForkThatReplacesPocketMine() {
        $packages = Args::findServerPackages(self::VENDOR, ["pocketmine/pocketmine-mp"]);

        self::assertArrayHasKey("pocketmine/pocketmine-mp", $packages);
        self::assertArrayHasKey("fork/server", $packages);
        self::assertArrayHasKey("server/lib", $packages);
        self::assertArrayNotHasKey("acme/http", $packages);
    }

    public function testBundlesVirionsAndPlainLibrariesButNotServerOrExcludedPackages() {
        $args = Args::forComposerProject(self::PROJECT, self::VENDOR, self::EPITOPE);

        $roots = array_map(static fn(string $path) : string => (string) realpath($path), $args->sourceRoots);
        sort($roots);
        $expected = [
            realpath(self::PROJECT . "/src/Example/Plugin"),
            realpath(self::VENDOR . "/acme/http/src"),
            realpath(self::VENDOR . "/acme/util/src"),
            realpath(self::VENDOR . "/acme/virion/src"),
        ];
        sort($expected);
        self::assertSame($expected, $roots);
    }

    public function testMovesBundledNamespacesUnderThePlugin() {
        $args = Args::forComposerProject(self::PROJECT, self::VENDOR, self::EPITOPE);

        $files = [];
        foreach ($args->sourceRoots as $root) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $path => $_) {
                $files[] = PhpFile::parse((string) $path, false);
            }
        }
        foreach ($args->processors as $processor) {
            $processor->process($files);
        }

        $byNamespace = [];
        foreach ($files as $file) {
            $byNamespace[$file->namespace] = $file;
        }
        $namespaces = array_keys($byNamespace);
        sort($namespaces);
        self::assertSame([
            "Example\\Plugin",
            self::EPITOPE . "\\Acme\\Util",
            self::EPITOPE . "\\Acme\\Virion",
            self::EPITOPE . "\\Http",
        ], $namespaces);

        $main = $byNamespace["Example\\Plugin"];
        self::assertStringContainsString("use " . self::EPITOPE . "\\Acme\\Virion\\Form;", $main->header);
        self::assertStringContainsString("use " . self::EPITOPE . "\\Http\\Client;", $main->header);
        self::assertStringContainsString("use Server\\Lib\\Thing;", $main->header);
        self::assertStringContainsString("\\" . self::EPITOPE . "\\Acme\\Util\\Str::class", $main->items[0]->code);

        $client = $byNamespace[self::EPITOPE . "\\Http"];
        self::assertStringContainsString("namespace " . self::EPITOPE . "\\Http;", $client->header);
        self::assertStringContainsString("use " . self::EPITOPE . "\\Acme\\Util\\Str;", $client->header);
    }
}
