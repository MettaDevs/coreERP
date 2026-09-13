<#
.SYNOPSIS
    Menyegarkan blok alamat lingkungan di berkas hosts Windows.

.DESCRIPTION
    Menjalankan `php artisan environment:hosts`, lalu mengganti isi di antara penanda #ERP dan
    #END ERP dengan hasilnya. Baris di luar penanda itu tidak disentuh sama sekali.

    Perlu dijalankan setiap kali sebuah tenant atau lingkungan baru lahir — tetapi HANYA di mesin
    pengembang. Di server tidak ada berkas hosts: satu record wildcard menjawab setiap tenant dan
    setiap lingkungan tanpa pekerjaan per pelanggan, dan itu justru alasan utama memilih wildcard.

.EXAMPLE
    # Dari PowerShell yang dijalankan sebagai Administrator:
    .\deploy\traefik\update-hosts.ps1

.EXAMPLE
    # Lihat dulu apa yang akan ditulis, tanpa menyentuh apa pun:
    .\deploy\traefik\update-hosts.ps1 -WhatIf
#>

[CmdletBinding(SupportsShouldProcess = $true)]
param(
    # Alamat yang dituju tiap nama. Ubah bila dev server tidak berjalan di mesin ini.
    [string] $Ip = '127.0.0.1'
)

$ErrorActionPreference = 'Stop'

$begin = '#ERP'
$end = '#END ERP'
$hostsPath = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'

# Diperiksa di depan, dan sengaja tidak meminta elevasi sendiri. Skrip yang menaikkan haknya sendiri
# adalah skrip yang dijalankan orang tanpa membaca apa yang akan ditulisnya ke berkas sistem.
# `-WhatIf` dikecualikan supaya siapa pun dapat melihat apa yang AKAN ditulis tanpa menaikkan hak
# lebih dulu. Menuntut Administrator untuk sekadar melihat adalah cara tercepat membuat orang
# menjalankannya langsung tanpa pernah melihat isinya.
$identity = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $WhatIfPreference -and -not $identity.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "Berkas hosts hanya dapat ditulis sebagai Administrator. Buka PowerShell lewat 'Run as administrator', lalu jalankan lagi."
}

$coreDir = Join-Path $PSScriptRoot '..\..\apps\core'
if (-not (Test-Path $coreDir)) {
    throw "Folder apps/core tidak ditemukan dari $PSScriptRoot. Jalankan skrip ini dari dalam repo."
}

Push-Location $coreDir
try {
    <#
        TANPA `2>&1`, dan itu bukan kelalaian.

        PowerShell 5.1 membungkus tiap baris stderr sebuah program native menjadi ErrorRecord
        begitu ia diarahkan begitu. Dengan $ErrorActionPreference = 'Stop', satu peringatan PHP yang
        sama sekali tidak berbahaya — "The opentelemetry extension must be loaded" misalnya —
        berubah menjadi NativeCommandError yang menghentikan seluruh skrip.

        Terjadi pada percobaan pertama: skripnya mati sebelum menulis satu baris pun, dan yang
        terlihat hanyalah tumpukan merah tentang ekstensi yang tidak ada hubungannya dengan hosts.

        Jadi stderr dibiarkan mengalir ke konsol apa adanya, dan yang menentukan berhasil atau tidak
        cuma $LASTEXITCODE.
    #>
    $generated = & php artisan environment:hosts --bare "--ip=$Ip"
    if ($LASTEXITCODE -ne 0) {
        throw "environment:hosts gagal dengan kode $LASTEXITCODE. Jalankan perintahnya langsung untuk melihat sebabnya: php artisan environment:hosts"
    }
}
finally {
    Pop-Location
}

<#
    Hanya baris yang benar-benar berbentuk "<ip> <nama>" yang lolos.

    Penjaga kedua, dan ia perlu: apa pun yang tersasar ke stdout — peringatan PHP, keluaran
    debug, banner perkakas — akan tertulis ke berkas hosts sebagai baris sampah kalau yang
    disaring hanya "baris tidak kosong". Berkas hosts yang rusak mematikan resolusi nama di
    seluruh mesin, bukan hanya untuk repo ini.
#>
$entries = @($generated | Where-Object { $_ -match '^\s*\d{1,3}(\.\d{1,3}){3}\s+\S+\s*$' })

if ($entries.Count -eq 0) {
    throw "environment:hosts tidak memulangkan satu pun alamat. Periksa COREERP_BASE_DOMAIN di apps/core/.env."
}

$block = @($begin) + $entries + @($end)

$current = Get-Content -Path $hostsPath
$from = [Array]::IndexOf($current, $begin)
$to = [Array]::IndexOf($current, $end)

if ($from -ge 0 -and $to -gt $from) {
    # Blok sudah ada: yang di luarnya dipertahankan apa adanya. Berkas hosts sering memuat baris
    # milik hal lain — dan menimpanya seluruhnya akan mematikan sesuatu yang tidak ada hubungannya
    # dengan repo ini, tanpa jejak.
    $result = @()
    if ($from -gt 0) { $result += $current[0..($from - 1)] }
    $result += $block
    if ($to -lt ($current.Length - 1)) { $result += $current[($to + 1)..($current.Length - 1)] }
}
elseif ($from -ge 0 -or $to -ge 0) {
    throw "Penanda $begin dan $end tidak berpasangan di $hostsPath. Rapikan tangan dulu supaya skrip ini tidak menebak batasnya."
}
else {
    $result = $current + @('') + $block
}

if ($PSCmdlet.ShouldProcess($hostsPath, "Menulis $($block.Count - 2) alamat lingkungan")) {
    $backup = "$hostsPath.cadangan-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
    Copy-Item -Path $hostsPath -Destination $backup
    Set-Content -Path $hostsPath -Value $result -Encoding ASCII

    Write-Output "Ditulis $($block.Count - 2) alamat ke $hostsPath"
    Write-Output "Cadangan: $backup"

    # Windows menyimpan hasil pembacaan hosts. Tanpa ini, alamat yang baru ditambahkan kadang masih
    # dijawab "tidak ditemukan" sampai beberapa menit kemudian — kegagalan yang terbaca seperti
    # salah tulis padahal tulisannya benar.
    & ipconfig /flushdns | Out-Null
    Write-Output "Singgahan DNS dibersihkan."
}
