<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoInvitacionIncorporacion;
use App\Models\AltaDigital;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\IncorporacionInvitacion;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\ExpedienteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cadena REAL Candidato → contratación → colaborador → expediente, sin
 * duplicar a nadie (ver ContratacionCandidatoService):
 *
 *   Candidato (estado autorizado_rh)
 *     → «Iniciar contratación»: crea el Colaborador (candidatos.colaborador_id
 *       ↔ colaboradores.candidato_id) + IncorporacionInvitacion (QR, con
 *       candidato_id y colaborador_id) — estado en_contratacion;
 *     → la persona escanea el QR y crea su cuenta (invitación «usada»);
 *       alta digital legacy (altas_digitales.candidato_id) si existiera;
 *     → expediente = el mismo Colaborador (documentos requeridos N/M);
 *     → contratos generados (GeneratedDocument contrato_*);
 *     → contratado (contratos firmados) = «En inducción» hasta que inicia
 *       operación (activado_en) = «Contratado / Laborando».
 *
 * El botón al expediente SOLO aparece cuando ya existe el colaborador (es
 * decir, cuando ya hubo incorporación/QR); un candidato seleccionado sin
 * contratación iniciada no lo tiene. Quien no puede ver ese expediente
 * tampoco ve el enlace.
 *
 * @phpstan-type Estado array{clave: string, etiqueta: string, detalle: string|null, tono: string, colaborador_id: int|null, expediente_url: string|null, expediente_etiqueta: string|null, proceso_url: string|null, proceso_etiqueta: string|null}
 */
