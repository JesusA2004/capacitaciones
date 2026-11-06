<?php

use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoCorteRecepcion;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoRecepcionDocumento;
use App\Enums\EstadoReciboNomina;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoUsuario;
use App\Enums\ReglaRecepcionDocumental;
use App\Enums\TipoContratacion;
use App\Enums\TipoSolicitudInterna;
use App\Models\Baja;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\Prestamo;
use App\Models\Puesto;
use App\Models\RecepcionDocumentalCorte;
use App\Models\RecepcionDocumentalRenglon;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Bajas\CatalogoBajasService;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use App\Services\RecepcionDocumental\RecepcionDocumentalService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/*
| Recepción documental RH — reglas y materialización del corte mensual
| (docs/RECEPCION_DOCUMENTAL_RH.md). El reloj se fija con
| Carbon::setTestNow; la app corre en UTC y el mes se decide en hora de
| México.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-11-20 18:00:00', 'UTC'));
    $this->sucursal = Sucursal::factory()->create(['nombre' => 'Toluca Centro']);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'grupo_documental' => 'gestor']);
    $this->administrativo = Puesto::factory()->create(['nombre' => 'Auxiliar administrativo', 'grupo_documental' => 'administrativo_no_confianza']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function rdAlta(object $prueba, string $fecha, ?Puesto $puesto = null, array $extra = []): Colaborador
{
    return Colaborador::factory()->create([
        'fecha_ingreso' => $fecha,
        'puesto_id' => ($puesto ?? $prueba->gestor)->id,
        'sucursal_principal_id' => $prueba->sucursal->id,
        'estatus' => EstadoUsuario::Activo,
        ...$extra,
    ]);
}

function rdGenerar(string $periodo): RecepcionDocumentalCorte
{
    return app(RecepcionDocumentalService::class)->generar(PeriodoRecepcion::desdeClave($periodo), null)['corte'];
}

/**
 * @return Collection<int, RecepcionDocumentalRenglon>
 */
function rdRenglones(RecepcionDocumentalCorte $corte, ?ReglaRecepcionDocumental $regla = null)
{
    return RecepcionDocumentalRenglon::query()->where('corte_id', $corte->id)
        ->when($regla !== null, fn ($q) => $q->where('regla', $regla->value))
        ->orderBy('id')->get();
}

test('el periodo usa el mes calendario real: 31, 30, febrero 28 y bisiesto 29', function (string $clave, string $fin, int $dias) {
    $periodo = PeriodoRecepcion::desdeClave($clave);

    expect($periodo->inicio()->toDateString())->toBe(sprintf('%s-01', $clave))
        ->and($periodo->fin()->toDateString())->toBe($fin)
        ->and($periodo->dias())->toBe($dias);
})->with([
    'mes de 31' => ['2026-01', '2026-01-31', 31],
    'mes de 30' => ['2026-04', '2026-04-30', 30],
    'febrero 28' => ['2026-02', '2026-02-28', 28],
    'febrero bisiesto 29' => ['2028-02', '2028-02-29', 29],
]);

test('el rango de timestamps va de las 00:00 a las 23:59:59 de México expresado en UTC', function () {
    expect(PeriodoRecepcion::desdeClave('2026-11')->rangoUtc())->toBe(['2026-11-01 06:00:00', '2026-12-01 05:59:59']);
});

test('una alta de Gestor espera sus 3 originales iniciales y 5 altas esperan 15', function () {
    rdAlta($this, '2026-11-05');
    $corte = rdGenerar('2026-11');

    expect(rdRenglones($corte, ReglaRecepcionDocumental::Altas)->pluck('tipo_documento')->sort()->values()->all())
        ->toBe(['contrato_capacitacion', 'contrato_confidencialidad', 'contrato_no_competencia']);

    foreach (range(1, 4) as $i) {
        rdAlta($this, sprintf('2026-11-%02d', 10 + $i));
    }

    rdGenerar('2026-11');
    expect(rdRenglones($corte, ReglaRecepcionDocumental::Altas))->toHaveCount(15);
});

