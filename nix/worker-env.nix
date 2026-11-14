# The worker's Python environment, as a package.
#
# This list used to live inline in `nix/module.nix`. That was fine for the host
# and useless for everyone else: the only interpreter in the repository was
# whatever the deployer's module happened to compose, so CI could not run the
# worker's own code without describing the stack a second time — in pip — and
# two descriptions of one environment drift. The canary (`tests/corpora`) exists
# to catch a parser regression in the binary we actually ship; if CI builds it
# from pip, it measures a different MuPDF and proves nothing.
#
# So the environment is a package. The module composes it from the deployer's
# nixpkgs (`python = cfg.package.python3`) and CI builds the same file. One list,
# one derivation, two consumers.
{ pkgs, python ? pkgs.python3 }:

# The OCR stack. Kept as an explicit list so a missing dependency is a build
# error with a name, not an ImportError at 3am.
python.withPackages (ps: [
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
])
