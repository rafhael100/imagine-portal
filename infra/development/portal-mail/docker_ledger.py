"""Host-to-isolated-PHP bridge; plaintext bearer travels only over child stdin."""
import json
import subprocess
from pathlib import Path

from relay import WorkspaceRelay
from verification import IssuedChallenge


class LedgerError(RuntimeError):
    pass


class DockerLedger:
    DIRECTORY = Path("/srv/imagine/portal-development/infra/development/portal-db")
    SOURCE = Path("/srv/imagine/portal-mail/infra/development/portal-mail")

    def _request(self, action, **fields):
        command = ["docker", "compose", "run", "--rm", "-T", "--interactive=true",
                   "-e", "PORTAL_EMAIL_LEDGER=PRIVATE_INVITED_ONLY",
                   "-v", str(self.SOURCE) + ":/opt/portal-mail:ro",
                   "-v", str(self.DIRECTORY / ".private/email-proof-key")
                   + ":/run/secrets/email-proof-key:ro",
                   "provision", "php", "/opt/portal-mail/bridge.php"]
        result = None
        failed = False
        try:
            result = subprocess.run(command, cwd=self.DIRECTORY,
                input=json.dumps(dict(action=action, recipient=WorkspaceRelay.RECIPIENT,
                                      **fields)), text=True, capture_output=True,
                timeout=25, check=False)
            if result.returncode != 0 or len(result.stdout) > 1024:
                raise LedgerError()
            response = json.loads(result.stdout)
            if response.get("ok") is not True:
                raise LedgerError()
        except Exception:
            failed = True
        if failed:
            raise LedgerError("private-ledger-refused") from None
        return response["value"]

    def install(self):
        return self._request("install") is True

    def upgrade_ttl(self):
        return self._request("upgrade-ttl15") is True

    def reserve(self, recipient, token, ttl_seconds):
        if recipient != WorkspaceRelay.RECIPIENT:
            raise LedgerError("private-ledger-refused")
        result = self._request("reserve", token=token, ttl=ttl_seconds)
        return IssuedChallenge(result["reference"], result["recipient"])

    def mark_accepted(self, reference):
        return self._request("accepted", reference=reference) is True

    def revoke(self, reference):
        self._request("revoke", reference=reference)

    def consume(self, reference, token):
        return self._request("consume", reference=reference, token=token) is True
