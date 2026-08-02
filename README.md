
# phpbench-perfidious

[PHPBench](https://github.com/phpbench/phpbench) extension that measures benchmark iterations with hardware and
software performance counters (instructions, cycles, cache misses, page faults, context switches, ...) via
[`ext-perfidious`](https://github.com/jbboehr/php-perfidious), instead of (or alongside) wall-clock time.

It provides:

- a benchmark **executor** (`perfidious`) that wraps each variant's revolutions in a `perf_event_open` handle and
  attaches the counter values as an extra result alongside the normal time/memory results;
- a **progress logger** (`perfidious`) that adds an instructions-per-iteration summary to PHPBench's verbose progress
  output;
- a **report generator** (`perfidious`) that renders the raw and per-revolution-normalized counter values in a table.

## Installation

```shell
composer require --dev jbboehr/phpbench-perfidious
```

This package requires the [`perfidious`](https://github.com/jbboehr/php-perfidious) PHP extension to be installed and
enabled; it is declared as a `suggest` rather than a hard dependency since PHPBench itself doesn't require it.

## Configuration

Enable the extension and its executor/progress logger/report generator in `phpbench.json`:

```json
{
    "runner.executor": "perfidious",
    "runner.progress": "perfidious",
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
        "perf::PERF_COUNT_HW_INSTRUCTIONS",
        "perf::PERF_COUNT_SW_PAGE_FAULTS",
        "perf::PERF_COUNT_SW_CONTEXT_SWITCHES"
    ]
}
```

- `perfidious.metrics` accepts any event name understood by `Perfidious\open()` (see the `ext-perfidious` README for
  the full list, or `phpbench.json`'s own schema for the format). Defaults to
  `perf::PERF_COUNT_SW_CPU_CLOCK` and `perf::PERF_COUNT_HW_INSTRUCTIONS` if omitted.
- Hardware counters (anything under `perf::PERF_COUNT_HW_*`) require host access to the CPU's performance-monitoring
  unit. They are commonly unavailable in containers and nested/cloud CI runners — prefer the `perf::PERF_COUNT_SW_*`
  software events there.

Then run PHPBench and request the `perfidious` report, e.g. `phpbench run --report=perfidious`:

```text
+------+------------+-------------+------+-------------------------------+----------------------------------+
| iter | benchmark  | subject     | revs | perf__PERF_COUNT_SW_CPU_CLOCK | perf__PERF_COUNT_HW_INSTRUCTIONS |
+------+------------+-------------+------+-------------------------------+----------------------------------+
| 0    | SieveBench | benchArray  | 5    | 19285783.2                    | 394889008                        |
| 1    | SieveBench | benchArray  | 5    | 19460101.6                    | 394851491                        |
+------+------------+-------------+------+-------------------------------+----------------------------------+
```

Each metric column is normalized per revolution (`raw_count * timeEnabled / timeRunning / revolutions`); the
unnormalized raw counter total for a metric is also available under a `<metric>_raw` key, which the default
`perfidious` report generator omits for readability.

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
