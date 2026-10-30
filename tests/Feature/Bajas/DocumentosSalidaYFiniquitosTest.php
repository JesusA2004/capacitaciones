<?php

use App\Enums\EstadoFiniquito;
use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\FamiliaAdministrativa;
use App\Enums\FormaSalida;
use App\Enums\TipoFiniquito;
use App\Models\Baja;
use App\Models\BajaAvisoRescision;
use App\Models\BajaBitacora;
use App\Models\BajaDocumento;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Bajas\BajaDocumentoSalidaService;
use App\Services\Calendario\CalendarioLaboralService;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DisenoAdministrativoService;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\Finiquitos\FiniquitoService;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Etapa 1 · Bajas (PDF págs. 10, 11, 14-16): escenarios 17-27 de la matriz de
| aceptación. Documento de salida por forma, aviso de rescisión con hechos,
| plazo por negativa, firmado en NAS y pestaña Finiquitos sobre el MISMO
| registro que el expediente.
*/

beforeEach(function () {
    clRelojMediodiaMexico();
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->estructura = clEstructura();
    $sucursal = $this->estructura['sucursal'];
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $sucursal->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $sucursal->id]);
    $this->juridico = clUsuario('juridico', ['sucursal_principal_id' => $sucursal->id]);
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'jefe_id' => $this->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => now()->subYears(3)->toDateString(),
        'sueldo_mensual' => 12000,
    ]);
});

function salidaCausal(string $clave): CausalRotacion
{
    return CausalRotacion::query()->where('clave', $clave)->firstOrFail();
}

function salidaRegistrar(object $test, string $clave, array $extra = []): Baja
{
    $causal = salidaCausal($clave);
    $esRescision = $causal->forma() === FormaSalida::Rescision;

    $test->actingAs($test->rh)->post(route('rh.bajas.store'), [
        'colaborador_id' => $test->persona->id,
        'forma_salida' => $causal->forma()->value,
        'causal_rotacion_id' => $causal->id,
        'fecha_baja' => now()->subDay()->toDateString(),
        'recontratable' => $esRescision ? 'no' : 'si',
        'comentarios' => 'Se documentó el caso con el reporte de la sucursal.',
        ...($esRescision ? [
            'fecha_conocimiento_falta' => now()->subDays(3)->toDateString(),
            'entrega_aviso_rescision' => 'personal',
            'fecha_entrega_aviso' => now()->subDay()->toDateString(),
        ] : []),
        ...$extra,
    ])->assertRedirect();

    return Baja::query()->where('colaborador_id', $test->persona->id)->latest('id')->firstOrFail();
}

function salidaAviso(array $extra = []): array
{
    return [
        'fundamento' => 'Art. 47, fracción XIII, LFT',
        'que_ocurrio' => 'Se presentó a laborar con aliento alcohólico.',
        'hechos_en' => now()->subDays(2)->format('Y-m-d H:i'),
        'lugar' => 'Instalaciones de la sucursal',
        'constataron' => 'Subgerente de la sucursal',
        'relato' => 'Aproximadamente a las 10:30 horas se presentó a laborar y fue constatado por el subgerente.',
        'testigos' => [['nombre' => 'Testigo Uno', 'cargo' => 'Subgerente'], ['nombre' => 'Testigo Dos', 'cargo' => 'Gestor']],
        'fecha_aviso' => now()->subDay()->toDateString(),
        'metodo_entrega' => 'personal',
        'fecha_entrega' => now()->subDay()->toDateString(),
        ...$extra,
    ];
}

function salidaFiniquito(Baja $baja): FiniquitoCalculo
{
    return FiniquitoCalculo::query()->where('solicitud_interna_id', $baja->cierre->solicitud_interna_id)->firstOrFail();
}

