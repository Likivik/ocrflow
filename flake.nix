{
  description = "ocrflow — a Nextcloud OCR app (PHP) and a stateless Python worker";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
  };

  outputs = { self, nixpkgs, ... }:
    let
      systems = [ "x86_64-linux" "aarch64-linux" ];
      forEachSystem = f: nixpkgs.lib.genAttrs systems (system: f {
        inherit system;
        pkgs = import nixpkgs { inherit system; };
      });
    in
    {
      # The installable app tree: exactly the files Nextcloud serves, nothing
      # else (no worker, no tests, no node_modules). `js/` is the committed
      # bundle, so building this needs no node.
      packages = forEachSystem ({ pkgs, system }: {
        ocrflow-app = pkgs.callPackage ./nix/package-app.nix { };

        # The worker's Python environment. Not built by `nix flake check` in this
        # repository's CI (it pulls PyMuPDF, onnxruntime and the cloud SDKs, i.e.
        # minutes and gigabytes); it is built where it is deployed.
        ocrflow-worker = pkgs.callPackage ./nix/package-worker.nix { };

        default = self.packages.${system}.ocrflow-app;
      });

      # NixOS wiring: the module owns the unit, the credentials and the network
      # boundary. The host keeps only `services.ocrflow.enable = true;`.
      nixosModules = {
        ocrflow = import ./nix/module.nix { inherit self; };
        default = self.nixosModules.ocrflow;
      };

      formatter = forEachSystem ({ pkgs, ... }: pkgs.nixpkgs-fmt);
    };
}
