# Phase11 candidate correspondence rehearsal

Run `run.sh` only inside the existing Phase7 CI job after the six services have
been materialized and their runtime checks have passed. Set
`ADMIN_PHASE11_RELEASE_CI=1` and `PYTHONDONTWRITEBYTECODE=1` for the job. The
fixture uses the existing source checkout, environment and local images; it
does not pull/build images, create/start containers or contact a VPS.

`exercise.py` explicitly creates one synthetic expected manifest in a private
run/attempt-specific temporary directory, invokes the actual preflight CLI,
checks that the expectation was not rewritten, and removes its own temporary
manifest. Only sanitized checks/hashes are retained in
`$RUNNER_TEMP/phase11-release-evidence/checks.json`. No rendered configuration,
private mount paths, credentials or raw image/container inspection is uploaded.

`test_preflight.py` uses isolated temporary files and an injected read runner
to exercise refusal of mutable operational tags, missing registry digest
association, changed image ID/SQL/source SHA, dirty or untracked application
source, a Portal web image inheriting root, resource/config drift, an extra service, wrong engine bind, public
origin, remote Docker context, duplicate manifest keys, missing critical
files, absent CI guard and symlinked critical inputs. The CLI has no option to
accept that injected runner or a supplied image-inspection JSON file.

The observed positive CI result proves correspondence to an explicit synthetic
expectation using already available disposable images. It does not establish
a published release, trusted build provenance, actual host readiness, human
approval or authorization to install. The operational-mode positive case in
the unit suite uses injected registry observations and is labeled as such.