test('escenario 17: una baja se cierra sin pasar por Jurídico y sus acciones no ofrecen Jurídico', function () {
    $baja = salidaRegistrar($this, 'RE-04');

    expect($baja->estatus)->toBe(EstatusBaja::EnRevisionRh);

    $this->actingAs($this->rh)->get(route('rh.bajas.show', $baja))->assertInertia(fn (Assert $page) => $page
        ->component('Rh/Bajas/Show')
        ->where('baja.acciones', fn ($acciones) => collect($acciones)->intersect(['enviar_juridico', 'aprobar_juridico', 'devolver_juridico'])->isEmpty()));

    // Jurídico conserva consulta, sin acciones de autorización.
    $this->actingAs($this->juridico)->get(route('rh.bajas.show', $baja))->assertOk();
    $this->actingAs($this->juridico)->post(route('rh.bajas.aprobar-juridico', $baja), ['motivo' => 'Revisado por Jurídico'])->assertSessionHasErrors('estatus');
});

test('Jurídico entra a la bandeja a bajas abiertas, no a un estatus retirado', function () {
    salidaRegistrar($this, 'RE-04');

    $this->actingAs($this->juridico)->get(route('rh.bajas.index'))->assertInertia(fn (Assert $page) => $page
        ->where('filtros.estatus', 'abiertas')
        ->has('bajas.data', 1)
        ->where('estatus', fn ($opciones) => collect($opciones)->pluck('value')->doesntContain('en_revision_juridico')));
});

test('escenario 18: el expediente exige sus documentos obligatorios para cerrar', function () {
    $baja = salidaRegistrar($this, 'NR-02');

    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasErrors('documentos');

    expect($baja->refresh()->estatus)->toBe(EstatusBaja::EnRevisionRh);
});

test('escenario 19: RV corresponde a la carta de renuncia del motor de documentos maestros', function () {
    $baja = salidaRegistrar($this, 'RV-01');
    $panel = app(BajaDocumentoSalidaService::class)->panel($baja, $this->rh);

    expect($panel['documento'])->toBe(['clave' => 'carta_renuncia', 'etiqueta' => 'Carta de renuncia']);
});

test('escenario 20: NR genera el convenio de terminación con versiones preservadas', function () {
    $baja = salidaRegistrar($this, 'NR-01');

    $this->actingAs($this->rh)->get(route('rh.bajas.salida.vista-previa', $baja))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(GeneratedDocument::query()->where('clave_plantilla', 'convenio_terminacion')->count())->toBe(0);

    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja))->assertSessionHasNoErrors();
    $primero = GeneratedDocument::query()->where('clave_plantilla', 'convenio_terminacion')->sole();
    Storage::disk($primero->disk)->assertExists($primero->path);

    // Otra versión exige motivo y conserva la anterior en el NAS.
    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja))->assertSessionHasErrors('motivo');
    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja), ['motivo' => 'Se corrigió el último día'])->assertSessionHasNoErrors();

    expect(GeneratedDocument::query()->where('clave_plantilla', 'convenio_terminacion')->count())->toBe(2)
        ->and($primero->refresh()->estado_flujo?->value)->toBe('cancelado');
    Storage::disk($primero->disk)->assertExists($primero->path);
    expect(BajaBitacora::query()->where('baja_id', $baja->id)->where('accion', 'documento_salida')->count())->toBe(2);
});

