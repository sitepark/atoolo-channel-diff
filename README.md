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
php bin/console channel:diff <channelA> <channelB> [subPath] [options]
```

### Arguments

| Argument | Description |
| --- | --- |
| `<channelA>`, `<channelB>` | Base directories of the two publication channels (required). |
| `[subPath]` | Restrict the comparison to this sub directory of both channels (optional; default: the whole channel). |

The channel base directories are, for example:

- DOCUMENT_ROOT: `.../publications/<host>/www`
- RESOURCE: `.../publications/<host>/www/resources`

#### Restricting the comparison to a sub directory

`[subPath]` is resolved against the **channel base directory**, so one path
expression addresses the resource tree and the media tree alike — even though
those are separate sub trees in the RESOURCE layout. A sub path that lies
outside one of the two trees simply excludes that tree from the comparison.

For a RESOURCE-layout channel (`resourceDir` = `<base>/objects`, `mediaDir` =
`<base>/media/public`):

| `subPath` | Resources compared | Media compared |
| --- | --- | --- |
| *(omitted)* | all | all |
| `objects` | all | none |
| `objects/de/produkte` | `objects/de/produkte` only | none |
| `media/public` | none | all |
| `media/public/img` | none | `media/public/img` only |

In the DOCUMENT_ROOT layout, resources and media share one directory, so a sub
path narrows both at once.

Only the given sub tree is walked, which also makes a scoped run considerably
faster than a full channel comparison.

A sub path that exists in **neither** channel is an error (exit code `2`) — it is
almost always a typo, and reporting it as "identical" would be misleading. A sub
path that exists in only one of the two channels is a real difference and is
reported as such. Sub paths must stay inside the channel; `..` segments are
rejected.

### Options

| Option | Description |
| --- | --- |
| `-f`, `--format=console\|json` | Output format (default: `console`). |
| `-i`, `--ignore=<path>` | Additional dot-notation field path to ignore. Supports wildcards (see below). Repeatable. |
| `--ignore-config=<file.php>` | PHP file returning a list of dot-notation field paths to ignore. |
| `--exclude-resource=<path>` | Resource to leave out of the comparison entirely, together with its media. Supports wildcards (see below). Repeatable. |
| `--rules=<file.yaml>` | Rule file with accepted differences. Overrides the automatic lookup (see below). |
| `--no-rules` | Ignore any rule file found next to the channels. |
| `--float-precision=<n>` | Number of decimal places at which two floats still count as equal. Overrides `floatPrecision` from the rule file. |
| `--numeric-strings` | Treat a numeric string and the same number as equal (`"600"` and `600`). Off by default; also available as `numericStringsEqualNumbers` in the rule file. |
| `--no-media` | Skip the binary media comparison. |
| `--strict-null` | Treat a null field and a missing field as different. By default, a field that is `null` in one channel and absent in the other is considered equal. |
| `--strict-empty-string` | Treat an empty-string field and a missing field as different. By default, a field that is `""` in one channel and absent in the other is considered equal. |
| `--strict-empty-array` | Treat an empty-array field and a missing field as different. By default, a field that is an empty array in one channel and absent in the other is considered equal. Emptiness is recursive: an array whose (nested) values are all empty counts as empty (e.g. `['features' => ['primary' => []]]`). |
| `--strict-uuid-keys` | Treat UUID array keys as significant. By default, an array whose keys are all UUIDs is matched by the content of its values instead of by the (volatile, regenerated) keys, and inner values equal to the key (e.g. a mirrored `id`) are neutralized so entries pair up. |

### Rule file: recording accepted differences

Differences that have been reviewed and are known to be acceptable can be
recorded in a rule file, so they stop showing up in every subsequent run. Place a
`channel-diff.yaml` next to the channels:

```yaml
# .../publications/<host>/www/channel-diff.yaml

excludes:
  # The new publisher writes an explicit "static: false" per source.
  - '**.sources.*.static'

excludeResources:
  # The old publisher cannot build this page at all, so there is nothing to
  # compare - see "Excluding whole resources" below.
  - 'testseiten/aggregator-test.php'

# Focalpoints are stored with 8 decimals, but the 8th digit is not always a
# correct rounding of the previous value.
floatPrecision: 7

