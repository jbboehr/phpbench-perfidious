{
  lib,
  src,
  version,
  php,
  perfidious,
  composerVendor,
}: let
  phpEnv = php.buildEnv {
    extraConfig = "memory_limit = 2G";
    extensions = {enabled, ...}:
      enabled ++ [perfidious];
  };
in
  php.buildComposerProject2 (finalAttrs: {
    pname = "phpbench-perfidious";
    inherit src version composerVendor;

    php = phpEnv;
    composer = php.packages.composer;
    composerNoDev = false;
    composerNoPlugins = true;
    composerNoScripts = true;

    checkPhase = ''
      runHook preCheck

      php --ri perfidious
      php vendor/bin/phpunit --no-coverage

      runHook postCheck
    '';

    meta = {
      description = "PHPBench integration for perf_event_open performance counters";
      homepage = "https://github.com/jbboehr/phpbench-perfidious";
      license = {
        fullName = "GNU Affero General Public License v3.0 only with the Romic Exception";
        spdxId = "AGPL-3.0-only WITH romic-exception";
        url = "https://github.com/jbboehr/phpbench-perfidious/blob/master/LICENSE.md";
        free = true;
      };
      platforms = lib.platforms.linux;
    };
  })
