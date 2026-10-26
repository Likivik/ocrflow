# The installable Nextcloud app.
#
# An app is a directory, not a binary: what matters is that the tree contains
# only run-time files. `.nextcloudignore` says the same thing for the tarball
# route; this is the flake route, and both must agree or one of them ships the
# test suite to a web server.
{
  lib,
  stdenv,
  nix-gitignore ? null,
  ...
}:

let
  root = ../.;
  keep = [
    "appinfo"
    "lib"
    "templates"
    "js"
    "img"
    "l10n"
    "composer.json"
    "LICENSE"
    "README.md"
  ];
in
stdenv.mkDerivation {
  pname = "ocrflow-app";
  version = "0.1.0";

  src = lib.cleanSourceWith {
    src = root;
    filter = path: type:
      let
        rel = lib.removePrefix (toString root + "/") (toString path);
        first = lib.head (lib.splitString "/" rel);
      in
      lib.any (name: rel == name || first == name) keep;
  };

  dontBuild = true;
  dontConfigure = true;

  installPhase = ''
    runHook preInstall
    mkdir -p $out/apps/ocrflow
    cp -r . $out/apps/ocrflow/
    # A guard, not a comment: the two files that must never reach a web root are
    # the worker (a whole OCR stack) and the test suites.
    rm -rf $out/apps/ocrflow/worker $out/apps/ocrflow/tests
    runHook postInstall
  '';

  meta = {
    description = "OCR for Nextcloud: the app half (queue, state, UI)";
    license = lib.licenses.agpl3Plus;
    platforms = lib.platforms.linux;
  };
}
