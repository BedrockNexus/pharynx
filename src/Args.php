<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

use Composer\Console\Application;
use SOFe\Pharynx\Virion\VirionProcessor;
use Symfony\Component\Console\Input\ArgvInput;

use function array_push;
use function array_shift;
use function array_unshift;
use function basename;
use function bin2hex;
use function chdir;
use function count;
use function getcwd;
use function getopt;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function preg_match;
use function random_bytes;
use function realpath;
use function rtrim;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function substr_count;
use function version_compare;
use function yaml_parse_file;

final class Args {
    private const NAMESPACE_PATTERN = '#^[a-zA-Z_][a-zA-Z0-9_]*(\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$#';

    /** @var array<string, true> for virion processor deduplication when transitive dependency is repeated */
    private array $inferredComposer = [];

    /** @var array<string, true> lower-cased names of packages the server provides, which are never bundled */
    private array $serverPackages = [];

    /**
     * @param array{string, string}[] $inputFiles
     * @param string[] $sourceRoots
     * @param Processor[] $processors
     */
    private function __construct(
        public array $inputFiles,
        public array $sourceRoots,
        public string $outputSourceRoot,
        public ?string $outputDir,
        public ?string $outputPhar,
        public bool $verbose,
        public array $processors,
    ) {
    }

    public static function parse() : self {
        $opts = getopt("vc::i:f:s:r:o:p::a:e:", []);

        /** @var string[] $inputFilesRaw */
        $inputFilesRaw = isset($opts["f"]) ? (array) $opts["f"] : [];

        /** @var string[] $sourceRoots */
        $sourceRoots = isset($opts["s"]) ? (array) $opts["s"] : [];

        $outputSourceRoot = isset($opts["r"]) ? $opts["r"] : null;
        if (is_array($outputSourceRoot)) {
            Terminal::print("Error: `-r` can only be passed once.", true);
            throw self::cliUsage();
        }
        /** @var ?string $outputSourceRoot */

        $outputDir = isset($opts["o"]) ? $opts["o"] : null;
        if (is_array($outputDir)) {
            Terminal::print("Error: `-o` can only be passed once.", true);
            throw self::cliUsage();
        }
        /** @var ?string $outputDir */

        $outputPhar = isset($opts["p"]) ? $opts["p"] : null;
        if (is_array($outputPhar)) {
            Terminal::print("Error: `-p` can only be passed once.", true);
            throw self::cliUsage();
        }
        /** @var null|false|string $outputPhar */

        $inputFiles = [];
        foreach ($inputFilesRaw as $inputFile) {
            $inputFile = rtrim($inputFile, "/\\");

            $offset = 0;
            while (true) {
                $pos = strpos($inputFile, ":", $offset);
                if ($pos === false) {
                    break;
                }

                // Windows absolute path support
                if ($pos + 1 < strlen($inputFile) && $inputFile[$pos + 1] === "\\") {
                    $offset = $pos + 1;
                    continue;
                }

                break;
            }

            if ($pos === false) {
                $name = basename($inputFile);
            } else {
                $name = substr($inputFile, $pos);
                $inputFile = substr($inputFile, $pos + 1);
            }

            $inputFiles[] = [$name, $inputFile];
        }

        if ($outputSourceRoot === null) {
            $outputSourceRoot = "src";
        }

        if ($outputDir !== null) {
            $outputDir = rtrim($outputDir, "/\\");
        }

        if ($outputDir === null && ($outputPhar === false || $outputPhar === null)) {
            $outputPhar = "output.phar";
        } elseif ($outputPhar === false) {
            $outputPhar = $outputDir . ".phar";
        }

        /** @var Processor[] $processors */
        $processors = [];

        $epitope = "libs\\_" . bin2hex(random_bytes(8));

        $inputDir = isset($opts["i"]) ? $opts["i"] : null;
        if (is_array($inputDir)) {
            Terminal::print("Error: `-i` can only be passed once.", true);
            throw self::cliUsage();
        }
        /** @var ?string $inputDir */

        $composer = isset($opts["c"]) ? $opts["c"] : null;
        if (is_array($composer)) {
            Terminal::print("Error: `-c` can only be passed once.", true);
            throw self::cliUsage();
        }

        if ($inputDir !== null && is_file($inputDir . "/plugin.yml")) {
            $pluginYml = yaml_parse_file($inputDir . "/plugin.yml");
            if (isset($pluginYml["main"])) {
                $main = $pluginYml["main"];
                if (isset($pluginYml["src-namespace-prefix"])) {
                    throw Terminal::fatal("Error: Usage of 'src-namespace-prefix' in plugin.yml is not allowed.");
                }
                $lastNsSep = strrpos($main, "\\");
                if ($lastNsSep !== false) {
                    $epitope = substr($main, 0, $lastNsSep + 1) . $epitope;
                }
            }
        }

        /** @var string[] $antigens */
        $antigens = isset($opts["a"]) ? (array) $opts["a"] : [];

        foreach ($antigens as $antigen) {
            $processors[] = new VirionProcessor([$antigen], $epitope, null, null);
        }

        $args = new self(
            inputFiles: $inputFiles,
            sourceRoots: $sourceRoots,
            outputSourceRoot: $outputSourceRoot,
            outputDir: $outputDir,
            outputPhar: $outputPhar,
            verbose: isset($opts["v"]),
            processors: $processors,
        );

        if ($inputDir !== null) {
            $inputDir = rtrim($inputDir, "/\\");
            array_unshift($args->inputFiles, ["plugin.yml", "$inputDir/plugin.yml"]);
            if (is_dir("$inputDir/resources")) {
                array_unshift($args->inputFiles, ["resources", "$inputDir/resources"]);
            }

            // A plugin with a composer.json is built from it without needing `-c`.
            if ($composer === null && is_file("$inputDir/composer.json")) {
                $composer = false;
            }

            if ($composer === null) {
                array_unshift($args->sourceRoots, "$inputDir/src");
            }
            // else, sourceRoots will be populated in inferComposerArgs instead
        }

        if ($composer !== null) {
            if ($composer === false) {
                if ($inputDir === null) {
                    Terminal::print("Error: `-c` must provide a value if `-i` is not passed.", true);
                    throw self::cliUsage();
                }

                $composer = $inputDir;
            }
            $composer = rtrim($composer, "/\\");

            $oldCwd = Files::tryFalse(getcwd(), "get current workdir");
            Files::tryFalse(chdir($composer), "chdir to $composer");
            Terminal::print("Running `composer install` for plugin", true);
            $composerApp = new Application();
            $composerApp->setAutoExit(false);
            $exitCode = $composerApp->run(new ArgvInput(["composer", "install", "--ignore-platform-reqs", "--no-interaction"]));
            Files::tryFalse(chdir($oldCwd), "chdir back to $oldCwd");
            if ($exitCode !== 0) {
                throw Terminal::fatal("`composer install` failed with exit code $exitCode");
            }

            $args->inferComposer($composer, $composer . "/vendor", $epitope);

            // Plugins whose composer.json has no autoload section still keep their src folder.
            if ($inputDir !== null && is_dir("$inputDir/src") && !self::overlapsPath($args->sourceRoots, "$inputDir/src")) {
                array_unshift($args->sourceRoots, "$inputDir/src");
            }
        }

        if (count($args->inputFiles) + count($args->sourceRoots) === 0) {
            Terminal::print("Error: No sources or inputs provided.", true);
            throw self::cliUsage();
        }

        return $args;
    }