# One channel writes a height as "600", the other as 600.
numericStringsEqualNumbers: true
```

The file is looked up from each channel's base directory **upwards**, so a single
file above both channels covers both — for example one `www/channel-diff.yaml`
for `www/resources` and `www/resources.old`. The nearest file wins; `.yml` is
accepted as well. The report lists which rule file was applied, so it is always
visible why a difference is missing.

Use `--rules=<file>` to point at a specific file instead, or `--no-rules` to
ignore a discovered one for a single run.

| Key | Meaning |
| --- | --- |
| `excludes` | List of dot-notation field paths to exclude, with the same wildcards as `--ignore` (see below). |
| `excludeResources` | List of slash-notation resource paths to leave out of the comparison entirely, together with their media. See [Excluding whole resources](#excluding-whole-resources). |
| `floatPrecision` | Number of decimal places at which two floats still count as equal. Omit it to compare floats strictly. |
| `numericStringsEqualNumbers` | `true` accepts a number written as a string on one side and as a number on the other. Omit it, or set `false`, to keep the strict comparison. |

`floatPrecision: n` is applied as a tolerance of `0.5 × 10⁻ⁿ` rather than by
rounding both sides, so two values that happen to straddle a rounding boundary
are not reported as a difference. Note that this makes the effective precision
one place coarser than the number of digits you see: values written with 8
decimals whose last digit is not a faithful rounding of the original need
`floatPrecision: 7`. Only float-to-float pairs use the tolerance — a float
against an int or a numeric string stays a difference, since that is a type
change rather than a precision issue.

`numericStringsEqualNumbers: true` is what accepts that last case, where it has
been reviewed and decided. It is **not** an exclude and differs from one in
kind: no field is blinded, so a value that really changed is still reported —
only the notation may differ. It is deliberately narrow. Against an integer
only the exact decimal form counts: `"600"` equals `600`, while `"1e3"` does
not equal `1000`, because casting both sides would lose precision above 2⁵³ and
could call two different ids equal. Against a float the configured
`floatPrecision` tolerance applies, since such a pair is a precision question
as well as a notation one. Two strings are never compared this way.

Reach for it where two publishers disagree about notation rather than value,
and where the consumer does not care — typically because it declares the type
it wants. Prefer it to an `excludes` entry in that case: an exclude takes the
field's value *and* its presence out of the comparison for good, and is a trap
for the next real defect on that path.

Rule files are parsed as data and never executed, unlike `--ignore-config`.

### Excluding whole resources

`excludes` drops a *field* out of a resource that is still compared.
`excludeResources` is different in kind: it drops the *resource*, in both
channels, so it is neither counted as identical nor reported as a difference.

That is for the case where one channel cannot produce a page at all, and no
field comparison is therefore possible — a publisher that does not know a class
the other one uses, for instance, writes no page, and the page is one-sided by
construction. Accepting that as a difference on every run would bury the real
ones.

```yaml
excludeResources:
  - 'testseiten/aggregator-test.php'
```

Paths are matched against the resource key the report shows, which is the path
relative to the channel's resource directory:

| Pattern | Meaning |
| --- | --- |
| `page.php` | exactly that resource |
| `Aggregator-Test-*.php` | `*` matches within one path segment |
| `page?.php` | `?` matches one character within a segment |
| `testseiten/**` | `**` matches any number of segments, so the whole sub tree |
| `**/index.php` | every `index.php`, at any depth — `**` also matches no segment at all |

A pattern matches a whole key, not a prefix: `testseiten` alone does **not**
cover `testseiten/page.php`; write `testseiten/**` for that.

**Media follow their resource.** IES publishes a resource's media into a sidecar
directory named after it, `<resource>.media/…`. Excluding a resource excludes
those files too — otherwise every rendition of an excluded page would still be
reported as one-sided. A pattern is matched against media keys directly as well,
so `img/**` excludes a binary sub tree on its own.

The report names every pattern with what it actually removed, and warns about
one that removed nothing:

```
 * Excluded resource: testseiten/aggregator-test.php (1 resource, 5 media)
 * Excluded resource: leftover/old.php (no match)
```

A rule that matches nothing hides nothing today, but it still hides the next
difference that appears on that path — which is why it is worth removing rather
than keeping "just in case". The summary reports `Resources excluded` and
`Media excluded` as their own numbers, so the entries never disappear silently.

`--exclude-resource=<path>` adds a pattern for a single run, on top of whatever
the rule file says.

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
| `2` | An error occurred (e.g. an invalid channel path, or a `subPath` that exists in neither channel). |

### Examples

Compare two channels with human-readable output:

```bash
php bin/console channel:diff \
    ~/ies-environments/site/data/publications/host/www/resources \
    ~/ies-environments/site/data/publications/host/preview/resources
```

Compare only one sub directory — here the German product pages, which also skips
the media comparison entirely:

```bash
php bin/console channel:diff /path/to/A /path/to/B objects/de/produkte
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

Accept the known differences of a re-publish via a rule file, and check what is
left:

```bash
cat > /path/to/www/channel-diff.yaml <<'YAML'
excludes:
  - '**.sources.*.static'
floatPrecision: 7
YAML

php bin/console channel:diff \
    /path/to/www/resources.old \
    /path/to/www/resources \
    objects/testseiten
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
- Floats are compared strictly unless a `floatPrecision` is configured; see
  [Rule file](#rule-file-recording-accepted-differences).
- A numeric string and the same number (`"600"` and `600`) count as different
  unless `numericStringsEqualNumbers` is set; see
  [Rule file](#rule-file-recording-accepted-differences).
- A resource named by `excludeResources` is not compared at all, in either
  channel, and neither are the media in its `.media` sidecar; see
  [Excluding whole resources](#excluding-whole-resources).
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
