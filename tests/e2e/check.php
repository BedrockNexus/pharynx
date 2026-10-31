<?php

declare(strict_types=1);

// Loads the plugin built from tests/e2e/plugin the way PocketMine does
// (classes looked up by namespace under the phar's src folder) and checks
// what pharynx bundled.

$pharPath = $argv[1] ?? "";
if (!is_file($pharPath)) {
    fwrite(STDERR, "Usage: php check.php path/to/plugin.phar\n");
    exit(1);
}

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures) : void {
    echo ($ok ? "ok   " : "FAIL ") . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$phar = new Phar($pharPath);
$files = [];
foreach (new RecursiveIteratorIterator($phar) as $file) {
    $path = str_replace("\\", "/", (string) $file->getPathname());
    $files[] = substr($path, strpos($path, ".phar/") + 6);
}

$check(in_array("plugin.yml", $files, true), "plugin.yml is in the phar");
$check(in_array("src/PharynxE2E/Plugin/Main.php", $files, true), "the plugin's own classes are in the phar");
$shaded = static fn(string $library) : bool => count(array_filter($files, static fn(string $path) : bool => preg_match("#^src/PharynxE2E/Plugin/libs/_[0-9a-f]+/" . preg_quote($library, "#") . "/#", $path) === 1)) > 0;
$check($shaded("Doctrine/Inflector"), "doctrine/inflector is bundled under the plugin namespace");
$check($shaded("Webmozart/Assert"), "webmozart/assert is bundled under the plugin namespace");
$check($shaded("dktapps/pmforms"), "the pmforms virion is bundled under the plugin namespace");
$leaked = array_filter($files, static fn(string $path) : bool => preg_match("#/pocketmine/|/Brick/|^src/(Doctrine|Webmozart|dktapps)/#", $path) === 1);
$check($leaked === [], "nothing from the server or unshaded libraries is bundled" . ($leaked === [] ? "" : ": " . implode(", ", array_slice($leaked, 0, 5))));

$src = "phar://" . realpath($pharPath) . "/src/";
spl_autoload_register(static function (string $class) use ($src) : void {
    $file = $src . str_replace("\\", "/", $class) . ".php";
    if (is_file($file)) {
        require $file;
    }
});

$main = new PharynxE2E\Plugin\Main();
$check($main->pluralize("form") === "forms", "bundled libraries load and run");
[$form, $pluginBase, $bigInteger] = $main->referencedClasses();
$check(str_contains($form, "\\libs\\_") && str_ends_with($form, "\\dktapps\\pmforms\\MenuForm"), "references to the virion are rewritten ($form)");
$check($pluginBase === "pocketmine\\plugin\\PluginBase", "references to the server are left alone ($pluginBase)");
$check($bigInteger === "Brick\\Math\\BigInteger", "references to libraries the server provides are left alone ($bigInteger)");
$check(!class_exists("Doctrine\\Inflector\\InflectorFactory", false), "the original library namespace is not loaded");

if ($failures !== []) {
    echo count($failures) . " check(s) failed\n";
    exit(1);
}
echo "All checks passed\n";
