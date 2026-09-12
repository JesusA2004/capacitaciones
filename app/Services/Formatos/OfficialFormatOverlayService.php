<?php

namespace App\Services\Formatos;

use App\Models\OfficialFormat;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * Genera un PDF nuevo a partir de un OfficialFormat pintando los datos del
 * colaborador ENCIMA del PDF original (overlay), sin tocar su contenido —
 * a diferencia de App\Services\Plantillas\PlantillaDocumentoService (que
 * reemplaza placeholders dentro de un DOCX), aqui el documento oficial es
 * fijo (docs/FORMATOS_OFICIALES.md). Usa setasign/fpdi-fpdf: importa cada
 * pagina del PDF original como plantilla y escribe texto encima con las
 * coordenadas de OfficialFormat::$overlay_config.
 */
class OfficialFormatOverlayService
{
    /**
     * Catalogo cerrado de campos que se pueden colocar sobre un formato
     * oficial — unica fuente de verdad para el configurador (frontend) y
     * para validar overlay_config al guardarlo. Los valores vienen de
     * App\Services\Plantillas\PlaceholderResolver::resolver() (mismo
     * resolver que usan las plantillas DOCX, para no duplicar logica de
     * "que dato viene de donde").
     *
     * @var array<string, string>
     */
    public const CAMPOS_DISPONIBLES = [
        'nombre_completo' => 'Nombre completo',
        'nombre_colaborador' => 'Nombre (sin apellidos)',
        'apellidos_colaborador' => 'Apellidos',
        'puesto' => 'Puesto',
        'sucursal' => 'Sucursal',
        'departamento' => 'Departamento',
        'fecha_actual' => 'Fecha actual',
        'fecha_ingreso' => 'Fecha de ingreso',
        'curp' => 'CURP',
        'rfc' => 'RFC',
        'nss' => 'NSS',
        'domicilio' => 'Domicilio',
        'telefono' => 'Teléfono',
        'correo' => 'Correo',
        'dias_vacaciones' => 'Días de vacaciones',
        'fecha_inicio_permiso' => 'Fecha inicio de permiso',
        'fecha_fin_permiso' => 'Fecha fin de permiso',
        'motivo_permiso' => 'Motivo del permiso',
        'observaciones' => 'Observaciones',
        'finiquito_fecha_baja' => 'Finiquito: fecha de baja',
        'finiquito_antiguedad' => 'Finiquito: antigüedad',
        'finiquito_sueldo_diario' => 'Finiquito: sueldo diario',
        'finiquito_sueldo_mensual' => 'Finiquito: sueldo mensual',
        'finiquito_sueldo_pendiente' => 'Finiquito: sueldo pendiente',
        'finiquito_vacaciones_pendientes' => 'Finiquito: vacaciones pendientes',
        'finiquito_prima_vacacional' => 'Finiquito: prima vacacional',
        'finiquito_aguinaldo_proporcional' => 'Finiquito: aguinaldo proporcional',
        'finiquito_indemnizacion' => 'Finiquito: indemnización',
        'finiquito_bonos_extra' => 'Finiquito: bonos extra',
        'finiquito_descuentos' => 'Finiquito: descuentos',
        'finiquito_adeudos' => 'Finiquito: adeudos',
        'finiquito_otros_conceptos' => 'Finiquito: otros conceptos',
        'finiquito_total_ajustado' => 'Finiquito: total ajustado',
        'finiquito_fecha_generacion' => 'Finiquito: fecha de generación',
    ];

    public function __construct(
        private readonly OfficialFormatStorageService $storage,
    ) {}

    /**
     * Datos ficticios para la vista previa del configurador (nunca hay un
     * colaborador real de por medio ahí, ver Rh\FormatoOficialController).
     *
     * @return array<string, string>
     */
    public static function datosMuestra(): array
    {
        return [
            'nombre_completo' => 'Juan Pérez García',
            'nombre_colaborador' => 'Juan',
            'apellidos_colaborador' => 'Pérez García',
            'puesto' => 'Analista de Sistemas',
            'sucursal' => 'Corporativo',
            'departamento' => 'Sistemas',
            'fecha_actual' => now()->format('d/m/Y'),
            'fecha_ingreso' => '01/01/2020',
            'curp' => 'PEGJ800101HDFRRN01',
            'rfc' => 'PEGJ800101AB1',
            'nss' => '12345678901',
            'domicilio' => 'Calle Ejemplo 123, Colonia Centro',
            'telefono' => '5512345678',
            'correo' => 'juan.perez@ejemplo.com',
            'dias_vacaciones' => '12',
            'fecha_inicio_permiso' => '01/01/2026',
            'fecha_fin_permiso' => '05/01/2026',
            'motivo_permiso' => 'Motivo de ejemplo',
            'observaciones' => 'Observación de ejemplo',
            'finiquito_fecha_baja' => now()->format('d/m/Y'),
            'finiquito_antiguedad' => '2 año(s), 3 mes(es)',
            'finiquito_sueldo_diario' => '$500.00',
            'finiquito_sueldo_mensual' => '$15,000.00',
            'finiquito_sueldo_pendiente' => '$0.00',
            'finiquito_vacaciones_pendientes' => '12 días',
            'finiquito_prima_vacacional' => '$1,500.00',
            'finiquito_aguinaldo_proporcional' => '$1,850.00',
            'finiquito_indemnizacion' => '$0.00',
            'finiquito_bonos_extra' => '$0.00',
            'finiquito_descuentos' => '$0.00',
            'finiquito_adeudos' => '$0.00',
            'finiquito_otros_conceptos' => '$0.00',
            'finiquito_total_ajustado' => '$3,350.00',
            'finiquito_fecha_generacion' => now()->format('d/m/Y'),
        ];
    }