    public static function cliUsage() : never {
        echo "USAGE\n";
        echo "  -v           : Enable verbose output.\n";
        echo "  -i PATH      : Equivalent to `-f plugin.yml:PATH/plugin.yml -f PATH/resources -s PATH/src`.\n";
        echo "  -f NAME:PATH : Copy the file or directory at PATH to output/NAME.\n";
        echo "                 `:` is not considered as a separator if immediately followed by a backslash.\n";
        echo "                 Can be passed multiple times.\n";
        echo "  -s PATH      : Use the directory at PATH as a source root.\n";
        echo "                 Can be passed multiple times\n";
        echo "  -r NAME      : The path of the source root in the output.\n";
        echo "                 Default `src`.\n";
        echo "  -o PATH      : Store the output in directory form at PATH.\n";
        echo "  -p[=PATH]    : Pack the output in phar form at PATH.\n";
        echo "                 If no value is given, uses the path in `-o` followed by `.phar`.\n";
        echo "                 If neither -o nor -p are passed, or only `-p` is passed but without values,\n";
        echo "                 `-p output.phar` is assumed.\n";
        echo "  -a ANTIGEN   : The virion antigens to shade.\n";
        echo "                 Can be passed multiple times\n";
        echo "  -c[=PATH]    : Run `composer install` and bundle the Composer dependencies.\n";
        echo "                 Automatic when the `-i` directory contains composer.json.\n";
        echo "                 If a value is not provided, uses the same path as `-i`.\n";
        echo "                 Otherwise, PATH should be the path to the directory containing composer.json.\n";
        echo "                 Virions and plain libraries are shaded under the plugin namespace.\n";
        echo "                 pocketmine/* packages, pocketmine/pocketmine-mp (or a fork that replaces it)\n";
        echo "                 and their dependencies come from the server and are not bundled.\n";
        echo "                 List more packages to leave out under extra.pharynx.exclude in composer.json.\n";
        echo "\n";
        echo "EXAMPLES\n";
        echo "  Package a plugin phar, including its Composer dependencies:\n";
        echo "  $ php pharynx.phar -i path/to/your/plugin -p=my-plugin.phar\n";
        echo "\n";
        echo "  Bundle output to a directory without building phar\n";
        echo "  $ php pharynx.phar -i path/to/your/plugin -o output\n";
        echo "\n";
        echo "  Package a plugin phar to output.phar, along with generated files in a gen directory:\n";
        echo "  $ php pharynx.phar -i path/to/your/plugin -s path/to/gen\n";
        exit(1);
    }

