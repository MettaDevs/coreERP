#!/usr/bin/env python3
"""admin.erp tiruan untuk menguji agen situs. Hanya pustaka standar Python.

Ini bukan admin.erp versi kecil. Tugasnya satu: menolak apa pun yang tidak sesuai kontrak
`apps/control-plane/contracts/openapi-agent.yaml`, seketat yang dituntut kontrak itu.

- Tanda tangan RFC 9421 disusun ulang dari header yang diterima dan diperiksa dengan `openssl dgst
  -verify`, memakai kunci publik yang tersimpan — bukan dengan membandingkan string buatan agen.
- Skema dibaca dari kontraknya sendiri, bukan disalin tangan ke sini. Salinan tangan akan diam-diam
  tertinggal pada hari kontraknya berubah, dan pengujian lalu membuktikan agen cocok dengan salinan
  yang basi. `run-tests.sh` mengonversi YAML kontrak menjadi JSON supaya berkas ini tetap stdlib.
- Kata kunci skema yang tidak dikenal validator ini menghentikan server, alih-alih diabaikan. Validator
  yang melewatkan `minimum` tanpa suara membuat pengujian lulus untuk alasan yang salah.

Endpoint `/_test/...` dipakai `run-tests.sh` untuk mengantre operasi dan membaca apa yang diterima.

Berkas pemasang disajikan seperti admin.erp menyajikannya (PS-07 di docs/todo/pasang-satu-perintah):
`/pasang.sh` dengan alamat server ini ditanam di isiannya, dan `/agen/<berkas>` byte persis dari berkas yang
diuji. Pengujian pasang.sh karena itu berjalan lewat jalur yang sama dengan server klien, tanpa jalan pintas
di skrip yang dipasang.
"""

import argparse
import base64
import hashlib
import json
import os
import re
import secrets
import subprocess
import tempfile
import threading
import time
import traceback
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

BATAS_SELISIH_DETIK = 300
LEASE_DETIK = 900
ISIAN_ALAMAT_ADMIN = b'@@COREERP_ADMIN_URL@@'
# Host registry yang dijawab kredensial tiruan. Bukan host sungguhan: shim docker tidak pernah menyambung.
REGISTRY_UJI = 'registry.uji.test'
BERKAS_AGEN = ('coreerp-agent', 'coreerp-agent.service', 'coreerp-agent.timer', 'env.template', 'update.sh', 'kunci-rilis.pub')

