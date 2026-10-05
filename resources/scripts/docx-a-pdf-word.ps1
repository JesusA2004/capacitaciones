# Convierte un DOCX a PDF con Microsoft Word (automatización COM): fidelidad
# NATIVA — el mismo motor de composición con el que Jurídico diseñó el
# documento.
#
# Uso:
#   powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File docx-a-pdf-word.ps1 `
#       -Origen in.docx -Destino out.pdf [-ArchivoPid word.pid]
#
# Lo usa App\Services\Formatos\Motor\ConversorDocxPdf. Garantías:
#  - el DOCX se abre en SOLO LECTURA, sin agregarlo a recientes, sin
#    diálogos de conversión/codificación ni macros;
#  - se exporta con ExportAsFixedFormat (calidad de impresión, fuentes que
#    no se puedan incrustar se rasterizan en vez de sustituirse);
#  - siempre se cierra el documento, se cierra Word y se liberan los objetos
#    COM (no quedan WINWORD.EXE huérfanos);
#  - el PID del WINWORD.EXE que ESTE script creó se escribe en -ArchivoPid:
#    si PHP corta por tiempo, mata solo ese proceso (nunca un Word que el
#    usuario tenga abierto);
#  - rutas con espacios y Unicode (se pasan como argumentos, no se
#    interpolan en una línea de comandos).
param(
    [Parameter(Mandatory = $true)][string]$Origen,
    [Parameter(Mandatory = $true)][string]$Destino,
    [string]$ArchivoPid = ''
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$word = $null
$documentos = $null
$documento = $null
$pidPropio = $null
$codigo = 1

try {
    if (-not (Test-Path -LiteralPath $Origen)) {
        throw "No existe el archivo de origen: $Origen"
    }

    $antes = @(Get-Process -Name WINWORD -ErrorAction SilentlyContinue | ForEach-Object { $_.Id })
    $word = New-Object -ComObject Word.Application
    $despues = @(Get-Process -Name WINWORD -ErrorAction SilentlyContinue | ForEach-Object { $_.Id })
    $nuevos = @($despues | Where-Object { $antes -notcontains $_ })

    if ($nuevos.Count -eq 1) {
        $pidPropio = $nuevos[0]
        if ($ArchivoPid -ne '') { Set-Content -LiteralPath $ArchivoPid -Value $pidPropio -Encoding ascii }
    }

    $word.Visible = $false
    $word.DisplayAlerts = 0                      # wdAlertsNone
    $word.ScreenUpdating = $false
    $word.Options.UpdateLinksAtOpen = $false
    $word.AutomationSecurity = 3                 # msoAutomationSecurityForceDisable (sin macros)

    $documentos = $word.Documents
    # Open(FileName, ConfirmConversions=false, ReadOnly=true, AddToRecentFiles=false).
    # (La forma larga con parámetros VARIANT* no la acepta el enlazador COM de
    # PowerShell; los diálogos ya están apagados con DisplayAlerts.)
    $documento = $documentos.Open([string](Resolve-Path -LiteralPath $Origen).Path, $false, $true, $false)

    # ExportAsFixedFormat(OutputFileName, ExportFormat=17 PDF, OpenAfterExport,
    #   OptimizeFor=0 impresión, Range=0 todo, From, To, Item=0 contenido,
    #   IncludeDocProps, KeepIRM, CreateBookmarks=0, DocStructureTags,
    #   BitmapMissingFonts, UseISO19005_1)
    $documento.ExportAsFixedFormat($Destino, 17, $false, 0, 0, 1, 1, 0, $true, $true, 0, $true, $true, $false)

    if (-not (Test-Path -LiteralPath $Destino)) {
        throw 'Word no produjo el PDF.'
    }

    $codigo = 0
}
catch {
    [Console]::Error.WriteLine("Word: " + $_.Exception.Message)
    $codigo = 1
}
finally {
    if ($null -ne $documento) {
        try { $documento.Close(0) | Out-Null } catch { }           # wdDoNotSaveChanges
        [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($documento)
    }
    if ($null -ne $documentos) {
        [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($documentos)
    }
    if ($null -ne $word) {
        try { $word.Quit(0) | Out-Null } catch { }
        [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($word)
    }
    $documento = $null
    $documentos = $null
    $word = $null
    [System.GC]::Collect()
    [System.GC]::WaitForPendingFinalizers()

    # Si el Word que ESTE script abrió sigue vivo (Quit colgado), se termina.
    if ($null -ne $pidPropio) {
        $proceso = Get-Process -Id $pidPropio -ErrorAction SilentlyContinue
        if ($null -ne $proceso) {
            $null = $proceso.WaitForExit(5000)
            if (-not $proceso.HasExited) { Stop-Process -Id $pidPropio -Force -ErrorAction SilentlyContinue }
        }
    }
}

exit $codigo