    /**
     * Builds the source roots and processors for a Composer project without
     * running `composer install`, so the dependency rules can be tested.
     */
    public static function forComposerProject(string $projectDir, string $vendorPath, string $epitope, bool $verbose = false) : self {
        $args = new self(
            inputFiles: [],
            sourceRoots: [],
            outputSourceRoot: "src",
            outputDir: null,
            outputPhar: null,
            verbose: $verbose,
            processors: [],
        );
        $args->inferComposer($projectDir, $vendorPath, $epitope);
        return $args;
    }

    private function inferComposer(string $projectDir, string $vendorPath, string $epitope) : void {
        $cj = self::readComposerJson($projectDir);

        $invalidExclude = "$projectDir/composer.json has an invalid extra.pharynx.exclude, expected a list of package names";
        $exclude = $cj["extra"]["pharynx"]["exclude"] ?? [];
        if (!is_array($exclude)) {
            throw Terminal::fatal($invalidExclude);
        }
        $roots = ["pocketmine/pocketmine-mp"];
        foreach ($exclude as $excluded) {
            if (!is_string($excluded)) {
                throw Terminal::fatal($invalidExclude);
            }
            $roots[] = $excluded;
        }
        $this->serverPackages = self::findServerPackages($vendorPath, $roots);

        $depDedup = [];
        $this->inferComposerArgs($vendorPath, $projectDir, null, $depDedup, $epitope);
    }

