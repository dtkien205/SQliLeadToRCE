#!/usr/bin/env python3
import socket
import time


HOST = "0.0.0.0"
PORT = 4444


def read_output(conn: socket.socket) -> None:
    time.sleep(0.4)
    conn.setblocking(False)
    try:
        while True:
            try:
                data = conn.recv(8192)
            except BlockingIOError:
                break

            if not data:
                break

            print(data.decode("utf-8", errors="replace"), end="")
    finally:
        conn.setblocking(True)


with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as server:
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind((HOST, PORT))
    server.listen(1)

    print(f"LISTENING on {HOST}:{PORT}")
    conn, addr = server.accept()

    with conn:
        print(f"CONNECTED from {addr[0]}:{addr[1]}")

        while True:
            cmd = input("sh> ").strip()
            if not cmd:
                continue

            conn.sendall((cmd + "\n").encode("ascii", errors="ignore"))

            if cmd == "exit":
                break

            read_output(conn)
