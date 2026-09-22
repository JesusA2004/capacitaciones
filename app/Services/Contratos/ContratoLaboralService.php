<?php

namespace App\Services\Contratos;

use App\Enums\EstadoContratoLaboral;
use App\Enums\PrioridadTarea;
use App\Enums\TipoContratacion;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Tareas\TareaService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Relación contractual del colaborador: contrato inicial (periodo de
 * prueba / capacitación / tiempo determinado / indeterminado), paquete de
 * documentos contractuales (confidencialidad, no competencia...),
 * renovación a indeterminado y terminación. Los PDFs los genera
 * MotorDocumentalService; aquí solo se decide QUÉ documentos corresponden y
 * con qué datos del contrato (snapshot de fechas y sueldo).
 */
class ContratoLaboralService
{
    public const PERMISO_GENERAR = 'documentos_laborales.generar';

    public function __construct(
        private readonly MotorDocumentalService $motor,
        private readonly TareaService $tareas,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * Crea la relación contractual vigente y sincroniza los campos del
     * colaborador (tipo de contratación y periodo de prueba). Debe llamarse
     * dentro de la transacción del alta.
     */
    public function crearContrato(Colaborador $colaborador, TipoContratacion $tipo, CarbonInterface $inicio, ?CarbonInterface $fin, User $actor, ?ContratoLaboral $anterior = null): ContratoLaboral
    {
        if ($tipo->tieneVencimiento() && $fin === null) {
            throw ValidationException::withMessages(['fecha_fin_contrato' => 'Captura la fecha de vencimiento del contrato.']);
        }

        if ($fin !== null && $fin->lt($inicio)) {
            throw ValidationException::withMessages(['fecha_fin_contrato' => 'La fecha de vencimiento no puede ser anterior al inicio.']);
        }
        $contrato = ContratoLaboral::query()->create([
            'colaborador_id' => $colaborador->id,
            'tipo' => $tipo,
            'fecha_inicio' => $inicio->toDateString(),
            'fecha_fin' => $tipo->tieneVencimiento() ? $fin?->toDateString() : null,
            'estado' => EstadoContratoLaboral::Vigente,
            'sueldo_mensual' => $colaborador->sueldo_mensual,
            'puesto_id' => $colaborador->puesto_id,
            'sucursal_id' => $colaborador->sucursal_principal_id,
            'contrato_anterior_id' => $anterior?->id,
            'creado_por' => $actor->id,
        ]);

        $colaborador->update([
            'tipo_contratacion' => $tipo,
            'periodo_prueba_inicio' => $tipo === TipoContratacion::PeriodoPrueba ? $inicio->toDateString() : $colaborador->periodo_prueba_inicio,
            'periodo_prueba_fin' => $tipo === TipoContratacion::PeriodoPrueba ? $fin?->toDateString() : $colaborador->periodo_prueba_fin,
        ]);

        $this->auditoria->registrar('contrato_creado', $contrato, $actor, [
            'colaborador_id' => $colaborador->id,
            'tipo' => $tipo->value,
            'fecha_inicio' => $inicio->toDateString(),
            'fecha_fin' => $fin?->toDateString(),
        ]);

        return $contrato;
    }

    /**
     * Genera los documentos contractuales del paquete indicado. Las claves
     * sin plantilla activa NO truenan el proceso: quedan como pendiente
     * explícito ("contrato pendiente") en la bandeja de RH, para que
     * Jurídico cargue el formato y se regenere después.
     *
     * @param  list<string>  $claves  La primera es el contrato principal.
     * @return array{generados: list<GeneratedDocument>, pendientes: list<string>}
     */
    public function prepararDocumentos(ContratoLaboral $contrato, array $claves, User $actor): array
    {
        $contrato->loadMissing('colaborador');
        $generados = [];
        $pendientes = [];

        foreach ($claves as $indice => $clave) {
            try {
                $documento = $this->motor->generar($contrato->colaborador, $clave, $actor, $this->variablesContrato($contrato), $contrato);
                $generados[] = $documento;

                if ($indice === 0) {
                    $contrato->update(['generated_document_id' => $documento->id]);
                }
            } catch (ValidationException $e) {
                $pendientes[] = $clave;

                $this->tareas->abrir(TipoTarea::ContratoPendiente, $contrato, [
                    'titulo' => sprintf('Documento contractual pendiente: %s', config("contratos.plantillas.{$clave}.nombre", $clave)),
                    'descripcion' => collect($e->errors())->flatten()->implode(' '),
                    'prioridad' => $indice === 0 ? PrioridadTarea::Alta : PrioridadTarea::Media,
                    'colaborador' => $contrato->colaborador,
                    'permiso' => self::PERMISO_GENERAR,
                    'accion' => 'generar_documento',
                    'datos' => ['clave' => $clave],
                ]);
            }
        }

        if ($pendientes === []) {
            $this->tareas->resolver(TipoTarea::ContratoPendiente, $contrato, $actor);
        }

        return ['generados' => $generados, 'pendientes' => $pendientes];
    }

    /**
     * Genera (o regenera) un documento contractual concreto del contrato,
     * p. ej. cuando Jurídico ya cargó la plantilla que faltaba.
     */
    public function generarDocumento(ContratoLaboral $contrato, string $clave, User $actor): GeneratedDocument
    {
        $contrato->loadMissing('colaborador');
        $documento = $this->motor->generar($contrato->colaborador, $clave, $actor, $this->variablesContrato($contrato), $contrato);

        $principal = $this->clavesPaquete($contrato)[0] ?? null;

        if ($clave === $principal) {
            $contrato->update(['generated_document_id' => $documento->id]);
        }

        $faltantes = collect($this->clavesPaquete($contrato))
            ->reject(fn (string $c) => GeneratedDocument::query()
                ->where('documentable_type', $contrato->getMorphClass())
                ->where('documentable_id', $contrato->id)
                ->where('clave_plantilla', $c)
                ->where('estado_flujo', '!=', 'cancelado')
                ->exists());

        if ($faltantes->isEmpty()) {
            $this->tareas->resolver(TipoTarea::ContratoPendiente, $contrato, $actor);
        }

        return $documento;
    }

    /**
     * Claves del paquete documental que corresponde a este contrato.
     *
     * @return list<string>
     */
    public function clavesPaquete(ContratoLaboral $contrato): array
    {
        if ($contrato->contrato_anterior_id !== null) {
            $claves = self::clavesConfiguradas('contratos.paquete_renovacion');

            return $claves;
        }
        $claves = self::clavesConfiguradas("contratos.paquetes_alta.{$contrato->tipo->value}");

        return $claves;
    }

    /**
     * Renovación a tiempo indeterminado (evaluación aprobada con decisión de
     * renovar): el contrato anterior queda "renovado", nace el nuevo contrato
     * vigente y se prepara su documento para firma → impresión → firma
     * física → envío → recepción → archivo (flujo documental).
     */
    public function renovar(ContratoLaboral $anterior, User $actor): ContratoLaboral
    {
        $nuevo = DB::transaction(function () use ($anterior, $actor): ContratoLaboral {
            $anterior = ContratoLaboral::query()->lockForUpdate()->findOrFail($anterior->id);

            if ($anterior->estado !== EstadoContratoLaboral::Vigente) {
                throw ValidationException::withMessages(['contrato' => 'Solo un contrato vigente puede renovarse.']);
            }

            $anterior->update(['estado' => EstadoContratoLaboral::Renovado]);
            $inicio = $anterior->fecha_fin !== null ? $anterior->fecha_fin->copy()->addDay() : now()->startOfDay();

            return $this->crearContrato($anterior->colaborador, TipoContratacion::Indeterminado, $inicio, null, $actor, $anterior);
        });

        $this->auditoria->registrar('contrato_renovado', $nuevo, $actor, ['contrato_anterior_id' => $anterior->id]);

        // Fuera de la transacción: el contrato ya es válido aunque el PDF
        // quede pendiente (la falta de plantilla se vuelve tarea, no error).
        $claves = self::clavesConfiguradas('contratos.paquete_renovacion');
        $this->prepararDocumentos($nuevo, $claves, $actor);

        return $nuevo->refresh();
    }

    /**
     * Lista de claves de plantilla de una entrada de config/contratos.php
     * (solo cadenas; cualquier otro valor se descarta).
     *
     * @return list<string>
     */
    public static function clavesConfiguradas(string $llave): array
    {
        return array_values(array_filter((array) config($llave, []), 'is_string'));
    }

    public function terminar(ContratoLaboral $contrato, User $actor, ?string $observaciones = null): ContratoLaboral
    {
        if ($contrato->estado === EstadoContratoLaboral::Vigente) {
            $contrato->update(['estado' => EstadoContratoLaboral::Terminado, 'observaciones' => $observaciones ?? $contrato->observaciones]);
            $this->auditoria->registrar('contrato_terminado', $contrato, $actor);
        }

        return $contrato;
    }

    public function vigente(Colaborador $colaborador): ?ContratoLaboral
    {
        return ContratoLaboral::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', EstadoContratoLaboral::Vigente->value)
            ->orderByDesc('fecha_inicio')
            ->first();
    }

    /**
     * Contratos vigentes que vencen dentro de $dias días, acotados por el
     * alcance del usuario.
     *
     * @return Collection<int, ContratoLaboral>
     */
    public function porVencer(User $usuario, int $dias): Collection
    {
        return $this->queryVisibles($usuario)
            ->where('estado', EstadoContratoLaboral::Vigente->value)
            ->whereNotNull('fecha_fin')
            ->whereDate('fecha_fin', '<=', now()->addDays($dias)->toDateString())
            ->with(['colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id,jefe_id', 'evaluacion'])
            ->orderBy('fecha_fin')
            ->get();
    }

    /**
     * @return Builder<ContratoLaboral>
     */
    public function queryVisibles(User $usuario): Builder
    {
        $query = ContratoLaboral::query();

        if (! $this->alcance->tieneAlcanceGlobal($usuario)) {
            $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)->select('id'));
        }

