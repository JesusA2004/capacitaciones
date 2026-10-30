<?php

use App\Enums\EstadoReciboNomina;
use App\Enums\EstadoRectificacionNomina;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\EstatusIncapacidad;
use App\Enums\PeriodicidadNomina;
use App\Enums\ReglaDiasPagados;
use App\Enums\TipoDocumentoIncapacidad;
use App\Enums\TipoSolicitudInterna;
use App\Models\Baja;
use App\Models\Colaborador;
use App\Models\Incapacidad;
use App\Models\IncapacidadDocumento;
use App\Models\NominaRectificacion;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Services\Incapacidades\IncapacidadConsultaService;
use App\Services\Incapacidades\IncapacidadService;
use App\Services\Nomina\IncidenciasNominaService;
use App\Services\Nomina\LoteNominaService;
use App\Services\Nomina\NominaQuincenalService;
use App\Services\Nomina\ReciboNominaService;
use App\Services\Reportes\ReportesRhService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Etapa 1 · Incapacidades e incidencias de nómina (PDF págs. 6-9): lista 5.7 y
| escenarios 6-16 de la matriz de aceptación.
*/

beforeEach(function () {
    clRelojMediodiaMexico();
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->estructura = clEstructura();
    $sucursal = $this->estructura['sucursal'];
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $sucursal->id]);
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2020-01-01',
        'sueldo_mensual' => 30000,
        'salario_diario' => 1000,
    ]);

    $this->registrar = function (array $datos = [], bool $st7 = false): Incapacidad {
        return app(IncapacidadService::class)->registrar($this->persona, [
            'tipo' => 'enfermedad_general',
            'secuencia' => 'inicial',
            'serie_folio_imss' => 'EG '.fake()->unique()->numerify('#######'),
            'fecha_inicio' => '2026-10-21',
            'dias' => 14,
            ...$datos,
        ], UploadedFile::fake()->create('certificado.pdf', 20, 'application/pdf'), $st7 ? UploadedFile::fake()->create('st7.pdf', 20, 'application/pdf') : null, $this->rh);
    };

    $this->recibo = function (string $inicio, string $fin, string $tipo = 'quincenal', float $sueldo = 15000, array $extra = []): ReciboNomina {
        return app(ReciboNominaService::class)->generar($this->persona, [
            'periodo_inicio' => $inicio,
            'periodo_fin' => $fin,
            'tipo_periodo' => $tipo,
            'dias_pagados' => $tipo === 'quincenal' ? 15 : 7,
            'dias_falta' => 0,
            'dias_incapacidad' => 0,
            'conceptos' => [['tipo' => 'percepcion', 'clave' => '001', 'concepto' => 'Sueldo', 'cantidad' => $tipo === 'quincenal' ? 15 : 7, 'importe' => $sueldo], ...$extra],
        ], $this->rh, 'prueba', borrador: true);
    };
});

// --- Fechas, folio y cadena ---

it('calcula el término en el backend: 21/10/2026 + 14 días = 03/11/2026 (11)', function () {
    $incapacidad = ($this->registrar)();

    expect($incapacidad->fecha_fin->toDateString())->toBe('2026-11-03')
        ->and($incapacidad->documentos()->where('tipo', 'certificado')->count())->toBe(1);
});

it('registra por la web con certificado y rechaza sin él (8)', function () {
    $datos = ['colaborador_id' => $this->persona->id, 'tipo' => 'enfermedad_general', 'secuencia' => 'inicial', 'serie_folio_imss' => 'EG 3318104', 'fecha_inicio' => '2026-10-06', 'dias' => 3];

    $this->actingAs($this->rh)->post('/rh/incapacidades', $datos)->assertSessionHasErrors('certificado');
    expect(Incapacidad::query()->count())->toBe(0);

    $this->actingAs($this->rh)->post('/rh/incapacidades', [...$datos, 'certificado' => UploadedFile::fake()->create('c.pdf', 20, 'application/pdf'), 'fecha_fin' => '2030-01-01'])->assertRedirect();

    $incapacidad = Incapacidad::query()->firstOrFail();
    $documento = $incapacidad->documentos()->firstOrFail();

    // Un término enviado a mano se ignora.
    expect($incapacidad->fecha_fin->toDateString())->toBe('2026-10-08')
        ->and($incapacidad->registrado_por)->toBe($this->rh->id)
        ->and($documento->cargado_por)->toBe($this->rh->id)
        ->and($documento->checksum)->not->toBeNull();
    Storage::disk('nas')->assertExists($documento->ruta);
});

