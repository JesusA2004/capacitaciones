<?php

use App\Enums\EstadoFiniquito;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoReciboNomina;
use App\Enums\EstadoRectificacionNomina;
use App\Enums\EstadoUsuario;
use App\Enums\ModoCorreccion;
use App\Enums\TipoCambioSalario;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\Correccion;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Models\HistorialSalario;
use App\Models\Incapacidad;
use App\Models\NominaRectificacion;
use App\Models\ReciboNomina;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Compensacion\CompensacionService;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\Nomina\IncidenciasNominaService;
use App\Services\Nomina\NominaQuincenalService;
use App\Services\Nomina\ReciboNominaService;
use App\Services\Reclutamiento\AgendaEventoService;
use App\Services\Tareas\NotificadorRhService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Sistema universal de correcciones (docs/CORRECCIONES.md): motivo en el servicio,
| huella, alcance sin revelar datos, firmados/emitidos/pagados intactos, historial.
*/

beforeEach(function () {
    clRelojMediodiaMexico();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'departamento_id' => $this->estructura['departamento']->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2020-01-01',
        'sueldo_mensual' => 30000,
        'salario_diario' => 1000,
        'curp' => null,
    ]);
    $this->usuarioPersona = clUsuarioDe($this->persona);

    /** @param array<string, mixed> $valores */
    $this->corregir = function (string $dominio, int $id, array $valores, ?string $motivo = 'Se capturó mal el dato original.', array $extra = [], ?User $como = null, string $base = '/correcciones') {
        $usuario = $como ?? $this->rh;
        $descripcion = $this->actingAs($usuario)->getJson("{$base}/{$dominio}/{$id}")->json('data');

        return $this->actingAs($usuario)->postJson("{$base}/{$dominio}/{$id}", [
            'valores' => $valores,
            'motivo' => $motivo,
            'huella' => $descripcion['huella'],
            'clave_idempotencia' => (string) Str::uuid(),
            ...$extra,
        ]);
    };

    $this->recibo = fn (string $inicio, string $fin, float $sueldo = 15000, bool $borrador = false): ReciboNomina => app(ReciboNominaService::class)->generar($this->persona, [
        'periodo_inicio' => $inicio,
        'periodo_fin' => $fin,
        'fecha_pago' => $fin,
        'tipo_periodo' => 'quincenal',
        'dias_pagados' => 15,
        'sueldo_base' => $sueldo,
        'conceptos' => [['tipo' => 'percepcion', 'clave' => '001', 'concepto' => 'Sueldo', 'cantidad' => 15, 'importe' => $sueldo]],
    ], $this->rh, null, $borrador);

    $this->contratoFirmado = function (float $sueldo = 30000): ContratoLaboral {
        $documento = GeneratedDocument::factory()->create([
            'colaborador_id' => $this->persona->id,
            'estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente->value,
            'requiere_firma_fisica' => true,
        ]);

        return ContratoLaboral::factory()->create([
            'colaborador_id' => $this->persona->id,
            'estado' => 'vigente',
            'sueldo_mensual' => $sueldo,
            'generated_document_id' => $documento->id,
        ]);
    };
});

it('exige el motivo en el SERVICIO, no solo en el formulario', function (?string $motivo) {
    $respuesta = ($this->corregir)('personal', $this->persona->id, ['telefono' => '5512345678'], $motivo);

    $respuesta->assertUnprocessable()->assertJsonValidationErrors('motivo');
    expect(Correccion::query()->count())->toBe(0)
        ->and($this->persona->refresh()->telefono)->not->toBe('5512345678');
})->with([null, '', '   ', 'abc']);

it('rechaza con 409 si otra persona cambió el dato y no escribe nada', function () {
    $descripcion = $this->actingAs($this->rh)->getJson("/correcciones/personal/{$this->persona->id}")->json('data');
    $this->persona->update(['telefono' => '5511112222']);

    $respuesta = $this->actingAs($this->rh)->postJson("/correcciones/personal/{$this->persona->id}", [
        'valores' => ['telefono' => '5533334444'],
        'motivo' => 'Teléfono mal capturado.',
        'huella' => $descripcion['huella'],
        'clave_idempotencia' => (string) Str::uuid(),
    ]);

    $respuesta->assertStatus(409)->assertJsonPath('actual.dominio', 'personal');
    expect($this->persona->refresh()->telefono)->toBe('5511112222')
        ->and(Correccion::query()->count())->toBe(0)
        ->and($respuesta->json('actual.huella'))->not->toBe($descripcion['huella']);
});

