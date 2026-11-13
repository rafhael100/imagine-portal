#!/usr/bin/env python3
"""Fail-closed UI-only scope audit; NEVER permission to merge or deploy."""
from __future__ import annotations
import argparse
import json
import re
from pathlib import Path
import subprocess
import sys

DEV = "origin/dev/fast-track-portal-2026-11-05"
PINNED_BASE = "47514f4eac64a3d7ab0a0924cc890fb5a124d8b8"
LOGO = "apps/portal/public/assets/logo-imagine.png"
LOGO_SHA = "678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77"

# The complete approved candidate scope. No glob or directory exceptions.
FILES = frozenset({
 ".github/workflows/portal-wave04-offline-preview.yml",
 ".github/workflows/portal-ui-integration-gate.yml",
 "apps/portal/app/Presentation/ClientSection.php",
 "apps/portal/app/Presentation/DemoPreparationCards.php",
 "apps/portal/app/Presentation/DemoServicePreparation.php",
 "apps/portal/app/Presentation/PortablePortalStaticPreview.php",
 "apps/portal/bin/export-portable-preview.php",
 LOGO,
 "apps/portal/resources/views/components/portal/brand.blade.php",
 "apps/portal/resources/views/components/portal/layout.blade.php",
 "apps/portal/resources/views/components/portal/sidebar.blade.php",
 "apps/portal/resources/views/components/portal/styles.blade.php",
 "apps/portal/resources/views/portal/demo/overview.blade.php",
 "apps/portal/resources/views/portal/demo/preparation.blade.php",
 "apps/portal/resources/views/portal/demo/services.blade.php",
 "apps/portal/tests/Feature/PortalHelpPresentationTest.php",
 "apps/portal/tests/Feature/PortalJourneyPresentationTest.php",
 "apps/portal/tests/Feature/PortalNoticesPresentationTest.php",
 "apps/portal/tests/Feature/PortalOfflineSidebarNavigationTest.php",
 "apps/portal/tests/Feature/PortalOverviewNextStepsWaveTest.php",
 "apps/portal/tests/Feature/PortalPreparationCardsWaveTest.php",
 "apps/portal/tests/Feature/PortalPreparationMenuWaveTest.php",
 "apps/portal/tests/Feature/PortalRequestsPresentationTest.php",
 "apps/portal/tests/Feature/PortalServicePreparationWaveTest.php",
 "apps/portal/tests/Feature/PortalUiComponentsTest.php",
 "apps/portal/tools/qa_portable_preview.py",
 "apps/portal/tools/qa_ui_integration_gate.py",
})

