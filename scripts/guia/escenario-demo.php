<?php

/**
 * Escenario de la Guía maestra para grabar capturas y video — SOLO LOCAL.
 *
 *   php scripts/guia/escenario-demo.php            # imprime JSON con los IDs
 *
 * Lo usa scripts/guia/grabar-capturas.mjs para saber qué registro abrir en
 * cada escena. Trabaja sobre los datos FICTICIOS que siembra DemoSeeder
 * (CicloLaboralDemoSeeder: «Demo … Ciclo», cuentas @mrlana.test), que solo
 * existen en local/testing. Se niega a correr en cualquier otro entorno:
 * nunca toca producción.
 *
 * Si falta algo que la grabación necesita y el sistema lo puede producir con
 * sus propios services (un lote de nómina quincenal preparado, una renovación
 * autorizada), lo produce aquí mismo — igual que lo haría RH en pantalla.
 */

use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\PeriodicidadNomina;
use App\Models\Candidato;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\NominaLote;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Nomina\LoteNominaService;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Vacantes\VacantesListadoService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment(['local', 'testing'])) {
    fwrite(STDERR, 'escenario-demo: solo corre en local/testing (APP_ENV='.app()->environment()."). Nunca contra producción.\n");
    exit(1);
}

$rh = User::query()->where('email', 'rh.admin@mrlana.test')->first();
$gerente = User::query()->where('email', 'gerente.sucursal@mrlana.test')->first();

if ($rh === null || $gerente === null) {
    fwrite(STDERR, "escenario-demo: faltan las cuentas demo. Corre `php artisan migrate:fresh --seed` en local.\n");
    exit(1);
}

Auth::setUser($rh);

/** Candidato demo en un estado (prefiere los del ciclo, de la sucursal del gerente demo). */
$candidato = function (string $estado) use ($gerente): ?int {
    $sucursal = $gerente->colaborador?->sucursal_principal_id;

    return Candidato::query()
        ->where('estado', $estado)
        ->orderByRaw('CASE WHEN sucursal_id = ? THEN 0 ELSE 1 END', [$sucursal])
        ->orderBy('id')
        ->value('id');
};

$colaboradorDemo = fn (string $estadoAlta) => Colaborador::query()
    ->where('apellidos', 'like', '%Ciclo%')
    ->where('estado_alta', $estadoAlta)
    ->orderBy('id')
    ->value('id');

// Renovación autorizada: si ninguna persona demo tiene aún contrato
// indeterminado, RH autoriza una evaluación ya capturada (como en pantalla).
if (! ContratoLaboral::query()->where('tipo', 'indeterminado')->exists()) {
    $capturada = EvaluacionPeriodoPrueba::query()->where('estado', EstadoEvaluacionPrueba::Capturada)->first();

    if ($capturada !== null) {
        app(EvaluacionPeriodoPruebaService::class)->autorizar($capturada, $rh, ['renovar' => true, 'comentario' => 'Renovación autorizada (demo para la guía).']);
    }
}

// Un candidato autorizado por RH cuya vacante SIGUE con cupo, para grabar
// «Generar QR de contratación» de verdad. Si no lo hay, RH autoriza al
// siguiente que espera su autorización en una vacante con cupo.
$conCupo = fn (Candidato $c): bool => $c->vacante_id !== null
    && ($v = Vacante::query()->find($c->vacante_id)) !== null
    && app(VacantesListadoService::class)->tieneCupo($v);

$autorizado = Candidato::query()->where('estado', 'autorizado_rh')->get()->first($conCupo);

if ($autorizado === null) {
    $porAutorizar = Candidato::query()->where('estado', 'autorizacion_rh_pendiente')->get()->first($conCupo);

    if ($porAutorizar !== null) {
        $autorizado = app(CandidatoWorkflowService::class)->autorizarRh($porAutorizar, $rh, 'Autorizado (demo para la guía).');
    }
}

// Un lote quincenal preparado para las escenas de nómina.
if (! NominaLote::query()->exists()) {
    app(LoteNominaService::class)->preparar(PeriodicidadNomina::Quincenal, now(), $rh);
}

$indeterminado = ContratoLaboral::query()->where('tipo', 'indeterminado')->value('colaborador_id');
$cierreFiniquito = CierreLaboral::query()->whereNotNull('solicitud_interna_id')->orderByDesc('id')
    ->whereIn('estado', ['pago_programado', 'finiquito_autorizado', 'pendiente_rh'])->first();
$cierrePendiente = CierreLaboral::query()->where('estado', 'pendiente_rh')->first();

$enDocumentos = $colaboradorDemo('pendiente_documentos');

$escenario = [
    'usuarios' => [
        'rh' => $rh->username,
        'gerente' => $gerente->username,
        // La persona demo que va en documentos (creó su cuenta con el QR).
        'colaborador' => $enDocumentos !== null ? Colaborador::query()->find($enDocumentos)?->user?->username : null,
    ],
    'sucursal' => $gerente->colaborador?->sucursal_principal_id,
    'candidatos' => [
        'filtro' => $candidato('recibidos'),
        'entrevista' => $candidato('entrevista_pendiente'),
        'psicometricas' => $candidato('psicometricas_pendientes'),
        'socioeconomico' => $candidato('socioeconomico_pendiente'),
        'preseleccion' => $candidato('preseleccion_gerente'),
        'rechazado' => $candidato('rechazado_rh'),
        'autorizado' => $autorizado?->id,
    ],
    'colaboradores' => [
        'documentos' => $enDocumentos,
        'onboarding' => $colaboradorDemo('en_onboarding'),
        'indeterminado' => $indeterminado,
        'baja' => $cierrePendiente?->colaborador_id,
    ],
    'solicitudes' => [
        'finiquito' => $cierreFiniquito?->solicitud_interna_id,
    ],
    'lote' => NominaLote::query()->orderByDesc('id')->value('id'),
];

echo json_encode($escenario, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
