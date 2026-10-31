# pharynx

A tool to recompile PHP sources into a phar in PSR-0

```txt
USAGE
  -v           : Enable verbose output.
  -i PATH      : Equivalent to `-f plugin.yml:PATH/plugin.yml -f PATH/resources -s PATH/src`.
  -f NAME:PATH : Copy the file or directory at PATH to output/NAME.
                 `:` is not considered as a separator if immediately followed by a backslash.
                 Can be passed multiple times.
  -s PATH      : Use the directory at PATH as a source root.
                 Can be passed multiple times
  -r NAME      : The path of the source root in the output.
                 Default `src`.
  -o PATH      : Store the output in directory form at PATH.
  -p[=PATH]    : Pack the output in phar form at PATH.
                 If no value is given, uses the path in `-o` followed by `.phar`.
                 If neither -o nor -p are passed, or only `-p` is passed but without values,
                 `-p output.phar` is assumed.
  -a ANTIGEN   : The virion antigens to shade.
                 Can be passed multiple times
  -c[=PATH]    : Run `composer install` and bundle the Composer dependencies.
                 Automatic when the `-i` directory contains composer.json.
                 If a value is not provided, uses the same path as `-i`.
                 Otherwise, PATH should be the path to the directory containing composer.json.
                 Virions and plain libraries are shaded under the plugin namespace.
                 pocketmine/* packages, pocketmine/pocketmine-mp (or a fork that replaces it)
                 and their dependencies come from the server and are not bundled.
                 List more packages to leave out under extra.pharynx.exclude in composer.json.

EXAMPLES
  Package a plugin phar, including its Composer dependencies:
  $ php pharynx.phar -i path/to/your/plugin -p=my-plugin.phar

  Bundle output to a directory without building phar
  $ php pharynx.phar -i path/to/your/plugin -o output

  Package a plugin phar to output.phar, along with generated files in a gen directory:
  $ php pharynx.phar -i path/to/your/plugin -s path/to/gen

```

## Composer dependencies

When the plugin folder has a `composer.json`, pharynx runs `composer install`
and bundles every package in `require` (not `require-dev`):

- **Virions** (packages with `extra.virion`) are shaded under the plugin
  namespace, as before.
- **Plain libraries** are shaded the same way, using the namespaces from their
  PSR-4 or PSR-0 autoload. PocketMine only loads classes by namespace from a
  plugin, so libraries that need `autoload.files` or `autoload.classmap` stop
  the build with an error instead of producing a broken phar. Class names that
  a library builds from strings at runtime are not rewritten.
- **The server** is never bundled: `pocketmine/*` packages,
  `pocketmine/pocketmine-mp` or any fork that replaces or provides it, and
  everything they require. To leave out other packages the server already has:

```json
{
    "extra": {
        "pharynx": {
            "exclude": ["vendor/package"]
        }
    }
}
```

pharynx turns off `phar.readonly` by restarting itself with
`-dphar.readonly=0` when needed, so `php pharynx.phar ...` is enough.

## Use with PocketMine plugins

Download start.cmd/start.sh, pharynx.phar and bootstrap-plugin-dev.php from [releases](https://github.com/SOF3/pharynx/releases)
and copy them to your PocketMine install directory.
Replace PocketMine's start.cmd/start.sh with the one you downloaded.
Edit start.sh/start.cmd and change `"plugin_source/MyPlugin"` to the plugin(s) to build with pharynx
(only start.sh supports multiple plugins).
Then just start the server as usual!
