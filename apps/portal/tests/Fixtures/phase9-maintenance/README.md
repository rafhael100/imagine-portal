# Derived-cache maintenance fixture

This opt-in fixture requires `GITHUB_ACTIONS=true`,
`ADMIN_PHASE9_MAINTENANCE_CI=1`, the current run IDs and an already built
`P6_PORTAL_IMAGE`. It creates two new, exclusive named volumes and starts only
short-lived PHP CLI containers with no network, UID33, read-only image files and
no Linux capabilities. It never starts FPM or Laravel on these volumes.

The first container writes deliberately obsolete/invalid cache PHP together with
data, log, session, application-cache, unselected-PHP and nested-file sentinels.
The second container reopens the same volumes and runs the explicit maintenance
helper. It verifies check-only behavior, the exact immediate-file scope,
idempotence, no evaluation of stale PHP, ignored cache-path environment overrides,
and preservation of every sentinel.

Negative cases cover symlinks in both cache directories, an unselected symlink,
a symlinked cache root/ancestor, a nonregular candidate, a supplied root argument
and a script symlink. A failure in the second cache directory must preserve the
first directory's otherwise eligible files, proving that the complete preflight
precedes deletion. The fixture compares private data after every refusal.

The two containers use the same already built package. This demonstrates removal
of persisted stale cache from a prior package without bootstrapping it; it does
not claim that a different historical image was booted or rolled back. The
fixture never touches the Phase7/Phase8 services, their volumes or any VPS.
Its own containers and volumes are removed on exit. Only the check names and
scope flags are uploaded; synthetic file contents stay inside disposable volumes.
