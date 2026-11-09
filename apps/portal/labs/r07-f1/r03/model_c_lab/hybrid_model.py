"""SEC-R03 Modelo C: disposable SQLite-only *simulation*, NOT a production fence.

Z3 and Z4 use separate SQLite files in the SAME test runner. This does not
provide independent infrastructure, distributed transaction atomicity,
operator authentication, real revocation history, or durable host-rollback
protection. All subjects, sessions, writers and effects are fictitious.
"""
from __future__ import annotations

import hashlib
from pathlib import Path
import shutil
import sqlite3
from typing import Callable


class Denied(RuntimeError):
    """A simulated request, mutation or recovery action must fail closed."""


class Conflict(Denied):
    """A simulated compare-and-swap check failed."""


WRITERS = frozenset({"portal-http", "portal-worker"})
MANIFEST = "model-c-lab-v1:portal-http,portal-worker"


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


class HybridLab:
    def __init__(self, root: Path):
        self.root = root
        self.z3 = root / "restorable-z3" / "business.sqlite"
        self.z4 = root / "separate-z4" / "authority.sqlite"

    @classmethod
    def provision(cls, root: Path) -> "HybridLab":
        if root.exists() and any(root.iterdir()):
            raise Denied("Lab root must start empty.")
        lab = cls(root)
        lab.z3.parent.mkdir(parents=True, exist_ok=True)
        lab.z4.parent.mkdir(parents=True, exist_ok=True)
        with sqlite3.connect(lab.z4) as db:
            db.executescript("""
                CREATE TABLE fence(
                    id INTEGER PRIMARY KEY CHECK (id=1),
                    generation INTEGER NOT NULL,
                    sequence INTEGER NOT NULL,
                    mode TEXT NOT NULL,
                    session_epoch INTEGER NOT NULL,
                    reconciled INTEGER NOT NULL,
                    snapshot_sha TEXT NOT NULL,
                    manifest TEXT NOT NULL
                );
                INSERT INTO fence VALUES (1,1,1,'OPEN',1,1,'','');
                CREATE TABLE revoked(
                    subject TEXT NOT NULL, organization TEXT NOT NULL,
                    PRIMARY KEY(subject,organization)
                );
            """)
        with sqlite3.connect(lab.z3) as db:
            db.executescript("""
                CREATE TABLE membership(
                    subject TEXT NOT NULL, organization TEXT NOT NULL,
                    active INTEGER NOT NULL, PRIMARY KEY(subject,organization)
                );
                CREATE TABLE session(
                    subject TEXT PRIMARY KEY, epoch INTEGER NOT NULL,
                    active INTEGER NOT NULL
                );
                CREATE TABLE effect(
                    nonce TEXT PRIMARY KEY, subject TEXT NOT NULL,
                    organization TEXT NOT NULL, writer TEXT NOT NULL
                );
                INSERT INTO membership VALUES ('actor-a','org-a',1);
                INSERT INTO membership VALUES ('actor-b','org-b',1);
                INSERT INTO session VALUES ('actor-a',1,1);
                INSERT INTO session VALUES ('actor-b',1,1);
            """)
        return lab

    def _connect(self, path: Path) -> sqlite3.Connection:
        # mode=rw prohibits an absent authority silently becoming a new DB.
        try:
            db = sqlite3.connect(path.as_uri() + "?mode=rw", uri=True,
                                 timeout=4, isolation_level=None)
            db.execute("PRAGMA busy_timeout=4000")
            db.execute("PRAGMA foreign_keys=ON")
            return db
        except (sqlite3.Error, ValueError) as exc:
            raise Denied("Required laboratory authority/database unavailable.") from exc

    def _state(self, db: sqlite3.Connection) -> tuple:
        try:
            row = db.execute("""SELECT generation,sequence,mode,session_epoch,
                                       reconciled,snapshot_sha,manifest
                                FROM fence WHERE id=1""").fetchone()
        except sqlite3.Error as exc:
            raise Denied("Malformed laboratory authority.") from exc
        if row is None:
            raise Denied("Missing laboratory authority.")
        return row

    def state(self) -> tuple:
        db = self._connect(self.z4)
        try:
            return self._state(db)
        finally:
            db.close()

    def snapshot(self, target: Path) -> str:
        """Creates a mock pre-revocation backup, not a customer backup."""
        if target.exists():
            raise Denied("Refuse overwriting a laboratory snapshot.")
        source = self._connect(self.z3)
        try:
            with sqlite3.connect(target) as copy:
                source.backup(copy)
        finally:
            source.close()
        return digest(target)

    def revoke(self, subject: str, organization: str) -> int:
        """Revoke in simulated Z4 first, irrespective of stale Z3 state."""
        db = self._connect(self.z4)
        try:
            db.execute("BEGIN IMMEDIATE")
            gen, seq, mode, epoch, _, _, _ = self._state(db)
            db.execute("INSERT OR IGNORE INTO revoked VALUES (?,?)",
                       (subject, organization))
            db.execute("UPDATE fence SET sequence=? WHERE id=1", (seq + 1,))
            db.commit()
            return seq + 1
        except Exception:
            db.rollback()
            raise
        finally:
            db.close()

    def begin_recovery(self, expected_seq: int, backup_sha: str) -> int:
        """CAS on authority generation; DENY is established before restore."""
        if len(backup_sha) != 64 or any(c not in "0123456789abcdef" for c in backup_sha):
            raise Denied("Invalid synthetic backup digest.")
        db = self._connect(self.z4)
        try:
            db.execute("BEGIN IMMEDIATE")
            gen, seq, mode, epoch, _, _, _ = self._state(db)
            if seq != expected_seq:
                raise Conflict("Stale recovery coordinator.")
            if mode != "OPEN":
                raise Denied("Recovery already active or authority not open.")
            db.execute("""UPDATE fence SET generation=?,sequence=?,
                          mode='DENY', session_epoch=?,reconciled=0,
                          snapshot_sha=?,manifest=? WHERE id=1""",
                       (gen + 1, seq + 1, epoch + 1, backup_sha, MANIFEST))
            db.commit()
            return gen + 1
        except Exception:
            db.rollback()
            raise
        finally:
            db.close()

    def restore(self, backup: Path) -> None:
        """Restores ONLY Z3 after verifying Z4 is already DENY."""
        db = self._connect(self.z4)
        try:
            _, _, mode, _, _, sha, _ = self._state(db)
            if mode != "DENY" or not sha or digest(backup) != sha:
                raise Denied("Backup differs or recovery fence not established.")
        finally:
            db.close()
        shutil.copyfile(backup, self.z3)

    def reconcile(self, generation: int, partial: bool = False) -> int:
        """Invalidate all old sessions; project external revocations onto Z3."""
        auth = self._connect(self.z4)
        data = None
        try:
            auth.execute("BEGIN IMMEDIATE")
            gen, seq, mode, epoch, reconciled, sha, manifest = self._state(auth)
            if gen != generation or mode != "DENY" or reconciled or not sha or manifest != MANIFEST:
                raise Denied("Reconciliation prerequisites not satisfied.")
            revoked = auth.execute("SELECT subject,organization FROM revoked").fetchall()
            data = self._connect(self.z3)
            data.execute("BEGIN IMMEDIATE")
            data.execute("UPDATE session SET active=0")
            for subject, org in revoked:
                data.execute("UPDATE membership SET active=0 WHERE subject=? AND organization=?",
                             (subject, org))
            data.commit()
            # Simulate an interruption AFTER the Z3 update. Z4 remains DENY.
            if partial:
                raise Denied("Interrupted reconciliation: keep serving DENY.")
            if data.execute("SELECT count(*) FROM session WHERE active != 0").fetchone()[0]:
                raise Denied("Old sessions survived global reset.")
            for subject, org in revoked:
                if data.execute("""SELECT count(*) FROM membership WHERE
                                   subject=? AND organization=? AND active != 0""",
                                (subject, org)).fetchone()[0]:
                    raise Denied("Revoked membership survived restore.")
            auth.execute("UPDATE fence SET reconciled=1,mode='READY',sequence=? WHERE id=1",
                         (seq + 1,))
            auth.commit()
            return seq + 1
        except Exception:
            auth.rollback()
            raise
        finally:
            if data is not None:
                data.close()
            auth.close()

    def open_for_lab(self, expected_seq: int, approval_fixture: bool) -> None:
        """A Boolean TEST FIXTURE is *not* independent human approval."""
        if approval_fixture is not True:
            raise Denied("Lab approval fixture absent.")
        auth = self._connect(self.z4)
        try:
            auth.execute("BEGIN IMMEDIATE")
            gen, seq, mode, _, reconciled, sha, manifest = self._state(auth)
            if seq != expected_seq:
                raise Conflict("Stale lab OPEN attempt.")
            if mode != "READY" or not reconciled or not sha or manifest != MANIFEST:
                raise Denied("Reconciliation incomplete.")
            auth.execute("UPDATE fence SET mode='OPEN',sequence=? WHERE id=1", (seq + 1,))
            auth.commit()
        except Exception:
            auth.rollback()
            raise
        finally:
            auth.close()

    def fixture_reauthenticate(self, subject: str) -> None:
        """Not a login implementation. Deliberate synthetic session reset."""
        auth = self._connect(self.z4)
        data = None
        try:
            auth.execute("BEGIN IMMEDIATE")
            gen, seq, mode, epoch, _, _, _ = self._state(auth)
            if mode != "OPEN":
                raise Denied("Cannot prepare synthetic session while closed.")
            if auth.execute("SELECT 1 FROM revoked WHERE subject=? LIMIT 1",
                            (subject,)).fetchone():
                raise Denied("Revoked subject cannot be reauthenticated.")
            data = self._connect(self.z3)
            data.execute("UPDATE session SET active=1,epoch=? WHERE subject=?", (epoch, subject))
            data.commit()
        finally:
            if data is not None:
                data.close()
            auth.close()

    def attempt(self, subject: str, organization: str, generation: int,
                epoch: int, writer: str = "portal-http", nonce: str | None = None,
                pause_before_effect: Callable[[], None] | None = None) -> bool:
        """Toy commit barrier. Z4 SQLite lock serializes with simulated revoke.

        This is NOT a distributed cross-service commit guarantee; no external
        effects, API, SQL grants, real session, encryption or real identities.
        """
        auth = self._connect(self.z4)
        data = None
        try:
            auth.execute("BEGIN IMMEDIATE")
            gen, _, mode, current_epoch, _, _, _ = self._state(auth)
            if mode != "OPEN" or generation != gen or epoch != current_epoch:
                raise Denied("Stale generation/session or unavailable fence.")
            if writer not in WRITERS:
                raise Denied("Writer not listed in synthetic manifest.")
            if auth.execute("SELECT 1 FROM revoked WHERE subject=? AND organization=?",
                            (subject, organization)).fetchone():
                raise Denied("Revoked membership.")
            data = self._connect(self.z3)
            data.execute("BEGIN IMMEDIATE")
            active = data.execute("""SELECT 1 FROM membership WHERE
                                     subject=? AND organization=? AND active=1""",
                                  (subject, organization)).fetchone()
            session = data.execute("""SELECT 1 FROM session WHERE
                                      subject=? AND epoch=? AND active=1""",
                                   (subject, epoch)).fetchone()
            if not active or not session:
                raise Denied("Missing trusted synthetic membership or active session.")
            if pause_before_effect is not None:
                pause_before_effect()
            if nonce is not None:
                if not nonce or len(nonce) > 80:
                    raise Denied("Invalid synthetic effect key.")
                try:
                    data.execute("INSERT INTO effect VALUES (?,?,?,?)",
                                 (nonce, subject, organization, writer))
                except sqlite3.IntegrityError as exc:
                    raise Denied("Duplicate synthetic effect key.") from exc
            data.commit()
            auth.commit()
            return True
        except Exception:
            if data is not None:
                data.rollback()
            auth.rollback()
            raise
        finally:
            if data is not None:
                data.close()
            auth.close()

    def effects(self) -> list[tuple]:
        db = self._connect(self.z3)
        try:
            return db.execute("""SELECT nonce,subject,organization,writer
                                 FROM effect ORDER BY nonce""").fetchall()
        finally:
            db.close()

    def member_active(self, subject: str, organization: str) -> bool:
        db = self._connect(self.z3)
        try:
            row = db.execute("""SELECT active FROM membership WHERE
                                subject=? AND organization=?""",
                             (subject, organization)).fetchone()
            return bool(row and row[0])
        finally:
            db.close()
