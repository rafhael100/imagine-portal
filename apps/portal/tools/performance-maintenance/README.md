# Explicit derived-cache maintenance

`clear-derived-cache.php` is an offline, manually invoked helper for the Portal
package. It does not load Composer or Laravel and never evaluates a manifest or
compiled view. It has no automatic startup hook.

Run it only through the trusted private maintenance procedure, with the Portal
application writers stopped, the package's own private volumes mounted and the
trusted runtime UID. The installed helper fixes its root from its own location;
it does not accept a path/root argument or an environment path override. Invoke
the absolute installed path with exactly one action:

```sh
php /var/www/html/tools/performance-maintenance/clear-derived-cache.php --check
php /var/www/html/tools/performance-maintenance/clear-derived-cache.php --apply
```

`--check` validates both cache directories and reports the eligible-file count
without removing anything. `--apply` performs the same complete preflight and
then removes only immediate regular files matching:

- `bootstrap/cache/packages.php`, `services.php`, `config.php`, `events.php`
- `bootstrap/cache/routes*.php`
- `storage/framework/views/*.php`

It never recurses into subdirectories. Other bootstrap files, data, logs,
application cache data, sessions, databases and volumes are preserved. A missing
root, symlink in an ancestor/root or immediate cache entry, nonregular selected
file, alternate script path, or changed identity causes refusal. Both roots are
validated before the first deletion, and inode/device identities are rechecked
with PHP's stat/realpath cache cleared. Mounts on different devices are allowed.

These checks do not eliminate races against a concurrent filesystem writer.
Private volumes, stopped application writers and a trusted UID are required; the
helper cannot independently prove those operational conditions. An I/O failure
during deletion can leave some eligible files removed and others present. A
second explicit execution is idempotent after the underlying issue is resolved.

This helper is preparation for an already authorized maintenance procedure. Its
existence does not authorize installation, deployment, a running-volume cleanup,
image rollback or a VPS operation.
