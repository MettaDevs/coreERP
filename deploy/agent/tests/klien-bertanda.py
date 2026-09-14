#!/usr/bin/env python3
"""Klien bertanda tangan RFC 9421 yang ditulis terpisah dari agen, hanya untuk pengujian.

Kalau agen dan admin.erp tiruan sama-sama salah dengan cara yang sama, pengujian yang hanya
mempertemukan keduanya tetap lulus. Klien ini implementasi ketiga, disusun dari teks kontrak, untuk
membuktikan admin.erp tiruan menerima tanda tangan yang benar dan menolak yang salah — terlepas dari
apa pun yang dilakukan agen.

    klien-bertanda.py --url URL --key KUNCI.pem --site ID --method POST --path /api/agent/v1/report \
        [--body BERKAS] [--created-offset DETIK] [--digest-palsu]

Baris pertama keluaran adalah kode status HTTP, sisanya isi jawaban.
"""

import argparse
import base64
import hashlib
import subprocess
import sys
import time
import urllib.error
import urllib.request


def utama():
    p = argparse.ArgumentParser()
    p.add_argument('--url', required=True)
    p.add_argument('--key', required=True)
    p.add_argument('--site', required=True)
    p.add_argument('--method', required=True)
    p.add_argument('--path', required=True)
    p.add_argument('--body')
    p.add_argument('--created-offset', type=int, default=0)
    p.add_argument('--digest-palsu', action='store_true')
    a = p.parse_args()

    isi = b''
    if a.body:
        with open(a.body, 'rb') as f:
            isi = f.read()

    sumber_digest = isi + (b' ' if a.digest_palsu else b'')
    digest = 'sha-256=:' + base64.b64encode(hashlib.sha256(sumber_digest).digest()).decode() + ':'
    dibuat = int(time.time()) + a.created_offset
    parameter = f'("@method" "@path" "content-digest");created={dibuat};keyid="{a.site}";alg="rsa-v1_5-sha256"'
    dasar = f'"@method": {a.method}\n"@path": {a.path}\n"content-digest": {digest}\n"@signature-params": {parameter}'

    tanda = subprocess.run(
        ['openssl', 'dgst', '-sha256', '-sign', a.key], input=dasar.encode(), capture_output=True, check=True,
    ).stdout

    permintaan = urllib.request.Request(
        a.url + a.path,
        data=isi if a.method != 'GET' else None,
        method=a.method,
        headers={
            'Content-Type': 'application/json',
            'Content-Digest': digest,
            'Signature-Input': f'sig1={parameter}',
            'Signature': 'sig1=:' + base64.b64encode(tanda).decode() + ':',
        },
    )

    try:
        with urllib.request.urlopen(permintaan, timeout=30) as jawaban:
            status, badan = jawaban.status, jawaban.read()
    except urllib.error.HTTPError as e:
        status, badan = e.code, e.read()

    sys.stdout.buffer.write(f'{status}\n'.encode() + badan)
    sys.stdout.buffer.flush()


if __name__ == '__main__':
    utama()