    /**
     * @param  array<string, string>  $datos  Ya resueltos (nombre_completo, puesto, etc.)
     */
    public function generar(OfficialFormat $formato, array $datos): string
    {
        if ($formato->file_type !== 'pdf') {
            throw new RuntimeException('OfficialFormatOverlayService solo genera a partir de un formato base PDF.');
        }

        $stream = $this->storage->disco()->readStream($formato->source_path);

        if ($stream === null) {
            throw new RuntimeException('No se pudo leer el PDF original del formato oficial.');
        }

        $config = $formato->overlay_config ?? [];
        $porPagina = $this->agruparPorPagina($config, $datos);

        try {
            $pdf = new Fpdi;
            $totalPaginas = $pdf->setSourceFile($stream);

            for ($pagina = 1; $pagina <= $totalPaginas; $pagina++) {
                $idPlantilla = $pdf->importPage($pagina);
                $tamano = $pdf->getTemplateSize($idPlantilla);

                if (! is_array($tamano)) {
                    throw new RuntimeException("No se pudo leer el tamaño de la página {$pagina} del PDF original.");
                }

                $pdf->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
                $pdf->useTemplate($idPlantilla);

                foreach ($porPagina[$pagina] ?? [] as $entrada) {
                    $this->dibujarCampo($pdf, $entrada['campo'], $entrada['valor']);
                }
            }

            return (string) $pdf->Output('S');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $config
     * @param  array<string, string>  $datos
     * @return array<int, array<int, array{campo: array<string, mixed>, valor: string}>>
     */
    private function agruparPorPagina(array $config, array $datos): array
    {
        $porPagina = [];

        foreach ($config as $clave => $campo) {
            if (! array_key_exists($clave, self::CAMPOS_DISPONIBLES)) {
                continue;
            }

            if (($campo['enabled'] ?? false) !== true) {
                continue;
            }

            $valor = trim((string) ($datos[$clave] ?? ''));

            if ($valor === '') {
                continue;
            }

            $pagina = max(1, (int) ($campo['pagina'] ?? 1));
            $porPagina[$pagina][] = ['campo' => $campo, 'valor' => $valor];
        }

        return $porPagina;
    }

    /**
     * @param  array<string, mixed>  $campo
     */
    private function dibujarCampo(Fpdi $pdf, array $campo, string $valor): void
    {
        [$r, $g, $b] = $this->hexARgb((string) ($campo['color'] ?? '#111111'));
        $tamanoFuente = (float) ($campo['font_size'] ?? 10);
        $x = (float) ($campo['x'] ?? 10);
        $y = (float) ($campo['y'] ?? 10);
        $anchoMaximo = isset($campo['max_width']) && $campo['max_width'] !== ''
            ? (float) $campo['max_width']
            : 80.0;
        $alineacion = match ($campo['align'] ?? 'left') {
            'center' => 'C',
            'right' => 'R',
            default => 'L',
        };

        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont('Helvetica', '', $tamanoFuente);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($anchoMaximo, $tamanoFuente * 0.5, $this->aLatin1($valor), 0, $alineacion);
    }

    /**
     * Las fuentes core de FPDF (Helvetica/Times/Courier) no soportan UTF-8:
     * esperan ISO-8859-1 (Latin-1). Sin esta conversion, acentos/eñes salen
     * como caracteres corruptos en el PDF final.
     */
    private function aLatin1(string $texto): string
    {
        try {
            return mb_convert_encoding($texto, 'ISO-8859-1', 'UTF-8');
        } catch (Throwable) {
            return $texto;
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexARgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6 || preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return [17, 17, 17];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