def git(*args: str) -> bytes:
    return subprocess.run(["git", *args], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

def sha(ref: str) -> str:
    result=git("rev-parse", "--verify", ref + "^{commit}").decode().strip()
    if not re.fullmatch(r"[a-f0-9]{40}", result):
        raise ValueError("Invalid commit SHA")
    return result

def parse(raw: bytes) -> dict[str, str]:
    fields=raw.split(b"\x00")
    if fields[-1:] == [b""]:
        fields.pop()
    if len(fields) % 2 != 0:
        raise ValueError("Incomplete Git name-status output")
    found={}
    for i in range(0,len(fields),2):
        status=fields[i].decode("ascii","strict")
        path=fields[i+1].decode("utf-8","strict")
        if status not in {"A","M"} or not path or path in found:
            raise ValueError("Unapproved deletion, rename, mode or duplicate")
        found[path]=status
    return found

def check(changes: dict[str,str]) -> None:
    if set(changes) != FILES:
        raise ValueError("UI-only scope mismatch; extra="+str(sorted(set(changes)-FILES))+
                         "; missing="+str(sorted(FILES-set(changes))))
    if changes[LOGO] != "A":
        raise ValueError("Original logo must be an addition against the dev")

def self_test() -> None:
    good={path:"M" for path in FILES}
    good[LOGO]="A"
    check(good)
    bad_cases=[
      {**good,"apps/portal/routes/web.php":"M"},
      {path:status for path,status in good.items() if path!=LOGO},
    ]
    for bad in bad_cases:
        try: check(bad)
        except ValueError: pass
        else: raise AssertionError("Unexpected change accepted")
    for payload in (
        b"D\x00apps/portal/resources/views/portal/demo/services.blade.php\x00",
        b"R100\x00old\x00new\x00",
        b"M\x00duplicate\x00M\x00duplicate\x00",
    ):
        try: parse(payload)
        except ValueError: pass
        else: raise AssertionError("Unsafe diff record accepted")

def run(out: Path) -> None:
    base=sha(DEV)
    head=sha("HEAD")
    if base!=PINNED_BASE or git("merge-base",base,head).decode().strip()!=base:
        raise ValueError("Dev ref changed or candidate ancestry invalid")
    behind,ahead=map(int,git("rev-list","--left-right","--count",base+"..."+head).decode().split())
    if behind!=0 or not 1<=ahead<=10:
        raise ValueError("Unexpected history/behind count")
    changes=parse(git("diff","--name-status","--no-renames","-z",base,head))
    check(changes)
    git("diff","--check",base,head)
    files=[]
    for path,status in sorted(changes.items()):
        entries=[e for e in git("ls-tree","-z","HEAD","--",path).split(b"\x00") if e]
        if len(entries)!=1:
            raise ValueError("Missing or ambiguous Git tree entry")
        meta,actual_path=entries[0].split(b"\t",1)
        mode,kind,blob=meta.decode("ascii").split()
        if mode!="100644" or kind!="blob" or actual_path.decode("utf-8")!=path:
            raise ValueError("Executable, symlink or non-regular blob")
        if not re.fullmatch(r"[0-9a-f]{40}",blob):
            raise ValueError("Invalid blob hash")
        files.append({"path":path,"status":status,"git_blob_sha1":blob})
    matching=next(e for e in files if e["path"]==LOGO)
    if matching["git_blob_sha1"]!=LOGO_SHA or git("cat-file","-s","HEAD:"+LOGO).strip()!=b"5594":
        raise ValueError("Imagine logo provenance mismatch")
    report={
      "schema":"imagine.portal.ui-integration-review-gate.v1",
      "base_sha":base,"candidate_sha":head,
      "ahead":ahead,"behind":behind,"reviewed_paths":len(files),
      "file_scope_valid":True,"approved_for_manual_review":True,
      "operational_homologation_passed":False,
      "safe_to_merge_automatically":False,
      "customer_access_authorized":False,
      "deployment_authorized":False,
      "appsec_dba_review_completed":False,
      "files":files,
    }
    out.mkdir(parents=True,exist_ok=False)
    (out/"scope.json").write_text(json.dumps(report,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    lines=[
      "# Imagine Portal - revisao do lote de interface",
      "", "SHA da dev: "+base, "SHA da candidata: "+head,
      "Arquivos de UI verificados: "+str(len(files)),
      "Commits a frente: "+str(ahead)+"; atras: "+str(behind),
      "", "STATUS: APTO SOMENTE PARA REVISAO MANUAL.",
      "NAO E APROVACAO PARA MERGE, OPERACAO OU DEPLOY.",
      "AppSec/DBA, issue #64 e homologacao operacional permanecem pendentes.",
      "Inventario completo de caminhos e blobs no arquivo scope.json.",
    ]
    (out/"review.md").write_text("\n".join(lines)+"\n",encoding="utf-8")
    print(json.dumps({"head":head,"scope_files":len(files),"manual_review_only":True}))

if __name__=="__main__":
    try:
        parser=argparse.ArgumentParser()
        parser.add_argument("--out",type=Path)
        parser.add_argument("--self-test",action="store_true")
        args=parser.parse_args()
        if args.self_test:
            self_test()
            print("Negative scope tests passed")
        elif args.out:
            run(args.out)
        else:
            parser.error("--out or --self-test required")
    except (OSError,subprocess.CalledProcessError,ValueError,AssertionError) as exc:
        print("UI scope NO-GO: "+str(exc)[:350],file=sys.stderr)
        sys.exit(2)
