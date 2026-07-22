[![codecov](https://codecov.io/gh/sitepark/channel-diff/graph/badge.svg)](https://codecov.io/gh/sitepark/channel-diff)
[![Verify](https://github.com/sitepark/channel-diff/actions/workflows/verify.yml/badge.svg)](https://github.com/sitepark/channel-diff/actions/workflows/verify.yml)
![phpstan](https://img.shields.io/badge/PHPStan-level%208-brightgreen)
![php](https://img.shields.io/badge/PHP-8.2-blue)
![php](https://img.shields.io/badge/PHP-8.3-blue)
![php](https://img.shields.io/badge/PHP-8.4-blue)
![php](https://img.shields.io/badge/PHP-8.5-blue)

# channel-diff

A command-line tool to compare two IES publication channels. It loads the PHP
resource files of each channel into their nested arrays and diffs them
field-by-field; binary media files are compared by hash.

Typical use cases: verifying a channel after a re-publish, a publisher change,
or a migration between environments.

## How it works

- Each publication channel is a directory containing SiteKit resource PHP files.
  These are loaded via `atoolo/resource-bundle` into their raw nested arrays and
  compared.
- The three channel layouts are detected automatically:
  - **DOCUMENT_ROOT** — everything in one directory (resources and media
    intermixed); the SiteKit framework subtree (`WEB-IES/`) is excluded.
  - **RESOURCE, URL-based** — separate `objects/` and `media/public/` trees.
  - **RESOURCE, ID-based** — same as above, with an ID-bucketed `objects/` tree.
- Resources and media are **matched by their relative file path**. Volatile
  fields (`version`, `created`, `changed`, `generated`, `changedBy`,
  `cacheInfo`) are ignored by default.

> **Note:** Because matching is by physical path, both channels should use the
> **same layout**. Comparing channels of different layouts will report every
> resource as "only in A / only in B".

## Requirements

- PHP >= 8.2 with the `intl` and `json` extensions
- [Composer](https://getcomposer.org/) 2.x
- [phive](https://phar.io/) (for the QA tools; only needed for `composer test`
  and `composer analyse`)

## Installation

```bash
git clone <repository-url> channel-diff
cd channel-diff
composer install
```

`atoolo/resource-bundle` is resolved from Packagist like any other dependency.

`composer install` runs a `post-install-cmd` that installs the QA PHAR tools
(PHPUnit, PHPStan, PHP-CS-Fixer, composer-normalize) into `tools/` via
[phive](https://phar.io/). phplint is installed as a regular Composer
dev dependency.

Contributors should enable the shared git hooks (Conventional Commit check)
once after cloning:

```bash
git config core.hooksPath .githooks
```

## Usage

```bash
php bin/console channel:diff <channelA> <channelB> [options]
```

`<channelA>` and `<channelB>` are the base directories of the two publication
channels, for example:

- DOCUMENT_ROOT: `.../publications/<host>/www`
- RESOURCE: `.../publications/<host>/www/resources`

### Options

| Option | Description |
| --- | --- |
| `-f`, `--format=console\|json` | Output format (default: `console`). |
| `-i`, `--ignore=<path>` | Additional dot-notation field path to ignore. Supports wildcards (see below). Repeatable. |
| `--ignore-config=<file.php>` | PHP file returning a list of dot-notation field paths to ignore. |
| `--no-media` | Skip the binary media comparison. |
| `--strict-null` | Treat a null field and a missing field as different. By default, a field that is `null` in one channel and absent in the other is considered equal. |
| `--strict-empty-string` | Treat an empty-string field and a missing field as different. By default, a field that is `""` in one channel and absent in the other is considered equal. |
| `--strict-empty-array` | Treat an empty-array field and a missing field as different. By default, a field that is an empty array in one channel and absent in the other is considered equal. Emptiness is recursive: an array whose (nested) values are all empty counts as empty (e.g. `['features' => ['primary' => []]]`). |
| `--strict-uuid-keys` | Treat UUID array keys as significant. By default, an array whose keys are all UUIDs is matched by the content of its values instead of by the (volatile, regenerated) keys, and inner values equal to the key (e.g. a mirrored `id`) are neutralized so entries pair up. |

### Ignore path wildcards

Ignore paths may contain wildcard segments:

- `*` matches exactly one key segment. A `*` in the middle of a path is a field
  ignore, e.g. `a.b.*.id` ignores the `id` field of every child of `a.b`.
- `**` matches any number of segments (including none), so a structure that
  occurs at many depths can be addressed once, e.g. `**.geo.features.*`.
- A **terminal** `*`, e.g. `**.geo.features.*`, is a *key-normalization* marker:
  the matched array is compared by the content of its values, not by their keys
  (useful for arrays keyed by volatile values, when UUID auto-detection does not
  apply).

### Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Channels are identical. |
| `1` | Differences were found. |
| `2` | An error occurred (e.g. an invalid path). |

### Examples

Compare two channels with human-readable output:

```bash
php bin/console channel:diff \
    ~/ies-environments/site/data/publications/host/www/resources \
    ~/ies-environments/site/data/publications/host/preview/resources
```

Produce a machine-readable report for CI:

```bash
php bin/console channel:diff /path/to/A /path/to/B --format=json > diff.json
```

Ignore additional fields, both inline and via a config file:

```bash
php bin/console channel:diff /path/to/A /path/to/B \
    --ignore=ies \
    --ignore=base.germanCourse.venue.link \
    --ignore-config=ignore.php
```

An `ignore.php` config file simply returns a list of field paths:

```php
<?php

return [
    'ies',
    'base.germanCourse.venue.addressData.geo',
];
```

## Notes on comparison

- Nested arrays are compared recursively; differences are reported with a
  dot-notation path (e.g. `base.metadata.headline`).
- A field that is `null` in one channel and missing in the other is treated as
  equal by default; use `--strict-null` to report it as a difference. The same
  applies to an empty string (`""`) versus a missing field
  (`--strict-empty-string`) and an empty array (`[]`) versus a missing field
  (`--strict-empty-array`).
- Arrays keyed by UUIDs (which IES regenerates on every publish) are matched by
  the content of their values by default, so the changing keys do not show up as
  differences; use `--strict-uuid-keys` to compare them by key instead.
- PHP objects (e.g. `stdClass`) are compared structurally, not by instance.
- Closures (e.g. from "code" content sections) are compared by their source
  code, not by instance.
- In the JSON output, field values are rendered as bounded single-line strings;
  closures and objects are shown as `<closure>` / `<object:Class>`.

## Development

```bash
composer test           # run the PHPUnit test suite (with coverage)
composer analyse        # phplint, PHPStan, PHP-CS-Fixer (check), PHP compatibility
composer fix            # apply PHP-CS-Fixer fixes
composer test:infection # run mutation testing (Infection)
```
