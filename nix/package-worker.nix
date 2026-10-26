# The worker: the Python code plus an entry point. The interpreter is not fixed
# here on purpose — the module composes one with the OCR stack (PyMuPDF,
# onnxruntime, the cloud SDKs) so that the deployer pins it once, in the host
# configuration, instead of inheriting whatever a package default happened to be.
{ lib, stdenv }:

stdenv.mkDerivation {
  pname = "ocrflow-worker";
  version = "0.1.0";

  src = lib.cleanSourceWith {
    src = ../worker;
    filter = path: type:
      let
        rel = lib.removePrefix (toString ../worker + "/") (toString path);
        first = lib.head (lib.splitString "/" rel);
      in
      first == "lib" || rel == "pyproject.toml";
  };

  dontBuild = true;
  dontConfigure = true;

  installPhase = ''
    runHook preInstall
    mkdir -p $out/share/ocrflow-worker
    cp -r lib $out/share/ocrflow-worker/lib
    cp pyproject.toml $out/share/ocrflow-worker/
    runHook postInstall
  '';

  meta = {
    description = "OCR for Nextcloud: the stateless worker";
    license = lib.licenses.agpl3Plus;
    platforms = lib.platforms.linux;
  };
}