class CadenaContratacionService
{
    /** Contratos laborales (no convenios) del paquete de ingreso / renovación. */
    private const CLAVES_CONTRATO = ['contrato_capacitacion', 'contrato_indeterminado', 'contrato_periodo_prueba', 'contrato_determinado'];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly ExpedienteService $expedientes,
    ) {}

    /**
     * @return Estado|null null = el candidato no ha llegado a selección (sigue en reclutamiento o salió).
     */
    public function paraCandidato(Candidato $candidato, User $viewer): ?array
    {
        return $this->paraCandidatos(new Collection([$candidato]), $viewer)[$candidato->id] ?? null;
    }

    /**
     * Mismo cálculo en lote para el tablero (consultas fijas, sin N+1).
     *
     * @param  Collection<int, Candidato>  $candidatos
     * @return array<int, Estado> por candidato_id (solo los seleccionados o más avanzados)
     */
    public function paraCandidatos(Collection $candidatos, User $viewer): array
    {
        $relevantes = $candidatos->filter(fn (Candidato $c): bool => $c->colaborador_id !== null
            || in_array($c->estado, [EstadoCandidato::AutorizadoRh, EstadoCandidato::EnContratacion, EstadoCandidato::Contratado], true));

        if ($relevantes->isEmpty()) {
            return [];
        }

        $idsColaborador = $relevantes->pluck('colaborador_id')->filter()->map(fn ($id): int => (int) $id)->values();
        /** @var Collection<int, Colaborador> $colaboradores */
        $colaboradores = Colaborador::withTrashed()
            ->whereIn('id', $idsColaborador)
            ->get(['id', 'name', 'apellidos', 'estatus', 'activado_en', 'sucursal_principal_id', 'departamento_id', 'puesto_id', 'jefe_id', 'gerente_id', 'deleted_at'])
            ->keyBy('id');
        $resumenes = $idsColaborador->isEmpty() ? [] : $this->expedientes->resumenesCompletitud($idsColaborador);
        $conContrato = $idsColaborador->isEmpty() ? [] : GeneratedDocument::query()
            ->whereIn('colaborador_id', $idsColaborador)
            ->whereIn('clave_plantilla', self::CLAVES_CONTRATO)
            ->where(fn (Builder $q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->pluck('colaborador_id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();
        $invitaciones = IncorporacionInvitacion::query()
            ->whereIn('candidato_id', $relevantes->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'candidato_id', 'estado', 'used_at'])
            ->keyBy('candidato_id');
        $conAlta = AltaDigital::query()->whereIn('candidato_id', $relevantes->pluck('id'))->pluck('candidato_id')->map(fn ($id): int => (int) $id)->flip()->all();

        $estados = [];

        foreach ($relevantes as $candidato) {
            $colaborador = $candidato->colaborador_id !== null ? $colaboradores->get($candidato->colaborador_id) : null;
            $estados[$candidato->id] = $this->estado(
                $candidato,
                $colaborador,
                $colaborador !== null ? ($resumenes[$colaborador->id] ?? null) : null,
                $colaborador !== null && isset($conContrato[$colaborador->id]),
                $invitaciones->get($candidato->id),
                isset($conAlta[$candidato->id]),
                $viewer,
            );
        }

        return $estados;
    }

    /**
     * «Ver origen en reclutamiento» desde el expediente.
     *
     * @return array{candidato_id: int, nombre: string, estado: string, vacante: string|null, puesto: string|null, contratado_en: string|null, registrado_en: string|null, url: string|null}|null
     */
    public function origenDeColaborador(Colaborador $colaborador, User $viewer): ?array
    {
        $candidato = Candidato::query()
            ->with('puestoObjetivo:id,nombre')
            ->where(fn (Builder $q) => $q->where('colaborador_id', $colaborador->id)
                ->when($colaborador->candidato_id !== null, fn (Builder $s) => $s->orWhere('id', $colaborador->candidato_id)))
            ->latest('id')
            ->first();

        if ($candidato === null) {
            return null;
        }

        return [
            'candidato_id' => $candidato->id,
            'nombre' => $candidato->nombreCompleto(),
            'estado' => $candidato->etiquetaEstado(),
            'vacante' => $candidato->vacante_id !== null ? sprintf('Vacante #%d', $candidato->vacante_id) : null,
            'puesto' => $candidato->puestoObjetivo?->nombre,
            'contratado_en' => $candidato->contratado_en?->toIso8601String(),
            'registrado_en' => $candidato->created_at?->toIso8601String(),
            'url' => $viewer->can('view', $candidato) ? route('rh.candidatos.show', $candidato->id) : null,
        ];
    }

    /**
     * @param  array{porcentaje: float, requeridos_total: int, requeridos_aprobados: int, pendientes: int, rechazados: int}|null  $resumen
     * @return Estado
     */
    private function estado(Candidato $candidato, ?Colaborador $colaborador, ?array $resumen, bool $conContrato, ?IncorporacionInvitacion $invitacion, bool $conAlta, User $viewer): array
    {
        $fichaCandidato = route('rh.candidatos.show', ['candidato' => $candidato->id, 'paso' => 'contratacion']);

        // Seleccionado (autorizado por RH) sin contratación iniciada: aún
        // no hay colaborador ni QR → sin botón al expediente.
        if ($colaborador === null) {
            return $this->fila('seleccionado', 'Seleccionado', 'Listo para iniciar contratación', 'info', null, null, null, $fichaCandidato, 'Iniciar contratación');
        }

        $verExpediente = $this->alcance->puedeVerExpediente($viewer, $colaborador);
        // Mismo criterio que Candidato::yaLabora() (columna «Laborando» del tablero).
        $yaEsColaborador = $colaborador->activado_en !== null;
        $expedienteUrl = $verExpediente ? route('rh.expedientes.show', $colaborador->id) : null;
        $expedienteEtiqueta = $verExpediente ? ($yaEsColaborador ? 'Ver expediente del colaborador' : 'Ver expediente de contratación') : null;
        $ciclo = $verExpediente ? route('rh.colaboradores.ciclo', $colaborador->id) : null;
        $documentos = $resumen !== null && $resumen['requeridos_total'] > 0
            ? sprintf('Expediente %d/%d', $resumen['requeridos_aprobados'], $resumen['requeridos_total'])
            : null;

        if ($candidato->estado === EstadoCandidato::Contratado) {
            return $yaEsColaborador
                ? $this->fila('contratado', 'Contratado', 'Ya labora', 'exito', $colaborador->id, $expedienteUrl, $expedienteEtiqueta, $ciclo, 'Ver ciclo laboral')
                : $this->fila('en_induccion', 'En inducción', $documentos, 'primario', $colaborador->id, $expedienteUrl, $expedienteEtiqueta, $ciclo, 'Ver onboarding');
        }

        if ($conContrato) {
            return $this->fila('contrato_generado', 'Contrato generado', $documentos ?? 'Pendiente de firma', 'oro', $colaborador->id, $expedienteUrl, $expedienteEtiqueta, $ciclo, 'Ver contratación');
        }

        $qrPendiente = ! $conAlta && ($invitacion === null || ($invitacion->estado !== EstadoInvitacionIncorporacion::Usado && $invitacion->used_at === null));

        if ($qrPendiente) {
            return $this->fila('en_contratacion', 'En contratación', 'Esperando registro por QR', 'alerta', $colaborador->id, $expedienteUrl, $expedienteEtiqueta, $fichaCandidato, 'Ver QR de registro');
        }

        return $this->fila('expediente', $documentos ?? 'En contratación', $documentos !== null ? 'Documentos aprobados' : 'Registro por QR completado', 'alerta', $colaborador->id, $expedienteUrl, $expedienteEtiqueta, $ciclo, 'Ver contratación');
    }

    /**
     * @return Estado
     */
    private function fila(string $clave, string $etiqueta, ?string $detalle, string $tono, ?int $colaboradorId, ?string $expedienteUrl, ?string $expedienteEtiqueta, ?string $procesoUrl, ?string $procesoEtiqueta): array
    {
        return [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'detalle' => $detalle,
            'tono' => $tono,
            'colaborador_id' => $colaboradorId,
            'expediente_url' => $expedienteUrl,
            'expediente_etiqueta' => $expedienteEtiqueta,
            'proceso_url' => $procesoUrl,
            'proceso_etiqueta' => $procesoEtiqueta,
        ];
    }
}