test('escenario 21: RE genera el aviso solo con hechos, testigos y evidencia, y los imprime', function () {
    $baja = salidaRegistrar($this, 'RE-04');

    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja))->assertSessionHasErrors('documento');

    $this->actingAs($this->rh)->put(route('rh.bajas.salida.aviso', $baja), salidaAviso(['relato' => '']))->assertSessionHasErrors('relato');
    $this->actingAs($this->rh)->put(route('rh.bajas.salida.aviso', $baja), salidaAviso(['testigos' => [['nombre' => 'Solo Uno', 'cargo' => '']]]))->assertSessionHasErrors('testigos');
    $this->actingAs($this->rh)->put(route('rh.bajas.salida.aviso', $baja), salidaAviso())->assertSessionHasNoErrors();

    // Sin evidencia todavía no se genera.
    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja))->assertSessionHasErrors('documento');

    $this->actingAs($this->rh)->post(route('rh.bajas.salida.evidencias', $baja), ['archivo' => clArchivoPdf('reporte.pdf')])->assertSessionHasNoErrors();
    expect(BajaDocumento::query()->where('baja_id', $baja->id)->where('clasificacion', 'evidencia_rescision')->count())->toBe(1);

    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja))->assertSessionHasNoErrors();
    expect(GeneratedDocument::query()->where('clave_plantilla', 'aviso_rescision')->count())->toBe(1);

    $datos = app(DatosDocumentoAdministrativo::class)->salida($baja->refresh(), $this->persona);
    expect($datos['hechos']['relato'])->toContain('10:30')
        ->and($datos['hechos']['testigos'])->toHaveCount(2)
        ->and($datos['fundamento'])->toBe('Art. 47, fracción XIII, LFT');

    $html = app(DocumentoAdministrativoService::class)->html(FamiliaAdministrativa::AvisoRescision, $datos, app(DisenoAdministrativoService::class)->porDefecto(FamiliaAdministrativa::AvisoRescision));
    expect($html)->toContain('aliento alcohólico')->toContain('Testigo Uno')->toContain('Representante legal');
});

test('escenario 22: una negativa de recepción muestra el plazo con festivos y se valida con bitácora', function () {
    $baja = salidaRegistrar($this, 'RE-04');
    $servicio = app(BajaDocumentoSalidaService::class);
    $this->actingAs($this->rh)->put(route('rh.bajas.salida.aviso', $baja), salidaAviso())->assertSessionHasNoErrors();
    expect($servicio->plazoNegativa($baja->refresh()))->toBeNull();

    $this->actingAs($this->rh)->put(route('rh.bajas.salida.aviso', $baja), salidaAviso(['metodo_entrega' => 'se_nego']))->assertSessionHasNoErrors();
    $baja = Baja::query()->findOrFail($baja->id);
    $plazo = $servicio->plazoNegativa($baja);
    $esperada = app(CalendarioLaboralService::class)->sumarDiasHabilesSinFestivos(now()->subDay()->startOfDay(), 5);

    expect($plazo['fecha_limite'])->toBe($esperada->toDateString())
        ->and($plazo['nota'])->toContain('área competente')
        ->and($plazo['validado'])->toBeFalse();

    $this->actingAs($this->juridico)->post(route('rh.bajas.salida.plazo', $baja))->assertSessionHasNoErrors();
    expect(BajaAvisoRescision::query()->where('baja_id', $baja->id)->value('plazo_validado'))->toBeTruthy();
});

test('el cómputo de días hábiles no cuenta fines de semana ni descansos obligatorios', function () {
    $calendario = app(CalendarioLaboralService::class);

    // Viernes 23/10/2026 + 5 hábiles = viernes 30/10/2026 (ejemplo del PDF).
    expect($calendario->sumarDiasHabilesSinFestivos(Carbon::parse('2026-10-23'), 5)->toDateString())->toBe('2026-10-30');
    // 16 de septiembre (miércoles) no cuenta.
    expect($calendario->sumarDiasHabilesSinFestivos(Carbon::parse('2026-09-11'), 5)->toDateString())->toBe('2026-09-21');
    // Tercer lunes de noviembre de 2026 (día 16) no cuenta.
    expect($calendario->sumarDiasHabilesSinFestivos(Carbon::parse('2026-11-13'), 1)->toDateString())->toBe('2026-11-17');

    config(['calendario_laboral.adicionales' => ['2026-10-26']]);
    expect($calendario->sumarDiasHabilesSinFestivos(Carbon::parse('2026-10-23'), 5)->toDateString())->toBe('2026-11-02');
});

