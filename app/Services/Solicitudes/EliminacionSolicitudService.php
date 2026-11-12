<?php

namespace App\Services\Solicitudes;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Models\OfficialFormatGeneration;
use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\Nomina\IncidenciasNominaService;
use App\Services\Nomina\PrestamoService;
use App\Services\Tareas\TareaService;
use App\Support\OrigenPeticion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Eliminar una solicitud interna (de cualquier tipo, en cualquier estado)
 * que se registró por error o como PRUEBA. Permiso `solicitudes.eliminar`
 * (SolicitudInternaPolicy::eliminar); web y API llaman a este servicio.
 *
 * Es SILENCIOSO: no avisa al colaborador ni a los aprobadores, cierra los
 * pendientes de la bandeja y retira de la campana los avisos que apuntaban a
 * la solicitud (nadie se queda con un enlace muerto).
 *
 * Nunca borra personas ni expedientes: la solicitud se retira con borrado
 * lógico (SoftDeletes) y conserva su historial; quién, cuándo y qué queda en
 * la bitácora (`solicitud_eliminada`).
 *
 * Efectos de una solicitud ya aprobada:
 *  - se REVIERTEN solos o aquí: saldo de vacaciones (se calcula en vivo),
 *    días pagados de la nómina por un permiso, préstamo aún sin entregar,
 *    documentos generados sin firmar;
 *  - se CONSERVAN (y se dicen en la confirmación): datos ya aplicados al
 *    expediente, documentos archivados o firmados, adjuntos;
 *  - BLOQUEAN: la baja con cierre laboral o ya aplicada, un finiquito ligado
 *    y un préstamo entregado o con movimientos (no se deshacen borrando).
 */
class EliminacionSolicitudService
{
    public const PERMISO = 'solicitudes.eliminar';

    public const ACCION_HISTORIAL = 'eliminada';

    private const ESTADOS_VIGENTES = [
        EstadoSolicitudInterna::Creada,
        EstadoSolicitudInterna::Enviada,
        EstadoSolicitudInterna::EnRevision,
        EstadoSolicitudInterna::RequiereCorreccion,
    ];

    private const TIPOS_PERMISO_NOMINA = [
        TipoSolicitudInterna::Permiso,
        TipoSolicitudInterna::PermisoConGoce,
        TipoSolicitudInterna::PermisoSinGoce,
    ];

    public function __construct(
        private readonly TareaService $tareas,
        private readonly AuditoriaService $auditoria,
        private readonly PrestamoService $prestamos,
        private readonly FlujoDocumentalService $flujoDocumental,
        private readonly IncidenciasNominaService $incidencias,
    ) {}

