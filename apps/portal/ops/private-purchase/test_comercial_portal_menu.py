"""P0: Click the actual 'Portal do Cliente' links in the built Comercial.

Both desktop and mobile must arrive at the *running* isolated Laravel
login. Then real synthetic customer/admin sessions must remain role-bound.
The build's reviewed production HTTPS URL is only rewritten in a fresh,
unserved temporary CI copy to the local HTTP test server.
No connection to the public Portal, VPS, PSP or real customers.
"""
from __future__ import annotations

from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from tempfile import TemporaryDirectory
from threading import Thread
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
import json
import os
import re
import shutil

assert os.environ.get("GITHUB_ACTIONS") == "true"
assert os.environ.get("PORTAL_CUSTOMER_COMMERCIAL_E2E") == "1"
assert os.environ.get("PORTAL_COMERCIAL_MENU_E2E") == "1"
runner = Path(os.environ["RUNNER_TEMP"]).resolve(strict=True)
root = Path(os.environ["IMAGINE_COMERCIAL_CUSTOMER_PREVIEW_ROOT"]).resolve(strict=True)
assert root.is_dir() and not root.is_symlink()
source_path = root / "index.html"
assert source_path.is_file() and not source_path.is_symlink()
original = source_path.read_text(encoding="utf-8")
official = 'href="https://portal.imaginecomunicacao.com.br/login"'
local_url = "http://127.0.0.1:18083/_preview/portal"
assert original.count(official) == 2, "Both real Astro menus must contain the reviewed Portal login URL"
assert original.count("Portal do Cliente</a>") == 2
fixture = Path("/tmp/portal-customer-ci.json")
assert fixture.is_file(), "Owned customer fixture from earlier real PG18 browser test missing"
customer = json.loads(fixture.read_text(encoding="utf-8"))
assert set(customer) == {"user", "order"}
assert re.fullmatch(r"demo-a-[a-f0-9]{10}", customer["user"])
assert re.fullmatch(
    r"http://127\.0\.0\.1:18083/_preview/portal/shop/orders/TEST-[a-f0-9]{32}",
    customer["order"]
)

class StaticReadOnly(SimpleHTTPRequestHandler):
    def log_message(self,*_args:object)->None:
        pass
    def do_POST(self)->None:
        self.send_error(405,"No payment or account creation on the static CI website")

with TemporaryDirectory(prefix="imagine-comercial-menu-",dir=str(runner)) as scratch:
    tree=Path(scratch)/"website"
    shutil.copytree(root,tree,symlinks=False)
    candidate = original.replace(official,'href="'+local_url+'"')
    assert candidate != original and candidate.count('href="'+local_url+'"')==2
    (tree/"index.html").write_text(candidate,encoding="utf-8")
    assert source_path.read_text(encoding="utf-8")==original, "Original Astro candidate must remain untouched"
    with ThreadingHTTPServer(
        ("127.0.0.1",0),partial(StaticReadOnly,directory=str(tree))
    ) as server:
        port=server.server_port
        thread=Thread(target=server.serve_forever,daemon=True)
        thread.start()
        try:
            with sync_playwright() as pw:
                browser=pw.chromium.launch(
                    executable_path=shutil.which("google-chrome") or shutil.which("chromium"),
                    headless=True,args=["--no-sandbox","--disable-background-networking"],
                )
                try:
                    for role,width,username,password in (
                        ("client",1280,customer["user"],"ci-owned-customer-only-2026"),
                        ("admin",390,"administrador.teste","ci-admin-only-2026"),
                    ):
                        context=browser.new_context(viewport={"width":width,"height":900})
                        page=context.new_page()
                        blocked=[]
                        def local_only(route):
                            url=urlsplit(route.request.url)
                            if url.scheme=="http" and url.hostname=="127.0.0.1" and url.port in (port,18083):
                                route.continue_()
                            elif url.scheme=="data":
                                route.continue_()
                            else:
                                blocked.append(url.hostname or url.scheme)
                                route.abort()
                        page.route("**/*",local_only)
                        response=page.goto("http://127.0.0.1:"+str(port)+"/")
                        assert response and response.status==200
                        assert page.evaluate("document.documentElement.scrollWidth <= innerWidth+1")
                        if role=="client":
                            link=page.locator('nav[aria-label="Navegação principal"] a.nav-cta')
                        else:
                            page.locator("header details.mobile-menu > summary").click()
                            link=page.locator('nav[aria-label="Navegação móvel"] a.nav-cta-mobile')
                        assert link.count()==1 and link.is_visible()
                        assert link.inner_text().strip()=="Portal do Cliente"
                        assert link.get_attribute("href")==local_url
                        link.click()
                        page.wait_for_url(local_url)
                        assert page.get_by_role(
                            "heading",name="Bem-vindo ao seu Portal"
                        ).count()==1
                        page.locator('[name="username"]').fill(username)
                        page.locator('[name="password"]').fill(password)
                        page.get_by_role("button",name="Entrar",exact=True).click()
                        page.wait_for_url(local_url+"/"+role+"/overview")
                        if role=="client":
                            own=page.request.get(customer["order"])
                            assert own.status==200,"Owned order must survive login from Comercial"
                            assert page.request.get(local_url+"/admin/orders").status==403
                            page.goto(local_url+"/client/services")
                            order_id=customer["order"].rsplit("/",1)[1]
                            assert page.locator('tr[data-customer-order="'+order_id+'"]').count()==1
                        else:
                            assert page.request.get(local_url+"/admin/orders").status==200
                            assert page.request.get(local_url+"/client/services").status==403
                        assert not blocked,("External network forbidden",blocked)
                        print("PASS built Comercial menu -> Laravel login ->",role,"owned role-specific panel")
                        context.close()
                finally:
                    browser.close()
        finally:
            server.shutdown()
            thread.join(timeout=5)
print("PASS both Portal do Cliente menu links, synthetic client/admin roles; NO-GO for public accounts/payments/deploy")