test('una alta fuera del mes no se espera y un puesto sin no competencia espera solo 2 (paquete configurado)', function () {
    rdAlta($this, '2026-10-31');
    rdAlta($this, '2026-11-03', $this->administrativo);
    $corte = rdGenerar('2026-11');

    expect(rdRenglones($corte, ReglaRecepcionDocumental::Altas)->pluck('tipo_documento')->sort()->values()->all())
        ->toBe(['contrato_capacitacion', 'contrato_confidencialidad']);
});

test('la alta se liga a la versión digital vigente de su paquete', function () {
    $colaborador = rdAlta($this, '2026-11-05');
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id, 'tipo' => TipoContratacion::CapacitacionInicial, 'estado' => EstadoContratoLaboral::Vigente, 'fecha_inicio' => '2026-11-05']);
    $vieja = GeneratedDocument::factory()->create(['colaborador_id' => $colaborador->id, 'clave_plantilla' => 'contrato_confidencialidad', 'documentable_type' => $contrato->getMorphClass(), 'documentable_id' => $contrato->id, 'estado_flujo' => EstadoFlujoDocumento::Cancelado]);
    $vigente = GeneratedDocument::factory()->create(['colaborador_id' => $colaborador->id, 'clave_plantilla' => 'contrato_confidencialidad', 'documentable_type' => $contrato->getMorphClass(), 'documentable_id' => $contrato->id, 'estado_flujo' => EstadoFlujoDocumento::Generado]);

    $corte = rdGenerar('2026-11');
    $renglon = rdRenglones($corte, ReglaRecepcionDocumental::Altas)->firstWhere('tipo_documento', 'contrato_confidencialidad');

    expect($renglon->generated_document_id)->toBe($vigente->id)->not->toBe($vieja->id);
});

test('el contrato indeterminado aparece en el mes en que se generó y no en el mes del alta', function () {
    $colaborador = rdAlta($this, '2026-08-03');
    $inicial = ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id, 'tipo' => TipoContratacion::CapacitacionInicial, 'estado' => EstadoContratoLaboral::Renovado, 'fecha_inicio' => '2026-08-03']);
    $indeterminado = ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id, 'tipo' => TipoContratacion::Indeterminado, 'estado' => EstadoContratoLaboral::Vigente, 'fecha_inicio' => '2026-11-03', 'contrato_anterior_id' => $inicial->id]);
    $documento = GeneratedDocument::factory()->create(['colaborador_id' => $colaborador->id, 'clave_plantilla' => 'contrato_indeterminado', 'documentable_type' => $indeterminado->getMorphClass(), 'documentable_id' => $indeterminado->id, 'estado_flujo' => EstadoFlujoDocumento::Generado]);
    $documento->forceFill(['created_at' => Carbon::parse('2026-11-10 17:00:00', 'UTC')])->save();

    $noviembre = rdGenerar('2026-11');
    $agosto = rdGenerar('2026-08');

    $renglon = rdRenglones($noviembre, ReglaRecepcionDocumental::ContratoIndeterminado)->sole();
    expect($renglon->tipo_documento)->toBe('contrato_indeterminado')
        ->and($renglon->generated_document_id)->toBe($documento->id)
        ->and($renglon->fecha_evento?->toDateString())->toBe('2026-11-10')
        ->and(rdRenglones($agosto, ReglaRecepcionDocumental::ContratoIndeterminado))->toHaveCount(0)
        ->and(rdRenglones($agosto, ReglaRecepcionDocumental::Altas)->pluck('tipo_documento'))->not->toContain('contrato_indeterminado');
});