it('rechaza un folio duplicado aunque cambie el formato (10)', function () {
    ($this->registrar)(['serie_folio_imss' => 'RT 4471088', 'fecha_inicio' => '2026-01-01', 'dias' => 2]);

    expect(fn () => ($this->registrar)(['serie_folio_imss' => 'rt-4471088', 'fecha_inicio' => '2026-02-01', 'dias' => 2]))
        ->toThrow(ValidationException::class, 'serie y folio');
});

it('liga la subsecuente a su inicial y hereda el subtipo (9)', function () {
    $inicial = ($this->registrar)(['tipo' => 'riesgo_trabajo', 'subtipo_riesgo' => 'accidente_trayecto', 'fecha_inicio' => '2026-10-07']);
    $sub = ($this->registrar)(['tipo' => 'riesgo_trabajo', 'secuencia' => 'subsecuente', 'incapacidad_inicial_id' => $inicial->id, 'fecha_inicio' => '2026-10-21']);

    expect($sub->incapacidad_inicial_id)->toBe($inicial->id)
        ->and($sub->subtipo_riesgo?->value)->toBe('accidente_trayecto')
        ->and($sub->riesgo_folio)->toBeNull()
        ->and($inicial->riesgo_folio)->toStartWith('RT-2026-')
        ->and($inicial->subtipo_riesgo?->cuentaParaSiniestralidad())->toBeFalse();
});

it('la subsecuente de riesgo se registra por la web sin volver a pedir el subtipo', function () {
    $inicial = ($this->registrar)(['tipo' => 'riesgo_trabajo', 'subtipo_riesgo' => 'accidente_trabajo', 'fecha_inicio' => '2026-10-07']);
    $base = ['colaborador_id' => $this->persona->id, 'tipo' => 'riesgo_trabajo', 'serie_folio_imss' => 'RT 900001', 'dias' => 2, 'certificado' => UploadedFile::fake()->create('c.pdf', 5, 'application/pdf')];

    $this->actingAs($this->rh)->post('/rh/incapacidades', [...$base, 'secuencia' => 'inicial', 'fecha_inicio' => '2026-12-01'])->assertSessionHasErrors('subtipo_riesgo');
    $this->actingAs($this->rh)->post('/rh/incapacidades', [...$base, 'secuencia' => 'subsecuente', 'incapacidad_inicial_id' => $inicial->id, 'fecha_inicio' => '2026-10-21'])->assertSessionHasNoErrors();

    expect(Incapacidad::query()->where('incapacidad_inicial_id', $inicial->id)->firstOrFail()->subtipo_riesgo?->value)->toBe('accidente_trabajo');
});

it('no permite cadenas inconsistentes', function () {
    $otra = Colaborador::factory()->create(['sucursal_principal_id' => $this->persona->sucursal_principal_id, 'fecha_ingreso' => '2020-01-01']);
    $inicial = ($this->registrar)(['fecha_inicio' => '2026-10-07', 'dias' => 3]);
    $sub = ($this->registrar)(['secuencia' => 'subsecuente', 'incapacidad_inicial_id' => $inicial->id, 'fecha_inicio' => '2026-10-10', 'dias' => 2]);

    // Subsecuente de subsecuente.
    expect(fn () => ($this->registrar)(['secuencia' => 'subsecuente', 'incapacidad_inicial_id' => $sub->id, 'fecha_inicio' => '2026-10-20', 'dias' => 1]))->toThrow(ValidationException::class, 'no a otra subsecuente');
    // De otro tipo.
    expect(fn () => ($this->registrar)(['tipo' => 'maternidad', 'secuencia' => 'subsecuente', 'incapacidad_inicial_id' => $inicial->id, 'fecha_inicio' => '2026-10-20', 'dias' => 1]))->toThrow(ValidationException::class, 'otro tipo');
    // De otra persona.
    expect(fn () => app(IncapacidadService::class)->registrar($otra, ['tipo' => 'enfermedad_general', 'secuencia' => 'subsecuente', 'incapacidad_inicial_id' => $inicial->id, 'serie_folio_imss' => 'EG 999999', 'fecha_inicio' => '2026-10-20', 'dias' => 1], UploadedFile::fake()->create('c.pdf', 5, 'application/pdf'), null, $this->rh))->toThrow(ValidationException::class, 'otra persona');
    // Sin continuar la cadena.
    expect(fn () => ($this->registrar)(['secuencia' => 'subsecuente', 'incapacidad_inicial_id' => $inicial->id, 'fecha_inicio' => '2026-10-11', 'dias' => 1]))->toThrow(ValidationException::class);
});

