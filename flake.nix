# Copyright (c) anno Domini nostri Jesu Christi MMXXIV John Boehr & contributors
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.
{
  description = "jbboehr/phpbench-perfidious";

  inputs = {
    nixpkgs.url = "github:nixos/nixpkgs/nixos-23.11";
    flake-utils = {
      url = "github:numtide/flake-utils";
    };
    pre-commit-hooks = {
      url = "github:cachix/pre-commit-hooks.nix";
      inputs.nixpkgs.follows = "nixpkgs";
    };
    gitignore = {
      url = "github:hercules-ci/gitignore.nix";
      inputs.nixpkgs.follows = "nixpkgs";
    };
    perfidious = {
      url = "github:jbboehr/php-perf";
      inputs.nixpkgs.follows = "nixpkgs";
    };
  };

  outputs = {
    self,
    nixpkgs,
    flake-utils,
    pre-commit-hooks,
    gitignore,
    perfidious,
    ...
  }:
    flake-utils.lib.eachDefaultSystem (system: let
      buildEnv = php:
        php.buildEnv {
          extraConfig = "memory_limit = 2G";
          extensions = {
            enabled,
            all,
          }:
            enabled ++ [all.pcov perfidious.packages.${system}.php81-gcc];
        };
      pkgs = nixpkgs.legacyPackages.${system};
      php = buildEnv pkgs.php81;
      src = gitignore.lib.gitignoreSource ./.;

      pre-commit-check = pre-commit-hooks.lib.${system}.run {
        inherit src;
        hooks = {
          actionlint.enable = true;
          alejandra.enable = true;
          alejandra.excludes = ["\/vendor\/"];
          shellcheck.enable = true;
        };
      };
    in rec {
      checks = {
        inherit pre-commit-check;
      };

      devShells.default = pkgs.mkShell {
        buildInputs = with pkgs; [
          actionlint
          alejandra
          mdl
          php
          php.packages.composer
          pre-commit
        ];
        shellHook = ''
          ${pre-commit-check.shellHook}
          export PATH="$PWD/vendor/bin:$PATH"
        '';
      };

      formatter = pkgs.alejandra;
    });
}
