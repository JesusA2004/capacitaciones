# Convierte un DOCX a PDF con Microsoft Word (automatización COM).
# Uso: powershell -NoProfile -ExecutionPolicy Bypass -File docx-a-pdf-word.ps1 -Origen in.docx -Destino out.pdf
# Lo usa App\Services\Formatos\Motor\ConversorDocxPdf cuando
# FORMATOS_CONVERSOR=word (servidores/equipos Windows con Office). En Linux
# se usa LibreOffice headless (FORMATOS_LIBREOFFICE_PATH).
param(
    [Parameter(Mandatory = $true)][string]$Origen,
    [Parameter(Mandatory = $true)][string]$Destino
)

$ErrorActionPreference = 'Stop'
$word = $null
$documento = $null

try {
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $word.DisplayAlerts = 0
    # Solo lectura, sin agregar a recientes, sin diálogos de conversión.
    $documento = $word.Documents.Open($Origen, $false, $true, $false)
    # 17 = wdFormatPDF
    $documento.SaveAs2($Destino, 17)
    exit 0
}
catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
finally {
    if ($documento -ne $null) { $documento.Close(0) | Out-Null }
    if ($word -ne $null) { $word.Quit() | Out-Null }
}