    /**
     * @param array<string, true> $depDedup
     */
    private function inferComposerArgs(string $vendorPath, string $path, ?string $name, array &$depDedup, string $epitope) : void {
        if ($name !== null) {
            if (isset($this->inferredComposer[$name])) {
                return;
            }

            $this->inferredComposer[$name] = true;

            if ($this->isServerPackage($name)) {
                Terminal::print("Info: $name comes from the server and is not bundled", $this->verbose);
                return;
            }
        }

        $cj = self::readComposerJson($path);

        $isVirion = isset($cj["extra"]["virion"]);
        if ($name !== null && !$isVirion) {
            [$packageSourceRoots, $libraryRoots] = self::libraryAutoload($path, $name, $cj);
            Terminal::print("Info: bundling library $name", true);
        } else {
            $packageSourceRoots = self::autoloadPaths($path, $cj);
            $libraryRoots = [];
            Terminal::print("Info: including source paths from " . ($name ?? $path), $this->verbose);
        }
        array_push($this->sourceRoots, ...$packageSourceRoots);

        foreach ($cj["require"] ?? [] as $depName => $_) {
            $depName = (string) $depName;
            if (self::isPlatformPackage($depName)) {
                continue;
            }

            if (substr_count($depName, "/") !== 1) {
                throw Terminal::fatal("invalid dependency $depName, only two parts expected, referenced from $path/composer.json");
            }

            if (isset($depDedup[$depName])) {
                continue;
            }
            $depDedup[$depName] = true;

            if ($this->isServerPackage($depName)) {
                Terminal::print("Info: $depName comes from the server and is not bundled", $this->verbose);
                continue;
            }

            $installed = self::findInstalledPackage($vendorPath, $depName);
            if ($installed === null) {
                throw Terminal::fatal("Cannot find dependency $depName in $vendorPath, referenced from $path/composer.json. Run `composer install` first.");
            }
            [$installedName, $installedPath] = $installed;
            if ($installedName !== $depName) {
                Terminal::print("Info: using $installedName for $depName, which it replaces or provides", $this->verbose);
            }

            $this->inferComposerArgs($vendorPath, $installedPath, $installedName, $depDedup, $epitope);
        }

        if ($isVirion) {
            $virion = $cj["extra"]["virion"];

            if (!is_array($virion)) {
                throw Terminal::fatal("$path/composer.json has an invalid extra.virion");
            }

            if (!isset($virion["spec"])) {
                throw Terminal::fatal("$path/composer.json does not declare extra.virion.spec");
            }

            $specVersion = $virion["spec"];
            if (version_compare($specVersion, "3.1", ">")) {
                throw Terminal::fatal("$path/composer.json requires a new virion spec version $specVersion which is not supported by this version of pharynx");
            }
            if (version_compare($specVersion, "3.0", "<")) {
                throw Terminal::fatal("$path/composer.json requires an old virion spec version $specVersion which is not supported by this version of pharynx");
            }

            if (!isset($virion["namespace-root"])) {
                throw Terminal::fatal("$path/composer.json does not declare extra.virion.namespace-root");
            }

            $antigen = $virion["namespace-root"];
            $shared = $virion["shared-namespace-root"] ?? null;
            $this->processors[] = new VirionProcessor([$antigen], $epitope, $shared, $packageSourceRoots);
            Terminal::print("Info: namespace root $antigen will be shaded", $this->verbose);
        } elseif ($libraryRoots !== []) {
            $this->processors[] = new VirionProcessor($libraryRoots, $epitope, null, $packageSourceRoots, allowTopLevel: true);
            Terminal::print("Info: namespace roots " . implode(", ", $libraryRoots) . " will be shaded", $this->verbose);
        }
    }

    /**
     * @return array<mixed>
     */
    private static function readComposerJson(string $path) : array {
        $cj = json_decode(Files::read($path . "/composer.json"), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw Terminal::fatal("Failed to parse $path/composer.json as JSON: " . json_last_error_msg());
        }
        if (!is_array($cj)) {
            throw Terminal::fatal("$path/composer.json must contain a JSON object");
        }

        return $cj;
    }

    /**
     * @param array<mixed> $cj
     * @return list<string>
     */
    private static function autoloadPaths(string $path, array $cj) : array {
        $packageSourceRoots = [];
        if (isset($cj["autoload"])) {
            foreach ($cj["autoload"]["psr-0"] ?? [] as $srcs) {
                foreach ((array) $srcs as $src) {
                    $packageSourceRoots[] = $path . "/" . $src;
                }
            }
            foreach ($cj["autoload"]["psr-4"] ?? [] as $srcs) {
                foreach ((array) $srcs as $src) {
                    $packageSourceRoots[] = $path . "/" . $src;
                }
            }
            foreach ($cj["autoload"]["classmap"] ?? [] as $src) {
                $packageSourceRoots[] = $path . "/" . $src;
            }
            foreach ($cj["autoload"]["files"] ?? [] as $src) {
                $packageSourceRoots[] = $path . "/" . $src;
            }
        }

        return $packageSourceRoots;
    }

    /**
     * Reads the source folders and namespace roots of a plain Composer library.
     *
     * PocketMine only autoloads classes by namespace from the plugin's src
     * folder, so a library is bundled by moving its namespaces under the
     * plugin's. Files and classmap autoloading have no equivalent there.
     *
     * @param array<mixed> $cj
     * @return array{list<string>, list<string>} the source folders and the namespace roots
     */
    private static function libraryAutoload(string $path, string $name, array $cj) : array {
        $autoload = $cj["autoload"] ?? [];
        if (!is_array($autoload)) {
            throw Terminal::fatal("$path/composer.json has an invalid autoload section");
        }

        foreach (["files", "classmap"] as $kind) {
            if (isset($autoload[$kind]) && $autoload[$kind] !== []) {
                throw Terminal::fatal("Cannot bundle $name: it uses autoload.$kind, which a PocketMine plugin cannot load. Only PSR-4 and PSR-0 libraries can be bundled. If the server already provides $name, list it under extra.pharynx.exclude in your composer.json.");
            }
        }

        $sourceRoots = [];
        $namespaceRoots = [];
        foreach (["psr-4", "psr-0"] as $kind) {
            foreach ($autoload[$kind] ?? [] as $prefix => $srcs) {
                $namespaceRoot = rtrim((string) $prefix, "\\");
                if (preg_match(self::NAMESPACE_PATTERN, $namespaceRoot) !== 1) {
                    throw Terminal::fatal("Cannot bundle $name: its autoload.$kind prefix \"$prefix\" is not a namespace, so it cannot be moved under the plugin namespace.");
                }
                if (!in_array($namespaceRoot, $namespaceRoots, true)) {
                    $namespaceRoots[] = $namespaceRoot;
                }
                foreach ((array) $srcs as $src) {
                    $sourceRoots[] = $path . "/" . $src;
                }
            }
        }

        return [$sourceRoots, $namespaceRoots];
    }

