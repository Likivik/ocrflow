# NixOS module for the OCR worker side.
#
# The app itself is a directory that Nextcloud serves; the only thing a host has
# to run is the worker. Everything here is about that unit, the two secrets it
# needs, and the two invariants that are expensive to learn twice:
#
#  * the worker secret must come from `LoadCredential`, never from `ExecStart` or
#    `environment`, or it shows up in `systemctl show` and in `/proc/*/environ`;
#  * after the secret is issued or rotated the PHP process must be reloaded, or
#    the running web workers keep answering 401 with "worker secret not
#    configured" from their own fast cache (see docs/adr/0001);
#  * exactly ONE OCR pipeline may own PDFs in an instance (see the long note on
#    `forbidCompetingApps`).
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
    # `runner` imports `_process_file` from webhook_server, and that module
    # imports FastAPI at load time. Omitting these two made every worker die at
    # start with ModuleNotFoundError — found by the poweredge VM smoke, not by
    # eval, which is the whole reason that smoke exists.
    ps.fastapi
    ps.uvicorn
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
    # Without a DAV identity the worker exits 2 at start-up (deliberate: the
    # invented "likivik" default was removed). The username had no source at all —
    # neither this module nor the host aspect ever set it, so a fresh boot
    # crash-looped while every gate stayed green. Found by the poweredge VM smoke.
    ${lib.optionalString (cfg.davUser != null) "export NC_OCR_DAV_USER=${lib.escapeShellArg cfg.davUser}"}
${lib.concatMapStrings (name: "    export ${name}=${lib.escapeShellArg cfg.extraEnvironment.${name}}\n") (lib.attrNames cfg.extraEnvironment)}
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

    checkSecretFiles = lib.mkOption {
      type = lib.types.bool;
      default = true;
      description = ''
        Assert at evaluation time that `secretFile` and `davPasswordFile` exist.

        True on a host, where the files are provisioned before a deploy. False
        where they are created later than evaluation: sops-nix renders secrets at
        BOOT, so a builder that evaluates `nixos-rebuild build` or a VM test can
        never satisfy the assertion, and the whole host fails to evaluate with a
        message about a file the operator has in fact configured correctly.
      '';
    };

    secretFile = lib.mkOption {
      type = lib.types.path;
      description = ''
        File holding the shared secret issued by `occ ocrflow:worker:secret`.
        Installed via LoadCredential, so it never appears in the unit's command
        line or environment.
      '';
    };

    davUser = lib.mkOption {
      type = lib.types.nullOr lib.types.str;
      default = null;
      description = ''
        Username the worker authenticates as over WebDAV.

        No default on purpose: an invented one turns a misconfiguration into an
        authentication failure. The module refuses to evaluate with a password and
        no user, because the alternative is a unit that exits 2 on every restart.
      '';
      example = "ocr-service";
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

    extraEnvironment = lib.mkOption {
      type = lib.types.attrsOf lib.types.str;
      default = { };
      example = {
        GOOGLE_APPLICATION_CREDENTIALS = "$CREDENTIALS_DIRECTORY/google-ocr-key";
        NC_OCR_GOOGLE_PROJECT = "my-project";
      };
      description = ''
        Extra environment for the worker, for everything the engines need and this
        module does not model: Google Vision's key path and project, a MiniMax key,
        an engine selector. `$CREDENTIALS_DIRECTORY` expands to the unit's private
        credentials directory, so a secret is referenced there and never inlined.
      '';
    };

    extraCredentials = lib.mkOption {
      type = lib.types.listOf lib.types.str;
      default = [ ];
      example = [ "google-ocr-key:/run/secrets/google/ocr-key.json" ];
      description = ''
        Additional `LoadCredential` entries, as `name:path`. The file is copied into
        the unit's private credentials directory before it starts and is readable by
        nothing else — which is why the key path in `extraEnvironment` is
        `$CREDENTIALS_DIRECTORY/<name>` rather than the source path.
      '';
    };

    forbidCompetingApps = lib.mkOption {
      type = lib.types.bool;
      default = true;
      description = ''
        Fail evaluation when a known competing OCR app is configured, and force
        the Nextcloud app store off.

        A second writer that OCRs the same postCreate/postWrite events does not
        "add" anything: it overwrites the first one's text layer minutes later,
        silently, while the victim's own XMP stamp survives the rewrite — so its
        skip logic still records the file as stamped by us, and the money is
        already spent. Measured on the old deployment: our 7.6 MB Google layer
        replaced by a 6.3 MB OCRmyPDF one extracting 0 Cyrillic characters.

        A `throw` rather than a NixOS assertion, deliberately: an assertion only
        fails once the system is really built, and this must fail `nix eval`.
      '';
    };

    competingApps = lib.mkOption {
      type = lib.types.listOf lib.types.str;
      default = [ "workflow_ocr" ];
      description = "Curated list of Nextcloud app ids that must not share this instance.";
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

  config = lib.mkMerge [
    (lib.mkIf cfg.enable {
      assertions = [
        {
          assertion = !cfg.checkSecretFiles || builtins.pathExists cfg.secretFile;
          message = "services.ocrflow.secretFile does not exist: run `occ ocrflow:worker:secret` and point this at the file it wrote.";
        }
        {
          assertion = cfg.davPasswordFile == null || cfg.davUser != null;
          message = "services.ocrflow.davUser is unset but davPasswordFile is set: the worker exits 2 at start-up without a WebDAV username (no default, deliberately).";
        }
        {
          assertion = !cfg.checkSecretFiles || builtins.pathExists cfg.davPasswordFile;
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
          ] ++ cfg.extraCredentials;

          # A worker downloads untrusted files and parses them with three
          # different engines. Give it as little as possible to be wrong with.
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
    })

    # Only when a Nextcloud is actually configured here: referencing its options
    # in a host that has no Nextcloud is an evaluation error, not a guard.
    (lib.mkIf (cfg.enable && cfg.forbidCompetingApps && config.services ? nextcloud && (config.services.nextcloud.enable or false)) (
      let
        rivals = lib.filter (app: config.services.nextcloud.extraApps ? ${app}) cfg.competingApps;
      in
      {
        # The app store is the only way a rival can appear at run time; the throw
        # below can only see what this configuration declares.
        services.nextcloud.appstoreEnable = lib.mkForce false;

        warnings = lib.mkIf (rivals != [ ]) (throw ''
          services.ocrflow: a competing OCR app is configured: ${lib.concatStringsSep ", " rivals}

          Two OCR pipelines on one instance silently destroy each other's text
          layers (the survivor's stamp makes the victim record the file as done),
          and the API spend is already paid by the time you notice.

          Either drop it from services.nextcloud.extraApps, or set
          services.ocrflow.forbidCompetingApps = false if you know why you want
          both.
        '');
      }
    ))
  ];
}
