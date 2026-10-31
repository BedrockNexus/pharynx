<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

use PHPUnit\Framework\TestCase;
use function realpath;

final class InstalledPackageTest extends TestCase {
    private const VENDOR = __DIR__ . "/fixtures/installed-packages";

    public function testFindsPackageInstalledUnderItsOwnName() {
        self::assertSame(
            ["dktapps/pmforms", self::VENDOR . "/dktapps/pmforms"],
            Args::findInstalledPackage(self::VENDOR, "dktapps/pmforms"),
        );
    }

    public function testFindsPackageThatReplacesTheDependency() {
        self::assertSame(
            ["axolotl-pm/pocketmine-mp", realpath(self::VENDOR . "/axolotl-pm/pocketmine-mp")],
            Args::findInstalledPackage(self::VENDOR, "pocketmine/pocketmine-mp"),
        );
    }

    public function testFindsPackageThatProvidesTheDependency() {
        self::assertSame(
            ["example/logger", realpath(self::VENDOR . "/example/logger")],
            Args::findInstalledPackage(self::VENDOR, "psr/log-implementation"),
        );
    }

    public function testMatchesReplacedNamesCaseInsensitively() {
        self::assertSame(
            "axolotl-pm/pocketmine-mp",
            Args::findInstalledPackage(self::VENDOR, "PocketMine/PocketMine-MP")[0] ?? null,
        );
    }

    public function testReturnsNullForMissingDependency() {
        self::assertNull(Args::findInstalledPackage(self::VENDOR, "missing/package"));
    }

    public function testReturnsNullWithoutInstalledJson() {
        self::assertNull(Args::findInstalledPackage(__DIR__ . "/fixtures", "pocketmine/pocketmine-mp"));
    }
}