it('rechaza superposiciones, días inválidos y fechas previas al ingreso', function () {
    ($this->registrar)(['fecha_inicio' => '2026-10-10', 'dias' => 5]);

    expect(fn () => ($this->registrar)(['fecha_inicio' => '2026-10-14', 'dias' => 2]))->toThrow(ValidationException::class, 'empalma')
        ->and(fn () => ($this->registrar)(['fecha_inicio' => '2019-01-01', 'dias' => 2]))->toThrow(ValidationException::class, 'ingreso');

    $this->actingAs($this->rh)->post('/rh/incapacidades', ['colaborador_id' => $this->persona->id, 'tipo' => 'enfermedad_general', 'secuencia' => 'inicial', 'serie_folio_imss' => 'EG 123456', 'fecha_inicio' => '2026-11-01', 'dias' => 0, 'certificado' => UploadedFile::fake()->create('c.pdf', 5, 'application/pdf')])->assertSessionHasErrors('dias');
});

// --- Documentos y estatus ---

it('riesgo: abierto hasta ST-2, en orden ST-7 → ST-2 → ST-3 (12)', function () {
    $servicio = app(IncapacidadService::class);
    $consulta = app(IncapacidadConsultaService::class);
    $inicial = ($this->registrar)(['tipo' => 'riesgo_trabajo', 'subtipo_riesgo' => 'accidente_trabajo', 'fecha_inicio' => '2026-01-05', 'dias' => 10]);
    $pdf = fn () => UploadedFile::fake()->create('d.pdf', 10, 'application/pdf');

    // Pasó su fecha de término y sigue abierto: solo el ST-2 lo concluye.
    expect($consulta->estatus($inicial))->toBe(EstatusIncapacidad::Activa)
        ->and($consulta->documentosPendientes($inicial))->toBe(['ST-7', 'ST-2']);

    expect(fn () => $servicio->subirDocumento($inicial, TipoDocumentoIncapacidad::St2, $pdf(), '2026-01-20', null, $this->rh))->toThrow(ValidationException::class, 'ST-7');

    $servicio->subirDocumento($inicial, TipoDocumentoIncapacidad::St7, $pdf(), '2026-01-06', null, $this->rh);
    expect($consulta->documentosPendientes($inicial->refresh()))->toBe(['ST-2']);

    expect(fn () => $servicio->subirDocumento($inicial, TipoDocumentoIncapacidad::St2, $pdf(), null, null, $this->rh))->toThrow(ValidationException::class, 'fecha');

    $servicio->subirDocumento($inicial, TipoDocumentoIncapacidad::St2, $pdf(), '2026-01-20', null, $this->rh);
    $inicial->refresh();
    expect($consulta->estatus($inicial))->toBe(EstatusIncapacidad::Concluida)
        ->and($inicial->riesgo_concluido_en?->toDateString())->toBe('2026-01-20');

    // ST-3 opcional.
    $servicio->subirDocumento($inicial, TipoDocumentoIncapacidad::St3, $pdf(), '2026-01-25', null, $this->rh);
    expect($inicial->documentos()->where('tipo', 'st3')->count())->toBe(1);

    // Concluido: no admite subsecuentes.
    expect(fn () => ($this->registrar)(['tipo' => 'riesgo_trabajo', 'secuencia' => 'subsecuente', 'incapacidad_inicial_id' => $inicial->id, 'fecha_inicio' => '2026-02-01', 'dias' => 1]))->toThrow(ValidationException::class, 'ST-2');
});

it('maternidad y enfermedad general no aceptan documentos de riesgo', function () {
    $maternidad = ($this->registrar)(['tipo' => 'maternidad']);

    expect(fn () => app(IncapacidadService::class)->subirDocumento($maternidad, TipoDocumentoIncapacidad::St7, UploadedFile::fake()->create('d.pdf', 5, 'application/pdf'), null, null, $this->rh))
        ->toThrow(ValidationException::class, 'riesgo de trabajo')
        ->and(app(IncapacidadConsultaService::class)->documentosPendientes($maternidad))->toBe([])
        ->and(fn () => ($this->registrar)(['fecha_inicio' => '2026-01-01', 'dias' => 1], st7: true))->toThrow(ValidationException::class, 'ST-7');
});

it('enfermedad general concluye al llegar su fecha de término', function () {
    $incapacidad = ($this->registrar)(['fecha_inicio' => '2026-10-01', 'dias' => 5]);
    $consulta = app(IncapacidadConsultaService::class);

    expect($consulta->estatus($incapacidad, CarbonImmutable::parse('2026-10-04')))->toBe(EstatusIncapacidad::Activa)
        ->and($consulta->estatus($incapacidad, CarbonImmutable::parse('2026-10-05')))->toBe(EstatusIncapacidad::Concluida);
});

