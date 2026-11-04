<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use Illuminate\Support\Collection;

/**
 * REGLA ÚNICA de los requisitos documentales que PEOPLE GENERA en vez de
 * pedirlos subir (docs/SOLICITUD_EMPLEO_Y_SOCIOECONOMICO.md): la Solicitud de
 * empleo y el Estudio socioeconómico.
 *
 * Un tipo documental es «generable» cuando un master de
 * config('documentos_maestros.documentos') lo declara como `tipo_documental`,
 * su clave está en `documentos_incorporacion` y tiene al menos una fuente
 * activa. Para esos tipos:
 *
 * - CUMPLIDO si existe un GeneratedDocument vigente (cualquier estado de
 *   flujo salvo «cancelado») del colaborador con esa clave. Basta GENERADO:
 *   el original firmado a mano lo controla Recepción documental / flujo
 *   físico, nunca bloquea a la persona.
 * - CUMPLIDO también si ya hay un escaneo subido válido (colaboradores
 *   antiguos): el historial nunca se invalida.
 * - Si no hay ninguno de los dos, NO es un «documento por subir»: no cuenta
 *   para el bloqueo de la app, el modo «completar expediente» ni la carta
 *   compromiso. Durante el registro en la app se presenta como «Se genera con
 *   tus datos de la Solicitud de empleo» (paso de captura); después, como
 *   «Lo prepara Recursos Humanos» (RH lo genera desde la web).
 *
 * Lo consumen DocumentosPendientesService (pendientes/carta/bloqueo),
 * IncorporacionService (lista de la app), RegistroAppService, la guía
 * «Tu siguiente paso» y ExpedienteService/ProgresoExpediente (avance).
 *
 * @phpstan-type PresentacionGenerado array{estado: string, cumplido: bool, titulo: string, mensaje: string, captura: string, documento_generado_id: int|null}
 */
class RequisitoDocumentoGeneradoService
{
    /** El documento ya está cubierto por un escaneo subido (historial). */
    public const ESTADO_ESCANEO = 'escaneo';

    /** Ya se generó con los datos de la persona. */
    public const ESTADO_GENERADO = 'generado';

    /** Todavía no se genera (lo prepara RH o sale de la captura del registro). */
    public const ESTADO_POR_GENERAR = 'por_generar';

    /** Quién lo resuelve: la propia persona en el paso «Solicitud de empleo» del registro. */
    public const CAPTURA_REGISTRO = 'registro';

    /** Quién lo resuelve: Recursos Humanos desde la web. */
    public const CAPTURA_RH = 'rh';

    /**
     * Tipo documental generable => clave de plantilla (GeneratedDocument.clave_plantilla).
     *
     * @return array<string, string>
     */
    public function tiposGenerables(): array
    {
        $claves = array_map('strval', (array) config('documentos_maestros.documentos_incorporacion', []));
        $mapa = [];

        foreach ((array) config('documentos_maestros.documentos', []) as $definicion) {
            if (! is_array($definicion)) {
                continue;
            }

            $tipo = $definicion['tipo_documental'] ?? null;
            $clave = $definicion['clave'] ?? null;

            if (! is_string($tipo) || ! is_string($clave) || ! in_array($clave, $claves, true) || ! $this->tieneFuenteActiva($definicion)) {
                continue;
            }

            $mapa[$tipo] = $clave;
        }

        return $mapa;
    }

    public function esGenerable(DocumentType $tipo): bool
    {
        return array_key_exists($tipo->clave, $this->tiposGenerables());
    }

