"""Benign offline refusals: spies only, never PHP, PDO or PostgreSQL."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

source = Path(__file__).resolve().parent
checks = 0
with tempfile.TemporaryDirectory(prefix="order-launch-test-") as tmp:
    root = Path(tmp)
    app = root / "app"
    lab = app / "labs/onepage-pending-order"
    lab.mkdir(parents=True)
    (app / "bootstrap/cache").mkdir(parents=True)
    for name in ("start-isolated.sh", "run-clean.sh"):
        shutil.copyfile(source / name, lab / name)
    marker = root / "tool-opened"
    tools = root / "tools"
    tools.mkdir()
    for name in ("php", "initdb", "pg_ctl"):
        spy = tools / name
        spy.write_text('#!/bin/sh\ntouch "' + str(marker) + '"\nexit 99\n')
        spy.chmod(0o700)
    base = dict(PATH="/usr/bin:/bin", ONEPAGE_ORDER_ALLOW_LOCAL_START="SYNTHETIC_ONLY",
                PG_BIN=str(tools), PHP_BIN=str(tools / "php"), ONEPAGE_PG_RUNTIME_LIB=str(tools))
    def refuse(env, worker=False):
        global checks
        script = "run-clean.sh" if worker else "start-isolated.sh"
        args = ["/bin/bash", "--noprofile", "--norc", str(lab / script)]
        if worker:
            args += [str(tools), str(tools / "php"), str(lab), str(tools)]
        result = subprocess.run(args, env=env, capture_output=True, text=True, timeout=5)
        assert result.returncode == 2, (script, result.returncode, result.stderr)
        assert "REFUSED:" in result.stderr, result.stderr
        assert not marker.exists(), "PHP/PG spy invoked"
        assert not (root / "run").exists(), "startup directory created"
        checks += 1
    for name in ("PGHOSTADDR", "PGOPTIONS", "PGSERVICE", "PGSERVICEFILE", "PGPASSFILE",
                 "PHPRC", "PHP_INI_SCAN_DIR", "APP_CONFIG_CACHE", "APP_ENV", "APP_BASE_PATH",
                 "DB_URL", "DATABASE_URL", "DB_HOST", "LD_LIBRARY_PATH", "BASH_ENV", "ENV"):
        refuse(dict(base, **{name: "/nonexistent-order-lab-input"}))
        if name != "LD_LIBRARY_PATH":  # Explicit loader directory is the worker contract.
            refuse(dict(PATH="/usr/bin:/bin", HOME=str(root), LC_ALL="C",
                        **{name: "/nonexistent-order-lab-input"}), worker=True)
    for file in (".env", ".env.testing", "bootstrap/cache/config.php"):
        path = app / file
        path.write_text("synthetic invalid configuration\n")
        refuse(base)
        path.unlink()
        path.symlink_to(root / "missing-config")
        refuse(base)
        path.unlink()
    refuse(dict(base, ONEPAGE_ORDER_ALLOW_LOCAL_START="disabled"))
print(f"PASS ORDER_OFFLINE_LAUNCH_REFUSALS: {checks}; zero PHP/PDO/PG invocations")