it('reemplazar un documento exige motivo y conserva el original en el NAS', function () {
    $incapacidad = ($this->registrar)();
    $servicio = app(IncapacidadService::class);
    $original = $incapacidad->documentos()->firstOrFail();
    $pdf = UploadedFile::fake()->create('nuevo.pdf', 10, 'application/pdf');

    expect(fn () => $servicio->subirDocumento($incapacidad, TipoDocumentoIncapacidad::Certificado, $pdf, null, null, $this->rh))->toThrow(ValidationException::class, 'motivo');

    $nuevo = $servicio->subirDocumento($incapacidad, TipoDocumentoIncapacidad::Certificado, $pdf, null, 'Certificado ilegible', $this->rh);

    expect($nuevo->reemplaza_id)->toBe($original->id)
        ->and($original->refresh()->reemplazado_en)->not->toBeNull()
        ->and(IncapacidadDocumento::query()->count())->toBe(2);
    Storage::disk('nas')->assertExists($original->ruta);
    Storage::disk('nas')->assertExists($nuevo->ruta);
});

it('cancelar conserva el registro, libera el folio y sale de nómina', function () {
    $incapacidad = ($this->registrar)(['serie_folio_imss' => 'EG 777777', 'fecha_inicio' => '2026-10-16', 'dias' => 3]);
    $servicio = app(IncapacidadService::class);
    expect(fn () => $this->actingAs($this->rh)->post("/rh/incapacidades/{$incapacidad->id}/cancelar", [])->assertSessionHasErrors('motivo'))->not->toThrow(Exception::class);

    $servicio->cancelar($incapacidad, 'Se capturó a otra persona', $this->rh);

    expect(Incapacidad::query()->whereKey($incapacidad->id)->exists())->toBeTrue()
        ->and(app(IncapacidadConsultaService::class)->estatus($incapacidad->refresh()))->toBe(EstatusIncapacidad::Cancelada)
        ->and(app(IncidenciasNominaService::class)->resumen($this->persona, CarbonImmutable::parse('2026-10-16'), CarbonImmutable::parse('2026-10-31'), 15)['dias_incapacidad'])->toBe(0);

    ($this->registrar)(['serie_folio_imss' => 'EG 777777', 'fecha_inicio' => '2026-10-16', 'dias' => 3]);
    expect(Incapacidad::query()->count())->toBe(2);
});

it('la corrección exige motivo, recalcula el término y queda en bitácora', function () {
    $incapacidad = ($this->registrar)(['serie_folio_imss' => 'EG 111111']);

    $this->actingAs($this->rh)->post("/rh/incapacidades/{$incapacidad->id}/corregir", ['serie_folio_imss' => 'EG 111111', 'fecha_inicio' => '2026-10-21', 'dias' => 2])->assertSessionHasErrors('motivo');
    $this->actingAs($this->rh)->post("/rh/incapacidades/{$incapacidad->id}/corregir", ['serie_folio_imss' => 'EG 111111', 'fecha_inicio' => '2026-10-21', 'dias' => 2, 'motivo' => 'Capturé mal los días'])->assertSessionHasNoErrors();

    expect($incapacidad->refresh()->fecha_fin->toDateString())->toBe('2026-10-22')
        ->and($incapacidad->actualizado_por)->toBe($this->rh->id);
    $this->assertDatabaseHas('activity_log', ['event' => 'incapacidad_corregida']);
});

// --- Incidencias y nómina ---

