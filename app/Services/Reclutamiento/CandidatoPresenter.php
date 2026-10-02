<?php

namespace App\Services\Reclutamiento;

use App\Models\Candidato;
use App\Models\CandidatoEntrevista;
use App\Models\CandidatoEvidencia;
use App\Models\CandidatoPsicometrica;
use App\Models\CandidatoReferencia;
use App\Models\CandidatoSocioeconomico;
use App\Models\IncorporacionInvitacion;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Datos de la ficha del candidato (registros estructurados del
 * reclutamiento) en un formato estable para web y API. Nunca expone disco
 * ni ruta de las evidencias: solo su id para la descarga autorizada.
 */
class CandidatoPresenter
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

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
                Candidato::query()->with([
                    'empresa:id,nombre',
                    'sucursal:id,nombre',
                    'departamento:id,nombre',
                    'puestoObjetivo:id,nombre',
                    'vacante:id,puesto_id',
                    'responsableRh:id,name,apellidos',
                    'gerenteInvolucrado:id,name,apellidos',
                ]),
                $usuario,
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
            'estado_etiqueta' => $candidato->estado->etiqueta(),
            'etapa_maxima' => $candidato->etapa_maxima,
            'creado_en' => $candidato->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detalle(Candidato $candidato): array
    {
        $candidato->loadMissing([
            'empresa:id,nombre', 'sucursal:id,nombre', 'departamento:id,nombre', 'puestoObjetivo:id,nombre',
            'vacante:id,puesto_id,estado', 'campana:id,nombre,canal', 'responsableRh:id,name,apellidos,colaborador_id',
            'gerenteInvolucrado:id,name,apellidos,colaborador_id',
            'entrevistas.entrevistador:id,name,apellidos,colaborador_id',
            'psicometricas.evidencias', 'socioeconomicos.evidencias', 'socioeconomicos.visitador:id,name,apellidos,colaborador_id',
            'referencias.validadaPor:id,name,apellidos,colaborador_id',
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
            'campana' => $candidato->campana?->nombre,
            'empresa' => $candidato->empresa?->nombre,
            'sucursal' => $candidato->sucursal?->nombre,
            'sucursal_id' => $candidato->sucursal_id,
            'departamento' => $candidato->departamento?->nombre,
            'puesto' => $candidato->puestoObjetivo?->nombre,
            'puesto_objetivo_id' => $candidato->puesto_objetivo_id,
            'vacante_id' => $candidato->vacante_id,
            'empresa_id' => $candidato->empresa_id,
            'departamento_id' => $candidato->departamento_id,
            'responsable_rh_id' => $candidato->responsable_rh_id,
            'gerente_involucrado_id' => $candidato->gerente_involucrado_id,
            'responsable_rh' => $candidato->responsableRh?->nombreCompleto(),
            'gerente' => $candidato->gerenteInvolucrado?->nombreCompleto(),
            'observaciones' => $candidato->observaciones,
            'estado' => $candidato->estado->value,
            'estado_etiqueta' => $candidato->estado->etiqueta(),
            'motivo_salida' => $candidato->motivo_salida,
            'tiene_cv' => $candidato->tiene_cv,
            'colaborador_id' => $candidato->colaborador_id,
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
                'riesgos' => $s->riesgos,
                'observaciones' => $s->observaciones,
                'resultado' => $s->resultado->value,
                'resultado_etiqueta' => $s->resultado->etiqueta(),
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
            ])->values()->all(),
            'invitacion' => $invitacion !== null ? [
                'id' => $invitacion->id,
                'estado' => $invitacion->estado->value,
                'estado_etiqueta' => $invitacion->estado->etiqueta(),
                'expira_en' => $invitacion->expires_at->toIso8601String(),
                'usada_en' => $invitacion->used_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidencia(CandidatoEvidencia $evidencia): array
    {
        return [
            'id' => $evidencia->id,
            'tipo' => $evidencia->tipo->value,
            'tipo_etiqueta' => $evidencia->tipo->etiqueta(),
            'nombre' => $evidencia->original_name,
            'mime' => $evidencia->mime,
            'tamano' => $evidencia->size,
            'subida_en' => $evidencia->created_at?->toIso8601String(),
        ];
    }
}