        return $query;
    }

    /**
     * true si el documento principal del contrato ya fue firmado por el
     * colaborador (digital o físicamente).
     */
    public function contratoFirmado(ContratoLaboral $contrato): bool
    {
        $documento = $contrato->documento;

        return $documento?->estado_flujo?->estaFirmado() ?? false;
    }

    /**
     * @return array<string, string>
     */
    public function variablesContrato(ContratoLaboral $contrato): array
    {
        return [
            'tipo_contratacion' => $contrato->tipo->etiqueta(),
            'fecha_inicio_contrato' => $contrato->fecha_inicio->format('d/m/Y'),
            'fecha_fin_contrato' => $contrato->fecha_fin?->format('d/m/Y') ?? '',
            'sueldo_mensual' => $contrato->sueldo_mensual !== null ? sprintf('$%s', number_format((float) $contrato->sueldo_mensual, 2)) : '',
            'sueldo_diario' => $contrato->sueldo_mensual !== null ? sprintf('$%s', number_format((float) $contrato->sueldo_mensual / 30, 2)) : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(ContratoLaboral $contrato): array
    {
        try {
            $firmado = $this->contratoFirmado($contrato);
        } catch (Throwable) {
            $firmado = false;
        }

        return [
            'id' => $contrato->id,
            'colaborador_id' => $contrato->colaborador_id,
            'colaborador' => $contrato->relationLoaded('colaborador') ? $contrato->colaborador->nombreCompleto() : null,
            'tipo' => $contrato->tipo->value,
            'tipo_etiqueta' => $contrato->tipo->etiqueta(),
            'estado' => $contrato->estado->value,
            'fecha_inicio' => $contrato->fecha_inicio->toDateString(),
            'fecha_fin' => $contrato->fecha_fin?->toDateString(),
            'dias_para_vencer' => $contrato->diasParaVencer(),
            'sueldo_mensual' => $contrato->sueldo_mensual,
            'documento_id' => $contrato->generated_document_id,
            'documento_firmado' => $firmado,
            'contrato_anterior_id' => $contrato->contrato_anterior_id,
            'aviso_vencimiento_en' => $contrato->aviso_vencimiento_en?->toIso8601String(),
            'evaluacion_id' => $contrato->relationLoaded('evaluacion') ? $contrato->evaluacion?->id : null,
        ];
    }
}