it('identifica 11 días naturales del 21 al 31 de octubre y cruza dos quincenas (13, 14)', function () {
    ($this->registrar)();
    $servicio = app(IncidenciasNominaService::class);
    $octubre = $servicio->resumen($this->persona, CarbonImmutable::parse('2026-10-16'), CarbonImmutable::parse('2026-10-31'), 15);
    $noviembre = $servicio->resumen($this->persona, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-15'), 15);

    expect($octubre['dias_naturales'])->toBe(16)
        ->and($octubre['dias_base'])->toBe(15)
        ->and($octubre['dias_incapacidad'])->toBe(11)
        // Regla vigente: 16 naturales − 11 = 5 días pagados, no 15 − 11.
        ->and($octubre['dias_pagados'])->toBe(5.0)
        ->and($octubre['regla_validada'])->toBeFalse()
        ->and($noviembre['dias_incapacidad'])->toBe(3)
        ->and($noviembre['dias_pagados'])->toBe(12.0);
});

it('las tres reglas configurables dan el resultado documentado', function () {
    expect(ReglaDiasPagados::DiasCalendarioConTope->diasPagados(15, 16, 11))->toBe(5.0)
        ->and(ReglaDiasPagados::RestaDirecta->diasPagados(15, 16, 11))->toBe(4.0)
        ->and(ReglaDiasPagados::Proporcional->diasPagados(15, 16, 11))->toBe(4.69)
        // Un día de incidencia en un periodo de 16 con tope en 15: la regla vigente paga los 15.
        ->and(ReglaDiasPagados::DiasCalendarioConTope->diasPagados(15, 16, 1))->toBe(15.0)
        // Nunca negativos.
        ->and(ReglaDiasPagados::RestaDirecta->diasPagados(15, 16, 16))->toBe(0.0)
        ->and(ReglaDiasPagados::DiasCalendarioConTope->diasPagados(15, 16, 20))->toBe(0.0)
        ->and(ReglaDiasPagados::DiasCalendarioConTope->diasPagados(7, 7, 0))->toBe(7.0);

    config(['nomina_incidencias.regla' => 'resta_directa']);
    ($this->registrar)();
    expect(app(IncidenciasNominaService::class)->resumen($this->persona, CarbonImmutable::parse('2026-10-16'), CarbonImmutable::parse('2026-10-31'), 15)['dias_pagados'])->toBe(4.0);
});

it('permiso e incapacidad en los mismos días no descuentan dos veces (15)', function () {
    ($this->registrar)(['fecha_inicio' => '2026-10-20', 'dias' => 3]);
    SolicitudInterna::factory()->create([
        'user_id' => $this->rh->id,
        'colaborador_id' => $this->persona->id,
        'tipo' => TipoSolicitudInterna::Permiso,
        'estado' => EstadoSolicitudInterna::Aprobada,
        'permiso_tipo' => 'faltar',
        'permiso_goce' => 'sin_goce',
        'fecha_inicio' => '2026-10-21',
        'fecha_fin' => '2026-10-24',
    ]);

    $resumen = app(IncidenciasNominaService::class)->resumen($this->persona, CarbonImmutable::parse('2026-10-16'), CarbonImmutable::parse('2026-10-31'), 15);

    expect($resumen['dias_incapacidad'])->toBe(3)
        ->and($resumen['dias_sin_goce'])->toBe(2)
        ->and($resumen['dias_no_pagables'])->toBe(5)
        ->and($resumen['dias_pagados'])->toBe(11.0);
});

it('permiso con goce no descuenta y salir temprano se reporta sin descontar', function () {
    SolicitudInterna::factory()->create(['user_id' => $this->rh->id, 'colaborador_id' => $this->persona->id, 'tipo' => TipoSolicitudInterna::Permiso, 'estado' => EstadoSolicitudInterna::Aprobada, 'permiso_tipo' => 'faltar', 'permiso_goce' => 'con_goce', 'fecha_inicio' => '2026-10-21', 'fecha_fin' => '2026-10-22']);
    SolicitudInterna::factory()->create(['user_id' => $this->rh->id, 'colaborador_id' => $this->persona->id, 'tipo' => TipoSolicitudInterna::Permiso, 'estado' => EstadoSolicitudInterna::Aprobada, 'permiso_tipo' => 'salir_temprano', 'permiso_goce' => 'sin_goce', 'fecha_inicio' => '2026-10-23', 'fecha_fin' => '2026-10-23']);
    // Sin aprobar: no cuenta.
    SolicitudInterna::factory()->create(['user_id' => $this->rh->id, 'colaborador_id' => $this->persona->id, 'tipo' => TipoSolicitudInterna::Permiso, 'estado' => EstadoSolicitudInterna::Enviada, 'permiso_tipo' => 'faltar', 'permiso_goce' => 'sin_goce', 'fecha_inicio' => '2026-10-26', 'fecha_fin' => '2026-10-27']);

    $resumen = app(IncidenciasNominaService::class)->resumen($this->persona, CarbonImmutable::parse('2026-10-16'), CarbonImmutable::parse('2026-10-31'), 15);

    expect($resumen['dias_con_goce'])->toBe(2)
        ->and($resumen['dias_sin_goce'])->toBe(0)
        ->and($resumen['dias_pagados'])->toBe(15.0)
        ->and(collect($resumen['incidencias'])->pluck('tipo')->all())->toContain('permiso_con_goce', 'permiso_parcial');
});

it('las solicitudes históricas de incapacidad no se reaplican a nómina (7)', function () {
    SolicitudInterna::factory()->create(['user_id' => $this->rh->id, 'colaborador_id' => $this->persona->id, 'tipo' => TipoSolicitudInterna::Incapacidad, 'estado' => EstadoSolicitudInterna::Aprobada, 'fecha_inicio' => '2026-10-21', 'fecha_fin' => '2026-10-24']);

    expect(app(IncidenciasNominaService::class)->resumen($this->persona, CarbonImmutable::parse('2026-10-16'), CarbonImmutable::parse('2026-10-31'), 15)['dias_no_pagables'])->toBe(0);
});

it('ingreso a media quincena e incapacidad se combinan sin contar doble', function () {
    $this->persona->update(['fecha_ingreso' => '2026-10-20']);
    ($this->registrar)(['fecha_inicio' => '2026-10-20', 'dias' => 2]);

    $resumen = app(IncidenciasNominaService::class)->resumen($this->persona->refresh(), CarbonImmutable::parse('2026-10-16'), CarbonImmutable::parse('2026-10-31'), 15);

    expect($resumen['dias_fuera_de_vigencia'])->toBe(4)
        ->and($resumen['dias_incapacidad'])->toBe(2)
        ->and($resumen['dias_pagados'])->toBe(10.0);
});

it('baja dentro del periodo: paga hasta el último día (con expediente de baja)', function () {
    Baja::query()->create(['folio' => 'BJ-INC-1', 'colaborador_id' => $this->persona->id, 'nombre_colaborador' => $this->persona->nombreCompleto(), 'sucursal_id' => $this->persona->sucursal_principal_id, 'estatus' => EstatusBaja::EnRevisionRh, 'fecha_baja' => '2026-10-20', 'creado_por' => $this->rh->id]);
    $resumen = app(IncidenciasNominaService::class)->resumen($this->persona, CarbonImmutable::parse('2026-10-16'), CarbonImmutable::parse('2026-10-31'), 15);

    expect($resumen['dias_fuera_de_vigencia'])->toBe(11)
        ->and($resumen['dias_pagados'])->toBe(5.0);
});

it('baja dentro del periodo: paga hasta la fecha de baja del colaborador', function () {
    $this->persona->update(['fecha_baja' => '2026-10-20']);
    $resumen = app(IncidenciasNominaService::class)->resumen($this->persona->refresh(), CarbonImmutable::parse('2026-10-16'), CarbonImmutable::parse('2026-10-31'), 15);

    expect($resumen['dias_pagados'])->toBe(5.0)
        ->and($resumen['avisos'][0])->toContain('Último día laborado 20/10/2026');
});

it('actualiza el borrador sin duplicar el descuento (borrador, 15)', function () {
    $recibo = ($this->recibo)('2026-10-16', '2026-10-31', sueldo: 15000);
    ($this->registrar)();

    $recibo->refresh();
    expect((float) $recibo->dias_pagados)->toBe(5.0)
        ->and((float) $recibo->dias_incapacidad)->toBe(11.0)
        ->and((float) $recibo->neto)->toBe(5000.0)
        ->and($recibo->advertencias[0])->toContain('11 día(s) de incapacidad');

    // Aplicarlo otra vez no cambia nada.
    app(IncidenciasNominaService::class)->sincronizarRecibos($this->persona, $recibo->periodo_inicio, $recibo->periodo_fin, $this->rh, 'otra vez');
    expect((float) $recibo->refresh()->neto)->toBe(5000.0)
        ->and(ReciboNomina::query()->where('colaborador_id', $this->persona->id)->count())->toBe(1);

    // Cancelar la incapacidad regresa el borrador a la quincena completa.
    app(IncapacidadService::class)->cancelar(Incapacidad::query()->firstOrFail(), 'Captura equivocada', $this->rh);
    expect((float) $recibo->refresh()->neto)->toBe(15000.0)
        ->and((float) $recibo->dias_pagados)->toBe(15.0)
        ->and($recibo->advertencias)->toBeNull();
});

it('nunca genera neto negativo: deja el borrador intacto con aviso (16)', function () {
    $recibo = ($this->recibo)('2026-10-16', '2026-10-31', sueldo: 15000, extra: [['tipo' => 'deduccion', 'concepto' => 'Préstamo', 'importe' => 9000]]);
    ($this->registrar)();

    $recibo->refresh();
    expect((float) $recibo->neto)->toBe(6000.0)
        ->and((float) $recibo->dias_pagados)->toBe(15.0)
        ->and($recibo->advertencias[0])->toContain('neto no puede ser negativo');
});

it('tampoco deja crear ni editar un recibo con neto negativo', function () {
    expect(fn () => ($this->recibo)('2026-09-01', '2026-09-15', sueldo: 100, extra: [['tipo' => 'deduccion', 'concepto' => 'Otro', 'importe' => 200]]))
        ->toThrow(ValidationException::class, 'negativo');
});

it('un recibo emitido no cambia en silencio: rectificación auditada', function () {
    Storage::fake('local');
    $recibo = ($this->recibo)('2026-10-16', '2026-10-31', sueldo: 15000);
    $recibo->update(['estado' => EstadoReciboNomina::Emitido, 'emitido_at' => now(), 'emitido_por' => $this->rh->id]);

    ($this->registrar)();

    $rectificacion = NominaRectificacion::query()->firstOrFail();
    expect((float) $recibo->refresh()->neto)->toBe(15000.0)
        ->and((float) $recibo->dias_pagados)->toBe(15.0)
        ->and($rectificacion->estado)->toBe(EstadoRectificacionNomina::Pendiente)
        ->and((float) $rectificacion->dias_pagados_despues)->toBe(5.0)
        ->and((float) $rectificacion->importe_sueldo_despues)->toBe(5000.0);

    // Otra corrida no crea una segunda.
    app(IncidenciasNominaService::class)->sincronizarRecibos($this->persona, $recibo->periodo_inicio, $recibo->periodo_fin, $this->rh, 'otra vez');
    expect(NominaRectificacion::query()->count())->toBe(1);

    $this->actingAs($this->rh)->post("/rh/incapacidades/rectificaciones/{$rectificacion->id}/aplicar")->assertRedirect();
    expect($rectificacion->refresh()->estado)->toBe(EstadoRectificacionNomina::Aplicada)
        ->and($rectificacion->resuelta_por)->toBe($this->rh->id)
        ->and((float) $recibo->refresh()->neto)->toBe(5000.0)
        ->and((float) $recibo->dias_incapacidad)->toBe(11.0);
});

it('descartar una rectificación exige nota y no toca el recibo; cancelar la causa la descarta', function () {
    $recibo = ($this->recibo)('2026-10-16', '2026-10-31', sueldo: 15000);
    $recibo->update(['estado' => EstadoReciboNomina::Emitido, 'emitido_at' => now()]);
    $incapacidad = ($this->registrar)();
    $rectificacion = NominaRectificacion::query()->firstOrFail();

    app(IncapacidadService::class)->cancelar($incapacidad, 'Captura equivocada', $this->rh);
    expect($rectificacion->refresh()->estado)->toBe(EstadoRectificacionNomina::Descartada);

    $otra = ($this->registrar)(['fecha_inicio' => '2026-10-25', 'dias' => 2]);
    $nueva = NominaRectificacion::query()->where('estado', 'pendiente')->firstOrFail();
    $this->actingAs($this->rh)->post("/rh/incapacidades/rectificaciones/{$nueva->id}/descartar", [])->assertSessionHasErrors('nota');
    $this->actingAs($this->rh)->post("/rh/incapacidades/rectificaciones/{$nueva->id}/descartar", ['nota' => 'Se paga por acuerdo'])->assertRedirect();

    expect($nueva->refresh()->estado)->toBe(EstadoRectificacionNomina::Descartada)
        ->and((float) $recibo->refresh()->neto)->toBe(15000.0)
        ->and($otra->exists)->toBeTrue();
});

it('el lote semanal y la quincena automática aplican las incidencias al prepararse', function () {
    ($this->registrar)(['fecha_inicio' => '2026-10-19', 'dias' => 2]);

    // Quincena automática: 16 naturales − 2 = 14 de 15.
    app(NominaQuincenalService::class)->preparar(app(NominaQuincenalService::class)->periodoPorClave('2026-10-2'), $this->rh);
    $quincenal = ReciboNomina::query()->where('tipo_periodo', 'quincenal')->where('colaborador_id', $this->persona->id)->firstOrFail();
    expect((float) $quincenal->dias_pagados)->toBe(14.0)
        ->and((float) $quincenal->dias_incapacidad)->toBe(2.0)
        ->and((float) $quincenal->neto)->toBe(14000.0);
    $quincenal->delete();

    // Lote semanal: lunes 19 al domingo 25, 5 de 7.
    $lote = app(LoteNominaService::class)->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-21'), $this->rh);
    $semanal = $lote->recibos()->where('colaborador_id', $this->persona->id)->firstOrFail();
    expect((float) $semanal->dias_pagados)->toBe(5.0)
        ->and((float) $semanal->dias_incapacidad)->toBe(2.0)
        ->and((float) $semanal->neto)->toBe(5000.0);
});

it('el reporte Incidencias de nómina usa la misma fuente que el recibo', function () {
    ($this->registrar)(['serie_folio_imss' => 'RT 123456']);
    $reporte = app(ReportesRhService::class)->generar('incidencias_nomina', $this->rh, ['fecha_inicio' => '2026-10-16', 'fecha_fin' => '2026-10-31']);
    $fila = collect($reporte['filas'])->firstWhere(7, 'RT 123456');

    expect($fila[5])->toBe(11)
        ->and($fila[8])->toContain('11 día(s)');
});

// --- Pantallas, PDF y permisos ---

it('el listado muestra indicadores, impacto y regla pendiente de validar', function () {
    ($this->registrar)();

    $this->actingAs($this->rh)->get('/rh/incapacidades?mes=2026-10&periodo=2026-10-2')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Rh/Incapacidades/Index')
        ->where('incapacidades.data.0.fecha_fin', '2026-11-03')
        ->where('indicadores.dias_mes', 11)
        ->where('impacto.filas.0.dias', 11)
        ->where('impacto.regla.validada_por_rh', false)
    );
});

