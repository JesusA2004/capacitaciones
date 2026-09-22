<?php

namespace App\Services\Nomina;

use App\Enums\TipoConceptoNomina;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Carga MANUAL administrativa de recibos internos semanales desde un
 * CSV/XLSX preparado por RH (no es una integración con NOI ni con ningún
 * otro sistema). Formato "largo": un renglón por concepto.
 *
 *   numero_empleado | tipo (percepcion/deduccion) | concepto | importe | cantidad? | observaciones?
 *
 * El periodo (inicio, fin, fecha de pago) se indica en la petición, igual
 * para todo el archivo. Reglas auditables:
 *  - toda fila que no cuadra se reporta con su número de fila y motivo,
 *    nunca se ignora en silencio;
 *  - si un colaborador tiene alguna fila inválida, NO se genera su recibo
 *    (queda reportado), para no emitir recibos incompletos;
 *  - `simular = true` valida y reporta sin escribir nada.
 */
class ReciboNominaImportService
{
    private const COLUMNAS_REQUERIDAS = ['numero_empleado', 'tipo', 'concepto', 'importe'];

    public function __construct(
        private readonly ReciboNominaService $recibos,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * @param  array<string, mixed>  $periodo  periodo_inicio, periodo_fin, fecha_pago?, simular? (validado por ImportarRecibosRequest).
     * @return array{lote: string|null, simulacion: bool, filas_leidas: int, recibos_generados: int, colaboradores: list<array<string, mixed>>, errores: list<array{fila: int|null, numero_empleado: string|null, motivo: string}>}
     */
    public function importar(UploadedFile $archivo, array $periodo, User $actor): array
    {
        $filas = $this->leer($archivo);
        $simular = (bool) ($periodo['simular'] ?? false);
        $periodoInicio = (string) $periodo['periodo_inicio'];
        $periodoFin = (string) $periodo['periodo_fin'];
        $fechaPago = isset($periodo['fecha_pago']) ? (string) $periodo['fecha_pago'] : $periodoFin;
        $errores = [];
        /** Conceptos válidos agrupados por número de empleado. */
        $porEmpleado = [];
        /** Números de empleado con al menos una fila inválida. */
        $invalidos = [];

        foreach ($filas as $numeroFila => $fila) {
            $numero = trim((string) ($fila['numero_empleado'] ?? ''));
            $tipo = Str::of((string) ($fila['tipo'] ?? ''))->lower()->ascii()->trim()->toString();
            $concepto = trim((string) ($fila['concepto'] ?? ''));
            $importe = $fila['importe'] ?? null;
            $cantidad = $fila['cantidad'] ?? null;

            $motivo = match (true) {
                $numero === '' => 'Falta el número de empleado.',
                TipoConceptoNomina::tryFrom($tipo) === null => sprintf('Tipo «%s» inválido: usa percepcion o deduccion.', $tipo),
                $concepto === '' => 'Falta el concepto.',
                ! is_numeric($importe) || (float) $importe < 0 => sprintf('Importe «%s» inválido.', is_scalar($importe) ? (string) $importe : ''),
                $cantidad !== null && $cantidad !== '' && ! is_numeric($cantidad) => 'Cantidad inválida.',
                default => null,
            };

            if ($motivo !== null) {
                $errores[] = ['fila' => $numeroFila, 'numero_empleado' => $numero !== '' ? $numero : null, 'motivo' => $motivo];
                $invalidos[$numero] = true;

                continue;
            }

            $porEmpleado[$numero][] = [
                'tipo' => $tipo,
                'concepto' => $concepto,
                'importe' => (float) $importe,
                'cantidad' => is_numeric($cantidad) ? (float) $cantidad : 1.0,
                'observaciones' => isset($fila['observaciones']) && $fila['observaciones'] !== '' ? (string) $fila['observaciones'] : null,
            ];
        }

        // Filas sin número de empleado ya quedaron reportadas arriba con su fila.
        unset($invalidos['']);

        // Un empleado con TODAS sus filas inválidas también debe reportarse.
        foreach (array_keys($invalidos) as $numeroInvalido) {
            $porEmpleado[$numeroInvalido] ??= [];
        }

        $colaboradores = Colaborador::query()->whereIn('numero_empleado', array_map('strval', array_keys($porEmpleado)))->get()->keyBy('numero_empleado');
        $lote = $simular ? null : 'IMP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
        $resultados = [];
        $generados = 0;

        foreach ($porEmpleado as $numero => $conceptos) {
            $numero = (string) $numero;
            $colaborador = $colaboradores->get($numero);

            if ($colaborador === null) {
                $errores[] = ['fila' => null, 'numero_empleado' => $numero, 'motivo' => 'No existe un colaborador con ese número de empleado.'];

                continue;
            }

            if (! $this->alcance->alcanzaColaborador($actor, $colaborador)) {
                $errores[] = ['fila' => null, 'numero_empleado' => $numero, 'motivo' => 'El colaborador está fuera de tu alcance organizacional.'];

                continue;
            }

            if (isset($invalidos[$numero])) {
                $errores[] = ['fila' => null, 'numero_empleado' => $numero, 'motivo' => 'No se generó el recibo: el colaborador tiene filas inválidas (ver arriba).'];

                continue;
            }

            $percepciones = 0.0;
            $deducciones = 0.0;

            foreach ($conceptos as $c) {
                if ($c['tipo'] === TipoConceptoNomina::Percepcion->value) {
                    $percepciones += $c['importe'];
                } else {
                    $deducciones += $c['importe'];
                }
            }

            $resumen = [
                'numero_empleado' => $numero,
                'colaborador' => $colaborador->nombreCompleto(),
                'conceptos' => count($conceptos),
                'total_percepciones' => round($percepciones, 2),
                'total_deducciones' => round($deducciones, 2),
                'neto' => round($percepciones - $deducciones, 2),
                'recibo_id' => null,
            ];

            if (! $simular) {
                try {
                    $recibo = $this->recibos->generar($colaborador, [
                        'periodo_inicio' => $periodoInicio,
                        'periodo_fin' => $periodoFin,
                        'fecha_pago' => $fechaPago,
                        'tipo_periodo' => ReciboNominaService::TIPO_PERIODO_SEMANAL,
                        'conceptos' => $conceptos,
                    ], $actor, $lote);
                    $resumen['recibo_id'] = $recibo->id;
                    $generados++;
                } catch (ValidationException $e) {
                    $errores[] = ['fila' => null, 'numero_empleado' => $numero, 'motivo' => collect($e->errors())->flatten()->implode(' ')];

                    continue;
                } catch (Throwable $e) {
                    $errores[] = ['fila' => null, 'numero_empleado' => $numero, 'motivo' => 'Error inesperado al generar el recibo; revisa el log.'];
                    report($e);

                    continue;
                }
            }

            $resultados[] = $resumen;
        }

        if (! $simular) {
            $this->auditoria->registrar('recibos_nomina_importados', null, $actor, [
                'lote' => $lote,
                'archivo' => $archivo->getClientOriginalName(),
                'periodo' => $periodoInicio.' / '.$periodoFin,
                'generados' => $generados,
                'errores' => count($errores),
            ]);
        }

        return [
            'lote' => $lote,
            'simulacion' => $simular,
            'filas_leidas' => count($filas),
            'recibos_generados' => $generados,
            'colaboradores' => $resultados,
            'errores' => $errores,
        ];
    }

    /**
     * @return array<int, array<string, mixed>> fila (número real del archivo) => columnas normalizadas
     */
    private function leer(UploadedFile $archivo): array
    {
        try {
            $hoja = IOFactory::load((string) $archivo->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        } catch (Throwable) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el archivo. Usa CSV o XLSX con encabezados en la primera fila.']);
        }

        $encabezados = array_map(
            fn ($h) => Str::of((string) $h)->lower()->ascii()->trim()->replace([' ', '-'], '_')->toString(),
            array_shift($hoja) ?? [],
        );

        $faltantes = array_diff(self::COLUMNAS_REQUERIDAS, $encabezados);

        if ($faltantes !== []) {
            throw ValidationException::withMessages(['archivo' => 'Faltan columnas: '.implode(', ', $faltantes).'.']);
        }

        $filas = [];

        foreach ($hoja as $indice => $valores) {
            if (count(array_filter($valores, fn ($v) => $v !== null && trim((string) $v) !== '')) === 0) {
                continue;
            }

            $fila = [];

            foreach ($encabezados as $posicion => $columna) {
                $valor = $valores[$posicion] ?? null;
                $fila[$columna] = is_string($valor) ? trim($valor) : $valor;
            }

            // +2: la fila 1 del archivo son los encabezados y el índice empieza en 0.
            $filas[(int) $indice + 2] = $fila;
        }

        return $filas;
    }
}
