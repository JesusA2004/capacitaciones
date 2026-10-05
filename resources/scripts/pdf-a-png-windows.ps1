# Rasteriza cada página de un PDF a PNG con el motor PDF nativo de Windows
# (Windows.Data.Pdf, WinRT — Windows 10/11 y Server 2016+). Sin dependencias
# externas: no requiere Ghostscript, Poppler ni ImageMagick.
#
# Uso: powershell -NoProfile -ExecutionPolicy Bypass -File pdf-a-png-windows.ps1 -Origen in.pdf -Carpeta outdir -Dpi 50
# Escribe outdir\pagina-001.png, pagina-002.png… y en stdout una línea JSON
# por página: {"pagina":1,"ancho_pt":612,"alto_pt":792,"archivo":"..."}.
#
# Lo usa App\Services\DocumentosMaestros\Calidad\RasterizadorPdf para el QA
# visual de documentos maestros (comparar ORIGINAL vs GENERADO). En Linux se
# usa pdftoppm (DOCUMENTOS_QA_PDFTOPPM_PATH).
param(
    [Parameter(Mandatory = $true)][string]$Origen,
    [Parameter(Mandatory = $true)][string]$Carpeta,
    [int]$Dpi = 50
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

try {
    Add-Type -AssemblyName System.Runtime.WindowsRuntime
    $null = [Windows.Storage.StorageFile, Windows.Storage, ContentType = WindowsRuntime]
    $null = [Windows.Data.Pdf.PdfDocument, Windows.Data.Pdf, ContentType = WindowsRuntime]
    $null = [Windows.Storage.Streams.InMemoryRandomAccessStream, Windows.Storage.Streams, ContentType = WindowsRuntime]

    $asTaskGenerico = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
            $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1'
        })[0]
    $asTaskAccion = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
            $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction'
        })[0]

    function Esperar($operacion, [Type]$tipo) {
        $tarea = $asTaskGenerico.MakeGenericMethod($tipo).Invoke($null, @($operacion))
        $null = $tarea.Wait(60000)
        if (-not $tarea.IsCompleted) { throw 'Tiempo de espera agotado al rasterizar el PDF.' }
        return $tarea.Result
    }

    function EsperarAccion($accion) {
        $tarea = $asTaskAccion.Invoke($null, @($accion))
        $null = $tarea.Wait(60000)
        if (-not $tarea.IsCompleted) { throw 'Tiempo de espera agotado al rasterizar la página.' }
    }

    $rutaCompleta = (Resolve-Path -LiteralPath $Origen).Path
    $archivo = Esperar ([Windows.Storage.StorageFile]::GetFileFromPathAsync($rutaCompleta)) ([Windows.Storage.StorageFile])
    $pdf = Esperar ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($archivo)) ([Windows.Data.Pdf.PdfDocument])

    if (-not (Test-Path -LiteralPath $Carpeta)) { $null = New-Item -ItemType Directory -Path $Carpeta }

    for ($i = 0; $i -lt $pdf.PageCount; $i++) {
        $pagina = $pdf.GetPage([uint32]$i)
        try {
            $anchoPt = $pagina.Size.Width * 72 / 96
            $altoPt = $pagina.Size.Height * 72 / 96
            $opciones = New-Object Windows.Data.Pdf.PdfPageRenderOptions
            $opciones.DestinationWidth = [uint32][Math]::Max(1, [Math]::Round($anchoPt * $Dpi / 72))
            $opciones.DestinationHeight = [uint32][Math]::Max(1, [Math]::Round($altoPt * $Dpi / 72))
            $opciones.BackgroundColor = [Windows.UI.Color]::FromArgb(255, 255, 255, 255)

            $flujo = New-Object Windows.Storage.Streams.InMemoryRandomAccessStream
            EsperarAccion ($pagina.RenderToStreamAsync($flujo, $opciones))

            $destino = Join-Path $Carpeta ('pagina-{0:D3}.png' -f ($i + 1))
            $lector = [System.IO.WindowsRuntimeStreamExtensions]::AsStreamForRead($flujo.GetInputStreamAt(0))
            $salida = [System.IO.File]::Create($destino)
            try { $lector.CopyTo($salida) } finally { $salida.Dispose(); $lector.Dispose(); $flujo.Dispose() }

            $linea = @{ pagina = $i + 1; ancho_pt = [Math]::Round($anchoPt, 2); alto_pt = [Math]::Round($altoPt, 2); archivo = $destino } | ConvertTo-Json -Compress
            [Console]::Out.WriteLine($linea)
        }
        finally {
            $pagina.Dispose()
        }
    }

    exit 0
}
catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
