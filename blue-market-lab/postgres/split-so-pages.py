#!/usr/bin/env python3
import argparse
from pathlib import Path


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Split a PostgreSQL extension .so into 2048-byte hex pages for manual pg_largeobject demos."
    )
    parser.add_argument("input_path", help="Path to pg_rev_shell.so")
    parser.add_argument(
        "--output-dir",
        default="postgres/artifacts/pg_rev_shell_pages",
        help="Directory to write page-XXX.hex files",
    )
    parser.add_argument(
        "--page-size",
        type=int,
        default=2048,
        help="Large object page size in bytes (default: 2048)",
    )
    args = parser.parse_args()

    input_path = Path(args.input_path).resolve()
    output_dir = Path(args.output_dir).resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    raw = input_path.read_bytes()
    page_count = (len(raw) + args.page_size - 1) // args.page_size

    for page_no in range(page_count):
        start = page_no * args.page_size
        end = start + args.page_size
        chunk = raw[start:end]
        (output_dir / f"page-{page_no:03d}.hex").write_text(chunk.hex() + "\n", encoding="ascii")

    manifest = output_dir / "manifest.txt"
    manifest.write_text(
        "\n".join(
            [
                f"input={input_path}",
                f"size_bytes={len(raw)}",
                f"page_size={args.page_size}",
                f"page_count={page_count}",
                "",
                "Use each page-XXX.hex file as the value inside:",
                "decode('<hex>', 'hex')",
            ]
        )
        + "\n",
        encoding="ascii",
    )

    print(f"Wrote {page_count} page files to {output_dir}")


if __name__ == "__main__":
    main()