    /**
     * Qué pasará al eliminarla (para el diálogo de confirmación): no cambia nada.
     *
     * @return array{id: int, folio: string, tipo: string, estado: string, persona: string|null, puede: bool, bloqueos: list<string>, revierte: list<string>, conserva: list<string>}
     */
    public function revisar(SolicitudInterna $solicitud): array
    {
        $persona = $this->persona($solicitud);
        $prestamo = $this->prestamoDe($solicitud);
        $documentos = $this->documentosDe($solicitud);
        $porCancelar = $documentos->filter(fn (GeneratedDocument $d): bool => $this->esCancelable($d));
        $concluidos = $documentos->count() - $porCancelar->count();
        $aprobada = $solicitud->estado === EstadoSolicitudInterna::Aprobada;

        $bloqueos = [];
        $revierte = [];
        $conserva = [];

        if ($solicitud->tipo === TipoSolicitudInterna::BajaColaborador) {
            if (CierreLaboral::query()->where('solicitud_interna_id', $solicitud->id)->exists()) {
                $bloqueos[] = 'Es la solicitud de una baja registrada: tiene su cierre laboral (documentos, finiquito y acceso suspendido). Se gestiona en «Bajas»; borrarla aquí dejaría a la persona suspendida sin trámite.';
            } elseif ($aprobada) {
                $bloqueos[] = 'La baja ya se aplicó (acceso bloqueado, movimiento laboral y vacante): eso no se deshace borrando la solicitud.';
            }
        }

        if (FiniquitoCalculo::query()->where('solicitud_interna_id', $solicitud->id)->exists()) {
            $bloqueos[] = 'Tiene un cálculo de finiquito ligado, que debe conservarse.';
        }

        if ($prestamo !== null) {
            if ($this->prestamoReversible($prestamo)) {
                if ($prestamo->estado !== 'cancelado') {
                    $revierte[] = 'El préstamo autorizado que todavía no se entrega se cancela.';
                }
            } else {
                $bloqueos[] = 'El préstamo ya se entregó o tiene movimientos de cobro: se conserva junto con su solicitud.';
            }
        }

        if ($solicitud->tipo === TipoSolicitudInterna::Vacaciones && (int) $solicitud->dias_solicitados > 0) {
            $dias = (int) $solicitud->dias_solicitados;
            $texto = $dias === 1 ? '1 día' : sprintf('%d días', $dias);

            if ($aprobada) {
                $revierte[] = sprintf('Vuelven al saldo de vacaciones %s que se habían descontado.', $texto);
            } elseif (in_array($solicitud->estado, self::ESTADOS_VIGENTES, true)) {
                $revierte[] = sprintf('Se liberan %s que estaban «en solicitud».', $texto);
            }
        }

        if ($this->afectaNomina($solicitud)) {
            $revierte[] = 'Se recalculan los recibos de nómina del periodo: el permiso deja de contar (un recibo ya emitido queda con una rectificación pendiente para RH).';
        }

        if ($porCancelar->isNotEmpty()) {
            $revierte[] = sprintf('%s sin firmar se %s.', $this->contar($porCancelar->count(), 'documento generado', 'documentos generados'), $porCancelar->count() === 1 ? 'cancela' : 'cancelan');
        }

        $pendientes = $this->tareasAbiertas($solicitud)->count();

        if ($pendientes > 0) {
            $revierte[] = sprintf('Se %s de la bandeja de trabajo.', $pendientes === 1 ? 'cierra 1 pendiente' : sprintf('cierran %d pendientes', $pendientes));
        }

        $revierte[] = 'Se retiran de la campana los avisos que apuntaban a esta solicitud.';

        if ($aprobada && $solicitud->tipo === TipoSolicitudInterna::ActualizacionDatos) {
            $conserva[] = 'Los datos que ya se aplicaron al expediente NO se deshacen: si eran de prueba, corrígelos desde el expediente.';
        }

        if ($concluidos > 0) {
            $conserva[] = sprintf('%s en el expediente (ya %s).', $this->contar($concluidos, 'documento se conserva', 'documentos se conservan'), $concluidos === 1 ? 'está archivado o firmado' : 'están archivados o firmados');
        }

        $formatos = OfficialFormatGeneration::query()->where('solicitud_interna_id', $solicitud->id)->count();

        if ($formatos > 0) {
            $conserva[] = sprintf('%s.', $this->contar($formatos, 'formato oficial generado se conserva', 'formatos oficiales generados se conservan'));
        }

        $adjuntos = $solicitud->documentos()->count();

        if ($adjuntos > 0) {
            $conserva[] = sprintf('%s en el archivo.', $this->contar($adjuntos, 'adjunto se conserva', 'adjuntos se conservan'));
        }

        $conserva[] = 'El historial de la solicitud y el registro de quién la eliminó quedan en la bitácora.';

        return [
            'id' => $solicitud->id,
            'folio' => (string) $solicitud->folio,
            'tipo' => $solicitud->tipo->etiqueta(),
            'estado' => $solicitud->estado->etiqueta(),
            'persona' => $persona?->nombreCompleto(),
            'puede' => $bloqueos === [],
            'bloqueos' => $bloqueos,
            'revierte' => $revierte,
            'conserva' => $conserva,
        ];
    }

    /**
     * Elimina la solicitud SIN avisar a nadie. Todo o nada: si algo la
     * bloquea no se toca ningún dato.
     *
     * @return array{id: int, folio: string, tipo: string, estado: string, persona: string|null, puede: bool, bloqueos: list<string>, revierte: list<string>, conserva: list<string>}
     *
     * @throws ValidationException Tiene efectos que no se pueden deshacer, o ya no existe.
     */
    public function eliminar(SolicitudInterna $solicitud, User $actor, ?string $motivo = null): array
    {
        $motivo = $motivo !== null && trim($motivo) !== '' ? trim($motivo) : null;

        $revision = DB::transaction(function () use ($solicitud, $actor, $motivo): array {
            // Doble clic o dos personas a la vez: la segunda ya no la encuentra.
            $vigente = SolicitudInterna::query()->whereKey($solicitud->getKey())->lockForUpdate()->first();

            if ($vigente === null) {
                throw ValidationException::withMessages(['solicitud' => 'Esta solicitud ya había sido eliminada.']);
            }

            $revision = $this->revisar($vigente);

            if (! $revision['puede']) {
                throw ValidationException::withMessages(['solicitud' => implode(' ', $revision['bloqueos'])]);
            }

            $prestamo = $this->prestamoDe($vigente);

            if ($prestamo !== null && $prestamo->estado !== 'cancelado') {
                $this->prestamos->cancelar($prestamo);
            }

            foreach ($this->documentosDe($vigente) as $documento) {
                if ($this->esCancelable($documento)) {
                    $this->flujoDocumental->cancelar($documento, $actor, sprintf('Se eliminó la solicitud %s.', $revision['folio']));
                }
            }

            $vigente->historial()->create([
                'user_id' => $actor->id,
                'accion' => self::ACCION_HISTORIAL,
                'comentario' => $motivo,
                ...OrigenPeticion::contexto(),
                'created_at' => now(),
            ]);

            $this->tareas->resolverTodas($vigente, $actor);
            $this->retirarAvisos($vigente);

            $vigente->delete();

            return $revision;
        });

        // Después del commit; un fallo aquí nunca deshace la eliminación.
        $colaborador = $solicitud->personaSolicitante();

        if ($this->afectaNomina($solicitud) && $colaborador !== null && $solicitud->fecha_inicio !== null) {
            try {
                $this->incidencias->sincronizarRecibos($colaborador, $solicitud->fecha_inicio, $solicitud->fecha_fin ?? $solicitud->fecha_inicio, $actor, sprintf('Permiso %s: eliminado', $revision['folio']));
            } catch (Throwable $e) {
                Log::warning('EliminacionSolicitudService: no se pudo recalcular la nómina del permiso eliminado.', ['solicitud_id' => $solicitud->id, 'error' => $e->getMessage()]);
            }
        }

        $this->auditoria->registrar('solicitud_eliminada', $solicitud, $actor, [
            'modulo' => 'solicitudes',
            'solicitud_id' => $solicitud->id,
            'folio' => $revision['folio'],
            'tipo' => $solicitud->tipo->value,
            'estado_al_eliminar' => $solicitud->estado->value,
            'colaborador_id' => $this->persona($solicitud)?->id,
            'solicitante_user_id' => $solicitud->user_id,
            'motivo' => $motivo,
            'sin_aviso_al_colaborador' => true,
            'revierte' => $revision['revierte'],
            'conserva' => $revision['conserva'],
        ]);

        Log::info('Solicitud interna eliminada sin aviso al colaborador.', [
            'solicitud_id' => $solicitud->id,
            'folio' => $revision['folio'],
            'tipo' => $solicitud->tipo->value,
            'estado' => $solicitud->estado->value,
            'eliminada_por' => $actor->id,
        ]);

        return $revision;
    }

