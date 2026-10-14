<#
.SYNOPSIS
    Deja Chrome listo para la bandeja de CVs: descarga directo (sin preguntar
    dónde guardar) en la carpeta sincronizada con el NAS.

.DESCRIPTION
    Se corre UNA vez en la computadora de reclutamiento (la que tiene WhatsApp
    Web abierto y Synology Drive Client sincronizando la bandeja de CVs).
    Escribe políticas de Chrome del usuario actual (HKCU, no requiere
    administrador):

      PromptForDownloadLocation = 0     -> ya no aparece «Guardar como…»
      DownloadDirectory         = ...   -> todo se descarga en la bandeja
      AutomaticDownloadsAllowedForUrls  -> WhatsApp Web puede descargar varios
                                           archivos seguidos sin preguntar

    Después hay que cerrar Chrome por completo y abrirlo de nuevo; en
    chrome://policy deben aparecer las tres políticas.

    Para deshacerlo: .\configurar-chrome-bandeja-cv.ps1 -Quitar

.EXAMPLE
    .\configurar-chrome-bandeja-cv.ps1 -Carpeta "C:\Users\Reclutamiento\SynologyDrive\bandeja-cv"
#>
param(
    [string] $Carpeta,
    [switch] $Quitar
)

$ErrorActionPreference = 'Stop'
$ruta = 'HKCU:\Software\Policies\Google\Chrome'
$rutaDescargas = Join-Path $ruta 'AutomaticDownloadsAllowedForUrls'

if ($Quitar) {
    if (Test-Path $ruta) {
        Remove-ItemProperty -Path $ruta -Name 'PromptForDownloadLocation', 'DownloadDirectory' -ErrorAction SilentlyContinue
    }
    if (Test-Path $rutaDescargas) {
        Remove-Item -Path $rutaDescargas -Recurse -Force
    }
    Write-Output 'Listo: Chrome vuelve a su configuración normal de descargas. Reinicia Chrome.'
    exit 0
}

if (-not $Carpeta) {
    throw 'Indica la carpeta sincronizada de la bandeja de CVs con -Carpeta "C:\...\bandeja-cv".'
}

if (-not (Test-Path $Carpeta)) {
    throw "No existe la carpeta '$Carpeta'. Primero configura Synology Drive Client para sincronizar la bandeja de CVs."
}

New-Item -Path $ruta -Force | Out-Null
New-ItemProperty -Path $ruta -Name 'PromptForDownloadLocation' -PropertyType DWord -Value 0 -Force | Out-Null
New-ItemProperty -Path $ruta -Name 'DownloadDirectory' -PropertyType String -Value $Carpeta -Force | Out-Null

New-Item -Path $rutaDescargas -Force | Out-Null
New-ItemProperty -Path $rutaDescargas -Name '1' -PropertyType String -Value 'https://web.whatsapp.com' -Force | Out-Null

Write-Output "Listo: Chrome descargará sin preguntar en '$Carpeta'."
Write-Output 'Cierra Chrome por completo (también en la bandeja de Windows) y ábrelo de nuevo. Verifica en chrome://policy.'