it('la misma clave de idempotencia no aplica dos veces', function () {
    $descripcion = $this->actingAs($this->rh)->getJson("/correcciones/personal/{$this->persona->id}")->json('data');
    $cuerpo = ['valores' => ['telefono' => '5533334444'], 'motivo' => 'Teléfono mal capturado.', 'huella' => $descripcion['huella'], 'clave_idempotencia' => $descripcion['clave_idempotencia']];

    $primera = $this->actingAs($this->rh)->postJson("/correcciones/personal/{$this->persona->id}", $cuerpo)->assertCreated();
    $segunda = $this->actingAs($this->rh)->postJson("/correcciones/personal/{$this->persona->id}", $cuerpo)->assertCreated();

    expect($segunda->json('data.id'))->toBe($primera->json('data.id'))
        ->and(Correccion::query()->count())->toBe(1);
});

it('fuera de alcance responde 403 sin revelar datos, tampoco en vista previa ni historial', function () {
    $otraSucursal = Sucursal::factory()->create();
    $ajeno = Colaborador::factory()->create(['sucursal_principal_id' => $otraSucursal->id, 'sueldo_mensual' => 87654.32, 'salario_diario' => 2921.81, 'curp' => 'PEPJ900101HMSRRN09']);
    $gerente = clUsuario('gerente', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $gerente->givePermissionTo('expedientes.editar');

    foreach ([
        fn () => $this->actingAs($gerente)->getJson("/correcciones/personal/{$ajeno->id}"),
        fn () => $this->actingAs($gerente)->postJson("/correcciones/personal/{$ajeno->id}/vista-previa", ['valores' => ['telefono' => '1']]),
        fn () => $this->actingAs($gerente)->getJson("/correcciones/personal/{$ajeno->id}/historial"),
        fn () => $this->actingAs($gerente)->getJson("/correcciones/colaboradores/{$ajeno->id}/historial"),
        fn () => $this->actingAs($gerente)->getJson("/correcciones/salario/{$ajeno->id}"),
    ] as $peticion) {
        $respuesta = $peticion()->assertForbidden();
        expect($respuesta->getContent())->not->toContain('87654')->not->toContain('PEPJ900101')->not->toContain('huella');
    }
});

it('nadie corrige sus propios datos, aunque sea RH', function () {
    $this->actingAs($this->rh)->getJson("/correcciones/personal/{$this->rh->colaborador_id}")->assertForbidden();
});

it('ver el sueldo no autoriza escribirlo (D11), tampoco por el expediente', function () {
    Permission::findOrCreate('salarios.ver_en_expediente');
    $lector = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $lector->givePermissionTo(['salarios.ver_en_expediente', 'expedientes.editar', 'expedientes.ver_todos']);

    $this->actingAs($lector)->getJson("/correcciones/salario/{$this->persona->id}")->assertForbidden();

    $this->actingAs($lector)->put("/rh/expedientes/{$this->persona->id}/datos-laborales", ['sueldo_mensual' => '99999', 'motivo' => 'Prueba de escritura.']);
    expect((float) $this->persona->refresh()->sueldo_mensual)->toBe(30000.0);
});

it('sin permiso de sueldo el expediente no revela si el valor probado coincide (D12)', function () {
    $lector = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $lector->givePermissionTo(['expedientes.editar', 'expedientes.ver_todos']);

    $this->actingAs($lector)->put("/rh/expedientes/{$this->persona->id}/datos-laborales", ['sueldo_mensual' => '12345'])
        ->assertSessionDoesntHaveErrors('motivo');
});

it('un campo vacío no borra puesto, sucursal ni departamento (D8)', function () {
    $this->actingAs($this->rh)->put("/rh/expedientes/{$this->persona->id}/datos-laborales", [
        'puesto_id' => null, 'sucursal_principal_id' => null, 'departamento_id' => null,
    ]);

    $this->persona->refresh();
    expect($this->persona->puesto_id)->toBe($this->estructura['puesto']->id)
        ->and($this->persona->sucursal_principal_id)->toBe($this->estructura['sucursal']->id)
        ->and($this->persona->departamento_id)->toBe($this->estructura['departamento']->id);

    ($this->corregir)('laboral', $this->persona->id, ['puesto_id' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('puesto_id');
});

it('la fecha de ingreso con recibos emitidos no se corrige: queda sin cambio y con explicación', function () {
    ($this->recibo)('2026-10-01', '2026-10-15');

    $descripcion = $this->actingAs($this->rh)->getJson("/correcciones/laboral/{$this->persona->id}")->json('data');
    $campo = collect($descripcion['campos'])->firstWhere('campo', 'fecha_ingreso');
    expect($campo['modo'])->toBe(ModoCorreccion::NoCorregible->value)
        ->and($campo['editable'])->toBeFalse()
        ->and($campo['explicacion'])->toContain('recibos emitidos');

    ($this->corregir)('laboral', $this->persona->id, ['fecha_ingreso' => '2021-01-01'])->assertUnprocessable();
    expect($this->persona->refresh()->fecha_ingreso->toDateString())->toBe('2020-01-01');
});

it('Escenario 2: capturar sueldo, historial, corregir con motivo, afectados e históricos intactos', function () {
    // 1. Capturar: primera captura con su vigencia.
    $nuevo = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id, 'fecha_ingreso' => '2026-09-01', 'sueldo_mensual' => null, 'salario_diario' => null]);
    $this->persona = $nuevo;
    ($this->corregir)('salario', $nuevo->id, ['sueldo_mensual' => '20000'], 'Sueldo acordado en la contratación.', ['tipo' => TipoCambioSalario::PrimeraCaptura->value])->assertCreated();

    // 2. Historial: renglón tipificado con mensual exacto.
    $captura = HistorialSalario::query()->where('colaborador_id', $nuevo->id)->sole();
    expect($captura->tipo)->toBe(TipoCambioSalario::PrimeraCaptura)
        ->and((float) $captura->sueldo_mensual_nuevo)->toBe(20000.0)
        ->and($captura->fecha_efectiva->toDateString())->toBe('2026-09-01');

    // Recibos con el sueldo erróneo: uno cerrado y uno preparado.
    $emitido = ($this->recibo)('2026-09-16', '2026-09-30', 10000);
    $preparado = ($this->recibo)('2026-10-01', '2026-10-15', 10000, true);
    $pdfOriginal = [$emitido->pdf_path, $emitido->checksum, $emitido->folio];
    $firmado = ContratoLaboral::factory()->create(['colaborador_id' => $nuevo->id, 'estado' => 'vigente', 'sueldo_mensual' => 20000, 'generated_document_id' => GeneratedDocument::factory()->create(['colaborador_id' => $nuevo->id, 'estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente->value, 'requiere_firma_fisica' => true])->id]);
    $finiquito = FiniquitoCalculo::factory()->create(['colaborador_id' => $nuevo->id, 'estado' => EstadoFiniquito::Pagado->value]);
    $finiquitoAntes = $finiquito->fresh()->getAttributes();

    // 3. Vista previa: dice qué toca, sin escribir.
    $vista = $this->actingAs($this->rh)->postJson("/correcciones/salario/{$nuevo->id}/vista-previa", [
        'valores' => ['sueldo_mensual' => '24000'],
        'tipo' => TipoCambioSalario::Correccion->value,
    ])->assertOk()->json('data');
    $acciones = collect($vista['afectados'])->groupBy('accion');
    expect($acciones['requiere_modificacion_contractual'])->toHaveCount(1)
        ->and($acciones['requiere_rectificacion'][0]['etiqueta'])->toContain($emitido->folio)
        ->and($acciones['se_actualiza'][0]['etiqueta'])->toContain($preparado->folio)
        ->and($acciones['intacto'][0]['tipo'])->toBe('finiquito')
        ->and(collect($vista['cambios'])->firstWhere('campo', 'salario_diario')['despues'])->toBe('800.00')
        ->and((float) $nuevo->refresh()->sueldo_mensual)->toBe(20000.0);

    // 4. Corregir con motivo.
    $respuesta = ($this->corregir)('salario', $nuevo->id, ['sueldo_mensual' => '24000'], 'El sueldo acordado era 24,000: error de captura.', ['tipo' => TipoCambioSalario::Correccion->value, 'huella_afectados' => $vista['huella_afectados']])->assertCreated();
    expect($respuesta->json('data.vigente_desde'))->toBe('2026-09-01');

    // 5. Nuevo importe y diario derivado.
    expect((float) $nuevo->refresh()->sueldo_mensual)->toBe(24000.0)
        ->and((float) $nuevo->salario_diario)->toBe(800.0);

    // 6. Contrato firmado conservado.
    expect((float) $firmado->refresh()->sueldo_mensual)->toBe(20000.0);

    // 7. Nómina: el preparado se ajusta; el emitido queda con rectificación y no cambia.
    expect((float) $preparado->refresh()->sueldo_base)->toBe(12000.0);
    $rectificacion = NominaRectificacion::query()->where('recibo_nomina_id', $emitido->id)->sole();
    expect($rectificacion->estado)->toBe(EstadoRectificacionNomina::Pendiente)
        ->and($rectificacion->origen)->toBe('sueldo')
        ->and((float) $rectificacion->importe_sueldo_despues)->toBe(12000.0);

    // 8. Aplicar la rectificación emite otro recibo; el original sigue igual.
    app(IncidenciasNominaService::class)->aplicarRectificacion($rectificacion, $this->rh);
    $emitido->refresh();
    $resultante = ReciboNomina::query()->findOrFail($rectificacion->refresh()->recibo_resultante_id);
    expect([$emitido->pdf_path, $emitido->checksum, $emitido->folio])->toBe($pdfOriginal)
        ->and((float) $emitido->neto)->toBe(10000.0)
        ->and($emitido->conceptos()->count())->toBe(1)
        ->and($emitido->rectificado_por_id)->toBe($resultante->id)
        ->and((float) $resultante->neto)->toBe(12000.0)
        ->and($resultante->folio)->not->toBe($emitido->folio)
        ->and(GeneratedDocument::query()->where('documentable_id', $resultante->id)->where('documentable_type', $resultante->getMorphClass())->latest('id')->first()?->revision_de_id)->not->toBeNull();

    // 9. Finiquito pagado intacto.
    expect($finiquito->fresh()->getAttributes())->toBe($finiquitoAntes);

    // 10. Historial completo: registro, campos, motivo, usuario, origen, huella.
    $historial = $this->actingAs($this->rh)->getJson("/correcciones/salario/{$nuevo->id}/historial")->assertOk()->json('data');
    expect($historial)->toHaveCount(2)
        ->and($historial[0]['motivo'])->toContain('error de captura')
        ->and($historial[0]['version'])->toBe(2)
        ->and($historial[0]['origen'])->toBe('Portal web')
        ->and($historial[0]['actor'])->not->toBe('')
        ->and($historial[0]['huella'])->toHaveLength(64)
        ->and(collect($historial[0]['campos'])->firstWhere('campo', 'sueldo_mensual')['anterior'])->toBe('20000.00')
        ->and(collect($historial[0]['campos'])->firstWhere('campo', 'sueldo_mensual')['nuevo'])->toBe('24000.00');

    // El sueldo no queda en claro en la tabla del historial.
    $crudo = Correccion::query()->latest('id')->first()?->campos()->where('campo', 'sueldo_mensual')->first();
    expect($crudo->valor_nuevo)->not->toContain('24000');
});

it('vigenteEn y la rectificación usan el sueldo del periodo, no el de hoy (D2)', function () {
    $servicio = app(CompensacionService::class);
    $servicio->actualizarSueldoMensual($this->persona, 30000, $this->rh, ['fuente' => 'alta', 'fecha_efectiva' => '2020-01-01']);
    $recibo = ($this->recibo)('2026-09-16', '2026-09-30');

    // Aumento posterior: un periodo anterior conserva su sueldo.
    $servicio->cambiarSueldo($this->persona->refresh(), 60000, TipoCambioSalario::Aumento, 'Aumento autorizado.', $this->rh);

    expect($servicio->vigenteEn($this->persona, '2026-09-30')['mensual'])->toBe(30000.0)
        ->and($servicio->vigenteEn($this->persona, now())['mensual'])->toBe(60000.0)
        ->and($servicio->vigenteEn($this->persona, '2019-01-01')['mensual'])->toBe(30000.0);

    // Falta capturada después: los días cambian, el sueldo del periodo no.
    $recibo->update(['dias_pagados' => 15]);
    NominaRectificacion::query()->create([
        'recibo_nomina_id' => $recibo->id, 'colaborador_id' => $this->persona->id, 'estado' => EstadoRectificacionNomina::Pendiente,
        'dias_pagados_despues' => 15, 'dias_incapacidad_despues' => 0, 'dias_falta_despues' => 0, 'neto_antes' => $recibo->neto, 'importe_sueldo_despues' => 15000, 'motivo' => 'Prueba de periodo.',
    ]);
    $rectificacion = NominaRectificacion::query()->sole();
    app(IncidenciasNominaService::class)->aplicarRectificacion($rectificacion, $this->rh);

    expect((float) ReciboNomina::query()->findOrFail($rectificacion->refresh()->recibo_resultante_id)->neto)->toBe(15000.0);
});

it('reglas distintas por tipo de cambio de sueldo', function () {
    $servicio = app(CompensacionService::class);
    $fecha = fn (TipoCambioSalario $tipo, float $monto, ?string $dia = null) => $servicio->fechaEfectivaDe($this->persona, $monto, $tipo, $dia);

    expect(fn () => $fecha(TipoCambioSalario::PrimeraCaptura, 40000))->toThrow(ValidationException::class)
        ->and(fn () => $fecha(TipoCambioSalario::Aumento, 20000))->toThrow(ValidationException::class)
        ->and(fn () => $fecha(TipoCambioSalario::Aumento, 30000))->toThrow(ValidationException::class)
        ->and(fn () => $fecha(TipoCambioSalario::AjusteConVigencia, 40000))->toThrow(ValidationException::class)
        ->and(fn () => $fecha(TipoCambioSalario::AjusteConVigencia, 40000, CarbonImmutable::now()->addDay()->toDateString()))->toThrow(ValidationException::class)
        ->and(fn () => $fecha(TipoCambioSalario::AjusteConVigencia, 40000, '2019-01-01'))->toThrow(ValidationException::class)
        ->and(fn () => $fecha(TipoCambioSalario::Importacion, 40000))->toThrow(ValidationException::class)
        ->and($fecha(TipoCambioSalario::AjusteConVigencia, 40000, '2026-01-15'))->toBe('2026-01-15')
        ->and($fecha(TipoCambioSalario::Aumento, 40000))->toBe(now('America/Mexico_City')->toDateString());

    expect(fn () => $servicio->cambiarSueldo($this->persona, 40000, TipoCambioSalario::Aumento, '  ', $this->rh))->toThrow(ValidationException::class);
});

it('el contrato firmado conserva su sueldo también desde el expediente; el sin firmar se sincroniza', function () {
    $firmado = ($this->contratoFirmado)();
    $sinFirmar = ContratoLaboral::factory()->create(['colaborador_id' => $this->persona->id, 'estado' => 'vigente', 'sueldo_mensual' => 30000]);

    $this->actingAs($this->rh)->put("/rh/expedientes/{$this->persona->id}/datos-laborales", ['sueldo_mensual' => '45000', 'motivo' => 'Aumento autorizado.'])
        ->assertSessionHas('toast', fn (array $t): bool => $t['type'] === 'warning' && str_contains($t['message'], 'modificación contractual'));

    expect((float) $firmado->refresh()->sueldo_mensual)->toBe(30000.0)
        ->and((float) $sinFirmar->refresh()->sueldo_mensual)->toBe(45000.0);
});

it('contratación: sin firmar se corrige; firmado no y lo explica', function () {
    $firmado = ($this->contratoFirmado)();
    $descripcion = $this->actingAs($this->rh)->getJson("/correcciones/contratacion/{$firmado->id}")->assertOk()->json('data');
    expect(collect($descripcion['campos'])->firstWhere('campo', 'fecha_fin')['modo'])->toBe(ModoCorreccion::NuevaVersion->value);

    ($this->corregir)('contratacion', $firmado->id, ['fecha_fin' => '2030-01-01'])->assertUnprocessable();

    $abierto = ContratoLaboral::factory()->create(['colaborador_id' => $this->persona->id, 'estado' => 'vigente', 'fecha_fin' => '2027-01-01']);
    ($this->corregir)('contratacion', $abierto->id, ['fecha_fin' => '2027-06-01'])->assertCreated();
    expect($abierto->refresh()->fecha_fin->toDateString())->toBe('2027-06-01');
});

it('un recibo emitido o cancelado no se edita, ni uno por uno ni en masa (D1, D5)', function () {
    $emitido = ($this->recibo)('2026-09-01', '2026-09-15');
    $cancelado = ($this->recibo)('2026-09-16', '2026-09-30', 15000, true);
    app(ReciboNominaService::class)->cancelar($cancelado, $this->rh);
    $preparado = ($this->recibo)('2026-10-01', '2026-10-15', 15000, true);
    $conceptos = [['tipo' => 'percepcion', 'concepto' => 'Sueldo', 'cantidad' => 15, 'importe' => 1]];

    $this->actingAs($this->rh)->put("/rh/nomina/recibos/{$emitido->id}", ['conceptos' => $conceptos])->assertSessionHasErrors('estado');
    $this->actingAs($this->rh)->put("/rh/nomina/recibos/{$cancelado->id}", ['conceptos' => $conceptos])->assertSessionHasErrors('estado');
    expect((float) $emitido->refresh()->neto)->toBe(15000.0)
        ->and((float) $cancelado->refresh()->neto)->toBe(15000.0)
        ->and($emitido->conceptos()->count())->toBe(1);

    $quincenas = app(NominaQuincenalService::class);

    foreach (['2026-09-Q1' => $emitido, '2026-09-Q2' => $cancelado, '2026-10-Q1' => $preparado] as $recibo) {
        $periodo = ['inicio' => CarbonImmutable::parse($recibo->periodo_inicio), 'fin' => CarbonImmutable::parse($recibo->periodo_fin)];
        $quincenas->aplicarMasivo($periodo, $this->rh, ['accion' => 'agregar', 'tipo' => 'percepcion', 'concepto' => 'Bono', 'importe' => 100]);
    }

    expect((float) $emitido->refresh()->neto)->toBe(15000.0)
        ->and((float) $cancelado->refresh()->neto)->toBe(15000.0)
        ->and((float) $preparado->refresh()->neto)->toBe(15100.0);
});

it('corregir un recibo emitido solo propone la rectificación; cancelado no se puede', function () {
    $emitido = ($this->recibo)('2026-09-01', '2026-09-15');
    ($this->corregir)('recibo_nomina', $emitido->id, ['concepto_0' => '14000'])->assertCreated();

    expect((float) $emitido->refresh()->neto)->toBe(15000.0);
    $rectificacion = NominaRectificacion::query()->sole();
    expect($rectificacion->origen)->toBe('manual');
    app(IncidenciasNominaService::class)->aplicarRectificacion($rectificacion, $this->rh);
    expect((float) $emitido->refresh()->neto)->toBe(15000.0)
        ->and((float) ReciboNomina::query()->findOrFail($rectificacion->refresh()->recibo_resultante_id)->neto)->toBe(14000.0);

    // Ya rectificado: se corrige el vigente.
    $this->actingAs($this->rh);
    ($this->corregir)('recibo_nomina', $emitido->id, ['concepto_0' => '13000'])->assertUnprocessable();

    $cancelado = ($this->recibo)('2026-10-01', '2026-10-15', 15000, true);
    app(ReciboNominaService::class)->cancelar($cancelado, $this->rh);
    ($this->corregir)('recibo_nomina', $cancelado->id, ['concepto_0' => '1'])->assertUnprocessable();
    expect($cancelado->refresh()->estado)->toBe(EstadoReciboNomina::Cancelado);
});

it('reparar el PDF de un emitido usa los datos fijados al emitir, no la sucursal de hoy (D6)', function () {
    $recibo = ($this->recibo)('2026-09-01', '2026-09-15');
    $empresaOriginal = $recibo->refresh()->datos_emision['empresa_razon_social'] ?? null;
    expect($empresaOriginal)->not->toBeNull();

    $otra = clEstructura();
    $this->persona->update(['sucursal_principal_id' => $otra['sucursal']->id]);
    $otra['sucursal']->empresa->update(['razon_social' => 'OTRA RAZON SOCIAL']);
    $recibo = $recibo->fresh(['colaborador']);

    expect(app(DatosDocumentoAdministrativo::class)->recibo($recibo)['empresa_razon_social'])->toBe($empresaOriginal);
});

it('finiquito: sin firmar se corrige con motivo; firmado o pagado queda intacto y lo explica', function () {
    $abierto = FiniquitoCalculo::factory()->create(['colaborador_id' => $this->persona->id]);
    $descripcion = $this->actingAs($this->rh)->getJson("/correcciones/finiquito/{$abierto->id}")->assertOk()->json('data');
    $campo = collect($descripcion['campos'])->first(fn (array $c): bool => str_starts_with($c['campo'], 'auto_'));
    ($this->corregir)('finiquito', $abierto->id, [$campo['campo'] => '123.45'])->assertCreated();
    expect($abierto->refresh()->ajustes()->count())->toBe(1);

    $firmado = FiniquitoCalculo::factory()->create(['colaborador_id' => $this->persona->id, 'estado' => EstadoFiniquito::Firmado->value]);
    $descripcion = $this->actingAs($this->rh)->getJson("/correcciones/finiquito/{$firmado->id}")->assertOk()->json('data');
    $campo = collect($descripcion['campos'])->first(fn (array $c): bool => str_starts_with($c['campo'], 'auto_'));
    expect($campo['editable'])->toBeFalse()->and($campo['explicacion'])->toContain('pendiente de validación');
    ($this->corregir)('finiquito', $firmado->id, [$campo['campo'] => '1'])->assertUnprocessable();
    expect($firmado->refresh()->ajustes()->count())->toBe(0);
});

it('un fallo a media corrección revierte también el historial; un aviso que falla no la deshace', function () {
    // Sin permiso sobre el dato médico: se rechaza sin escribir nada.
    $this->actingAs($this->rh);
    ($this->corregir)('salario', $this->persona->id, ['sueldo_mensual' => '40000'], 'Motivo suficiente.', ['tipo' => 'ajuste_con_vigencia', 'vigente_desde' => '2019-01-01'])->assertUnprocessable();
    expect(Correccion::query()->count())->toBe(0)
        ->and(HistorialSalario::query()->where('colaborador_id', $this->persona->id)->count())->toBe(0);

    $this->mock(NotificadorRhService::class)->shouldReceive('notificar')->andThrow(new RuntimeException('correo caído'));
    ($this->corregir)('personal', $this->persona->id, ['telefono' => '5599998888'])->assertCreated();
    expect($this->persona->refresh()->telefono)->toBe('5599998888')
        ->and(Correccion::query()->count())->toBe(1);
});

it('el historial es inmutable', function () {
    ($this->corregir)('personal', $this->persona->id, ['telefono' => '5599998888'])->assertCreated();
    $correccion = Correccion::query()->sole();
    $campo = $correccion->campos()->sole();

    expect(fn () => $correccion->update(['motivo' => 'otro']))->toThrow(LogicException::class)
        ->and(fn () => $correccion->delete())->toThrow(LogicException::class)
        ->and(fn () => $campo->update(['valor_nuevo' => 'otro']))->toThrow(LogicException::class)
        ->and(fn () => $campo->delete())->toThrow(LogicException::class);

    $renglon = app(CompensacionService::class)->cambiarSueldo($this->persona->refresh(), 50000, TipoCambioSalario::Aumento, 'Aumento autorizado.', $this->rh);
    expect(fn () => $renglon->update(['motivo' => 'otro']))->toThrow(LogicException::class)
        ->and(fn () => $renglon->delete())->toThrow(LogicException::class);
});

it('los datos médicos solo los corrige y lee quien tiene su permiso, y van cifrados', function () {
    $this->rh->givePermissionTo('expedientes.datos_medicos.ver');
    ($this->corregir)('personal', $this->persona->id, ['alergias' => 'Penicilina'])->assertCreated();
    expect(Correccion::query()->sole()->campos()->sole()->valor_nuevo)->not->toContain('Penicilina');

    $lector = clUsuario('rh_admin', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $lector->revokePermissionTo('expedientes.datos_medicos.ver');
    foreach ($lector->getRoleNames() as $rol) {
        Role::findByName($rol)->revokePermissionTo('expedientes.datos_medicos.ver');
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $lector = $lector->fresh();

    $respuesta = $this->actingAs($lector)->getJson("/correcciones/personal/{$this->persona->id}/historial")->assertOk();
    expect($respuesta->getContent())->not->toContain('Penicilina')
        ->and($respuesta->json('data.0.campos.0.visible'))->toBeFalse();
    $this->actingAs($lector)->postJson("/correcciones/personal/{$this->persona->id}/vista-previa", ['valores' => ['alergias' => 'X']])->assertUnprocessable();
});

it('la API usa el mismo servicio y deja fuera salario y recibos', function () {
    $recibo = ($this->recibo)('2026-09-01', '2026-09-15');
    $this->actingAs($this->rh, 'sanctum');

    $this->getJson("/api/v1/rh/correcciones/salario/{$this->persona->id}")->assertNotFound();
    $this->getJson("/api/v1/rh/correcciones/recibo_nomina/{$recibo->id}")->assertNotFound();
    $this->getJson("/api/v1/rh/correcciones/personal/{$this->rh->colaborador_id}")->assertForbidden();

    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $this->persona->id, 'estado' => 'vigente']);
    $campos = collect($this->getJson("/api/v1/rh/correcciones/contratacion/{$contrato->id}")->assertOk()->json('data.campos'));
    expect($campos->pluck('campo'))->not->toContain('sueldo_mensual');

    ($this->corregir)('personal', $this->persona->id, ['telefono' => '5577776666'], 'Teléfono mal capturado.', [], null, '/api/v1/rh/correcciones')->assertCreated();
    expect(Correccion::query()->sole()->origen)->toBe('app');
});

it('agenda: la corrección de fecha reprograma con motivo y conserva la cita anterior', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => $this->estructura['sucursal']->id]);
    $evento = app(AgendaEventoService::class)->agendar([
        'sucursal_id' => $this->estructura['sucursal']->id, 'candidato_id' => $candidato->id, 'tipo' => 'entrevista',
        'fecha' => now()->addDays(2)->toDateString(), 'hora' => '10:00',
    ], $this->rh)['evento'];
    $nueva = now()->addDays(3)->format('Y-m-d').' 11:00';

    ($this->corregir)('agenda', $evento->id, ['fecha_hora' => $nueva], '   ')->assertUnprocessable();
    ($this->corregir)('agenda', $evento->id, ['fecha_hora' => $nueva], 'El candidato pidió otra hora.')->assertCreated();

    $evento->refresh();
    expect($evento->reprogramado_a_id)->not->toBeNull()
        ->and(Correccion::query()->sole()->resultado_id)->toBe($evento->reprogramado_a_id);

    $this->actingAs($this->rh)->getJson("/correcciones/agenda/{$evento->id}")->assertOk()
        ->assertJsonPath('data.campos.0.editable', false);
});

it('incapacidad: delega en su servicio y deja rectificación en el recibo emitido', function () {
    $recibo = ($this->recibo)('2026-10-16', '2026-10-31');
    $incapacidad = Incapacidad::query()->create([
        'colaborador_id' => $this->persona->id, 'sucursal_id' => $this->estructura['sucursal']->id, 'tipo' => 'enfermedad_general',
        'secuencia' => 'inicial', 'serie_folio_imss' => 'EG 1234567', 'folio_vigente' => 'EG1234567', 'fecha_inicio' => '2026-10-21', 'dias' => 2, 'fecha_fin' => '2026-10-22',
        'registrado_por' => $this->rh->id, 'estatus' => 'activa',
    ]);

    $vista = $this->actingAs($this->rh)->postJson("/correcciones/incapacidad/{$incapacidad->id}/vista-previa", ['valores' => ['dias' => 5]])->assertOk()->json('data');
    expect($vista['afectados'][0]['accion'])->toBe('requiere_rectificacion');

    ($this->corregir)('incapacidad', $incapacidad->id, ['dias' => 5], 'El certificado decía cinco días.')->assertCreated();

    expect($incapacidad->refresh()->dias)->toBe(5)
        ->and((float) $recibo->refresh()->neto)->toBe(15000.0)
        ->and(NominaRectificacion::query()->where('recibo_nomina_id', $recibo->id)->count())->toBe(1);
});
