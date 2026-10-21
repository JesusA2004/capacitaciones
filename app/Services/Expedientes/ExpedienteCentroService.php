<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoExtraccion;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\ActaAdministrativa;
use App\Models\Baja;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\DivisionExpedienteHistorico;
use App\Models\DocumentExtraction;
use App\Models\EntregaActivo;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\OnboardingProceso;
use App\Models\Responsiva;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Contratos\RegularizacionContratosService;
use App\Services\Reclutamiento\CadenaContratacionService;
use App\Services\Solicitudes\AprobacionOrganizacionalService;
use App\Support\FechaMexico;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Expediente como centro»: lo que la pantalla del expediente necesita para
 * mostrar, en tarjetas con contador + estado + acción, todo lo de la
 * persona (organización, contratación, onboarding, periodo de prueba,
 * evaluaciones, activos/responsivas, actas, bajas, OCR/correcciones…) sin
 * llenar la pantalla de texto. Solo consultas de conteo / último registro;
 * se manda como prop diferida (Inertia::defer) para no frenar la carga.
 *
 * Nunca decide permisos de acción: cada enlace lleva a la pantalla dueña,
 * que vuelve a autorizar en el backend.
 *
 * @phpstan-type Tarjeta array{clave: string, titulo: string, icono: string, valor: string, detalle: string|null, tono: string, pestana: string|null, url: string|null, accion: string|null}
 */
class ExpedienteCentroService
{
    public function __construct(
        private readonly CadenaContratacionService $cadena,
        private readonly RegularizacionContratosService $contratos,
        private readonly AprobacionOrganizacionalService $aprobaciones,
    ) {}

    /**
     * @return array{organizacion: array<string, mixed>, origen: array<string, mixed>|null, contratacion: array<string, mixed>|null, tarjetas: list<Tarjeta>}
     */
    public function resumen(Colaborador $colaborador, User $viewer): array
    {
        // Puesto COMPLETO: el plan de contratos usa meses_periodo_prueba y grupo_documental.
        $colaborador->loadMissing(['puesto', 'departamento:id,nombre', 'sucursalPrincipal:id,nombre,empresa_id', 'sucursalPrincipal.empresa:id,nombre']);
        // Jefe ORGANIZACIONAL (a quién reporta en el organigrama) — mismo
        // cálculo que usa el motor de aprobaciones, distinto del responsable.
        $jefe = $this->aprobaciones->jefeOrganizacional($colaborador) ?? $colaborador->jefe;

        return [
            'organizacion' => [
                'puesto' => $colaborador->puesto?->nombre,
                'departamento' => $colaborador->departamento?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'empresa' => $colaborador->sucursalPrincipal?->empresa?->nombre,
                'jefe_organizacional' => $jefe !== null ? [
                    'nombre' => $jefe->nombreCompleto(),
                    'puesto' => $jefe->puesto?->nombre,
                ] : null,
                'responsable_aprobacion' => $this->responsableAprobacion($colaborador),
            ],
            'origen' => $this->cadena->origenDeColaborador($colaborador, $viewer),
            'contratacion' => $this->contratacion($colaborador, $viewer),
            'tarjetas' => $this->tarjetas($colaborador, $viewer),
        ];
    }