    /**
     * El GeneratedDocument vigente (no cancelado) más reciente de esa plantilla.
     */
    public function generadoVigente(Colaborador $colaborador, string $clavePlantilla): ?GeneratedDocument
    {
        return GeneratedDocument::query()
            ->where('documentable_type', $colaborador->getMorphClass())
            ->where('documentable_id', $colaborador->id)
            ->where('clave_plantilla', $clavePlantilla)
            ->where(fn ($q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->latest('id')
            ->first();
    }

    /**
     * Generados vigentes del colaborador, por clave de TIPO documental.
     *
     * @return array<string, int> clave del tipo => id del GeneratedDocument
     */
    public function generadosDe(Colaborador $colaborador): array
    {
        $porLote = $this->generadosPorLote(collect([$colaborador->id]), $colaborador->getMorphClass());

        return $porLote[$colaborador->id] ?? [];
    }

    /**
     * Lo mismo para muchos colaboradores en una sola consulta (listados).
     *
     * @param  Collection<int, int>  $idsColaboradores
     * @return array<int, array<string, int>> colaborador_id => [clave del tipo => id del generado]
     */
    public function generadosPorLote(Collection $idsColaboradores, ?string $morph = null): array
    {
        $generables = $this->tiposGenerables();

        if ($generables === [] || $idsColaboradores->isEmpty()) {
            return [];
        }

        $tipoPorPlantilla = array_flip($generables);
        $resultado = [];

        foreach ($idsColaboradores->unique()->chunk(1000) as $lote) {
            $filas = GeneratedDocument::query()
                ->where('documentable_type', $morph ?? (new Colaborador)->getMorphClass())
                ->whereIn('documentable_id', $lote->values())
                ->whereIn('clave_plantilla', array_values($generables))
                ->where(fn ($q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
                ->orderByDesc('id')
                ->toBase()
                ->get(['id', 'documentable_id', 'clave_plantilla']);

            foreach ($filas as $fila) {
                $tipo = $tipoPorPlantilla[sprintf('%s', $fila->clave_plantilla)] ?? null;

                if ($tipo !== null) {
                    $resultado[(int) $fila->documentable_id][$tipo] ??= (int) $fila->id;
                }
            }
        }

        return $resultado;
    }

    /** ¿El escaneo vigente ya cuenta como entregado? (misma regla que DocumentosPendientesService). */
    public function escaneoCumple(?EstadoDocumento $estado): bool
    {
        return $estado !== null && ! in_array($estado->value, DocumentosPendientesService::ESTADOS_FALTANTES, true);
    }

    /**
     * Estado con el que cuenta el tipo para el AVANCE del expediente
     * (ProgresoExpediente): un generable sin escaneo válido pero ya generado
     * cuenta como completo.
     *
     * @param  array<string, int>  $generados  clave del tipo => id del generado
     */
    public function estadoEfectivo(DocumentType $tipo, EstadoDocumento $estado, array $generados): EstadoDocumento
    {
        if (! $this->escaneoCumple($estado) && $this->esGenerable($tipo) && isset($generados[$tipo->clave])) {
            return EstadoDocumento::Aprobado;
        }

        return $estado;
    }

    /**
     * Ids de los tipos (de `$tipos`) que cuentan como completos por estar
     * generados: lo que ProgresoExpediente::calcular() recibe.
     *
     * @param  Collection<int, DocumentType>  $tipos
     * @param  Collection<int, EmployeeDocument>  $vigentes
     * @return list<int>
     */
    public function idsCumplidosPorGenerado(Colaborador $colaborador, Collection $tipos, Collection $vigentes): array
    {
        $generables = $tipos->filter(fn (DocumentType $tipo): bool => $this->esGenerable($tipo));

        if ($generables->isEmpty()) {
            return [];
        }

        $generados = $this->generadosDe($colaborador);
        $ids = [];

        foreach ($generables as $tipo) {
            if (isset($generados[$tipo->clave]) && ! $this->escaneoCumple($vigentes->get($tipo->id)?->status)) {
                $ids[] = $tipo->id;
            }
        }

        return $ids;
    }

    /**
     * Cómo se le presenta al colaborador un requisito generable (nunca con
     * botón de subir).
     *
     * @param  array<string, int>  $generados  clave del tipo => id del generado
     * @return PresentacionGenerado
     */
    public function presentacion(DocumentType $tipo, ?EmployeeDocument $vigente, array $generados, bool $registroAbierto): array
    {
        $captura = $registroAbierto ? self::CAPTURA_REGISTRO : self::CAPTURA_RH;

        if ($this->escaneoCumple($vigente?->status)) {
            return [
                'estado' => self::ESTADO_ESCANEO,
                'cumplido' => true,
                'titulo' => 'Entregado',
                'mensaje' => 'Ya entregaste este formato; no necesitas hacer nada más.',
                'captura' => $captura,
                'documento_generado_id' => $generados[$tipo->clave] ?? null,
            ];
        }

        if (isset($generados[$tipo->clave])) {
            return [
                'estado' => self::ESTADO_GENERADO,
                'cumplido' => true,
                'titulo' => 'Generado con tus datos',
                'mensaje' => 'Ya se generó con los datos de tu Solicitud de empleo. Recursos Humanos te lo entrega impreso para firmarlo.',
                'captura' => $captura,
                'documento_generado_id' => $generados[$tipo->clave],
            ];
        }

        return [
            'estado' => self::ESTADO_POR_GENERAR,
            'cumplido' => false,
            'titulo' => $registroAbierto ? 'Se genera con tus datos' : 'Lo prepara Recursos Humanos',
            'mensaje' => $registroAbierto
                ? 'Se genera con tus datos de la Solicitud de empleo. No tienes que subir ningún archivo.'
                : 'Lo prepara Recursos Humanos con tus datos. No tienes que subir ningún archivo.',
            'captura' => $captura,
            'documento_generado_id' => null,
        ];
    }

    /**
     * @param  array<mixed>  $definicion
     */
    private function tieneFuenteActiva(array $definicion): bool
    {
        foreach ((array) ($definicion['fuentes'] ?? []) as $fuente) {
            if (is_array($fuente) && (bool) ($fuente['activa'] ?? false)) {
                return true;
            }
        }

        return false;
    }
}
