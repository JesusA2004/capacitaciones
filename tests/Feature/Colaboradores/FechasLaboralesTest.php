<?php

use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoUsuario;
use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\Correccion;
use App\Models\EvaluacionPeriodoPrueba;
use App\Services\Colaboradores\FechasLaboralesService;
use App\Services\Contratos\ElegibilidadEvaluacionPeriodoPruebaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
| Fechas laborales (docs/FECHAS_LABORALES.md): periodo efectivo (específico ??
| puesto) desde el inicio de capacitación inicial, edición auditada con motivo
| y aviso de contrato desfasado, permiso propio.
*/

beforeEach(function () {
    clRelojMediodiaMexico();
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->estructura = clEstructura();
    $this->estructura['puesto']->update(['meses_periodo_prueba' => 3]);
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    // Plantilla importada por Excel: sin candidato de reclutamiento.
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'departamento_id' => $this->estructura['departamento']->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2026-09-17',
        'candidato_id' => null,
    ]);
});

it('usa el periodo específico de la persona o, vacío, el del puesto, desde el inicio de capacitación inicial', function () {
    $fechas = app(FechasLaboralesService::class);

    expect($fechas->periodoEfectivo($this->persona)['origen'])->toBe('puesto')
        ->and($fechas->finPeriodoPrueba($this->persona)?->toDateString())->toBe('2026-12-16');

    $this->persona->update(['periodo_prueba_inicio' => '2026-10-01', 'periodo_prueba_cantidad' => 45, 'periodo_prueba_unidad' => 'dias']);
    $persona = $this->persona->refresh();

    expect($fechas->periodoEfectivo($persona)['etiqueta'])->toBe('45 días')
        ->and($fechas->finPeriodoPrueba($persona)?->toDateString())->toBe('2026-11-14')
        // La elegibilidad de la evaluación de periodo de prueba lee la misma fuente.
        ->and(app(ElegibilidadEvaluacionPeriodoPruebaService::class)->finPeriodo($persona, null)?->toDateString())->toBe('2026-11-14');
});

it('guarda el periodo específico con motivo e historial, ajusta la evaluación pendiente y avisa que el contrato quedó desfasado', function () {
    $contrato = ContratoLaboral::factory()->create([
        'colaborador_id' => $this->persona->id,
        'tipo' => TipoContratacion::CapacitacionInicial->value,
        'fecha_inicio' => '2026-09-17',
        'fecha_fin' => '2026-12-16',
    ]);
    $evaluacion = EvaluacionPeriodoPrueba::query()->create([
        'contrato_laboral_id' => $contrato->id,
        'colaborador_id' => $this->persona->id,
        'estado' => EstadoEvaluacionPrueba::Pendiente,
        'fecha_limite' => '2026-12-16',
    ]);
    $descripcion = $this->actingAs($this->rh)->getJson("/correcciones/fechas_laborales/{$this->persona->id}")->assertOk()->json('data');
    $cuerpo = [
        'valores' => ['periodo_prueba_cantidad' => '2', 'periodo_prueba_unidad' => 'meses'],
        'huella' => $descripcion['huella'],
        'clave_idempotencia' => (string) Str::uuid(),
    ];

    // Sin motivo no se guarda nada.
    $this->actingAs($this->rh)->postJson("/correcciones/fechas_laborales/{$this->persona->id}", [...$cuerpo, 'motivo' => ''])->assertUnprocessable();

    $previa = $this->actingAs($this->rh)->postJson("/correcciones/fechas_laborales/{$this->persona->id}/vista-previa", ['valores' => $cuerpo['valores']])->assertOk()->json('data');
    expect(collect($previa['cambios'])->firstWhere('campo', 'fin_periodo')['despues'])->toBe('16/11/2026')
        ->and(collect($previa['afectados'])->pluck('etiqueta'))->toContain('Debes actualizar el contrato');

    $this->actingAs($this->rh)->postJson("/correcciones/fechas_laborales/{$this->persona->id}", [...$cuerpo, 'motivo' => 'Acuerdo con su gerente: periodo de 2 meses.'])->assertCreated();

    $correccion = Correccion::query()->where('dominio', 'fechas_laborales')->sole();
    expect($correccion->campos()->pluck('campo')->all())->toContain('periodo_prueba_cantidad', 'fin_periodo')
        ->and($this->persona->refresh()->periodo_prueba_fin?->toDateString())->toBe('2026-11-16')
        ->and($evaluacion->refresh()->fecha_limite?->toDateString())->toBe('2026-11-16')
        // El contrato NUNCA se modifica solo: queda la alerta para actualizarlo.
        ->and($contrato->refresh()->fecha_fin?->toDateString())->toBe('2026-12-16');

    $resumen = $this->actingAs($this->rh)->getJson("/rh/expedientes/{$this->persona->id}/fechas-laborales")->assertOk()->json('data');
    expect(collect($resumen['alertas'])->pluck('codigo'))->toContain('contrato_desfasado')
        ->and($resumen['periodo']['origen'])->toBe('especifico');
});

it('exige el permiso propio y nunca deja a la persona ver ni editar sus propias fechas', function () {
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $gerente->revokePermissionTo('expedientes.editar_fechas');

    $this->actingAs($gerente)->get('/rh/fechas-laborales')->assertForbidden();
    $this->actingAs(clUsuarioDe($this->persona))->getJson("/rh/expedientes/{$this->persona->id}/fechas-laborales")->assertForbidden();
    $this->actingAs($this->rh)->get('/rh/fechas-laborales')->assertOk();
});
