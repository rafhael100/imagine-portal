#!/bin/bash
set -euo pipefail
source "$GITHUB_WORKSPACE/apps/portal/tests/Fixtures/phase8-recovery/common.sh"
[[ "$#" == 1 ]]
umask 077
case "$1" in
    restart)
        for kind in portal receiver; do state live "$kind" capture > "$P8_PRIVATE/restart-before-$kind.json"; done
        live ps -q | sort > "$P8_PRIVATE/container-ids-before"
        live restart >/dev/null
        live up -d --wait portal-db performance-db >/dev/null
        [[ "$(live ps --status running --services | wc -l)" == 6 ]]
        live ps -q | sort > "$P8_PRIVATE/container-ids-after"
        cmp "$P8_PRIVATE/container-ids-before" "$P8_PRIVATE/container-ids-after"
        for kind in portal receiver; do state live "$kind" capture > "$P8_PRIVATE/restart-after-$kind.json"; done
        python3 "$fixture_dir/verify.py" restart
        ;;
    snapshot)
        # Freeze every possible application writer across BOTH DBs. These are
        # this run's disposable services only; no distributed-atomicity claim.
        live stop portal-web portal performance-web receiver >/dev/null
        mkdir -m 700 "$P8_PRIVATE/plain"
        for tuple in 'portal portal-db portal_p6_migrator portal_p6_synthetic portal.dump' 'receiver performance-db performance_p6_migrator performance_p6_synthetic performance.dump'; do
            read -r kind service role database file <<< "$tuple"
            state live "$kind" capture > "$P8_PRIVATE/baseline-$kind.json"
            live exec -T "$service" sh -ec 'export PGPASSWORD="$(cat /run/pg/bootstrap_password)"; exec pg_dump -U "$1" -d "$2" --format=custom' sh "$role" "$database" > "$P8_PRIVATE/plain/$file"
        done
        python3 "$fixture_dir/verify.py" manifest
        bundle seal /run/bundle/plain /run/bundle/key /run/bundle/manifest.json /run/bundle/paired.enc
        if [[ "${ADMIN_PHASE10_STORAGE_CI:-}" == 1 ]]; then
            bash "$GITHUB_WORKSPACE/apps/portal/tests/Fixtures/phase10-storage/run.sh" capture
        fi
        python3 "$fixture_dir/verify.py" tamper
        if bundle open /run/bundle/tampered.enc /run/bundle/key /run/bundle/manifest.json /run/bundle/tampered-open; then exit 1; fi
        if bundle open /run/bundle/paired.enc /run/bundle/wrong-key /run/bundle/manifest.json /run/bundle/wrong-key-open; then exit 1; fi
        if bundle open /run/bundle/paired.enc /run/bundle/key /run/bundle/wrong-generation.json /run/bundle/wrong-generation-open; then exit 1; fi
        [[ ! -e "$P8_PRIVATE/tampered-open" && ! -e "$P8_PRIVATE/wrong-key-open" && ! -e "$P8_PRIVATE/wrong-generation-open" ]]
        bundle open /run/bundle/paired.enc /run/bundle/key /run/bundle/manifest.json /run/bundle/authenticated
        if bundle open /run/bundle/paired.enc /run/bundle/key /run/bundle/manifest.json /run/bundle/authenticated; then exit 1; fi
        cmp "$P8_PRIVATE/plain/portal.dump" "$P8_PRIVATE/authenticated/portal.dump"
        cmp "$P8_PRIVATE/plain/performance.dump" "$P8_PRIVATE/authenticated/performance.dump"
        [[ -z "$(restored ps -aq)" && -z "$(docker volume ls -q --filter "label=com.docker.compose.project=$P8_PROJECT")" ]]
        restored config --quiet
        restored up -d --wait portal-db performance-db >/dev/null
        # Restore the new, literally empty storage volume before the first
        # Portal one-shot can populate it from the image's default files.
        if [[ "${ADMIN_PHASE10_STORAGE_CI:-}" == 1 ]]; then
            bash "$GITHUB_WORKSPACE/apps/portal/tests/Fixtures/phase10-storage/run.sh" restore
        fi
        for tuple in 'portal portal-db portal_p6_migrator portal_p6_synthetic portal.dump' 'receiver performance-db performance_p6_migrator performance_p6_synthetic performance.dump'; do
            read -r kind service role database file <<< "$tuple"
            cluster=$(restored exec -T "$service" sh -ec 'export PGPASSWORD="$(cat /run/pg/bootstrap_password)"; psql -U "$1" -d "$2" -Atc "SELECT system_identifier FROM pg_control_system()"' sh "$role" "$database")
            [[ "$cluster" =~ ^[0-9]+$ ]]
            printf '%s\n' "$cluster" | sudo tee "$P8_CONTEXT/restore/$kind/cluster" >/dev/null
            sudo chown 33:33 "$P8_CONTEXT/restore/$kind/cluster"
            sudo chmod 400 "$P8_CONTEXT/restore/$kind/cluster"
            state restore "$kind" fresh-roles
            restored exec -T "$service" sh -ec 'export PGPASSWORD="$(cat /run/pg/bootstrap_password)"; exec pg_restore -U "$1" -d "$2" --exit-on-error --single-transaction' sh "$role" "$database" < "$P8_PRIVATE/authenticated/$file"
            if state restore "$kind" fresh-roles > "$P8_PRIVATE/nonempty-$kind.log" 2>&1; then exit 1; fi
            grep -q 'stage=fresh-target' "$P8_PRIVATE/nonempty-$kind.log"
            state restore "$kind" capture > "$P8_PRIVATE/restored-baseline-$kind.json"
        done
        only_restore_databases
        python3 "$fixture_dir/verify.py" baseline
        state restore receiver profile-regression
        only_restore_databases
        live start portal receiver performance-web portal-web >/dev/null
        ;;
    revoke)
        state live portal revoke
        state live receiver revoke
        ;;
    reconcile)
        live stop portal-web portal performance-web receiver >/dev/null
        only_restore_databases
        for kind in portal receiver; do
            state live "$kind" capture > "$P8_PRIVATE/current-$kind.json"
            # CURRENT remains OUTSIDE plain/, authenticated/ and paired.enc.
            # This is an independently observed CI reference, not WORM custody.
            sudo install -m 400 -o 33 -g 33 "$P8_PRIVATE/current-$kind.json" "$P8_CONTEXT/restore/$kind/current.json"
            if state restore "$kind" gate > "$P8_PRIVATE/stale-$kind.log" 2>&1; then exit 1; fi
            grep -q 'stage=current-authority-gate' "$P8_PRIVATE/stale-$kind.log"
        done
        only_restore_databases
        for kind in portal receiver; do state restore "$kind" reconcile; done
        # Mandatory gate on BOTH DBs immediately before any application opens.
        for kind in portal receiver; do state restore "$kind" gate; done
        only_restore_databases
        restored up -d portal receiver performance-web portal-web >/dev/null
        [[ "$(restored ps --status running --services | wc -l)" == 6 ]]
        for kind in portal receiver; do
            restored exec -T -e GITHUB_ACTIONS=true -e ADMIN_PHASE7_CI=1 "$kind" php /dev/stdin "$kind" < "$GITHUB_WORKSPACE/apps/portal/tests/Fixtures/phase7/runtime.php"
        done
        fingerprint=$(sudo cat "$P6_CI_MATERIAL/receiver-bootstrap/certificate-sha256")
        restored exec -T -e GITHUB_ACTIONS=true -e ADMIN_PHASE8_RECOVERY_CI=1 -e "P8_CERTIFICATE_SHA256=$fingerprint" receiver php /dev/stdin < "$fixture_dir/receiver-probe.php"
        sudo sha256sum --check "$P8_PRIVATE/key-fingerprints" >/dev/null
        ;;
    finish)
        for kind in portal receiver; do
            state restore "$kind" gate
            state restore "$kind" capture > "$P8_PRIVATE/final-restored-$kind.json"
            state live "$kind" capture > "$P8_PRIVATE/final-source-$kind.json"
        done
        python3 "$fixture_dir/verify.py" finish
        sudo sha256sum --check "$P8_PRIVATE/key-fingerprints" >/dev/null
        live start portal receiver performance-web portal-web >/dev/null
        ;;
    *) exit 1 ;;
esac
