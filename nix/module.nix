# NixOS module for the OCR worker side.
#
# The app itself is a directory that Nextcloud serves; the only thing a host has
# to run is the worker. Everything here is about that unit and the two secrets it
# needs, because both are easy to get subtly wrong:
#
#  * the worker secret must come from `LoadCredential`, never from `ExecStart` or
#    `environment`, or it shows up in `systemctl show` and in `/proc/*/environ`;
#  * after the secret is issued or rotated the PHP process must be reloaded, or
#    the running web workers keep answering 401 with "worker secret not
#    configured" from their own fast cache (see docs/adr/0001).
{ self }:
{ config, lib, pkgs, ... }:

let
  cfg = config.services.ocrflow;

  workerSrc = pkgs.callPackage ../nix/package-worker.nix { };

  # The OCR stack. Kept as an explicit list so a missing dependency is a build
  # error with a name, not an ImportError at 3am.
  python = cfg.package.python3.withPackages (ps: [
    ps.pymupdf
    ps.pillow
    ps.requests
    ps.img2pdf
    ps.numpy
    ps.onnxruntime
    ps.pydantic
    ps.google-cloud-vision
    ps.google-cloud-storage
  ]);

  workerBin = pkgs.writeShellScript "ocrflow-worker" ''
    export PYTHONPATH=${workerSrc}/share/ocrflow-worker/lib
    export NC_OCR_URL=${lib.escapeShellArg cfg.url}
    export NC_OCR_WORKER_NAME=${lib.escapeShellArg cfg.workerName}
    export NC_OCR_IDLE_SECONDS=${toString cfg.idleSeconds}
    ${lib.optionalString (cfg.engine != null) "export NC_OCR_ENGINE=${lib.escapeShellArg cfg.engine}"}
    exec ${python}/bin/python3 -m nc_ocr_flow.runner
  '';
in
{
  options.services.ocrflow = {
    enable = lib.mkEnableOption "the OCR Flow worker";

    url = lib.mkOption {
      type = lib.types.str;
      default = "http://127.0.0.1";
      description = "Base URL of the Nextcloud the worker talks to (no trailing slash).";
    };

    workerName = lib.mkOption {
      type = lib.types.str;
      default = "worker";
      description = "Name recorded on claimed jobs, so a stuck claim names its owner.";
    };

    idleSeconds = lib.mkOption {
      type = lib.types.ints.positive;
      default = 5;
      description = "Sleep between claims when the queue is empty.";
    };

    engine = lib.mkOption {
      type = lib.types.nullOr (lib.types.enum [ "google" "tesseract" "minimax" ]);
      default = null;
      description = "Force an engine; null lets the app's settings decide.";
    };

    secretFile = lib.mkOption {
      type = lib.types.path;
      description = ''
        File holding the shared secret issued by `occ ocrflow:worker:secret`.
        Installed via LoadCredential, so it never appears in the unit's command
        line or environment.
      '';
    };

    davPasswordFile = lib.mkOption {
      type = lib.types.path;
      description = ''
        File holding the Nextcloud app password the worker uses for WebDAV. A
        dedicated service account, not an admin: the worker only needs the files
        it is told about.
      '';
    };

    user = lib.mkOption {
      type = lib.types.str;
      default = "ocrflow";
      description = "Unprivileged user the worker runs as.";
    };

    package = lib.mkOption {
      type = lib.types.raw;
      default = pkgs;
      defaultText = lib.literalExpression "pkgs";
      description = "Package set the Python interpreter is taken from.";
    };

    nginxQueueAllowCidrs = lib.mkOption {
      type = lib.types.listOf lib.types.str;
      default = [ "127.0.0.1/32" "::1/128" ];
      description = ''
        Who may reach the worker plane. `#[PublicPage]` plus a shared secret is
        the only authentication on a route that moves files, so the network is a
        second wall: everything not listed here gets 403 before PHP runs.
      '';
    };
  };

  config = lib.mkIf cfg.enable {
    assertions = [
      {
        assertion = builtins.pathExists cfg.secretFile;
        message = "services.ocrflow.secretFile does not exist: run `occ ocrflow:worker:secret` and point this at the file it wrote.";
      }
      {
        assertion = builtins.pathExists cfg.davPasswordFile;
        message = "services.ocrflow.davPasswordFile does not exist: the worker cannot download a single file without it.";
      }
    ];

    users.users.${cfg.user} = {
      isSystemUser = true;
      group = cfg.user;
      home = "/var/lib/ocrflow";
      createHome = true;
    };
    users.groups.${cfg.user} = { };

    systemd.services.ocrflow-worker = {
      description = "OCR Flow worker";
      wantedBy = [ "multi-user.target" ];
      after = [ "network-online.target" ];
      wants = [ "network-online.target" ];

      serviceConfig = {
        Type = "simple";
        User = cfg.user;
        Group = cfg.user;
        ExecStart = workerBin;
        Restart = "always";
        RestartSec = 5;

        # The two secrets, and nothing else about them in the unit's metadata.
        LoadCredential = [
          "ocrflow-worker-secret:${cfg.secretFile}"
          "ocrflow-dav-password:${cfg.davPasswordFile}"
        ];

        # A worker downloads untrusted files and parses them with three different
        # engines. Give it as little as possible to be wrong with.
        NoNewPrivileges = true;
        PrivateTmp = true;
        PrivateDevices = true;
        ProtectSystem = "strict";
        ProtectHome = true;
        ProtectKernelTunables = true;
        ProtectKernelModules = true;
        ProtectControlGroups = true;
        RestrictAddressFamilies = [ "AF_INET" "AF_INET6" "AF_UNIX" ];
        RestrictNamespaces = true;
        LockPersonality = true;
        MemoryDenyWriteExecute = false; # onnxruntime JITs
        SystemCallArchitectures = "native";
        StateDirectory = "ocrflow";
      };
    };

    # Surfaced rather than applied: the allow-list belongs in the vhost that
    # serves Nextcloud, which this module does not own.
    warnings = [
      ''
        services.ocrflow: add this to the Nextcloud vhost so the worker plane is
        not reachable from the internet (secret + @PublicPage is not enough on a
        route that moves files):

          location ~ ^/ocs/v2\.php/apps/ocrflow/api/v1/queue/ {
            ${lib.concatMapStringsSep "\n    " (c: "allow ${c};") cfg.nginxQueueAllowCidrs}
            deny all;
            include ${config.services.nginx.package}/conf/fastcgi_params;
            fastcgi_pass unix:/run/phpfpm/nextcloud.sock;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
          }
      ''
    ];
  };
}
