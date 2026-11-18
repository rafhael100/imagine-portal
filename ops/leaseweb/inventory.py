#!/usr/bin/python3 -I
"""Read selected Docker metadata for one administrator-approved Compose project."""
import datetime
import json
import re
import subprocess
import sys

DOCKER = ("/usr/bin/docker", "--config", "/etc/imagine-ops/docker",
          "--host", "unix:///run/docker.sock")
PROJECT = re.compile(r"[a-z0-9][a-z0-9_-]{0,62}")


def entries(args):
    output = subprocess.run(
        [*DOCKER, *args], timeout=15, check=True, text=True,
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        env={"PATH": "/usr/bin:/bin", "LANG": "C"},
    ).stdout
    if len(output) > 128000:
        raise ValueError("inventory_too_large")
    return output.splitlines()


def collect(project, query=entries):
    if not PROJECT.fullmatch(project):
        raise ValueError("invalid_project")
    label = "label=com.docker.compose.project=" + project
    fields = ("Name", "Service", "State", "Status", "Image", "Ports", "Mounts", "Networks")
    template = (
        '{"Name":{{json .Names}},"Service":{{json (.Label "com.docker.compose.service")}},'
        '"State":{{json .State}},"Status":{{json .Status}},"Image":{{json .Image}},'
        '"Ports":{{json .Ports}},"Mounts":{{json .Mounts}},"Networks":{{json .Networks}}}'
    )
    containers = [
        {field: row[field] for field in fields}
        for row in map(json.loads, query(["ps", "-a", "--filter", label, "--format", template]))
    ]
    # Only Compose-owned resources are listed here. External resources require review.
    return {
        "project": project,
        "collected_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "containers": containers,
        "volumes": query(["volume", "ls", "--filter", label, "--format", "{{.Name}}"]),
        "networks": query(["network", "ls", "--filter", label, "--format", "{{.Name}}"]),
    }


def main():
    if len(sys.argv) != 2:
        return 2
    try:
        print(json.dumps(collect(sys.argv[1]), sort_keys=True))
    except (OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError):
        print("inventory_unavailable", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
