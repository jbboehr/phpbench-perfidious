# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Added common sampler support to the `perfidious` report, with aligned columns for mixed metric sets and Linux results.
- Registered the common sampler executor as `perfidious`, with `perfidious.metrics` configuration and class hook support.
- Added a `perfidious` profile that uses PHPBench's standard progress output.
- Added the in-process `SamplerExecutor`, which returns sampler metrics and elapsed wall time for PHPBench iterations.
- Added `Sampler\NativeSampler`, a `SamplerInterface` adapter over ext-perfidious 0.3.1's common sampler API.

### Changed

- Allowed `SamplerInterface::measure()` callbacks to return values, which are ignored.
- Renamed the existing Linux executor from `perfidious` to `perfidious-linux`, the remote executor from
  `perfidious-remote` to `perfidious-linux-remote`, and the progress logger from `perfidious` to `perfidious-linux`.
- Renamed the Linux perf event configuration key from `perfidious.metrics` to `perfidious.linux.metrics`. The
  `perfidious` report generator and result key are unchanged.

### Deprecated

### Removed

### Fixed

### Security
