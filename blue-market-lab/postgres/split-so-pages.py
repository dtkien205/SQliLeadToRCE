#!/usr/bin/env python3
from pathlib import Path


PAGE_SIZE = 2048
POSTGRES_DIR = Path(__file__).resolve().parent
SOURCE = POSTGRES_DIR / "artifacts" / "pg_rev_shell.so"
OUTPUT = POSTGRES_DIR / "artifacts" / "pg_rev_shell_pages"


raw = SOURCE.read_bytes()
page_count = (len(raw) + PAGE_SIZE - 1) // PAGE_SIZE
OUTPUT.mkdir(parents=True, exist_ok=True)

for pageno in range(page_count):
    start = pageno * PAGE_SIZE
    page = raw[start:start + PAGE_SIZE]
    (OUTPUT / f"page-{pageno:03}.hex").write_text(page.hex() + "\n", encoding="ascii")

(OUTPUT / "manifest.txt").write_text(
    f"input={SOURCE}\n"
    f"size_bytes={len(raw)}\n"
    f"page_size={PAGE_SIZE}\n"
    f"page_count={page_count}\n",
    encoding="ascii",
)

print(f"Wrote {page_count} pages to {OUTPUT}")
