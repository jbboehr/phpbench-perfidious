# Repository guidance

## Release follow-up

- The README intentionally installs `jbboehr/phpbench-perfidious:dev-master` while Packagist has no tagged release.
  After the first tagged release is published to Packagist, remove the `:dev-master` constraint and this reminder.

## Nix maintenance

- All PHP development packages share one Composer vendor derivation generated with PHP 8.1.
- After changing `composer.lock`, temporarily set the derivation's `vendorHash` in `flake.nix` to `lib.fakeHash`, build
  any package, copy the `got: sha256-...` value from Nix's expected hash-mismatch error, replace `lib.fakeHash` with
  that value, and rebuild. Do not add generated Nix files or per-PHP vendor hashes.
