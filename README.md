# phpbench-perfidious

[PHPBench](https://github.com/phpbench/phpbench) extension for Linux that measures benchmark iterations with hardware
and software performance counters (instructions, cycles, cache misses, page faults, context switches, ...) via
[`ext-perfidious`](https://github.com/jbboehr/php-perfidious), instead of (or alongside) wall-clock time.

It provides:

- a benchmark **executor** (`perfidious`) that wraps each variant's revolutions in a `perf_event_open` handle, records
  the counter values, and supplies PHPBench timing data when a supported clock event is configured;
- a second **executor** (`perfidious-remote`) that does the same thing but in an isolated subprocess per variant,
  like PHPBench's own built-in `remote` executor — see [Remote execution](#remote-execution) below;
- a **progress logger** (`perfidious`) that adds an instructions-per-iteration summary to PHPBench's verbose progress
  output;
- a **report generator** (`perfidious`) that renders the raw and per-revolution-normalized counter values in a table.

## Requirements

- Linux with the `perf_events` kernel API available
- PHP 8.1+ (tested on PHP 8.1–8.5)
- [phpbench/phpbench](https://github.com/phpbench/phpbench) ^1.4
- the [`perfidious`](https://github.com/jbboehr/php-perfidious) PHP extension, loaded and enabled (see
  [Installation](#installation) below)

## Installation

```shell
composer require --dev jbboehr/phpbench-perfidious:dev-master
```

Until the first tagged release, the explicit `dev-master` constraint is required because Packagist only has development
versions of the package.

This package does not itself bundle the `perfidious` PHP extension — it's declared as a `suggest`, not a hard
dependency, since PHPBench doesn't require it. Follow php-perfidious's own
[Installation instructions](https://github.com/jbboehr/php-perfidious#installation) to build and enable it (Ubuntu/
Debian build deps, `phpize`/`configure`/`make`, then adding `extension=perfidious.so` to your *php.ini*). If you're
already in a Nix environment, this repo's own [`flake.nix`](flake.nix) devShells (`nix develop .#php82`, etc.) give
you a PHP build with the extension already compiled in.

If the extension isn't loaded, selecting the `perfidious` executor or calling anything under the `Perfidious\`
namespace will fail with a PHP fatal error (`Call to undefined function Perfidious\open()`) rather than a friendly
message — check `php -m | grep perfidious` first if you hit that.

## Quick start

In an existing PHPBench project, merge these keys into `phpbench.json`. The `runner.bootstrap` and `runner.path` values
are standard PHPBench settings; adjust them to match your project:

```json
{
    "runner.bootstrap": "vendor/autoload.php",
    "runner.path": "tests/Benchmark",
    "runner.executor": "perfidious",
    "core.extensions": [
        "jbboehr\\PhpBenchPerfidious\\PerfidiousExtension"
    ],
    "report.generators": {
        "perfidious": {
            "generator": "perfidious"
        }
    },
    "perfidious.metrics": [
        "perf::PERF_COUNT_SW_CPU_CLOCK",
        "perf::PERF_COUNT_HW_INSTRUCTIONS"
    ]
}
```

- `perfidious.metrics` accepts any event name understood by `Perfidious\open()` (see the `ext-perfidious` README for
  the full list, or `phpbench.json`'s own schema for the format). Defaults to
  `perf::PERF_COUNT_SW_CPU_CLOCK` and `perf::PERF_COUNT_HW_INSTRUCTIONS` if omitted.
- Hardware counters (anything under `perf::PERF_COUNT_HW_*`) require host access to the CPU's performance-monitoring
  unit. They are commonly unavailable in containers and nested/cloud CI runners; meaningful hardware-counter
  benchmarks must run on a PMU-enabled host.
- Set `"runner.progress": "perfidious"` to add the instructions-per-iteration progress summary. This logger requires
  `perf::PERF_COUNT_HW_INSTRUCTIONS` to be present in `perfidious.metrics`.

GitHub-hosted runners do not expose the hardware PMU events used in real benchmarks. This repository's end-to-end CI
test therefore falls back to `perf::PERF_COUNT_SW_CPU_CLOCK` and disables the instruction-specific progress logger. CI
verifies executor integration only; it does not validate hardware events.

Then run PHPBench and request the `perfidious` report:

```shell
vendor/bin/phpbench run --report=perfidious
```

Here's real output from running this repo's own `phpbench.json` against its `tests/Benchmark/SieveBench` fixture, with
rows and additional metric columns omitted for brevity. Every configured metric gets its own column, normalized per
revolution:

```text
+------+------------+-------------+------+-------------------------------+----------------------------------+
| iter | benchmark  | subject     | revs | perf__PERF_COUNT_SW_CPU_CLOCK | perf__PERF_COUNT_HW_INSTRUCTIONS |
+------+------------+-------------+------+-------------------------------+----------------------------------+
| 0    | SieveBench | benchArray  | 5    | 19285783.2                    | 394889008                        |
| 1    | SieveBench | benchArray  | 5    | 19460101.6                    | 394851491                        |
| ...  | ...        | ...         | ...  | ...                           | ...                              |
| 0    | SieveBench | benchString | 5    | 19313561.4                    | 410109529.8                      |
| 1    | SieveBench | benchString | 5    | 18638577                      | 410109435.4                      |
| ...  | ...        | ...         | ...  | ...                           | ...                              |
+------+------------+-------------+------+-------------------------------+----------------------------------+
```

Each metric column is normalized per revolution (`raw_count * timeEnabled / timeRunning / revolutions`); the
unnormalized raw counter total for a metric is also available under a `<metric>_raw` key, which the default
`perfidious` report generator omits for readability.

## Remote execution

The default `perfidious` executor runs every benchmark iteration, across every subject in the whole
`phpbench run`, inside a single long-lived PHP process — there's no isolation between benchmarks (opcache/JIT
warmup and memory state carry over from one subject to the next).

`perfidious-remote` runs each variant in its own freshly spawned subprocess instead — the same approach PHPBench's
own built-in `remote` executor uses — while still collecting perf counters (each subprocess opens its own handle).
This is opt-in; it isn't PHPBench's builtin default, and this repo's own `phpbench.json` doesn't default to it either.
Define a profile that selects it reliably:

```json
{
    "core.profiles": {
        "perfidious-remote": {
            "runner.executor": "perfidious-remote"
        }
    }
}
```

```shell
vendor/bin/phpbench run --profile=perfidious-remote --report=perfidious
```

Use the profile when `runner.executor` is already set: PHPBench 1.x's configuration precedence can otherwise
retain that configured executor when `--executor` is passed directly. Alternatively, set
`"runner.executor": "perfidious-remote"` in your own `phpbench.json`. It uses the same `perfidious.metrics`
configuration key as the in-process executor.

Trade-offs versus `perfidious`:

- adds a real `MemoryResult` (memory usage per variant) — meaningless for the in-process executor, since memory
  would accumulate across every subject in the run, but meaningful here since each variant gets a fresh process;
- has subprocess-spawn overhead per variant, so wall-clock-sensitive comparisons against `perfidious` aren't
  apples-to-apples;
- like the in-process executor, only produces a `TimeResult` if `perfidious.metrics` includes a recognized
  time-counter event (e.g. the default `perf::PERF_COUNT_SW_CPU_CLOCK`) — if you configure only, say,
  `perf::PERF_COUNT_HW_INSTRUCTIONS`, no `TimeResult` is emitted and PHPBench's built-in stats/aggregate reporting
  won't have timing data to work with.

## Nix development packages

The flake exposes development packages for every supported PHP version: `nix build .#php81` through
`nix build .#php85` (with PHP 8.2 at `.#default`). These outputs intentionally include Composer development
dependencies and run the PHPUnit suite while building; they are CI and development artifacts, not minimal runtime
packages.

## License

phpbench-perfidious is licensed under the **GNU Affero General Public License version 3 with the Romic Exception**:

```text
AGPL-3.0-only WITH romic-exception
```

The Romic Exception permits phpbench-perfidious to be linked or combined with other code without subjecting that other
code to the AGPL merely because of the linking or combination. Modifications to the covered project remain subject to
the Project License, including its source-availability requirements for modified versions made available over a
computer network.

See [LICENSE.md](LICENSE.md) and [docs/LICENSE_EXCEPTION.md](docs/LICENSE_EXCEPTION.md) for the complete terms.

Contributions are accepted under the terms in [CONTRIBUTING.md](CONTRIBUTING.md). Unless a contributor elects the CLA
route, each contribution is offered under `AGPL-3.0-only WITH romic-exception OR Apache-2.0`, at each recipient's
option, while the public project incorporates it under the Project License. The Apache-2.0 alternative applies only to
the contributor-authored portions and does not make the project as a whole available under Apache-2.0.

A contributor may instead elect [the CLA](docs/CLA-v1.md), keeping the contribution publicly under the Project License
while granting the [Project Steward](docs/STEWARD.md) the additional rights specified there.

Alternative commercial licenses may be available from the Project Steward. Contact John Boehr at `jbboehr@gmail.com`.