    /**
     * Lists the packages the server provides: the given root packages, or
     * whatever installed package replaces or provides them, and everything
     * they require.
     *
     * @param list<string> $roots
     * @return array<string, true> lower-cased package names
     */
    public static function findServerPackages(string $vendorPath, array $roots) : array {
        $found = [];
        $queue = $roots;
        while ($queue !== []) {
            $name = array_shift($queue);
            if (self::isPlatformPackage($name) || isset($found[strtolower($name)])) {
                continue;
            }
            $found[strtolower($name)] = true;

            $installed = self::findInstalledPackage($vendorPath, $name);
            if ($installed === null) {
                continue;
            }
            [$installedName, $installedPath] = $installed;
            $found[strtolower($installedName)] = true;

            foreach (self::readComposerJson($installedPath)["require"] ?? [] as $depName => $_) {
                $queue[] = (string) $depName;
            }
        }

        return $found;
    }

    private function isServerPackage(string $name) : bool {
        $name = strtolower($name);
        return str_starts_with($name, "pocketmine/") || isset($this->serverPackages[$name]);
    }

    /**
     * Whether any of the paths is the directory, or inside it, or contains it.
     *
     * @param string[] $paths
     */
    private static function overlapsPath(array $paths, string $dir) : bool {
        $wanted = realpath($dir);
        if ($wanted === false) {
            return false;
        }
        foreach ($paths as $candidate) {
            $candidate = realpath($candidate);
            if ($candidate === false) {
                continue;
            }
            if ($candidate === $wanted || str_starts_with($candidate, $wanted . DIRECTORY_SEPARATOR) || str_starts_with($wanted, $candidate . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds the installed package that satisfies a dependency.
     *
     * A package is usually installed at vendor/<name>, but forks such as
     * axolotl-pm/pocketmine-mp are installed under their own name and declare
     * that they replace or provide the original, so Composer's list of
     * installed packages is consulted when that directory is missing.
     *
     * @return array{string, string}|null the installed package name and its directory
     */
    public static function findInstalledPackage(string $vendorPath, string $depName) : ?array {
        if (is_file("$vendorPath/$depName/composer.json")) {
            return [$depName, "$vendorPath/$depName"];
        }

        $installedJson = "$vendorPath/composer/installed.json";
        if (!is_file($installedJson)) {
            return null;
        }
        $installed = json_decode(Files::read($installedJson), true);
        // Composer 2 wraps the list in "packages"; Composer 1 writes a bare list.
        $packages = is_array($installed) ? ($installed["packages"] ?? $installed) : null;
        if (!is_array($packages)) {
            return null;
        }

        $wanted = strtolower($depName);
        foreach ($packages as $package) {
            if (!is_array($package) || !isset($package["name"]) || !is_string($package["name"])) {
                continue;
            }

            $satisfies = [];
            foreach (["replace", "provide"] as $link) {
                if (isset($package[$link]) && is_array($package[$link])) {
                    foreach ($package[$link] as $linkedName => $_) {
                        $satisfies[] = strtolower((string) $linkedName);
                    }
                }
            }
            if (!in_array($wanted, $satisfies, true)) {
                continue;
            }

            $packagePath = isset($package["install-path"]) && is_string($package["install-path"])
                ? "$vendorPath/composer/" . $package["install-path"]
                : "$vendorPath/" . $package["name"];
            if (is_file("$packagePath/composer.json")) {
                return [$package["name"], realpath($packagePath) ?: $packagePath];
            }
        }

        return null;
    }

    private static function isPlatformPackage(string $depName) : bool {
        return preg_match('#^(php|(ext|php|lib|composer)-[^/]+)$#', $depName, $_) === 1;
    }
}