it('genera el PDF del expediente del riesgo y muestra el historial', function () {
    $inicial = ($this->registrar)(['tipo' => 'riesgo_trabajo', 'subtipo_riesgo' => 'accidente_trabajo', 'fecha_inicio' => '2026-10-07'], st7: true);
    ($this->registrar)(['tipo' => 'riesgo_trabajo', 'secuencia' => 'subsecuente', 'incapacidad_inicial_id' => $inicial->id, 'fecha_inicio' => '2026-10-21']);

    $this->actingAs($this->rh)->get("/rh/incapacidades/{$inicial->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Rh/Incapacidades/Expediente')
        ->where('cadenas.0.dias_totales', 28)
        ->where('cadenas.0.pendientes', ['ST-2'])
    );

    $respuesta = $this->actingAs($this->rh)->get("/rh/incapacidades/{$inicial->id}/pdf")->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('pdf');

    $this->actingAs($this->rh)->get("/rh/incapacidades/colaboradores/{$this->persona->id}/historial")->assertOk()->assertInertia(fn (Assert $p) => $p->has('cadenas', 1));
});

it('exporta Excel y PDF con permiso', function () {
    ($this->registrar)();

    $this->actingAs($this->rh)->get('/rh/incapacidades/exportar/excel?mes=2026-10')->assertOk();
    $this->actingAs($this->rh)->get('/rh/incapacidades/exportar/pdf?mes=2026-10')->assertOk();
});

