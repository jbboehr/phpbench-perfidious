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
    nixpkgs.url = "github:nixos/nixpkgs/nixos-24.05";
    nixpkgs-unstable.url = "github:nixos/nixpkgs/nixos-unstable";
    systems.url = "github:nix-systems/default-linux";
    flake-utils = {
      url = "github:numtide/flake-utils";
      inputs.systems.follows = "systems";
    };
    pre-commit-hooks = {
      url = "github:cachix/pre-commit-hooks.nix";
      inputs.nixpkgs.follows = "nixpkgs";
      inputs.nixpkgs-stable.follows = "nixpkgs";
      inputs.gitignore.follows = "gitignore";
    };
    gitignore = {
      url = "github:hercules-ci/gitignore.nix";
      inputs.nixpkgs.follows = "nixpkgs";
    };
    perfidious = {
      url = "github:jbboehr/php-perf";
      inputs.nixpkgs.follows = "nixpkgs";
      inputs.nixpkgs-unstable.follows = "nixpkgs-unstable";
      inputs.systems.follows = "systems";
      inputs.flake-utils.follows = "flake-utils";
    };
  };

  outputs = {
    self,
    nixpkgs,
    nixpkgs-unstable,
    systems,
    flake-utils,
    pre-commit-hooks,
    gitignore,
    perfidious,
  }:
    flake-utils.lib.eachDefaultSystem (system: let
      pkgs = nixpkgs.legacyPackages.${system};
      pkgs-unstable = nixpkgs-unstable.legacyPackages.${system};
      inherit (pkgs) lib;

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

      buildEnv = {
        php,
        perfidious,
        withPcov ? true,
      }:
        php.buildEnv {
          extraConfig = "memory_limit = 2G";
          extensions = {
            enabled,
            all,
          }:
            enabled ++ [perfidious] ++ (lib.optional withPcov all.pcov);
        };

      makeShell = {
        php,
        perfidious,
        withPcov ? true,
      }: let
        php' = buildEnv {inherit php perfidious withPcov;};
      in
        pkgs.mkShell {
          buildInputs = with pkgs; [
            actionlint
            alejandra
            mdl
            php'
            php'.packages.composer
            pre-commit
          ];
          shellHook = ''
            ${pre-commit-check.shellHook}
            export PATH="$PWD/vendor/bin:$PATH"
          '';
        };
    in rec {
      checks = {
        inherit pre-commit-check;
      };

      devShells = rec {
        php81 = makeShell {
          php = pkgs.php81;
          perfidious = perfidious.packages.${system}.php81-gcc;
        };
        php82 = makeShell {
          php = pkgs.php82;
          perfidious = perfidious.packages.${system}.php82-gcc;
        };
        php83 = makeShell {
          php = pkgs.php83;
          perfidious = perfidious.packages.${system}.php83-gcc;
        };
        php84 = makeShell {
          php = pkgs-unstable.php84;
          perfidious = perfidious.packages.${system}.php84-gcc;
          withPcov = false;
        };
        default = php81;
      };

      formatter = pkgs.alejandra;
    });
}
