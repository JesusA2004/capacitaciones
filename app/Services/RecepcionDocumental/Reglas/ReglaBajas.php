<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstatusBaja;
use App\Enums\OrigenBaja;
use App\Enums\ReglaRecepcionDocumental;
use App\Models\Baja;
use App\Models\DocumentoRequeridoBaja;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Services\RecepcionDocumental\EsperadoRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * BAJAS: por cada baja cuya fecha_baja (DATE, la fecha legal de la baja)
 * cae en el mes, SOLO los originales que RH recibe en paquetería: la carta
 * de renuncia firmada (renuncia voluntaria) y el finiquito firmado. Salen
 * del catálogo «Documentos requeridos de la baja» de su forma de salida,
 * filtrado por documento_sistema: la entrada «Bajas» del catálogo
 * configurable decide cuáles (por defecto
 * config recepcion_documental.bajas.documentos_sistema).
 * Lo demás del catálogo (entrevista de salida, acta con testigos, aviso de
 * rescisión, evidencias de la causal) sigue en el expediente de la baja,
 * pero no se espera aquí: sus renglones pendientes de cortes abiertos pasan
 * solos a «No aplica» y los ya recibidos se conservan tal cual.
 *
 * Por qué fecha_baja y no la fecha de cada documento: el paquete de salida
 * de una persona viaja junto y se concilia una sola vez; usar la fecha de
 * firma de cada pieza lo repartiría entre meses.
 *
 * Cuentan las bajas en trámite o cerradas (no borrador). Una baja
 * cancelada o rechazada deja de esperarse (sus pendientes pasan solos a
 * «No aplica»). Las bajas «cierre previo» (anteriores al módulo) no exigen
 * documentos, igual que en su expediente.
 */
class ReglaBajas implements ReglaRecepcion
{
    public const PREFIJO = 'baja.';

    public function regla(): ReglaRecepcionDocumental
    {
        return ReglaRecepcionDocumental::Bajas;
    }

    public function esperados(PeriodoRecepcion $periodo): array
    {
        $bajas = Baja::query()
            ->whereBetween('fecha_baja', $periodo->rangoFechas())
            ->whereIn('estatus', [EstatusBaja::EnRevisionRh->value, EstatusBaja::EnRevisionJuridico->value, EstatusBaja::Cerrada->value])
            ->where('origen', '!=', OrigenBaja::CierrePrevio->value)
            ->with(['sucursal:id,nombre', 'puesto:id,nombre'])
            ->orderBy('id')
            ->get();

        if ($bajas->isEmpty()) {
            return [];
        }

        $sistema = array_keys($this->documentosPosibles());

        if ($sistema === []) {
            return [];
        }

        $requeridos = DocumentoRequeridoBaja::query()
            ->where('activo', true)
            ->whereIn('documento_sistema', $sistema)
            ->orderByRaw('case when causal_rotacion_id is null then 0 else 1 end')
            ->orderBy('orden')
            ->get();

        $colaboradores = $bajas->pluck('colaborador_id')->filter()->unique()->values()->all();
        $documentos = $this->documentosSistema($colaboradores, $sistema);
        $finiquitos = FiniquitoCalculo::query()
            ->whereIn('colaborador_id', $colaboradores)
            ->whereNotNull('generated_document_id')
            ->orderByDesc('id')
            ->get(['id', 'colaborador_id', 'generated_document_id'])
            ->unique('colaborador_id')
            ->keyBy('colaborador_id');

        $esperados = [];

        foreach ($bajas as $baja) {
            $suyos = $requeridos->filter(fn (DocumentoRequeridoBaja $r): bool => ($baja->forma_salida !== null && $r->forma_salida === $baja->forma_salida)
                || ($baja->causal_rotacion_id !== null && $r->causal_rotacion_id === $baja->causal_rotacion_id));
            $descripcion = sprintf('Baja del %s%s', $baja->fecha_baja?->format('d/m/Y') ?? 'sin fecha', $baja->forma_salida !== null ? sprintf(' · %s', $baja->forma_salida->etiqueta()) : '');

            foreach ($suyos as $requerido) {
                $documentoId = match ($requerido->documento_sistema) {
                    null => null,
                    'finiquito' => $finiquitos->get($baja->colaborador_id)?->generated_document_id,
                    default => $documentos->get(sprintf('%d|%s', $baja->colaborador_id, $requerido->documento_sistema)),
                };

                $esperados[] = new EsperadoRecepcion(
                    $this->regla(),
                    mb_substr(self::PREFIJO.$requerido->clave, 0, 60),
                    mb_substr($requerido->nombre, 0, 160),
                    $baja,
                    is_int($documentoId) ? $documentoId : null,
                    $baja->colaborador_id,
                    $baja->sucursal_id,
                    $baja->nombre_colaborador,
                    $baja->numero_empleado,
                    $baja->puesto?->nombre,
                    $baja->sucursal?->nombre,
                    $baja->fecha_baja?->toDateString(),
                    $descripcion,
                    1,
                    $requerido->documento_sistema,
                );
            }
        }

        return $esperados;
    }

    /**
     * Originales de baja que el catálogo puede pedir: cada documento_sistema
     * del catálogo «Documentos requeridos de la baja» (activo) más los
     * valores por defecto de la configuración, con el nombre del catálogo.
     */
    public function documentosPosibles(): array
    {
        $posibles = [];

        foreach (self::documentosPorDefecto() as $clave) {
            $posibles[$clave] = EsperadoRecepcion::etiquetaTipo($clave);
        }

        DocumentoRequeridoBaja::query()
            ->where('activo', true)
            ->whereNotNull('documento_sistema')
            ->orderBy('orden')
            ->get(['documento_sistema', 'nombre'])
            ->each(function (DocumentoRequeridoBaja $r) use (&$posibles): void {
                $clave = (string) $r->documento_sistema;

                if ($clave !== '' && (! isset($posibles[$clave]) || $posibles[$clave] === $clave)) {
                    $posibles[$clave] = $r->nombre;
                }
            });

        return $posibles;
    }

    /**
     * documento_sistema que la entrada «Bajas» del catálogo espera por
     * defecto al sembrarse (carta_renuncia y finiquito).
     *
     * @return list<string>
     */
    public static function documentosPorDefecto(): array
    {
        return array_values(array_filter((array) config('recepcion_documental.bajas.documentos_sistema', ['carta_renuncia', 'finiquito']), fn (mixed $v): bool => is_string($v) && $v !== ''));
    }

    /**
     * Documento generado vigente más reciente de cada colaborador por clave
     * (carta de renuncia, aviso de terminación…): «colaborador|clave» => id.
     *
     * @param  array<int, mixed>  $colaboradores
     * @param  list<string>  $claves
     * @return Collection<string, int>
     */
    private function documentosSistema(array $colaboradores, array $claves): Collection
    {
        if ($colaboradores === []) {
            return collect();
        }

        $resultado = collect();

        GeneratedDocument::query()
            ->whereIn('colaborador_id', $colaboradores)
            ->whereIn('clave_plantilla', array_values(array_diff($claves, ['finiquito'])))
            ->where(fn (Builder $q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->orderByDesc('id')
            ->get(['id', 'colaborador_id', 'clave_plantilla'])
            ->each(function (GeneratedDocument $d) use ($resultado): void {
                $clave = sprintf('%d|%s', (int) $d->colaborador_id, (string) $d->clave_plantilla);

                if (! $resultado->has($clave)) {
                    $resultado->put($clave, $d->id);
                }
            });

        return $resultado;
    }
}
