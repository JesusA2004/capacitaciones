<?php

namespace App\Services\Reclutamiento;

use App\Enums\FuenteCandidato;
use App\Enums\TipoEvidenciaCandidato;
use App\Models\Candidato;
use App\Models\CandidatoEntrevista;
use App\Models\CandidatoEvidencia;
use App\Models\CandidatoPsicometrica;
use App\Models\CandidatoReferencia;
use App\Models\CandidatoRespuestaFiltro;
use App\Models\CandidatoSocioeconomico;
use App\Models\Colaborador;
use App\Models\IncorporacionInvitacion;
use App\Models\IntervencionCandidato;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FotoColaboradorService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Datos de la ficha del candidato (registros estructurados del
 * reclutamiento) en un formato estable para web y API. Nunca expone disco
 * ni ruta de las evidencias: solo su id para la descarga autorizada.
 */
class CandidatoPresenter
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly ExpedienteCandidatoService $expediente,
        private readonly FotoColaboradorService $fotos,
    ) {}

    /**
     * Fotografía oficial (la del expediente, en el NAS) de los candidatos que
     * ya tienen colaborador vinculado. Nunca se duplica ni se expone
     * `foto_path`: se devuelve la misma URL protegida que usa Expedientes
     * (Rh\ExpedienteController::descargarFoto), y solo si quien consulta
     * alcanza ese expediente — mismo criterio que la ruta, para no mandar
     * URLs que terminarían en 403. Sin colaborador, sin foto o fuera de
     * alcance → el candidato no aparece y la vista muestra iniciales.
     *
     * @param  iterable<Candidato>  $candidatos
     * @return array<int, string> URL por candidato_id
     */
    public function fotosOficiales(iterable $candidatos, User $viewer): array
    {
        $idsColaborador = [];

        foreach ($candidatos as $candidato) {
            if ($candidato->colaborador_id !== null) {
                $idsColaborador[] = (int) $candidato->colaborador_id;
            }
        }

        if ($idsColaborador === []) {
            return [];
        }

        $colaboradores = Colaborador::withTrashed()
            ->whereIn('id', array_unique($idsColaborador))
            ->whereNotNull('foto_path')
            ->get(['id', 'foto_path', 'sucursal_principal_id', 'jefe_id', 'gerente_id'])
            ->keyBy('id');

        $urls = [];

        foreach ($candidatos as $candidato) {
            $colaborador = $candidato->colaborador_id !== null ? $colaboradores->get($candidato->colaborador_id) : null;

            if ($colaborador === null || ! $this->alcance->puedeVerExpediente($viewer, $colaborador)) {
                continue;
            }

            $url = $this->fotos->url($colaborador);

            if ($url !== null) {
                $urls[$candidato->id] = $url;
            }
        }

        return $urls;
    }

    /** Foto oficial de un solo candidato (ficha), ver fotosOficiales(). */
    public function fotoOficial(Candidato $candidato, User $viewer): ?string
    {
        return $this->fotosOficiales([$candidato], $viewer)[$candidato->id] ?? null;
    }

    /**
     * Candidatos visibles para el usuario (alcance por sucursal) con los
     * filtros de la bandeja. Misma consulta para web, exportaciones y API.
     *
     * @param  array<string, mixed>  $filtros  empresa_id, sucursal_id, departamento_id, puesto_objetivo_id, vacante_id, responsable_rh_id, fuente, estado, fecha_inicio, fecha_fin, busqueda
     * @return Builder<Candidato>
     */
    public function consulta(User $usuario, array $filtros = []): Builder
    {
        $entero = fn (string $clave): int => is_numeric($filtros[$clave] ?? null) ? (int) $filtros[$clave] : 0;
        $texto = fn (string $clave): string => is_string($filtros[$clave] ?? null) ? trim($filtros[$clave]) : '';

        return $this->alcance
            ->limitarPorSucursal(
                Candidato::query()
                    ->with([
                        'empresa:id,nombre',
                        'sucursal:id,nombre',
                        'departamento:id,nombre',
                        'puestoObjetivo:id,nombre',
                        'vacante:id,puesto_id',
                        'responsableRh:id,name,apellidos',
                        'gerenteInvolucrado:id,name,apellidos',
                    ])
                    // Checks de la tarjeta (CLAUDE.md §12): hecho/pendiente de
                    // cada etapa sin cargar los registros completos.
                    ->withCount(['psicometricas', 'socioeconomicos']),
                $usuario,
                'sucursal_id',
                'candidatos',
            )
            ->when($entero('empresa_id'), fn ($query, $valor) => $query->where('empresa_id', $valor))
            ->when($entero('sucursal_id'), fn ($query, $valor) => $query->where('sucursal_id', $valor))
            ->when($entero('departamento_id'), fn ($query, $valor) => $query->where('departamento_id', $valor))
            ->when($entero('puesto_objetivo_id'), fn ($query, $valor) => $query->where('puesto_objetivo_id', $valor))
            ->when($entero('vacante_id'), fn ($query, $valor) => $query->where('vacante_id', $valor))
            ->when($entero('responsable_rh_id'), fn ($query, $valor) => $query->where('responsable_rh_id', $valor))
            ->when($texto('fuente'), fn ($query, string $valor) => $query->where('fuente', $valor))
            ->when($texto('estado'), fn ($query, string $valor) => $query->where('estado', $valor))
            ->when($texto('fecha_inicio'), fn ($query, string $valor) => $query->whereDate('created_at', '>=', $valor))
            ->when($texto('fecha_fin'), fn ($query, string $valor) => $query->whereDate('created_at', '<=', $valor))
            ->when($texto('busqueda'), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda): void {
                    $sub->where('nombre', 'like', "%{$busqueda}%")
                        ->orWhere('apellidos', 'like', "%{$busqueda}%")
                        ->orWhere('correo', 'like', "%{$busqueda}%");
                });
            });
    }

    /**
     * Renglón de lista (API móvil).
     *
     * @return array<string, mixed>
     */
    public function fila(Candidato $candidato): array
    {
        return [
            'id' => $candidato->id,
            'nombre_completo' => $candidato->nombreCompleto(),
            'puesto_objetivo' => $candidato->puestoObjetivo?->nombre,
            'sucursal' => $candidato->sucursal?->nombre,
            'estado' => $candidato->estado->value,
            'estado_etiqueta' => $candidato->etiquetaEstado(),
            'fase' => $candidato->fase,
            'etapa_maxima' => $candidato->etapa_maxima,
            'creado_en' => $candidato->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detalle(Candidato $candidato, ?User $viewer = null): array
    {
        $candidato->loadMissing([
            'empresa:id,nombre', 'sucursal:id,nombre', 'departamento:id,nombre', 'puestoObjetivo:id,nombre',
            'vacante:id,puesto_id,estado', 'campana:id,nombre,canal', 'responsableRh:id,name,apellidos,colaborador_id',
            'gerenteInvolucrado:id,name,apellidos,colaborador_id',
            'entrevistas.entrevistador:id,name,apellidos,colaborador_id',
            'psicometricas.evidencias', 'socioeconomicos.evidencias', 'socioeconomicos.visitador:id,name,apellidos,colaborador_id',
            'referencias.validadaPor:id,name,apellidos,colaborador_id', 'referencias.evidencias', 'evidencias',
            'intervenciones.gerenteSolicitante:id,name,apellidos',
            'intervenciones.rechazoRhPor:id,name,apellidos',
            'intervenciones.aprobador:id,name,apellidos',
            'motivoRechazo:id,nombre,categoria', 'respuestasFiltro',
        ]);

        $invitacion = IncorporacionInvitacion::query()->where('candidato_id', $candidato->id)->latest('id')->first();

        return [
            'id' => $candidato->id,
            'nombre' => $candidato->nombre,
            'apellidos' => $candidato->apellidos,
            'nombre_completo' => $candidato->nombreCompleto(),
            'telefono' => $candidato->telefono,
            'correo' => $candidato->correo,
            'fuente' => $candidato->fuente,
            'fuente_etiqueta' => $candidato->fuente !== null
                ? (FuenteCandidato::tryFrom($candidato->fuente)?->etiqueta() ?? $candidato->fuente)
                : null,
            // El nombre de la campaña es opcional: sin él se muestra su canal.
            'campana' => $candidato->campana !== null ? ($candidato->campana->nombre ?: $candidato->campana->canal->etiqueta()) : null,
            'campana_reclutamiento_id' => $candidato->campana_reclutamiento_id,
            'empresa' => $candidato->empresa?->nombre,
            'sucursal' => $candidato->sucursal?->nombre,
            'sucursal_id' => $candidato->sucursal_id,
            'departamento' => $candidato->departamento?->nombre,
            'puesto' => $candidato->puestoObjetivo?->nombre,
            'puesto_objetivo_id' => $candidato->puesto_objetivo_id,
            'vacante_id' => $candidato->vacante_id,
            'espontaneo' => $candidato->espontaneo,
            'empresa_id' => $candidato->empresa_id,
            'departamento_id' => $candidato->departamento_id,
            'responsable_rh_id' => $candidato->responsable_rh_id,
            'gerente_involucrado_id' => $candidato->gerente_involucrado_id,
            'responsable_rh' => $candidato->responsableRh?->nombreCompleto(),
            'gerente' => $candidato->gerenteInvolucrado?->nombreCompleto(),
            'observaciones' => $candidato->observaciones,
            'estado' => $candidato->estado->value,
            'estado_etiqueta' => $candidato->etiquetaEstado(),
            'fase' => $candidato->fase,
            'motivo_salida' => $candidato->motivo_salida,
            // Clasificación del motivo (catálogo) para que RH vea de un
            // vistazo por qué ya no continuó.
            'motivo_rechazo' => $candidato->motivoRechazo !== null
                ? sprintf('%s · %s', $candidato->motivoRechazo->categoria->etiqueta(), $candidato->motivoRechazo->nombre)
                : null,
            'salida_en' => $candidato->salida_en?->toIso8601String(),
            // Requisitos indispensables preguntados al registrarlo.
            'respuestas_filtro' => $candidato->respuestasFiltro->map(fn (CandidatoRespuestaFiltro $r): array => [
                'pregunta' => $r->pregunta,
                'respuesta' => $r->respuesta,
                'cumple' => $r->cumple,
            ])->values()->all(),
            'tiene_cv' => $candidato->tiene_cv,
            // Ver psicométrico / socioeconómico / video / CV sin bajar toda la ficha.
            'accesos_rapidos' => $this->accesosRapidos($candidato),
            // Mini expediente previo a la contratación (CV, psicométricos,
            // videos del socioeconómico, referencias), agrupado como en el NAS.
            'expediente' => $this->expediente->resumen($candidato),
            'colaborador_id' => $candidato->colaborador_id,
            // Foto oficial del expediente (URL protegida, nunca foto_path);
            // sin quien consulta (API móvil) no se calcula.
            'foto_url' => $viewer !== null ? $this->fotoOficial($candidato, $viewer) : null,
            'creado_en' => $candidato->created_at?->toIso8601String(),
            'contratado_en' => $candidato->contratado_en?->toIso8601String(),
            'entrevistas' => $candidato->entrevistas->map(fn (CandidatoEntrevista $e) => [
                'id' => $e->id,
                'realizada_en' => $e->realizada_en->toIso8601String(),
                'entrevistador' => $e->entrevistador?->nombreCompleto(),
                'resultado' => $e->resultado->value,
                'resultado_etiqueta' => $e->resultado->etiqueta(),
                'observaciones' => $e->observaciones,
            ])->values()->all(),
            'psicometricas' => $candidato->psicometricas->map(fn (CandidatoPsicometrica $p) => [
                'id' => $p->id,
                'link' => $p->link,
                'enviada_en' => $p->enviada_en?->toIso8601String(),
                'resultados_en' => $p->resultados_en?->toIso8601String(),
                'resumen_resultados' => $p->resumen_resultados,
                'revision_resultado' => $p->revision_resultado?->value,
                'revision_observaciones' => $p->revision_observaciones,
                'revisada_en' => $p->revisada_en?->toIso8601String(),
                'evidencias' => $p->evidencias->map(fn (CandidatoEvidencia $e) => $this->evidencia($e))->values()->all(),
            ])->values()->all(),
            'socioeconomicos' => $candidato->socioeconomicos->map(fn (CandidatoSocioeconomico $s) => [
                'id' => $s->id,
                'fecha_visita' => $s->fecha_visita->toDateString(),
                'visitador' => $s->visitador?->nombreCompleto(),
                'direccion' => $s->direccion,
                'checklist' => $s->checklist ?? [],
                'criterios' => $this->criteriosSocioeconomico($s->checklist ?? []),
                'riesgos' => $s->riesgos,
                'observaciones' => $s->observaciones,
                // Cargado sin revisar = «Pendiente de revisión» (cargar ≠ decidir).
                'resultado' => $s->resultado?->value,
                'resultado_etiqueta' => $s->resultado?->etiqueta() ?? 'Pendiente de revisión',
                'revisado_en' => $s->revisado_en?->toIso8601String(),
                'revision_observaciones' => $s->revision_observaciones,
                'evidencias' => $s->evidencias->map(fn (CandidatoEvidencia $e) => $this->evidencia($e))->values()->all(),
            ])->values()->all(),
            'referencias' => $candidato->referencias->map(fn (CandidatoReferencia $r) => [
                'id' => $r->id,
                'empresa' => $r->empresa,
                'contacto' => $r->contacto,
                'telefono' => $r->telefono,
                'relacion_puesto' => $r->relacion_puesto,
                'resultado' => $r->resultado->value,
                'resultado_etiqueta' => $r->resultado->etiqueta(),
                'observaciones' => $r->observaciones,
                'fecha_validacion' => $r->fecha_validacion->toDateString(),
                'validada_por' => $r->validadaPor?->nombreCompleto(),
                'evidencias' => $r->evidencias->map(fn (CandidatoEvidencia $e) => $this->evidencia($e))->values()->all(),
            ])->values()->all(),
            'invitacion' => $invitacion !== null ? [
                'id' => $invitacion->id,
                'estado' => $invitacion->estado->value,
                'estado_etiqueta' => $invitacion->estado->etiqueta(),
                'expira_en' => $invitacion->expires_at->toIso8601String(),
                'usada_en' => $invitacion->used_at?->toIso8601String(),
            ] : null,
            // Excepción jerárquica sobre un rechazo de RH (CLAUDE.md §10-12):
            // nunca otra fase del kanban, siempre auditable.
            'intervenciones' => $candidato->intervenciones->map(fn (IntervencionCandidato $i) => [
                'id' => $i->id,
                'ruta' => $i->ruta->value,
                'ruta_etiqueta' => $i->ruta->etiqueta(),
                'estado' => $i->estado->value,
                'estado_etiqueta' => $i->estado->etiqueta(),
                'rechazo_rh_por' => $i->rechazoRhPor?->nombreCompleto(),
                'rechazo_rh_motivo' => $i->rechazo_rh_motivo,
                'rechazo_rh_en' => $i->rechazo_rh_en?->toIso8601String(),
                'gerente_solicitante' => $i->gerenteSolicitante?->nombreCompleto(),
                'motivo_solicitud' => $i->motivo_solicitud,
                'solicitada_en' => $i->solicitada_en->toIso8601String(),
                'aprobador' => $i->aprobador?->nombreCompleto(),
                'comentario_decision' => $i->comentario_decision,
                'decidida_en' => $i->decidida_en?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Criterios del estudio socioeconómico listos para mostrar: el tiempo en
     * la vivienda junta años y meses («3 años 4 meses»); una clave fuera del
     * catálogo se muestra tal cual (nunca se oculta).
     *
     * @param  array<array-key, mixed>  $checklist
     * @return list<array{clave: string, etiqueta: string, valor: string}>
     */
    private function criteriosSocioeconomico(array $checklist): array
    {
        $etiquetas = [
            'vivienda_en_orden' => 'Vivienda en orden',
            'vive_con_familia' => 'Vive con familia',
            'resguardo_motocicleta' => 'Resguardo de motocicleta',
        ];
        $criterios = [];

        if (array_key_exists('arraigo_anios', $checklist) || array_key_exists('arraigo_meses', $checklist)) {
            $criterios[] = [
                'clave' => 'arraigo',
                'etiqueta' => 'Tiempo en la vivienda',
                'valor' => self::tiempoEnVivienda($checklist['arraigo_anios'] ?? null, $checklist['arraigo_meses'] ?? null),
            ];
        }

        foreach ($checklist as $clave => $valor) {
            $clave = (string) $clave;
            if ($clave === 'arraigo_anios' || $clave === 'arraigo_meses') {
                continue;
            }
            $criterios[] = [
                'clave' => $clave,
                'etiqueta' => $etiquetas[$clave] ?? ucfirst(str_replace('_', ' ', $clave)),
                'valor' => match (true) {
                    $valor === true => 'Sí',
                    $valor === false => 'No',
                    is_scalar($valor) => (string) $valor,
                    default => '—',
                },
            ];
        }

        return $criterios;
    }

    /**
     * «3 años 4 meses», «1 año», «8 meses», «Menos de un mes». Acepta años
     * con decimales de estudios anteriores (2.5 → 2 años 6 meses).
     */
    public static function tiempoEnVivienda(mixed $anios, mixed $meses): string
    {
        $totalMeses = (int) round((is_numeric($anios) ? (float) $anios : 0.0) * 12) + (is_numeric($meses) ? (int) $meses : 0);
        $a = intdiv($totalMeses, 12);
        $m = $totalMeses % 12;
        $partes = [];
        if ($a > 0) {
            $partes[] = sprintf('%d %s', $a, $a === 1 ? 'año' : 'años');
        }
        if ($m > 0) {
            $partes[] = sprintf('%d %s', $m, $m === 1 ? 'mes' : 'meses');
        }

        return $partes === [] ? 'Menos de un mes' : implode(' ', $partes);
    }

    /**
     * @return array<string, mixed>
     */
    private function evidencia(CandidatoEvidencia $evidencia): array
    {
        return $this->expediente->evidencia($evidencia);
    }

    /**
     * Botones «Ver psicométrico / Ver socioeconómico / Ver video / Ver CV»
     * (índice y encabezado de la ficha): el archivo más reciente de cada
     * etapa, siempre con su etiqueta — nunca evidencias mezcladas.
     * Requiere psicometricas.evidencias y socioeconomicos.evidencias.
     *
     * @return array{psicometrico: array<string, mixed>|null, socioeconomico: array<string, mixed>|null, video: array<string, mixed>|null, tiene_cv: bool}
     */
    public function accesosRapidos(Candidato $candidato): array
    {
        $psico = $candidato->psicometricas->sortByDesc('id')->flatMap(fn (CandidatoPsicometrica $p) => $p->evidencias)->first();
        $socioEvidencias = $candidato->socioeconomicos->sortByDesc('id')->flatMap(fn (CandidatoSocioeconomico $s) => $s->evidencias);
        $video = $socioEvidencias->first(fn (CandidatoEvidencia $e) => $e->tipo === TipoEvidenciaCandidato::Video);
        $socio = $socioEvidencias->first(fn (CandidatoEvidencia $e) => $e->tipo !== TipoEvidenciaCandidato::Video);

        return [
            'psicometrico' => $psico instanceof CandidatoEvidencia ? $this->evidencia($psico) : null,
            'socioeconomico' => $socio instanceof CandidatoEvidencia ? $this->evidencia($socio) : null,
            'video' => $video instanceof CandidatoEvidencia ? $this->evidencia($video) : null,
            'tiene_cv' => $candidato->tiene_cv,
        ];
    }
}