KATA_KUNCI_SKEMA = {
    'type', 'properties', 'required', 'additionalProperties', 'enum', 'maxLength', 'minLength',
    'maxItems', 'items', 'pattern', 'format', 'description', '$ref', 'minimum',
}
POLA_DATE_TIME = re.compile(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$')
POLA_DATE = re.compile(r'^\d{4}-\d{2}-\d{2}$')
POLA_BASE64 = r'[A-Za-z0-9+/]+={0,2}'


class Tolak(Exception):
    def __init__(self, status, error, message=None):
        super().__init__(error)
        self.status = status
        self.error = error
        self.message = message


class Putus(Exception):
    """Sambungan ditutup tanpa jawaban — menirukan jawaban yang hilang di jaringan."""


def tolak_tanda_tangan():
    # Kontrak: selalu 401 dengan error yang sama, tanpa menyebut bagian mana yang salah.
    return Tolak(401, 'signature_invalid')


def ulid():
    return '01J' + ''.join(secrets.choice('0123456789ABCDEFGHJKMNPQRSTVWXYZ') for _ in range(23))


def iso(detik):
    return datetime.fromtimestamp(detik, timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def cocok_tipe(nilai, tipe):
    return {
        'object': lambda: isinstance(nilai, dict),
        'array': lambda: isinstance(nilai, list),
        'string': lambda: isinstance(nilai, str),
        'integer': lambda: isinstance(nilai, int) and not isinstance(nilai, bool),
        'number': lambda: isinstance(nilai, (int, float)) and not isinstance(nilai, bool),
        'boolean': lambda: isinstance(nilai, bool),
        'null': lambda: nilai is None,
    }[tipe]()


class Kontrak:
    def __init__(self, dokumen):
        self.dokumen = dokumen

    def ref(self, skema):
        while isinstance(skema, dict) and '$ref' in skema:
            simpul = self.dokumen
            for bagian in skema['$ref'].lstrip('#/').split('/'):
                simpul = simpul[bagian]
            skema = simpul
        return skema

    def operasi(self, jalur, metode):
        return self.dokumen['paths'][jalur][metode]

    def skema_isi(self, jalur, metode):
        return self.operasi(jalur, metode)['requestBody']['content']['application/json']['schema']

    def skema_jawaban(self, jalur, metode, status):
        jawaban = self.operasi(jalur, metode)['responses'].get(str(status))
        if jawaban is None:
            raise RuntimeError(f'kontrak tidak mengenal jawaban {status} untuk {metode} {jalur}')
        konten = self.ref(jawaban).get('content', {}).get('application/json')
        return konten['schema'] if konten else None

    def enum_parameter(self, jalur, metode, nama):
        for parameter in self.operasi(jalur, metode).get('parameters', []):
            if parameter['name'] == nama:
                return parameter['schema'].get('enum')
        return None

    def periksa(self, nilai, skema, jalur='$'):
        skema = self.ref(skema)
        asing = set(skema) - KATA_KUNCI_SKEMA
        if asing:
            raise RuntimeError(f'kata kunci skema tidak didukung validator tiruan di {jalur}: {sorted(asing)}')

        tipe = skema.get('type')
        if tipe is not None:
            daftar = tipe if isinstance(tipe, list) else [tipe]
            if not any(cocok_tipe(nilai, t) for t in daftar):
                return [f'{jalur}: tipe harus {daftar}']

        if nilai is None:
            return []

        galat = []

        if 'enum' in skema and nilai not in skema['enum']:
            galat.append(f'{jalur}: di luar enum {skema["enum"]}')

        if isinstance(nilai, str):
            if 'maxLength' in skema and len(nilai) > skema['maxLength']:
                galat.append(f'{jalur}: lebih panjang dari {skema["maxLength"]}')
            if 'minLength' in skema and len(nilai) < skema['minLength']:
                galat.append(f'{jalur}: lebih pendek dari {skema["minLength"]}')
            if 'pattern' in skema and not re.search(skema['pattern'], nilai):
                galat.append(f'{jalur}: tidak cocok pola {skema["pattern"]}')
            if skema.get('format') == 'date-time' and not POLA_DATE_TIME.match(nilai):
                galat.append(f'{jalur}: bukan date-time')
            if skema.get('format') == 'date' and not POLA_DATE.match(nilai):
                galat.append(f'{jalur}: bukan date')

        if isinstance(nilai, (int, float)) and not isinstance(nilai, bool):
            if 'minimum' in skema and nilai < skema['minimum']:
                galat.append(f'{jalur}: lebih kecil dari {skema["minimum"]}')

        if isinstance(nilai, list):
            if 'maxItems' in skema and len(nilai) > skema['maxItems']:
                galat.append(f'{jalur}: lebih dari {skema["maxItems"]} anggota')
            if 'items' in skema:
                for i, anggota in enumerate(nilai):
                    galat += self.periksa(anggota, skema['items'], f'{jalur}[{i}]')

        if isinstance(nilai, dict):
            sifat = skema.get('properties', {})
            for wajib in skema.get('required', []):
                if wajib not in nilai:
                    galat.append(f'{jalur}: {wajib} wajib ada')
            if skema.get('additionalProperties') is False:
                for kunci in nilai:
                    if kunci not in sifat:
                        galat.append(f'{jalur}: kunci {kunci} di luar skema')
            for kunci, isi in nilai.items():
                if kunci in sifat:
                    galat += self.periksa(isi, sifat[kunci], f'{jalur}.{kunci}')

        return galat


def openssl_verifikasi(kunci_pem, data, tanda):
    with tempfile.TemporaryDirectory() as folder:
        jalur_kunci = os.path.join(folder, 'kunci.pem')
        jalur_data = os.path.join(folder, 'data')
        jalur_tanda = os.path.join(folder, 'tanda')
        with open(jalur_kunci, 'w') as f:
            f.write(kunci_pem)
        with open(jalur_data, 'wb') as f:
            f.write(data)
        with open(jalur_tanda, 'wb') as f:
            f.write(tanda)
        hasil = subprocess.run(
            ['openssl', 'dgst', '-sha256', '-verify', jalur_kunci, '-signature', jalur_tanda, jalur_data],
            capture_output=True,
        )
        return hasil.returncode == 0


def kunci_publik_sah(pem):
    # Kontrak: PEM `-----BEGIN PUBLIC KEY-----`, RSA, minimal 2048 bit.
    if not isinstance(pem, str) or '-----BEGIN PUBLIC KEY-----' not in pem:
        return False
    hasil = subprocess.run(['openssl', 'pkey', '-pubin', '-noout', '-text'], input=pem.encode(), capture_output=True)
    if hasil.returncode != 0:
        return False
    m = re.search(rb'Public-Key: \((\d+) bit\)', hasil.stdout)
    return bool(m) and int(m.group(1)) >= 2048


class Admin:
    def __init__(self, kontrak, folder_rilis, kunci_lisensi, pasang, berkas_agen):
        self.kontrak = kontrak
        self.folder_rilis = folder_rilis
        self.kunci_lisensi = kunci_lisensi
        self.pasang = pasang
        self.berkas_agen = berkas_agen
        self.isi_agen_pengganti = {}
        self.kunci = threading.Lock()
        self.token = {}
        self.situs = {}
        self.laporan = []
        self.operasi = {}
        self.urutan = []
        self.permintaan = []
        self.interval = 60
        # Kredensial registry: setiap permintaan dicatat tanpa kata sandinya. `kredensial_paksa` adalah antrean
        # jawaban yang dipakai lebih dulu sebelum jalur biasa; lihat /_test/registry-credential.
        self.kredensial = []
        self.kredensial_paksa = []

    def kedaluwarsakan(self, operasi):
        if operasi['status'] == 'running' and operasi['lease_until'] < time.time():
            operasi['status'] = 'failed'
            operasi['failure_message'] = 'lease habis'

    def buat_operasi(self, site_id, data):
        """Dipanggil dengan self.kunci dipegang."""
        nomor = f'op-{len(self.urutan) + 1:04d}'
        self.operasi[nomor] = {
            'id': nomor,
            'site_id': site_id,
            'operation': data['operation'],
            'parameters': data.get('parameters', {}),
            'status': 'requested',
            'lease_until': 0,
            'langkah': [],
            'percobaan_langkah': 0,
            'failure_message': None,
            'lepas_setelah': data.get('lepas_setelah'),
            'tanpa_validasi': bool(data.get('tanpa_validasi')),
            # Klaim sebanyak ini dijawab 204 dulu — admin.erp yang belum menentukan rilisnya.
            'sembunyi_klaim': int(data.get('sembunyi_klaim') or 0),
        }
        self.urutan.append(nomor)
        return nomor


class Penangan(BaseHTTPRequestHandler):
    server_version = 'fake-admin/1'
    protocol_version = 'HTTP/1.1'

    def log_message(self, format, *args):  # noqa: A002 — nama dari BaseHTTPRequestHandler
        pass

    def do_GET(self):  # noqa: N802
        self.tangani('get')

    def do_POST(self):  # noqa: N802
        self.tangani('post')

    @property
    def admin(self):
        return self.server.admin

    def tangani(self, metode):
        jalur = urlsplit(self.path).path
        panjang = int(self.headers.get('Content-Length') or 0)
        isi = self.rfile.read(panjang) if panjang else b''
        self.keyid = None

        try:
            status, badan, jenis = self.rute(metode, jalur, isi)
        except Putus:
            with self.admin.kunci:
                self.admin.permintaan.append({'method': metode.upper(), 'path': jalur, 'status': 0, 'keyid': self.keyid})
            self.close_connection = True
            return
        except Tolak as t:
            status = t.status
            muatan = {'error': t.error}
            if t.message:
                muatan['message'] = t.message
            badan, jenis = json.dumps(muatan).encode(), 'application/json'
        except Exception as e:  # noqa: BLE001 — server uji melaporkan apa pun sebagai 500
            # Juga ke stderr, yang ditulis run-tests.sh ke log/fake-admin.log: agen tidak mencetak isi jawaban
            # 500, dan tanpa ini sebabnya hilang bersama jawabannya.
            traceback.print_exc()
            status, badan, jenis = 500, json.dumps({'error': 'internal', 'message': repr(e)}).encode(), 'application/json'

        # Permintaan `/_test/...` tidak dicatat: pengujian yang menghitung permintaan agen tidak boleh ikut
        # menghitung permintaannya sendiri untuk membaca hitungan itu.
        if not jalur.startswith('/_test/'):
            with self.admin.kunci:
                self.admin.permintaan.append({
                    'method': metode.upper(), 'path': jalur, 'status': status, 'keyid': self.keyid,
                })

        self.send_response(status)
        if badan:
            self.send_header('Content-Type', jenis)
        self.send_header('Content-Length', str(len(badan or b'')))
        self.end_headers()
        if badan:
            self.wfile.write(badan)

    # --- pembantu ---------------------------------------------------------------------------------

    def json_isi(self, isi, jalur_kontrak, metode):
        try:
            data = json.loads(isi.decode('utf-8'))
        except (UnicodeDecodeError, json.JSONDecodeError):
            raise Tolak(422, 'invalid', 'isi bukan JSON')
        galat = self.admin.kontrak.periksa(data, self.admin.kontrak.skema_isi(jalur_kontrak, metode))
        if galat:
            raise Tolak(422, 'invalid', '; '.join(galat))
        return data

    def json_jawaban(self, status, data, jalur_kontrak, metode):
        # Jawaban tiruan ikut diperiksa terhadap kontrak. Tiruan yang menjawab di luar kontrak membuat agen
        # teruji terhadap bentuk yang tidak akan pernah dikirim admin.erp sungguhan.
        galat = self.admin.kontrak.periksa(data, self.admin.kontrak.skema_jawaban(jalur_kontrak, metode, status))
        if galat:
            raise RuntimeError('jawaban tiruan melanggar kontrak: ' + '; '.join(galat))
        return status, json.dumps(data).encode(), 'application/json'

    def verifikasi(self, metode, jalur, isi):
        digest = (self.headers.get('Content-Digest') or '').strip()
        masukan = (self.headers.get('Signature-Input') or '').strip()
        tanda = (self.headers.get('Signature') or '').strip()

        m = re.fullmatch(rf'sha-256=:({POLA_BASE64}):', digest)
        if not m or base64.b64decode(m.group(1)) != hashlib.sha256(isi).digest():
            raise tolak_tanda_tangan()

        if not masukan.startswith('sig1='):
            raise tolak_tanda_tangan()
        nilai_parameter = masukan[len('sig1='):]

        m = re.fullmatch(r'\("@method" "@path" "content-digest"\)((?:;[a-z]+=(?:"[^"\\]*"|[0-9]+))+)', nilai_parameter)
        if not m:
            raise tolak_tanda_tangan()

        parameter = {}
        for nama, nilai in re.findall(r';([a-z]+)=("[^"\\]*"|[0-9]+)', m.group(1)):
            if nama in parameter:
                raise tolak_tanda_tangan()
            parameter[nama] = nilai

        if set(parameter) != {'created', 'keyid', 'alg'}:
            raise tolak_tanda_tangan()
        if parameter['alg'] != '"rsa-v1_5-sha256"':
            raise tolak_tanda_tangan()
        if not parameter['created'].isdigit() or abs(time.time() - int(parameter['created'])) > BATAS_SELISIH_DETIK:
            raise tolak_tanda_tangan()
        if not (parameter['keyid'].startswith('"') and parameter['keyid'].endswith('"')):
            raise tolak_tanda_tangan()

        keyid = parameter['keyid'][1:-1]
        self.keyid = keyid

        with self.admin.kunci:
            situs = self.admin.situs.get(keyid)
            kunci_pem = situs['public_key'] if situs and not situs['dicabut'] else None
        if kunci_pem is None:
            raise tolak_tanda_tangan()

        m = re.fullmatch(rf'sig1=:({POLA_BASE64}):', tanda)
        if not m:
            raise tolak_tanda_tangan()

        dasar = (
            f'"@method": {metode}\n'
            f'"@path": {jalur}\n'
            f'"content-digest": {digest}\n'
            f'"@signature-params": {nilai_parameter}'
        )
        if not openssl_verifikasi(kunci_pem, dasar.encode(), base64.b64decode(m.group(1))):
            raise tolak_tanda_tangan()

        return keyid

    # --- rute -------------------------------------------------------------------------------------

    def rute(self, metode, jalur, isi):
        if jalur.startswith('/_test/'):
            return self.rute_uji(metode, jalur, isi)

        if metode == 'get' and jalur == '/pasang.sh':
            return self.skrip_pasang()
        m = re.fullmatch(r'/agen/([^/]+)', jalur)
        if metode == 'get' and m:
            return self.berkas_agen(m.group(1))

        if not jalur.startswith('/api/'):
            raise Tolak(404, 'not_found')
        dalam = jalur[len('/api'):]

        if metode == 'post' and dalam == '/agent/v1/enroll':
            return self.enroll(isi)

        situs = self.verifikasi(metode.upper(), jalur, isi)

        if metode == 'post' and dalam == '/agent/v1/report':
            return self.lapor(situs, isi)
        if metode == 'post' and dalam == '/agent/v1/operations/claim':
            return self.klaim(situs, isi)
        m = re.fullmatch(r'/agent/v1/operations/([^/]+)/steps', dalam)
        if metode == 'post' and m:
            return self.langkah(situs, m.group(1), isi)
        m = re.fullmatch(r'/agent/v1/releases/([^/]+)/([^/]+)/files/([^/]+)', dalam)
        if metode == 'get' and m:
            return self.berkas_rilis(*m.groups())
        if metode == 'post' and dalam == '/agent/v1/key':
            return self.ganti_kunci(situs, isi)
        if metode == 'post' and dalam == '/agent/v1/registry-credential':
            return self.kredensial_registry(situs, isi)

        raise Tolak(404, 'not_found')

    def skrip_pasang(self):
        # Alamat yang ditanam alamat server ini sendiri, seperti admin.erp menanam APP_URL-nya. Setiap
        # kemunculan isian diganti, sama dengan `str_replace` di admin.erp.
        alamat = f'http://127.0.0.1:{self.server.server_address[1]}'.encode()
        with open(self.admin.pasang, 'rb') as f:
            return 200, f.read().replace(ISIAN_ALAMAT_ADMIN, alamat), 'text/plain; charset=utf-8'

    def berkas_agen(self, nama):
        if nama not in BERKAS_AGEN:
            raise Tolak(404, 'not_found')
        with self.admin.kunci:
            pengganti = self.admin.isi_agen_pengganti.get(nama)
        if pengganti is not None:
            return 200, pengganti, 'text/plain; charset=utf-8'
        with open(self.admin.berkas_agen[nama], 'rb') as f:
            return 200, f.read(), 'text/plain; charset=utf-8'

    def enroll(self, isi):
        data = self.json_isi(isi, '/agent/v1/enroll', 'post')
        if not kunci_publik_sah(data['public_key']):
            raise Tolak(422, 'invalid', 'public_key bukan kunci RSA PEM minimal 2048 bit')

        with self.admin.kunci:
            token = self.admin.token.get(data['token'])
            if token is None or token['dipakai']:
                raise Tolak(401, 'enrollment_rejected')
            token['dipakai'] = True
            situs = ulid()
            tenant = token['tenant_id']
            self.admin.situs[situs] = {'public_key': data['public_key'], 'dicabut': False, 'riwayat_kunci': []}
            # Operasi yang dibuat bersama tokennya, seperti "Buat perintah pasang" membuat operasi install
            # sebelum server klien pernah mendaftar.
            for operasi in token['operasi']:
                self.admin.buat_operasi(situs, operasi)

        return self.json_jawaban(201, {
            'site_id': situs,
            'tenant_id': tenant,
            'tenant_name': token['tenant_name'],
            'interval_seconds': self.admin.interval,
            'update_window': {'start': '01:00', 'end': '04:00', 'timezone': 'Asia/Jakarta'},
            'license_public_key': self.admin.kunci_lisensi,
        }, '/agent/v1/enroll', 'post')

    def lapor(self, situs, isi):
        data = self.json_isi(isi, '/agent/v1/report', 'post')
        if data['site_id'] != situs:
            raise Tolak(422, 'invalid', 'site_id berbeda dari keyid')
        jawaban = {'interval_seconds': self.admin.interval}
        with self.admin.kunci:
            # Lisensi titipan pengujian ikut di jawaban laporan berikutnya saja, lalu dibuang — seperti
            # admin.erp sungguhan yang berhenti menyertakannya begitu perpanjangan tidak lagi jatuh tempo.
            # Keputusan jatuh tempo milik admin.erp, bukan agen, jadi tidak ditirukan di sini.
            lisensi = self.admin.situs.get(situs, {}).pop('lisensi_laporan', None)
            if lisensi is not None:
                jawaban['license'] = lisensi
            self.admin.laporan.append({'site_id': situs, 'isi': data, 'lisensi_dijawab': lisensi is not None})
        return self.json_jawaban(200, jawaban, '/agent/v1/report', 'post')

    def klaim(self, situs, isi):
        self.json_isi(isi, '/agent/v1/operations/claim', 'post')
        with self.admin.kunci:
            milik = [self.admin.operasi[i] for i in self.admin.urutan if self.admin.operasi[i]['site_id'] == situs]
            for operasi in milik:
                self.admin.kedaluwarsakan(operasi)
            if any(o['status'] == 'running' for o in milik):
                return 204, None, None
            pilihan = next((o for o in milik if o['status'] == 'requested'), None)
            if pilihan is None:
                return 204, None, None
            if pilihan['sembunyi_klaim'] > 0:
                pilihan['sembunyi_klaim'] -= 1
                return 204, None, None
            pilihan['status'] = 'running'
            pilihan['lease_until'] = time.time() + LEASE_DETIK
            jawaban = {
                'id': pilihan['id'],
                'operation': pilihan['operation'],
                'parameters': pilihan['parameters'],
                'lease_until': iso(pilihan['lease_until']),
            }
            tanpa_validasi = pilihan['tanpa_validasi']

        if tanpa_validasi:
            return 200, json.dumps(jawaban).encode(), 'application/json'
        return self.json_jawaban(200, jawaban, '/agent/v1/operations/claim', 'post')

    def langkah(self, situs, id_operasi, isi):
        with self.admin.kunci:
            operasi = self.admin.operasi.get(id_operasi)
            if operasi is not None:
                operasi['percobaan_langkah'] += 1

        data = self.json_isi(isi, '/agent/v1/operations/{operation}/steps', 'post')

        with self.admin.kunci:
            if operasi is None or operasi['site_id'] != situs:
                raise Tolak(409, 'operation_not_held')
            self.admin.kedaluwarsakan(operasi)
            if operasi['status'] != 'running':
                raise Tolak(409, 'operation_not_held')
            if operasi['lepas_setelah'] is not None and len(operasi['langkah']) >= operasi['lepas_setelah']:
                operasi['status'] = 'failed'
                operasi['failure_message'] = 'lease dilepas pengujian'
                raise Tolak(409, 'operation_not_held')
            if data['status'] == 'failed' and not data.get('failure_message'):
                raise Tolak(422, 'invalid', 'failed wajib membawa failure_message')

            operasi['langkah'].append(data)
            if data['status'] == 'running':
                operasi['lease_until'] = time.time() + LEASE_DETIK
                lease = iso(operasi['lease_until'])
            else:
                operasi['status'] = data['status']
                operasi['failure_message'] = data.get('failure_message')
                lease = None

        return self.json_jawaban(200, {'lease_until': lease}, '/agent/v1/operations/{operation}/steps', 'post')

    def berkas_rilis(self, edisi, rilis, nama):
        izin = self.admin.kontrak.enum_parameter('/agent/v1/releases/{edition}/{release}/files/{file}', 'get', 'file')
        if nama not in izin or not re.fullmatch(r'[A-Za-z0-9._-]+', edisi) or not re.fullmatch(r'[A-Za-z0-9._-]+', rilis):
            raise Tolak(404, 'not_found')
        if edisi.startswith('.') or rilis.startswith('.'):
            raise Tolak(404, 'not_found')
        jalur = os.path.join(self.admin.folder_rilis, edisi, rilis, nama)
        if not os.path.isfile(jalur):
            raise Tolak(404, 'not_found')
        with open(jalur, 'rb') as f:
            return 200, f.read(), 'application/octet-stream'

    def ganti_kunci(self, situs, isi):
        data = self.json_isi(isi, '/agent/v1/key', 'post')
        if not kunci_publik_sah(data['public_key']):
            raise Tolak(422, 'invalid', 'public_key bukan kunci RSA PEM minimal 2048 bit')
        with self.admin.kunci:
            catatan = self.admin.situs[situs]
            catatan['riwayat_kunci'].append(catatan['public_key'])
            catatan['public_key'] = data['public_key']
            putus = catatan.pop('putus_ganti_kunci', False)
        if putus:
            # Kunci sudah diganti, tetapi agen tidak pernah menerima 200-nya.
            raise Putus()
        return 200, None, None

    def kredensial_registry(self, situs, isi):
        jalur_kontrak = '/agent/v1/registry-credential'
        data = self.json_isi(isi, jalur_kontrak, 'post')

        with self.admin.kunci:
            catatan = {'site_id': situs, 'operation_id': data['operation_id'], 'status': None}
            self.admin.kredensial.append(catatan)
            # Satu anggota antrean per permintaan: null berarti jalur biasa, 409 atau 503 dijawab apa adanya, dan
            # objek mengganti bidang jawaban 200 — registry, username, password — untuk permintaan itu saja.
            paksa = self.admin.kredensial_paksa.pop(0) if self.admin.kredensial_paksa else None
            operasi = self.admin.operasi.get(data['operation_id'])
            if operasi is not None:
                self.admin.kedaluwarsakan(operasi)
            # Aturan kontrak: hanya operasi install atau upgrade yang sedang dipegang situs ini. Tiruan yang
            # menjawab siapa pun membuat agen yang mengirim operation_id keliru tetap lulus.
            dipegang = (
                operasi is not None and operasi['site_id'] == situs
                and operasi['operation'] in ('install', 'upgrade') and operasi['status'] == 'running'
            )
            ke = None
            if not isinstance(paksa, int) and dipegang:
                operasi['kredensial_ke'] = operasi.get('kredensial_ke', 0) + 1
                ke = operasi['kredensial_ke']

        if paksa == 503:
            catatan['status'] = 503
            return self.json_jawaban(503, {'error': 'registry_unavailable'}, jalur_kontrak, 'post')
        if isinstance(paksa, int) and paksa != 409:
            raise RuntimeError(f'status paksa kredensial tidak didukung tiruan: {paksa}')
        if paksa == 409 or not dipegang:
            catatan['status'] = 409
            return self.json_jawaban(409, {'error': 'operation_not_held'}, jalur_kontrak, 'post')

        # Satu robot per operasi; memanggil ulang mengganti robotnya. Kata sandi berawalan tetap supaya pengujian
        # dapat mencarinya di disk dan di log tanpa tiruan ini pernah menyebutnya di /_test/state.
        jawaban = {
            'registry': REGISTRY_UJI,
            'username': f'robot$coreerp+{data["operation_id"]}',
            'password': f'SandiRobotUji-{data["operation_id"]}-{ke}',
            'expires_at': iso(time.time() + 86400),
        }
        if isinstance(paksa, dict):
            jawaban.update({k: v for k, v in paksa.items() if k in ('registry', 'username', 'password')})
        catatan['status'] = 200
        return self.json_jawaban(200, jawaban, jalur_kontrak, 'post')

    # --- endpoint pengujian -----------------------------------------------------------------------

    def rute_uji(self, metode, jalur, isi):
        data = json.loads(isi.decode('utf-8')) if isi else {}

        if metode == 'post' and jalur == '/_test/tokens':
            with self.admin.kunci:
                self.admin.token[data['token']] = {
                    'dipakai': False,
                    'tenant_id': data.get('tenant_id') or ulid(),
                    'tenant_name': data.get('tenant_name') or 'Apotek Uji',
                    'operasi': data.get('operasi') or [],
                }
            return 201, b'{}', 'application/json'

        if metode == 'post' and jalur == '/_test/operations':
            with self.admin.kunci:
                nomor = self.admin.buat_operasi(data['site_id'], data)
            return 201, json.dumps({'id': nomor}).encode(), 'application/json'

        # {"isi": "..."} menggantikan isi /agen/<berkas>; {"isi": null} mengembalikan berkas aslinya.
        m = re.fullmatch(r'/_test/agen/([^/]+)', jalur)
        if metode == 'post' and m and m.group(1) in BERKAS_AGEN:
            with self.admin.kunci:
                if data.get('isi') is None:
                    self.admin.isi_agen_pengganti.pop(m.group(1), None)
                else:
                    self.admin.isi_agen_pengganti[m.group(1)] = data['isi'].encode()
            return 200, b'{}', 'application/json'

        if metode == 'get' and jalur == '/_test/state':
            with self.admin.kunci:
                keadaan = {
                    'sites': self.admin.situs,
                    'tokens': self.admin.token,
                    'reports': self.admin.laporan,
                    'operations': [self.admin.operasi[i] for i in self.admin.urutan],
                    'requests': self.admin.permintaan,
                    'registry_credentials': self.admin.kredensial,
                }
                return 200, json.dumps(keadaan).encode(), 'application/json'

        m = re.fullmatch(r'/_test/sites/([^/]+)/putus-ganti-kunci', jalur)
        if metode == 'post' and m:
            with self.admin.kunci:
                self.admin.situs[m.group(1)]['putus_ganti_kunci'] = True
            return 200, b'{}', 'application/json'

        m = re.fullmatch(r'/_test/sites/([^/]+)/lisensi-laporan', jalur)
        if metode == 'post' and m:
            with self.admin.kunci:
                self.admin.situs[m.group(1)]['lisensi_laporan'] = data
            return 200, b'{}', 'application/json'

        # {"jawab": [null, 409, 503, {"registry": "..."}, ...]} — jawaban permintaan kredensial berikutnya, berurutan;
        # sesudah antreannya habis, jalur biasa. {} mengosongkan antrean.
        if metode == 'post' and jalur == '/_test/registry-credential':
            with self.admin.kunci:
                self.admin.kredensial_paksa = list(data.get('jawab', []))
            return 200, b'{}', 'application/json'

        m = re.fullmatch(r'/_test/operations/([^/]+)/expire', jalur)
        if metode == 'post' and m:
            with self.admin.kunci:
                self.admin.operasi[m.group(1)]['lease_until'] = 0
            return 200, b'{}', 'application/json'

        m = re.fullmatch(r'/_test/validate/([A-Za-z]+)', jalur)
        if metode == 'post' and m:
            galat = self.admin.kontrak.periksa(data, self.admin.kontrak.dokumen['components']['schemas'][m.group(1)])
            return 200, json.dumps({'errors': galat}).encode(), 'application/json'

        raise Tolak(404, 'not_found')


def utama():
    argumen = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    argumen.add_argument('--contract', required=True, help='kontrak agen dalam JSON')
    argumen.add_argument('--releases', required=True, help='folder <edisi>/<rilis>/<berkas>')
    argumen.add_argument('--license-public-key', required=True, help='kunci publik lisensi (PEM)')
    argumen.add_argument('--port-file', required=True, help='berkas tempat nomor port ditulis')
    argumen.add_argument('--pasang', required=True, help='pasang.sh yang disajikan di /pasang.sh')
    argumen.add_argument('--agen', required=True, help='coreerp-agent yang disajikan di /agen/coreerp-agent')
    argumen.add_argument('--update-sh', required=True, help='update.sh yang disajikan di /agen/update.sh')
    argumen.add_argument('--folder-agen', required=True, help='folder unit systemd dan env.template')
    argumen.add_argument('--release-public-key', required=True, help='kunci publik rilis di /agen/kunci-rilis.pub')
    a = argumen.parse_args()

    with open(a.contract) as f:
        kontrak = Kontrak(json.load(f))
    with open(a.license_public_key) as f:
        kunci_lisensi = f.read()

    berkas_agen = {
        'coreerp-agent': a.agen,
        'coreerp-agent.service': os.path.join(a.folder_agen, 'coreerp-agent.service'),
        'coreerp-agent.timer': os.path.join(a.folder_agen, 'coreerp-agent.timer'),
        'env.template': os.path.join(a.folder_agen, 'env.template'),
        'update.sh': a.update_sh,
        'kunci-rilis.pub': a.release_public_key,
    }

    server = ThreadingHTTPServer(('127.0.0.1', 0), Penangan)
    server.admin = Admin(kontrak, a.releases, kunci_lisensi, a.pasang, berkas_agen)

    sementara = a.port_file + '.baru'
    with open(sementara, 'w') as f:
        f.write(str(server.server_address[1]))
    os.replace(sementara, a.port_file)

    server.serve_forever()


if __name__ == '__main__':
    utama()
