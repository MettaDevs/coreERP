"""Klien Valkey minimal (RESP2 lewat socket) untuk harness: kosongkan, simpan, dan pulihkan sesi siap pakai.

Hanya dipakai harness di host, terhadap Valkey eksperimen yang diterbitkan di 127.0.0.1. Nilai diperlakukan sebagai
byte, jadi payload sesi apa pun aman disalin. Isi sesi adalah rahasia dan tidak pernah dicetak.
"""

from __future__ import annotations

import base64
import socket

HOST = "127.0.0.1"
PORT = 18646


class Valkey:
    def __init__(self, host: str = HOST, port: int = PORT, timeout: float = 10) -> None:
        self.socket = socket.create_connection((host, port), timeout=timeout)
        self.buffer = b""

    def close(self) -> None:
        self.socket.close()

    def __enter__(self) -> "Valkey":
        return self

    def __exit__(self, *exc) -> None:
        self.close()

    def send(self, *args: bytes | str | int) -> None:
        parts = [b"*%d\r\n" % len(args)]
        for arg in args:
            data = arg if isinstance(arg, bytes) else str(arg).encode()
            parts.append(b"$%d\r\n%s\r\n" % (len(data), data))
        self.socket.sendall(b"".join(parts))

    def line(self) -> bytes:
        while b"\r\n" not in self.buffer:
            chunk = self.socket.recv(65536)
            if not chunk:
                raise ConnectionError("Valkey menutup koneksi.")
            self.buffer += chunk
        line, _, self.buffer = self.buffer.partition(b"\r\n")
        return line

    def exact(self, size: int) -> bytes:
        while len(self.buffer) < size + 2:
            chunk = self.socket.recv(65536)
            if not chunk:
                raise ConnectionError("Valkey menutup koneksi.")
            self.buffer += chunk
        data, self.buffer = self.buffer[:size], self.buffer[size + 2:]
        return data

    def reply(self):
        line = self.line()
        kind, rest = line[:1], line[1:]
        if kind == b"+":
            return rest.decode()
        if kind == b"-":
            raise RuntimeError("Valkey: " + rest.decode())
        if kind == b":":
            return int(rest)
        if kind == b"$":
            size = int(rest)
            return None if size < 0 else self.exact(size)
        if kind == b"*":
            size = int(rest)
            return None if size < 0 else [self.reply() for _ in range(size)]
        raise RuntimeError(f"Balasan Valkey tidak dikenal: {line[:20]!r}")

    def command(self, *args: bytes | str | int):
        self.send(*args)
        return self.reply()

    def keys(self) -> list[bytes]:
        cursor, found = b"0", []
        while True:
            cursor, batch = self.command("SCAN", cursor, "COUNT", 1000)
            found.extend(batch)
            if cursor == b"0":
                return found

    def snapshot(self) -> list[dict]:
        """Semua kunci beserta nilai string dan sisa TTL; dipakai sesudah login sekali per stack."""
        entries = []
        for key in self.keys():
            kind = self.command("TYPE", key)
            if kind != "string":
                raise RuntimeError(f"Kunci {key[:40]!r} bertipe {kind}; snapshot sesi hanya menyalin string.")
            entries.append({"key": base64.b64encode(key).decode(), "value": base64.b64encode(self.command("GET", key)).decode()})
        return entries

    def restore(self, entries: list[dict], ttl_seconds: int) -> int:
        """Kosongkan Valkey lalu tulis ulang sesi dengan TTL penuh, lewat pipeline agar cepat."""
        self.command("FLUSHALL")
        for entry in entries:
            self.send("SET", base64.b64decode(entry["key"]), base64.b64decode(entry["value"]), "EX", ttl_seconds)
        for _ in entries:
            if self.reply() != "OK":
                raise RuntimeError("SET sesi gagal.")
        return int(self.command("DBSIZE"))
