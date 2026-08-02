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
    nixpkgs.url = "github:nixos/nixpkgs/nixos-26.05";
    nix-phps = {
      url = "github:fossar/nix-phps";
      inputs.nixpkgs.follows = "nixpkgs";
    };
    systems.url = "github:nix-systems/default-linux";
    flake-utils = {
      url = "github:numtide/flake-utils";
      inputs.systems.follows = "systems";
    };
    pre-commit-hooks = {
      url = "github:cachix/pre-commit-hooks.nix";
      inputs.nixpkgs.follows = "nixpkgs";
    };
    gitignore = {
      url = "github:hercules-ci/gitignore.nix";
      inputs.nixpkgs.follows = "nixpkgs";
    };
    nix-github-actions = {
      url = "github:nix-community/nix-github-actions";
      inputs.nixpkgs.follows = "nixpkgs";
    };
    perfidious = {
      url = "github:jbboehr/php-perfidious";
      inputs.nixpkgs.follows = "nixpkgs";
      inputs.systems.follows = "systems";
      inputs.flake-utils.follows = "flake-utils";
    };
  };

  outputs = {
    self,
    nixpkgs,
    nix-phps,
    systems,
    flake-utils,
    pre-commit-hooks,
    gitignore,
    nix-github-actions,
    perfidious,
  }:
    (flake-utils.lib.eachDefaultSystem (system: let
      pkgs = nixpkgs.legacyPackages.${system};
      php-phps = nix-phps.packages.${system};
      inherit (pkgs) lib;

      src = gitignore.lib.gitignoreSource ./.;

      version = "0.0.0-dev";

      phpVersions = {
        php81 = {
          php = php-phps.php81;
          perfidious = perfidious.packages.${system}.php81-gcc;
        };
        php82 = {
          php = pkgs.php82;
          perfidious = perfidious.packages.${system}.php82-gcc;
        };
        php83 = {
          php = pkgs.php83;
          perfidious = perfidious.packages.${system}.php83-gcc;
        };
        php84 = {
          php = pkgs.php84;
          perfidious = perfidious.packages.${system}.php84-gcc;
        };
        php85 = {
          php = pkgs.php85;
          perfidious = perfidious.packages.${system}.php85-gcc;
        };
      };

      composerVendor = php-phps.php81.mkComposerVendor {
        pname = "phpbench-perfidious";
        inherit src version;
        vendorHash = "sha256-Ptxkv1tGOASLr6Oa5WXPZPHswZcrB/IS+FtN1B5u37o=";
        composerNoDev = false;
        composerNoPlugins = true;
        composerNoScripts = true;
      };

      nixPackages =
        lib.mapAttrs
        (
          _name: phpVersion:
            import ./nix/dev-package.nix {
              inherit lib src version composerVendor;
              inherit (phpVersion) php perfidious;
            }
        )
        phpVersions;

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
      checks =
        {
          inherit pre-commit-check;
        }
        // lib.optionalAttrs pkgs.stdenv.hostPlatform.isx86_64 (
          lib.mapAttrs' (name: value: lib.nameValuePair "test-${name}" value) nixPackages
        );

      packages = lib.optionalAttrs pkgs.stdenv.hostPlatform.isx86_64 (
        nixPackages
        // {
          default = nixPackages.php82;
        }
      );

      devShells = rec {
        php81 = makeShell {
          php = php-phps.php81;
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
          php = pkgs.php84;
          perfidious = perfidious.packages.${system}.php84-gcc;
        };
        php85 = makeShell {
          php = pkgs.php85;
          perfidious = perfidious.packages.${system}.php85-gcc;
        };
        default = php82;
      };

      formatter = pkgs.alejandra;
    }))
    // {
      githubActions = nix-github-actions.lib.mkGithubMatrix {
        checks = {inherit (self.checks) x86_64-linux;};
        attrPrefix = "checks";
      };
    };
}