    /**
     * Contratos que le corresponden (mismo plan que el módulo «Contratos»):
     * vista previa permisiva («PENDIENTE: …»), generación estricta.
     *
     * @return array<string, mixed>|null
     */
    private function contratacion(Colaborador $colaborador, User $viewer): ?array
    {
        if (! $this->contratos->puedeVer($viewer) || $viewer->colaborador_id === $colaborador->id) {
            return null;
        }

        try {
            $this->contratos->exigirAlcance($viewer, $colaborador);
            // Instancia limpia: la pantalla ya cargó el puesto con columnas
            // parciales y el plan necesita meses_periodo_prueba / grupo_documental.
            $plan = $this->contratos->plan($colaborador->fresh() ?? $colaborador);
        } catch (Throwable $e) {
            Log::warning('ExpedienteCentroService: no se pudo calcular el plan de contratos.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);

            return null;
        }

        return [
            ...$plan,
            'puede_generar' => $this->contratos->puedeGenerar($viewer),
        ];
    }

    /**
     * Responsable de APROBACIÓN (≠ jefe organizacional): lo decide el motor
     * de aprobaciones (AprobacionOrganizacionalService), nunca esta pantalla.
     *
     * @return array{nombre: string|null, puesto: string|null, origen: string|null, bloqueado: bool, mensaje: string|null}
     */
    private function responsableAprobacion(Colaborador $colaborador): array
    {
        try {
            $resultado = $this->aprobaciones->responsableDeAprobacion($colaborador);
        } catch (Throwable $e) {
            Log::warning('ExpedienteCentroService: no se pudo resolver el responsable de aprobación.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);

            return ['nombre' => null, 'puesto' => null, 'origen' => null, 'bloqueado' => true, 'mensaje' => 'No se pudo calcular el responsable de aprobación.'];
        }

        $nombres = $resultado['colaboradores']->map(fn (Colaborador $c): string => $c->nombreCompleto())->filter()->values()->all();
        $primero = $resultado['colaboradores']->first();

        return [
            'nombre' => $nombres !== [] ? implode(' · ', $nombres) : null,
            'puesto' => $primero?->puesto?->nombre,
            'origen' => $resultado['origen_etiqueta'],
            'bloqueado' => $resultado['bloqueado'],
            'mensaje' => $resultado['mensaje'],
        ];
    }

    /**
     * @return list<Tarjeta>
     */
    private function tarjetas(Colaborador $colaborador, User $viewer): array
    {
        $id = $colaborador->id;
        $ciclo = route('rh.colaboradores.ciclo', $id);
        $tarjetas = [];

        // Periodo de prueba (fechas del colaborador).
        if ($colaborador->periodo_prueba_fin !== null) {
            $dias = (int) FechaMexico::inicioDeHoy()->diffInDays($colaborador->periodo_prueba_fin, false);
            $tarjetas[] = $this->tarjeta('periodo_prueba', 'Periodo de prueba', 'hourglass',
                $dias >= 0 ? sprintf('%d d', $dias) : 'Concluido',
                sprintf('Vence %s', $colaborador->periodo_prueba_fin->format('d/m/Y')),
                $dias < 0 ? 'neutro' : ($dias <= 15 ? 'alerta' : 'info'), null, $ciclo, 'Ver ciclo laboral');
        }

        $onboarding = OnboardingProceso::query()->where('colaborador_id', $id)->latest('id')->first(['id', 'estado']);
        $tarjetas[] = $this->tarjeta('onboarding', 'Onboarding', 'sparkles',
            $onboarding?->estado->etiqueta() ?? 'Sin iniciar', null,
            $onboarding === null ? 'neutro' : ($onboarding->estado->value === 'completado' ? 'exito' : 'primario'), 'onboarding', null, 'Ver checklist');

        $evaluaciones = EvaluacionPeriodoPrueba::query()->where('colaborador_id', $id)->latest('id')->get(['id', 'estado']);
        $tarjetas[] = $this->tarjeta('evaluaciones', 'Evaluaciones', 'clipboard-check',
            (string) $evaluaciones->count(), $evaluaciones->first()?->estado->etiqueta(),
            $evaluaciones->isEmpty() ? 'neutro' : ($evaluaciones->first()->estado->value === 'autorizada' ? 'exito' : 'alerta'), null, $ciclo, 'Ver evaluación');

        $responsivas = Responsiva::query()->where('colaborador_id', $id)->count();
        $entregas = EntregaActivo::query()->where('colaborador_id', $id)->count();
        $tarjetas[] = $this->tarjeta('activos', 'Activos y responsivas', 'package',
            (string) ($responsivas + $entregas), sprintf('%d responsiva%s · %d entrega%s', $responsivas, $responsivas === 1 ? '' : 's', $entregas, $entregas === 1 ? '' : 's'),
            ($responsivas + $entregas) > 0 ? 'primario' : 'neutro', 'documentos', null, 'Ver responsivas');

        if ($viewer->can('actas.ver')) {
            $actas = ActaAdministrativa::query()->where('colaborador_id', $id)->latest('id')->get(['id', 'estado']);
            $ultima = $actas->first();
            $tarjetas[] = $this->tarjeta('actas', 'Actas administrativas', 'gavel',
                (string) $actas->count(), $ultima?->estado->etiqueta(),
                $actas->isEmpty() ? 'neutro' : 'alerta', null, $ultima !== null ? route('rh.actas.show', $ultima->id) : null, $ultima !== null ? 'Ver última acta' : null);
        }

        $baja = Baja::query()->where('colaborador_id', $id)->latest('id')->first(['id', 'estatus']);
        $cierre = CierreLaboral::query()->where('colaborador_id', $id)->latest('id')->first(['id', 'estado']);
        $tarjetas[] = $this->tarjeta('bajas', 'Bajas y cierres', 'log-out',
            $baja?->estatus->etiqueta() ?? $cierre?->estado->etiqueta() ?? 'Sin baja', null,
            $baja === null && $cierre === null ? 'exito' : 'peligro', null,
            $baja !== null && $viewer->can('bajas.ver') ? route('rh.bajas.show', $baja->id) : ($cierre !== null ? $ciclo : null),
            $baja !== null || $cierre !== null ? 'Ver baja' : null);

        $correcciones = SolicitudInterna::query()
            ->where('colaborador_id', $id)
            ->where('tipo', TipoSolicitudInterna::ActualizacionDatos->value)
            ->whereIn('estado', [EstadoSolicitudInterna::Creada->value, EstadoSolicitudInterna::Enviada->value, EstadoSolicitudInterna::EnRevision->value])
            ->count();
        $extracciones = DocumentExtraction::query()
            ->where('colaborador_id', $id)
            ->whereIn('status', [EstadoExtraccion::Procesado->value, EstadoExtraccion::Pendiente->value])
            ->count();
        $division = DivisionExpedienteHistorico::query()->where('colaborador_id', $id)->latest('id')->first(['id']);
        $tarjetas[] = $this->tarjeta('ocr', 'OCR y correcciones', 'scan-text',
            (string) ($correcciones + $extracciones),
            sprintf('%d corrección%s · %d lectura%s OCR', $correcciones, $correcciones === 1 ? '' : 'es', $extracciones, $extracciones === 1 ? '' : 's'),
            ($correcciones + $extracciones) > 0 ? 'alerta' : 'exito', $division === null ? 'documentos' : null,
            $division !== null ? route('rh.expedientes.division-historico.editor', $division->id) : null,
            $division !== null ? 'Abrir editor de hojas' : 'Ver documentos');

        return $tarjetas;
    }

    /**
     * @return Tarjeta
     */
    private function tarjeta(string $clave, string $titulo, string $icono, string $valor, ?string $detalle, string $tono, ?string $pestana, ?string $url, ?string $accion): array
    {
        return ['clave' => $clave, 'titulo' => $titulo, 'icono' => $icono, 'valor' => $valor, 'detalle' => $detalle, 'tono' => $tono, 'pestana' => $pestana, 'url' => $url, 'accion' => $accion];
    }
}
