# Changelog

## 0.4.0-bedrocknexus.1

### Features

- Bundle plain Composer libraries by shading their PSR-4 and PSR-0 namespaces
  under the plugin namespace, like virions
- Never bundle the server: `pocketmine/*`, `pocketmine/pocketmine-mp` or a fork
  that replaces it, and their dependencies
- `extra.pharynx.exclude` in composer.json leaves out more packages
- `-i` uses Composer automatically when the plugin has a composer.json
- Restart with `-dphar.readonly=0` automatically when phar.readonly is on
- Create the folder for the `-p` output if it is missing

### Fixes

- Stop when `composer install` fails instead of building without dependencies
- Clear error for libraries that need `autoload.files` or `autoload.classmap`

## 0.3.9-bedrocknexus.1

### Fixes

- Resolve dependencies through Composer `replace` and `provide`, so forks such
  as axolotl-pm/pocketmine-mp work

## 0.3.2

### Features

- Run `composer install` automaticlaly if `-c` is passed

## 0.3.1

### Fixes

- Handle `${}` curly brace counting correctly

## 0.3.0

### Features

- Virion v3.0 spec support

## 0.2.4

### Fixes

- `-i` now includes the `resources` folder correctly even if current workdir is not in the plugin.