test('una baja por renuncia espera solo la carta de renuncia y el finiquito firmados; si se cancela pasan solos a «No aplica»', function () {
    app(CatalogoBajasService::class)->sembrar();
    $colaborador = rdAlta($this, '2025-01-10');
    $baja = Baja::query()->create([
        'folio' => 'BAJ-0001', 'colaborador_id' => $colaborador->id, 'nombre_colaborador' => $colaborador->nombreCompleto(),
        'numero_empleado' => $colaborador->numero_empleado, 'sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id,
        'fecha_baja' => '2026-11-12', 'forma_salida' => 'RV', 'estatus' => 'cerrada', 'origen' => 'sistema',
    ]);
    $carta = GeneratedDocument::factory()->create(['colaborador_id' => $colaborador->id, 'clave_plantilla' => 'carta_renuncia', 'estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente]);

    $corte = rdGenerar('2026-11');
    $bajas = rdRenglones($corte, ReglaRecepcionDocumental::Bajas);
    $requeridos = 2;

    expect($bajas)->toHaveCount($requeridos)
        ->and($bajas->pluck('tipo_documento')->sort()->values()->all())->toBe(['baja.RV.carta_renuncia', 'baja.RV.finiquito'])
        ->and($bajas->pluck('tipo_documento')->all())->not->toContain('baja.RV.entrevista_salida')
        ->and($bajas->firstWhere('tipo_documento', 'baja.RV.carta_renuncia')->generated_document_id)->toBe($carta->id)
        ->and($bajas->first()->origen_type)->toBe($baja->getMorphClass());

    // Uno ya recibido no se toca; los pendientes pasan a «No aplica» con justificación.
    $recibido = $bajas->first();
    $recibido->update(['estado' => EstadoRecepcionDocumento::Recibido, 'cantidad_recibida' => 1]);
    $baja->update(['estatus' => 'cancelada']);
    rdGenerar('2026-11');

    $despues = rdRenglones($corte, ReglaRecepcionDocumental::Bajas);
    expect($despues)->toHaveCount($requeridos)
        ->and($despues->firstWhere('id', $recibido->id)->estado)->toBe(EstadoRecepcionDocumento::Recibido)
        ->and($despues->where('estado', EstadoRecepcionDocumento::NoAplica)->count())->toBe($requeridos - 1)
        ->and($despues->firstWhere('estado', EstadoRecepcionDocumento::NoAplica)->justificacion)->toContain('Automático')
        ->and($despues->firstWhere('estado', EstadoRecepcionDocumento::NoAplica)->no_aplica_automatico)->toBeTrue();
});

test('vacaciones aprobadas esperan su formato firmado; un evento a las 23:30 de México del último día cuenta en ese mes', function () {
    $colaborador = rdAlta($this, '2025-01-10');
    $base = ['colaborador_id' => $colaborador->id, 'tipo' => TipoSolicitudInterna::Vacaciones, 'estado' => EstadoSolicitudInterna::Aprobada, 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-18', 'dias_solicitados' => 5];
    // 2026-11-30 23:30 en México = 2026-12-01 05:30 UTC.
    $ultimoDia = SolicitudInterna::factory()->create([...$base, 'revisado_en' => Carbon::parse('2026-12-01 05:30:00', 'UTC')]);
    // 2026-12-01 00:30 en México = 2026-12-01 06:30 UTC → diciembre.
    $diciembre = SolicitudInterna::factory()->create([...$base, 'revisado_en' => Carbon::parse('2026-12-01 06:30:00', 'UTC')]);
    SolicitudInterna::factory()->create([...$base, 'estado' => EstadoSolicitudInterna::Rechazada, 'revisado_en' => Carbon::parse('2026-11-15 18:00:00', 'UTC')]);

    Carbon::setTestNow(Carbon::parse('2026-12-05 18:00:00', 'UTC'));
    $noviembre = rdRenglones(rdGenerar('2026-11'), ReglaRecepcionDocumental::Vacaciones);
    $enDiciembre = rdRenglones(rdGenerar('2026-12'), ReglaRecepcionDocumental::Vacaciones);

    expect($noviembre->pluck('origen_id')->all())->toBe([$ultimoDia->id])
        ->and($noviembre->first()->fecha_evento?->toDateString())->toBe('2026-11-30')
        ->and($noviembre->first()->tipo_documento)->toBe('formato_vacaciones')
        ->and($enDiciembre->pluck('origen_id')->all())->toBe([$diciembre->id]);
});

test('un préstamo autorizado espera contrato, pagaré y carta de retención; uno cancelado no', function () {
    $colaborador = rdAlta($this, '2025-01-10');
    $prestamo = Prestamo::factory()->create(['colaborador_id' => $colaborador->id, 'estado' => 'activo', 'autorizado_en' => Carbon::parse('2026-11-08 16:00:00', 'UTC')]);
    Prestamo::factory()->create(['colaborador_id' => $colaborador->id, 'estado' => 'cancelado', 'autorizado_en' => Carbon::parse('2026-11-09 16:00:00', 'UTC')]);
    $pagare = GeneratedDocument::factory()->create(['colaborador_id' => $colaborador->id, 'clave_plantilla' => 'prestamo_pagare', 'documentable_type' => $prestamo->getMorphClass(), 'documentable_id' => $prestamo->id, 'estado_flujo' => EstadoFlujoDocumento::Generado]);

    $renglones = rdRenglones(rdGenerar('2026-11'), ReglaRecepcionDocumental::Prestamos);

    expect($renglones->pluck('tipo_documento')->sort()->values()->all())->toBe(['prestamo_consentimiento_retencion', 'prestamo_contrato', 'prestamo_pagare'])
        ->and($renglones->pluck('origen_id')->unique()->all())->toBe([$prestamo->id])
        ->and($renglones->firstWhere('tipo_documento', 'prestamo_pagare')->generated_document_id)->toBe($pagare->id);
});

test('el alta IMSS se espera en el mes de su fecha de alta ante el IMSS', function () {
    rdAlta($this, '2026-10-28', null, ['fecha_alta_imss' => '2026-11-02']);
    rdAlta($this, '2026-10-28', null, ['fecha_alta_imss' => '2026-10-29']);

    $renglones = rdRenglones(rdGenerar('2026-11'), ReglaRecepcionDocumental::AltaImss);

    expect($renglones)->toHaveCount(1)
        ->and($renglones->first()->tipo_documento)->toBe('alta_imss')
        ->and($renglones->first()->fecha_evento?->toDateString())->toBe('2026-11-02');
});

test('nómina: solo los recibos EMITIDOS reales del mes, uno por recibo (borradores y cancelados no cuentan)', function () {
    $colaborador = rdAlta($this, '2025-01-10');
    $usuario = User::factory()->create();
    $base = ['colaborador_id' => $colaborador->id, 'generado_por' => $usuario->id];
    $primera = ReciboNomina::factory()->create([...$base, 'periodo_inicio' => '2026-11-01', 'periodo_fin' => '2026-11-15', 'fecha_pago' => '2026-11-15', 'estado' => EstadoReciboNomina::Emitido]);
    $segunda = ReciboNomina::factory()->create([...$base, 'periodo_inicio' => '2026-11-16', 'periodo_fin' => '2026-11-30', 'fecha_pago' => '2026-11-30', 'estado' => EstadoReciboNomina::Emitido]);
    ReciboNomina::factory()->create([...$base, 'periodo_inicio' => '2026-11-16', 'periodo_fin' => '2026-11-30', 'fecha_pago' => '2026-11-30', 'estado' => EstadoReciboNomina::Borrador]);
    ReciboNomina::factory()->create([...$base, 'periodo_inicio' => '2026-11-01', 'periodo_fin' => '2026-11-15', 'fecha_pago' => '2026-11-15', 'estado' => EstadoReciboNomina::Cancelado]);
    ReciboNomina::factory()->create([...$base, 'periodo_inicio' => '2026-10-16', 'periodo_fin' => '2026-10-31', 'fecha_pago' => '2026-10-31', 'estado' => EstadoReciboNomina::Emitido]);

    $renglones = rdRenglones(rdGenerar('2026-11'), ReglaRecepcionDocumental::RecibosNomina);

    expect($renglones->pluck('origen_id')->sort()->values()->all())->toBe([$primera->id, $segunda->id])
        ->and($renglones->pluck('tipo_documento')->unique()->all())->toBe(['recibo_nomina']);
});

test('materializar dos veces no duplica renglones (idempotente)', function () {
    rdAlta($this, '2026-11-05');
    rdAlta($this, '2026-11-06', null, ['fecha_alta_imss' => '2026-11-06']);

    $corte = rdGenerar('2026-11');
    $primera = rdRenglones($corte)->count();
    $segunda = app(RecepcionDocumentalService::class)->generar(PeriodoRecepcion::desdeClave('2026-11'), null);

    expect($primera)->toBe(7)
        ->and($segunda['conteo']['nuevos'])->toBe(0)
        ->and(RecepcionDocumentalRenglon::query()->count())->toBe(7)
        ->and(RecepcionDocumentalCorte::query()->count())->toBe(1);
});

test('un corte cerrado no se recalcula y su snapshot no cambia si cambian nombre, puesto o sucursal', function () {
    $colaborador = rdAlta($this, '2026-11-05', null, ['name' => 'Adriana', 'apellidos' => 'Cortés Lima']);
    $corte = rdGenerar('2026-11');
    $rh = clUsuario('super_admin');
    app(RecepcionDocumentalService::class)->cerrar($corte, $rh, 'Cierre de prueba con pendientes.');

    $otraSucursal = Sucursal::factory()->create(['nombre' => 'Orizaba']);
    $colaborador->update(['name' => 'Otra', 'apellidos' => 'Persona', 'puesto_id' => $this->administrativo->id, 'sucursal_principal_id' => $otraSucursal->id]);
    rdAlta($this, '2026-11-20');

    expect(fn () => app(RecepcionDocumentalService::class)->generar(PeriodoRecepcion::desdeClave('2026-11'), $rh))->toThrow(ValidationException::class);

    $resultado = app(RecepcionDocumentalService::class)->generar(PeriodoRecepcion::desdeClave('2026-11'), null, true);
    $renglones = rdRenglones($corte);

    expect($resultado['conteo'])->toBeNull()
        ->and($corte->refresh()->estado)->toBe(EstadoCorteRecepcion::Cerrado)
        ->and($renglones)->toHaveCount(3)
        ->and($renglones->pluck('colaborador_nombre')->unique()->all())->toBe(['Adriana Cortés Lima'])
        ->and($renglones->pluck('puesto_nombre')->unique()->all())->toBe(['Gestor'])
        ->and($renglones->pluck('sucursal_nombre')->unique()->all())->toBe(['Toluca Centro']);
});

test('INFONAVIT, pensión y listas de asistencia están registradas pero no generan nada', function () {
    rdAlta($this, '2026-11-05');
    $corte = rdGenerar('2026-11');

    expect(RecepcionDocumentalRenglon::query()->where('corte_id', $corte->id)->whereIn('regla', ['infonavit', 'pension', 'listas_asistencia'])->count())->toBe(0);
});

test('el estado del corte se calcula: abierto en el mes, con pendientes al terminar y completo al recibir todo', function () {
    rdAlta($this, '2026-11-05', $this->administrativo);
    $corte = rdGenerar('2026-11');
    expect($corte->estado)->toBe(EstadoCorteRecepcion::Abierto);

    Carbon::setTestNow(Carbon::parse('2026-12-02 18:00:00', 'UTC'));
    $corte = rdGenerar('2026-11');
    expect($corte->estado)->toBe(EstadoCorteRecepcion::ConPendientes);

    $rh = clUsuario('super_admin');
    $servicio = app(RecepcionDocumentalService::class);

    foreach (rdRenglones($corte) as $renglon) {
        $servicio->marcar($renglon, $rh, EstadoRecepcionDocumento::Recibido);
    }

    expect($corte->refresh()->estado)->toBe(EstadoCorteRecepcion::Completo);
});
