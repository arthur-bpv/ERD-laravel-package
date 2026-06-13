# To learn more about how to use Nix to configure your environment
# see: https://developers.google.com/idx/guides/customize-idx-env
{pkgs}: {
  # Which nixpkgs channel to use.
  channel = "stable-24.11"; # or "unstable"
  # Use https://search.nixos.org/packages to find packages
  packages = [
    pkgs.php83
    pkgs.php83Packages.composer
    pkgs.php83Extensions.pdo
    pkgs.php83Extensions.pdo_mysql
    pkgs.php83Extensions.pdo_sqlite
    pkgs.php83Extensions.mbstring
    pkgs.php83Extensions.xml
    pkgs.php83Extensions.zip
    pkgs.php83Extensions.bcmath
    pkgs.php83Extensions.tokenizer
    pkgs.php83Extensions.fileinfo
    pkgs.nodejs_22
  ];
  # Sets environment variables in the workspace
  env = {};
  idx = {
    # Search for the extensions you want on https://open-vsx.org/ and use "publisher.id"
    extensions = [
      "google.gemini-cli-vscode-ide-companion"
      "bmewburn.vscode-intelephense-client"
    ];
    workspace = {
      # Runs when a workspace is first created with this `dev.nix` file
      onCreate = {
        install-deps = "composer install && npm install";
        setup-env = "cp -n .env.example .env && php artisan key:generate --ansi";
        setup-db = "php artisan migrate --force --ansi";
        build-assets = "npm run build";
        default.openFiles = [ "README.md" "resources/views/welcome.blade.php" ];
      };
      # Runs each time the workspace is (re)started
      onStart = {
        install-deps = "composer install --no-interaction && npm install --silent";
      };
    };
    # Enable previews and customize configuration
    previews = {
      enable = true;
      previews = {
        web = {
          command = ["php" "artisan" "serve" "--port" "$PORT" "--host" "0.0.0.0"];
          manager = "web";
        };
      };
    };
  };
}