test('escenario 23 y NAS: el firmado se asocia al espacio requerido y no reemplaza al generado', function () {
    $baja = salidaRegistrar($this, 'NR-01');

    $this->actingAs($this->rh)->post(route('rh.bajas.salida.firmado', $baja), ['archivo' => clArchivoPdf('firmado.pdf')])->assertSessionHasErrors('archivo');

    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja))->assertSessionHasNoErrors();
    $generado = GeneratedDocument::query()->where('clave_plantilla', 'convenio_terminacion')->sole();

    $this->actingAs($this->rh)->post(route('rh.bajas.salida.firmado', $baja), ['archivo' => clArchivoPdf('firmado.pdf')])->assertSessionHasNoErrors();
    $firmado = BajaDocumento::query()->where('baja_id', $baja->id)->where('clasificacion', 'firmado_salida')->sole();

    Storage::disk('nas')->assertExists($firmado->ruta);
    Storage::disk($generado->disk)->assertExists($generado->path);
    expect($firmado->documento_requerido_id)->not->toBeNull()
        ->and($generado->refresh()->estado_flujo?->firmaFisicaRegistrada())->toBeTrue();

    // Un original firmado no se sustituye desde aquí.
    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja), ['motivo' => 'Intento de sustituir'])->assertSessionHasErrors('documento');
});

test('un usuario sin gestión de bajas no genera documentos de salida', function () {
    $baja = salidaRegistrar($this, 'NR-01');

    $this->actingAs($this->gerente)->post(route('rh.bajas.salida.generar', $baja))->assertForbidden();
    $this->actingAs($this->gerente)->get(route('rh.bajas.salida.vista-previa', $baja))->assertForbidden();
    $this->actingAs($this->juridico)->post(route('rh.bajas.salida.generar', $baja))->assertForbidden();
});

test('OT genera constancia sin presumir renuncia, rescisión ni indemnización', function () {
    $baja = salidaRegistrar($this, 'OT-03');
    $finiquito = salidaFiniquito($baja);

    expect((float) $finiquito->indemnizacion)->toBe(0.0)
        ->and($finiquito->tipo_documento)->toBe(TipoFiniquito::Finiquito);

    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja))->assertSessionHasNoErrors();
    $doc = GeneratedDocument::query()->where('clave_plantilla', 'constancia_terminacion')->sole();
    expect($doc->titulo)->toBe('Constancia de terminación');
    expect(CausalRotacion::query()->where('clave', 'RE-06')->exists())->toBeFalse();
});

test('escenarios 24 y 25: Finiquitos se consulta aparte y es el mismo registro del expediente', function () {
    $baja = salidaRegistrar($this, 'RV-01');
    $finiquito = salidaFiniquito($baja);

    $this->actingAs($this->rh)->get(route('rh.bajas.finiquitos.index', ['finiquito' => $finiquito->id]))->assertInertia(fn (Assert $page) => $page
        ->component('Rh/Bajas/Finiquitos')
        ->where('finiquitos.data.0.id', $finiquito->id)
        ->where('finiquitos.data.0.baja_id', $baja->id)
        ->where('finiquitos.data.0.estado_flujo', 'calculado')
        ->where('resumen.calculados', 1)
        ->where('seleccionado.id', $finiquito->id));

    // Cambiar en la pestaña se ve en la fila de la baja: una sola fuente.
    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.revisar', $finiquito))->assertSessionHasNoErrors();
    $this->actingAs($this->rh)->get(route('rh.bajas.index'))->assertInertia(fn (Assert $page) => $page
        ->where('bajas.data.0.finiquito.id', $finiquito->id)
        ->where('bajas.data.0.finiquito.etiqueta', 'Revisado'));
    expect(FiniquitoCalculo::query()->count())->toBe(1);
});

