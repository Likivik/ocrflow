# OCR Flow on NixOS — native worker (no Docker)
#
# This is the shape poweredge runs today: Nextcloud on the host, the worker as a
# systemd unit with its secret in a systemd credential. No AppAPI, no container,
# no account credential in the worker.
#
# Two things to keep in mind before copying this:
#   * the worker still uses the DAV transport here, which means it holds an app
#     password for one account (see the plan, R6). Removing that requires the api
#     transport, which needs the app to stream bytes — supported, but it has not
#     run in production yet.
#   * the secret below is what `occ ocrflow:worker:secret` prints. Put it in sops,
#     never in this file.

{ config, pkgs, lib, ... }:

let
  workerSecret = config.sops.secrets."ocrflow/worker-secret".path;
in
{
  services.nextcloud = {
    enable = true;
    package = pkgs.nextcloud34;
    hostName = "cloud.example.com";
    https = true;
    # The app itself, plus AppAPI if you later deploy the worker as an ExApp.
    extraApps = {
      inherit (config.services.nextcloud.package.packages.apps) ocrflow;
    };
  };

  # The worker: one shared secret, no Nextcloud identity in the environment.
  systemd.services.ocrflow-worker = {
    description = "OCR Flow worker";
    after = [ "nextcloud.service" "network-online.target" ];
    wants = [ "network-online.target" ];
    wantedBy = [ "multi-user.target" ];
    environment = {
      NC_OCR_URL = "http://127.0.0.1";
      NC_OCR_WORKER_NAME = "native-worker";
      NC_OCR_STATE_DIR = "/var/lib/ocrflow";
      NC_OCR_ENGINE = "tesseract";
    };
    serviceConfig = {
      ExecStart = "${pkgs.ocrflow-worker}/bin/ocrflow-runner";
      # LoadCredential puts the value in $CREDENTIALS_DIRECTORY, never in
      # `systemctl show` or /proc/<pid>/environ.
      LoadCredential = "ocrflow-worker-secret:${workerSecret}";
      StateDirectory = "ocrflow";
      Restart = "always";
      RestartSec = 5;
      DynamicUser = true;
      # A slow page must not be read as a crash.
      TimeoutStopSec = 30;
    };
  };

  sops.secrets."ocrflow/worker-secret" = {
    owner = "root";
    mode = "0400";
  };
}