it('sin permisos no se consulta ni descarga nada (acceso sin permisos)', function () {
    $incapacidad = ($this->registrar)();
    $documento = $incapacidad->documentos()->firstOrFail();
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->persona->sucursal_principal_id]);

    $this->actingAs($gerente)->get('/rh/incapacidades')->assertForbidden();
    $this->actingAs($gerente)->get('/rh/incapacidades/registrar')->assertForbidden();
    $this->actingAs($gerente)->get("/rh/incapacidades/{$incapacidad->id}")->assertForbidden();
    $this->actingAs($gerente)->get("/rh/incapacidades/{$incapacidad->id}/pdf")->assertForbidden();
    $this->actingAs($gerente)->get("/rh/incapacidades/documentos/{$documento->id}")->assertForbidden();
    $this->actingAs($gerente)->get('/rh/incapacidades/exportar/excel')->assertForbidden();
    $this->actingAs($gerente)->post('/rh/incapacidades', [])->assertForbidden();
    $this->actingAs($gerente)->post("/rh/incapacidades/{$incapacidad->id}/cancelar", ['motivo' => 'Sin permiso'])->assertForbidden();
});

it('RH descarga el documento con permiso y nadie consulta el suyo', function () {
    $incapacidad = ($this->registrar)();
    $documento = $incapacidad->documentos()->firstOrFail();

    $this->actingAs($this->rh)->get("/rh/incapacidades/documentos/{$documento->id}")->assertOk();

    $propia = app(IncapacidadService::class)->registrar($this->rh->colaborador, ['tipo' => 'enfermedad_general', 'secuencia' => 'inicial', 'serie_folio_imss' => 'EG 5555555', 'fecha_inicio' => now()->toDateString(), 'dias' => 1], UploadedFile::fake()->create('c.pdf', 5, 'application/pdf'), null, $this->rh);
    $this->actingAs($this->rh)->get("/rh/incapacidades/{$propia->id}")->assertForbidden();
});

// --- Solicitudes ---

it('Incapacidad no se puede crear por Solicitudes y el histórico sigue consultable (6, 7)', function () {
    expect(TipoSolicitudInterna::valoresCreables())->not->toContain('incapacidad');

    $this->actingAs($this->rh->refresh())->get('/rh/solicitudes?tipo=permiso')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('tiposFiltro', fn ($tipos) => ! collect($tipos)->pluck('value')->contains('incapacidad'))
    );

    SolicitudInterna::factory()->create(['user_id' => $this->rh->id, 'colaborador_id' => $this->persona->id, 'tipo' => TipoSolicitudInterna::Incapacidad, 'estado' => EstadoSolicitudInterna::Aprobada]);

    $this->actingAs($this->rh)->get('/rh/solicitudes?tipo=incapacidad')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('tiposFiltro', fn ($tipos) => collect($tipos)->firstWhere('value', 'incapacidad')['label'] === 'Incapacidad (histórico)')
    );

    $this->assertDatabaseHas('solicitudes_internas', ['tipo' => 'incapacidad']);
});