test('escenario 26: NR-01 propone liquidación con indemnización y advertencia, RE-04 y NR-02 no', function () {
    $baja = salidaRegistrar($this, 'NR-01');
    $finiquito = salidaFiniquito($baja);

    expect($finiquito->tipo_documento)->toBe(TipoFiniquito::Liquidacion)
        ->and((float) $finiquito->indemnizacion)->toBeGreaterThan(0);

    $this->actingAs($this->rh)->get(route('rh.bajas.finiquitos.index', ['finiquito' => $finiquito->id]))->assertInertia(fn (Assert $page) => $page
        ->where('seleccionado.tipo', 'liquidacion')
        ->where('seleccionado.advertencia_liquidacion', fn ($texto) => str_contains($texto, 'RH/Jurídico')));

    // RH/Jurídico decide el supuesto concreto con motivo.
    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.tipo', $finiquito), ['tipo' => 'finiquito', 'motivo' => ''])->assertSessionHasErrors('motivo');
    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.tipo', $finiquito), ['tipo' => 'finiquito', 'motivo' => 'Jurídico lo revisó para este caso'])->assertSessionHasNoErrors();
    expect($finiquito->refresh()->tipo_documento)->toBe(TipoFiniquito::Finiquito);

    $sinMarca = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id, 'jefe_id' => $this->gerente->colaborador_id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => now()->subYears(3)->toDateString(), 'sueldo_mensual' => 12000]);
    $this->persona = $sinMarca;
    $otra = salidaRegistrar($this, 'RE-04');
    $finiquitoRe = salidaFiniquito($otra);
    expect($finiquitoRe->tipo_documento)->toBe(TipoFiniquito::Finiquito)->and((float) $finiquitoRe->indemnizacion)->toBe(0.0);
});

test('un finiquito anterior sin tipo guardado se deriva sin reescribirlo', function () {
    $baja = salidaRegistrar($this, 'NR-01');
    $finiquito = salidaFiniquito($baja);
    $finiquito->update(['tipo_documento' => null]);

    $servicio = app(FiniquitoService::class);
    expect($servicio->tipoDe($finiquito->refresh()))->toBe(TipoFiniquito::Liquidacion)
        ->and($finiquito->tipo_documento)->toBeNull();
});

test('escenario 27 y F4: ajustes con bitácora y flujo Calculado → Revisado → Firmado → Pagado', function () {
    $baja = salidaRegistrar($this, 'RV-01');
    $finiquito = salidaFiniquito($baja);
    $calculado = (float) $finiquito->aguinaldo_proporcional;

    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.ajustar', $finiquito), ['concepto_clave' => 'aguinaldo_proporcional', 'importe' => 1000, 'motivo' => 'Ajuste acordado'])->assertSessionHasNoErrors();
    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.ajustar', $finiquito), ['concepto_clave' => 'aguinaldo_proporcional', 'importe' => 1200, 'motivo' => 'Corrección del ajuste'])->assertSessionHasNoErrors();

    $historial = app(FiniquitoService::class)->historialAjustes($finiquito->refresh());
    expect($historial[0])->toMatchArray(['anterior' => 1000.0, 'actualizado' => 1200.0, 'motivo' => 'Corrección del ajuste'])
        ->and($historial[1]['anterior'])->toBe($calculado)
        ->and($historial[0]['usuario'])->not->toBeNull();

    // No se sube firmado ni se paga sin revisar.
    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.firmado', $finiquito), ['archivo' => clArchivoPdf('firmado.pdf')])->assertSessionHasErrors('estado');
    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.pago', $finiquito), ['fecha' => now()->toDateString()])->assertSessionHasErrors('estado');

    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.revisar', $finiquito))->assertSessionHasNoErrors();
    $this->actingAs($this->rh)->get(route('rh.bajas.finiquitos.recibo', $finiquito))->assertOk();
    $finiquito->refresh();
    expect($finiquito->documento_generado_path)->not->toBeNull();

    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.firmado', $finiquito), ['archivo' => clArchivoPdf('firmado.pdf')])->assertSessionHasNoErrors();
    $finiquito->refresh();
    expect($finiquito->estado)->toBe(EstadoFiniquito::Firmado)
        ->and($finiquito->documento_firmado_path)->not->toBeNull()
        ->and($finiquito->documento_generado_path)->not->toBeNull();

    // Sin ediciones silenciosas después de firmar.
    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.ajustar', $finiquito), ['concepto_clave' => 'aguinaldo_proporcional', 'importe' => 1, 'motivo' => 'Intento tardío'])->assertForbidden();
    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.recalcular', $finiquito))->assertForbidden();

    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.pago', $finiquito), ['fecha' => now()->toDateString(), 'forma_pago' => 'transferencia'])->assertSessionHasNoErrors();
    expect($finiquito->refresh()->estado)->toBe(EstadoFiniquito::Pagado);
});

