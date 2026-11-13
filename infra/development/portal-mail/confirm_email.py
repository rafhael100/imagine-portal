"""Operator-only private console. No execution at import time."""
import getpass
import os
import re
import sys

from docker_ledger import DockerLedger
from relay import WorkspaceRelay
from verification import VerificationCoordinator, VerificationUnavailable


def normalize_code(value):
    # Clipboard formatting is not part of the numeric code. No prefix,
    # punctuation or arbitrary text is removed; those inputs still fail.
    value = value.replace("\x1b[200~", "").replace("\x1b[201~", "")
    result = "".join(value.split())
    if re.fullmatch(r"[0-9]{6}", result) is None:
        raise ValueError("invalid-code-format")
    return result


def main():
    if (len(sys.argv) != 1 or not sys.stdin.isatty() or not sys.stdout.isatty()
            or os.geteuid() != 0
            or os.environ.get("PORTAL_EMAIL_CONFIRM") != "PRIVATE_INVITED_EMAIL_ONLY"):
        print("Console privado do operador obrigatorio.")
        return 64
    ledger = DockerLedger()
    flow = VerificationCoordinator(ledger, WorkspaceRelay())
    reference = None
    completed = False
    try:
        print("Remetente: sistema@imaginecomunicacao.com.br")
        print("Destino: rafhael@imaginecomunicacao.com.br")
        if input("Digite ENVIAR para receber o codigo de confirmacao: ") != "ENVIAR":
            return 1
        receipt = flow.send(WorkspaceRelay.RECIPIENT)
        reference = receipt.reference
        print("Google aceitou a mensagem. Abra seu e-mail; validade de 15 minutos.")
        print("Cole o codigo apenas neste console. Nao envie no chat.")
        for attempt in range(5):
            token = getpass.getpass("Codigo de 6 numeros recebido por e-mail (digitacao oculta): ")
            try:
                try:
                    token = normalize_code(token)
                except ValueError:
                    print("Entrada recebida com " + str(len(token))
                          + " caracteres; esperado: codigo de 6 numeros.")
                    print("A entrada nao foi comparada com o banco. Confira a colagem no console.")
                    continue
                result = flow.confirm(reference, token)
                if result.email_verified:
                    completed = True
                    print("PASS e-mail confirmado; conta continua invited; login bloqueado.")
                    return 0
            except VerificationUnavailable:
                print("Falha no acesso ao registro de confirmacao. Nao indica codigo incorreto.")
                return 1
            except Exception:
                print("Confirmacao recusada. Confira o codigo e o prazo.")
            finally:
                token = ""
        return 1
    except (Exception, KeyboardInterrupt):
        print("Confirmacao nao concluida. Nenhum login foi liberado.")
        return 1
    finally:
        if reference and not completed:
            try:
                ledger.revoke(reference)
            except Exception:
                print("Estado incerto: reconciliacao administrativa necessaria antes de reenviar.")


if __name__ == "__main__":
    sys.exit(main())
