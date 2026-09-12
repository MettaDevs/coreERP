<?php

declare(strict_types=1);

namespace ControlPlane\Pelanggan;

use RuntimeException;

/**
 * Permintaannya tidak pernah sampai pada jawaban yang dapat dipakai.
 *
 * Dua keadaan dikumpulkan di satu kelas, dan itu keputusan sadar:
 *
 * 1. sambungannya putus atau kehabisan waktu — Core memang tidak di sana;
 * 2. sesuatu menjawab dengan 2xx, tetapi jawabannya tidak memuat yang dijanjikan kontrak.
 *
 * Dari sisi operator keduanya berbunyi sama: **yang di ujung alamat itu bukan Core yang kita
 * harapkan**, dan yang pertama kali harus diperiksa sama — `COREERP_URL`. Membedakannya menjadi
 * dua kelas menambah cabang yang tidak pernah dipakai berbeda.
 *
 * Setiap pesan yang dilempar kelas ini **wajib menyebut alamat yang dicoba.** Tanpa itu, sebab
 * yang paling sering — alamat salah setel — menyamar sebagai gangguan jaringan, dan operator
 * mencari di tempat yang salah.
 */
class CoreTidakTerjangkau extends RuntimeException {}