test('«Aprobado» de expedientes anteriores se presenta como Revisado sin perderse', function () {
    $baja = salidaRegistrar($this, 'RV-01');
    $finiquito = salidaFiniquito($baja);
    $finiquito->update(['estado' => EstadoFiniquito::Aprobado]);

    $this->actingAs($this->rh)->get(route('rh.bajas.finiquitos.index'))->assertInertia(fn (Assert $page) => $page
        ->where('finiquitos.data.0.estado_flujo', 'revisado')
        ->where('finiquitos.data.0.subestado', 'Aprobado')
        ->where('resumen.revisados', 1));
    expect($finiquito->refresh()->estado)->toBe(EstadoFiniquito::Aprobado);
});

test('la pestaña Finiquitos respeta permiso y alcance', function () {
    salidaRegistrar($this, 'RV-01');
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)->get(route('rh.bajas.finiquitos.index'))->assertForbidden();
});

test('las migraciones de Jurídico y OT conservan datos previos y son idempotentes', function () {
    $baja = salidaRegistrar($this, 'RE-04');
    $baja->update(['estatus' => EstatusBaja::EnRevisionJuridico, 'juridico_por' => $this->juridico->id, 'juridico_en' => now()]);

    $migracion = require database_path('migrations/2026_10_30_120000_bajas_retiro_compuerta_juridico.php');
    $migracion->up();
    $migracion->up();

    $baja->refresh();
    expect($baja->estatus)->toBe(EstatusBaja::EnRevisionRh)
        ->and($baja->juridico_por)->toBe($this->juridico->id)
        ->and(BajaBitacora::query()->where('baja_id', $baja->id)->where('accion', 'conversion_flujo')->count())->toBe(1);

    $catalogo = require database_path('migrations/2026_10_30_130000_causales_implica_indemnizacion_y_ot.php');
    $antes = CausalRotacion::query()->count();
    $catalogo->up();
    expect(CausalRotacion::query()->count())->toBe($antes);
});

test('las pestañas se muestran según permisos', function () {
    $this->actingAs($this->rh)->get(route('rh.bajas.causales'))->assertInertia(fn (Assert $page) => $page
        ->component('Rh/Bajas/Causales')
        ->where('pestanas.bajas', true)->where('pestanas.finiquitos', true)->where('pestanas.rotacion', true)->where('pestanas.causales', true));

    $this->actingAs($this->rh)->get(route('rh.bajas.rotacion'))->assertInertia(fn (Assert $page) => $page->component('Reportes/TableroRotacion'));
    $this->actingAs($this->gerente)->get(route('rh.bajas.causales'))->assertForbidden();
});

test('subir un archivo no cambia cierres de otra baja', function () {
    $baja = salidaRegistrar($this, 'RE-04');

    $this->actingAs($this->rh)->post(route('rh.bajas.salida.evidencias', $baja), ['archivo' => UploadedFile::fake()->create('nota.exe', 5)])->assertSessionHasErrors('archivo');
    expect(BajaDocumento::query()->count())->toBe(0);
});