    private function persona(SolicitudInterna $solicitud): ?Colaborador
    {
        return $solicitud->colaboradorDeBaja() ?? $solicitud->personaSolicitante();
    }

    private function prestamoDe(SolicitudInterna $solicitud): ?Prestamo
    {
        return Prestamo::query()->where('solicitud_id', $solicitud->id)->withCount('movimientos')->first();
    }

    /**
     * Solo un préstamo que nunca se entregó ni se cobró se puede dejar sin efecto.
     */
    private function prestamoReversible(Prestamo $prestamo): bool
    {
        return in_array($prestamo->estado, ['pendiente_entrega', 'cancelado'], true)
            && (int) $prestamo->getAttribute('movimientos_count') === 0;
    }

    /**
     * Documentos que el motor documental generó PARA esta solicitud.
     *
     * @return EloquentCollection<int, GeneratedDocument>
     */
    private function documentosDe(SolicitudInterna $solicitud): EloquentCollection
    {
        return GeneratedDocument::query()
            ->where(fn ($q) => $q
                ->where('solicitud_id', $solicitud->id)
                ->orWhere(fn ($d) => $d->where('documentable_type', $solicitud->getMorphClass())->where('documentable_id', $solicitud->id)))
            ->get();
    }

    /**
     * Con flujo documental abierto (por imprimir o firmar): se puede cancelar.
     * Uno archivado, firmado o ya cancelado se queda como está.
     */
    private function esCancelable(GeneratedDocument $documento): bool
    {
        return $documento->estado_flujo !== null && ! $documento->estado_flujo->estaConcluido();
    }

    private function afectaNomina(SolicitudInterna $solicitud): bool
    {
        return $solicitud->estado === EstadoSolicitudInterna::Aprobada
            && in_array($solicitud->tipo, self::TIPOS_PERMISO_NOMINA, true)
            && $solicitud->fecha_inicio !== null;
    }

    /**
     * @return Builder<TareaRh>
     */
    private function tareasAbiertas(SolicitudInterna $solicitud): Builder
    {
        return TareaRh::query()
            ->whereNull('resuelta_en')
            ->where('relacionado_type', $solicitud->getMorphClass())
            ->where('relacionado_id', $solicitud->id);
    }

    /**
     * Quita de la campana (web y app) los avisos que abrían esta solicitud:
     * los de la capa única (related_type/related_id) y los anteriores
     * (type + resource_id). La bitácora de entregas se conserva.
     */
    private function retirarAvisos(SolicitudInterna $solicitud): int
    {
        $tipo = class_basename($solicitud);

        return DB::table('notifications')
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->where('data->related_type', $tipo)->where('data->related_id', $solicitud->id))
                ->orWhere(fn ($r) => $r
                    ->whereIn('data->type', ['rh_solicitud', 'solicitud', 'visto_bueno_pendiente'])
                    ->where('data->resource_id', $solicitud->id)
                    ->where(fn ($s) => $s->whereNull('data->related_type')->orWhere('data->related_type', $tipo))))
            ->delete();
    }

    private function contar(int $cantidad, string $singular, string $plural): string
    {
        return $cantidad === 1 ? sprintf('1 %s', $singular) : sprintf('%d %s', $cantidad, $plural);
    }
}
