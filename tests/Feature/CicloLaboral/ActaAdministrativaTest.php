<?php

use App\Enums\EstadoActa;
use App\Enums\EstadoUsuario;
use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Models\User;
use App\Services\Actas\ActaPdfService;
use App\Services\Actas\ActaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Acta administrativa circunstanciada (docs/acta/…docx): formato ÚNICO de
| PEOPLE. Los datos del trabajador, la empresa y el centro de trabajo los
| pone el sistema; RH captura incidencia, hechos, participantes y
| manifestaciones. La vista previa es el PDF real sin guardar; el
| definitivo se congela en el expediente. Las «instrucciones de llenado»
| del formato nunca llegan al documento.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin', ['name' => 'Rocío', 'apellidos' => 'Hernández Paz']);
    $this->estructura = clEstructura();
    $this->estructura['empresa']->update(['razon_social' => 'MR LANA SA DE CV']);
    $this->estructura['sucursal']->update(['direccion' => 'Av. Morelos 123', 'colonia' => 'Centro', 'municipio' => 'Cuernavaca', 'estado' => 'Morelos', 'codigo_postal' => '62000']);

    $sucursal = $this->estructura['sucursal']->id;
    $area = Departamento::factory()->create(['nombre' => 'Operaciones']);
    // El jefe inmediato sale del organigrama: Cajera reporta a Gerente de sucursal.
    $gerente = Puesto::factory()->create(['nombre' => 'Gerente de sucursal']);
    $this->jefe = Colaborador::factory()->create(['name' => 'Luis', 'apellidos' => 'Garay Pérez', 'sucursal_principal_id' => $sucursal, 'puesto_id' => $gerente->id, 'estatus' => EstadoUsuario::Activo]);
    $this->trabajador = Colaborador::factory()->create([
        'name' => 'Adriana', 'apellidos' => 'Cortés Lima', 'numero_empleado' => 'EMP-4521',
        'sucursal_principal_id' => $sucursal, 'departamento_id' => $area->id,
        'puesto_id' => Puesto::factory()->create(['nombre' => 'Cajera', 'puesto_superior_id' => $gerente->id])->id,
        'fecha_ingreso' => '2024-03-01', 'estatus' => EstadoUsuario::Activo,
    ]);
    $this->testigoA = Colaborador::factory()->create(['name' => 'María', 'apellidos' => 'López Ruiz', 'sucursal_principal_id' => $sucursal, 'puesto_id' => Puesto::factory()->create(['nombre' => 'Subgerente'])->id, 'estatus' => EstadoUsuario::Activo]);
    $this->testigoB = Colaborador::factory()->create(['name' => 'Juan', 'apellidos' => 'Pérez Gómez', 'sucursal_principal_id' => $sucursal, 'puesto_id' => Puesto::factory()->create(['nombre' => 'Gestor'])->id, 'estatus' => EstadoUsuario::Activo]);
    $this->testigoC = Colaborador::factory()->create(['name' => 'Sofía', 'apellidos' => 'Ramos Vega', 'sucursal_principal_id' => $sucursal, 'estatus' => EstadoUsuario::Activo]);
});

/** Captura completa del acta (lo que envía el formulario por pasos). */
function acDatos(array $circunstanciada = [], array $extra = []): array
{
    return [
        'tipo' => 'administrativa',
        'fecha' => now()->toDateString(),
        'hora' => '14:35',
        'lugar' => 'Cuernavaca, Morelos',
        'hechos' => "1. El arqueo de caja arrojó un faltante de \$1,250.00.\n\n2. No se localizó comprobante que justificara la diferencia.",
        'circunstanciada' => array_replace_recursive([
            'disposiciones' => null,
            'incidencia' => ['tipo' => 'irregularidad_bienes', 'fecha_desde' => now()->subDays(2)->toDateString(), 'hora_desde' => '09:00', 'hora_hasta' => '09:30', 'lugar_hechos' => 'Área de cajas'],
            'responsable' => ['manifestacion' => null],
            'jefe' => ['participa' => false],
            'testigos_hechos' => [],
            'testigos_asistencia' => [],
            'trabajador' => ['manifesto' => true, 'manifestacion' => 'Desconozco el motivo del faltante.', 'elementos' => null],
        ], $circunstanciada),
        ...$extra,
    ];
}

function acLevantar(object $prueba, array $datos): ActaAdministrativa
{
    $prueba->actingAs($prueba->rh)->post(route('rh.actas.store', $prueba->trabajador), $datos)->assertSessionHasNoErrors()->assertRedirect();

    return ActaAdministrativa::query()->latest('id')->firstOrFail();
}

function acHtml(ActaAdministrativa $acta, bool $borrador = false): string
{
    return app(ActaPdfService::class)->html($acta->refresh(), borrador: $borrador);
}

test('el acta nace circunstanciada: folio único, resumen por tipo de incidencia y carácter sugerido desde PEOPLE', function () {
    $acta = acLevantar($this, acDatos());
    $otra = acLevantar($this, acDatos());

    expect($acta->formato)->toBe(ActaService::FORMATO_CIRCUNSTANCIADA)
        ->and($acta->estado)->toBe(EstadoActa::Borrador)
        ->and($acta->folio)->toStartWith('ACT-')
        ->and($otra->folio)->not->toBe($acta->folio)
        ->and($acta->motivo)->toBe('Irregularidad relacionada con bienes de la empresa')
        ->and($acta->circunstanciada['responsable']['caracter'])->toBe('recursos_humanos')
        ->and($acta->circunstanciada['responsable']['nombre'])->toBe('Rocío Hernández Paz');

    $this->actingAs($this->rh)->get(route('rh.actas.show', $acta))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/Actas/Show')
            ->where('acta.formato', 'circunstanciada')
            ->where('acta.jefe_sugerido.id', $this->jefe->id)
            ->has('catalogos.incidencias', 6)
            ->where('puedeGestionar', true));

    expect(acHtml($acta))->toContain('Folio')->toContain($acta->folio);
});

test('precarga: empresa, centro, domicilio y datos del trabajador salen de PEOPLE; sin instrucciones de llenado', function () {
    $acta = acLevantar($this, acDatos());
    $html = acHtml($acta);

    expect($html)
        ->toContain('MR LANA SA DE CV')->toContain('Cuernavaca')->toContain('Av. Morelos 123')->toContain('C.P. 62000')
        ->toContain('Adriana Cortés Lima')->toContain('EMP-4521')->toContain('Cajera')->toContain('Operaciones')
        ->toContain('1 de marzo de 2024')->toContain('Luis Garay Pérez')
        ->toContain('de las 09:00 a las 09:30 horas')
        ->not->toContain('INSTRUCCI')->not->toContain('____')->not->toContain('VISTA PREVIA');

    expect(acHtml($acta, borrador: true))->toContain('VISTA PREVIA · BORRADOR')->not->toContain('INSTRUCCI');
});

test('testigos de hechos: 0, 1 o 2; nunca más de 2', function () {
    $sin = acLevantar($this, acDatos());
    expect(acHtml($sin))->toContain('No se registraron testigos de hechos.');

    $uno = acLevantar($this, acDatos(['testigos_hechos' => [['colaborador_id' => $this->testigoA->id, 'relacion' => 'presencio_directamente', 'manifestacion' => 'Vi el conteo del arqueo.']]]));
    expect(acHtml($uno))->toContain('María López Ruiz')->toContain('Vi el conteo del arqueo.')->toContain('Testigo de hechos 1')
        ->not->toContain('Testigo de hechos 2');

    $dos = acLevantar($this, acDatos(['testigos_hechos' => [
        ['colaborador_id' => $this->testigoA->id, 'relacion' => 'presencio_directamente'],
        ['colaborador_id' => $this->testigoB->id, 'relacion' => 'conocimiento_posterior'],
    ]]));
    expect(acHtml($dos))->toContain('Juan Pérez Gómez')->toContain('Testigo de hechos 2');

    $this->actingAs($this->rh)->post(route('rh.actas.store', $this->trabajador), acDatos(['testigos_hechos' => [
        ['colaborador_id' => $this->testigoA->id, 'relacion' => 'otra'],
        ['colaborador_id' => $this->testigoB->id, 'relacion' => 'otra'],
        ['colaborador_id' => $this->testigoC->id, 'relacion' => 'otra'],
    ]]))->assertSessionHasErrors('circunstanciada.testigos_hechos');
});

test('testigos de asistencia: separados de los de hechos, con su firma', function () {
    $acta = acLevantar($this, acDatos([
        'testigos_hechos' => [['colaborador_id' => $this->testigoA->id, 'relacion' => 'presencio_directamente']],
        'testigos_asistencia' => [['colaborador_id' => $this->testigoB->id], ['colaborador_id' => $this->testigoC->id]],
    ]));

    expect($acta->circunstanciada['testigos_asistencia'])->toHaveCount(2)
        ->and($acta->circunstanciada['testigos_asistencia'][0]['puesto'])->toBe('Gestor');
    expect(acHtml($acta))->toContain('Testigos de asistencia')->toContain('Testigo de asistencia 2')->toContain('Sofía Ramos Vega');
});

test('no se duplican personas: ni el trabajador como testigo, ni la misma persona con dos caracteres', function () {
    $this->actingAs($this->rh)->post(route('rh.actas.store', $this->trabajador), acDatos([
        'testigos_hechos' => [['colaborador_id' => $this->trabajador->id, 'relacion' => 'otra']],
    ]))->assertSessionHasErrors('circunstanciada.testigos_hechos.0.colaborador_id');

    $this->actingAs($this->rh)->post(route('rh.actas.store', $this->trabajador), acDatos([
        'testigos_hechos' => [['colaborador_id' => $this->testigoA->id, 'relacion' => 'otra'], ['colaborador_id' => $this->testigoA->id, 'relacion' => 'otra']],
    ]))->assertSessionHasErrors('circunstanciada.testigos_hechos.0.colaborador_id');

    $this->actingAs($this->rh)->post(route('rh.actas.store', $this->trabajador), acDatos([
        'testigos_hechos' => [['colaborador_id' => $this->testigoA->id, 'relacion' => 'otra']],
        'testigos_asistencia' => [['colaborador_id' => $this->testigoA->id]],
    ]))->assertSessionHasErrors('circunstanciada.testigos_asistencia.0.colaborador_id');

    $this->actingAs($this->rh)->post(route('rh.actas.store', $this->trabajador), acDatos([
        'jefe' => ['participa' => true, 'colaborador_id' => $this->jefe->id],
        'testigos_asistencia' => [['colaborador_id' => $this->jefe->id]],
    ]))->assertSessionHasErrors('circunstanciada.testigos_asistencia.0.colaborador_id');

    $inactivo = Colaborador::factory()->create(['estatus' => EstadoUsuario::Inactivo]);
    $this->actingAs($this->rh)->post(route('rh.actas.store', $this->trabajador), acDatos([
        'testigos_asistencia' => [['colaborador_id' => $inactivo->id]],
    ]))->assertSessionHasErrors('circunstanciada.testigos_asistencia.0.colaborador_id');

    expect(ActaAdministrativa::query()->count())->toBe(0);
});

test('jefe que no participa no se imprime; si participa, con su manifestación y firma', function () {
    $sin = acLevantar($this, acDatos());
    expect(acHtml($sin))->not->toContain('Jefe inmediato o superior jerárquico');

    $con = acLevantar($this, acDatos(['jefe' => ['participa' => true, 'colaborador_id' => $this->jefe->id, 'manifestacion' => 'Recibí el reporte de arqueo.']]));
    expect(acHtml($con))->toContain('Jefe inmediato o superior jerárquico')->toContain('Recibí el reporte de arqueo.');
});

test('negativa a manifestar: frase neutral, nunca aceptación ni culpa', function () {
    $acta = acLevantar($this, acDatos(['trabajador' => ['manifesto' => false, 'manifestacion' => 'texto que no debe guardarse']]));

    expect($acta->circunstanciada['trabajador']['manifestacion'])->toBeNull();
    expect(acHtml($acta))->toContain('no deseaba formular manifestación')
        ->not->toContain('texto que no debe guardarse')->not->toContain('aceptó')->not->toContain('culpable');
});

test('anexos: sin anexos lo dice; con varios, cada uno se relaciona tal como se guardó', function () {
    $acta = acLevantar($this, acDatos());
    expect(acHtml($acta))->toContain('No se incorporaron anexos a la presente acta.');

    foreach ([['Reporte de arqueo', '13/10/2026 · ARQ-0451', 'Documenta el faltante'], ['Corte del sistema', '13/10/2026', 'Saldo esperado al cierre']] as [$desc, $fecha, $relacion]) {
        $this->actingAs($this->rh)->post(route('rh.actas.anexos.store', $acta), [
            'archivo' => UploadedFile::fake()->create('evidencia.pdf', 20, 'application/pdf'),
            'descripcion' => $desc, 'fecha_identificacion' => $fecha, 'relacion_hechos' => $relacion,
        ])->assertSessionHasNoErrors();
    }

    expect(acHtml($acta))->toContain('Reporte de arqueo')->toContain('ARQ-0451')->toContain('Documenta el faltante')
        ->toContain('Corte del sistema')->toContain('Saldo esperado al cierre')
        ->not->toContain('No se incorporaron anexos');
});

test('vista previa = PDF real sin guardar documento; incompleta explica qué falta', function () {
    $incompleta = acLevantar($this, acDatos(['trabajador' => ['manifesto' => null]]));
    $this->actingAs($this->rh)->get(route('rh.actas.vista-previa', $incompleta))->assertStatus(409);

    $acta = acLevantar($this, acDatos());
    $this->actingAs($this->rh)->get(route('rh.actas.vista-previa', $acta))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect(GeneratedDocument::query()->count())->toBe(0)
        ->and($acta->refresh()->estado)->toBe(EstadoActa::Borrador)
        ->and($acta->circunstanciada['control'])->toBeNull();
});

test('el acta definitiva se congela: documento en el expediente, control documental y ya no se edita ni se le agregan anexos', function () {
    $acta = acLevantar($this, acDatos());

    $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), ['clave' => 'acta_administrativa', 'proceso' => 'acta'])
        ->assertSuccessful();

    $acta->refresh();
    expect($acta->generated_document_id)->not->toBeNull()
        ->and($acta->estado)->toBe(EstadoActa::Generada)
        ->and($acta->circunstanciada['control']['integrado_por'])->toBe('Rocío Hernández Paz')
        ->and($acta->circunstanciada['cierre']['hora'])->not->toBeNull()
        ->and(GeneratedDocument::query()->count())->toBe(1);

    $this->actingAs($this->rh)->put(route('rh.actas.update', $acta), ['hechos' => 'otro'])->assertSessionHasErrors('acta');
    $this->actingAs($this->rh)->post(route('rh.actas.anexos.store', $acta), ['archivo' => UploadedFile::fake()->create('tarde.pdf', 20, 'application/pdf')])
        ->assertSessionHasErrors('archivo');
    expect($acta->refresh()->hechos)->toContain('arqueo');
});

test('negativa a firmar y entrega de copia quedan registradas sin implicar aceptación', function () {
    $acta = acLevantar($this, acDatos());
    $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), ['clave' => 'acta_administrativa', 'proceso' => 'acta'])->assertSuccessful();

    $this->actingAs($this->rh)->post(route('rh.actas.negativa-firma', $acta), ['motivo' => 'Se le leyó el acta y no quiso firmar.'])->assertSessionHasNoErrors();
    $this->actingAs($this->rh)->post(route('rh.actas.entrega', $acta), ['estado' => 'no_posible'])->assertSessionHasErrors('motivo');
    $this->actingAs($this->rh)->post(route('rh.actas.entrega', $acta), ['estado' => 'entregada'])->assertSessionHasNoErrors();

    $acta->refresh();
    expect($acta->negativa_firma)->toBeTrue()
        ->and($acta->circunstanciada['entrega']['estado'])->toBe('entregada')
        ->and(collect($acta->seguimiento)->pluck('nota')->implode(' '))->toContain('Negativa a firmar')->toContain('Se entregó copia');
});

test('acta cerrada: ya no se genera el formato; el trabajador implicado no la ve', function () {
    $acta = acLevantar($this, acDatos());
    $acta->update(['estado' => EstadoActa::Cerrada]);

    $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), ['clave' => 'acta_administrativa', 'proceso' => 'acta'])
        ->assertUnprocessable();

    $implicado = User::factory()->create(['colaborador_id' => $this->trabajador->id]);
    $implicado->assignRole('colaborador');

    $this->actingAs($implicado)->get(route('rh.actas.show', $acta))->assertForbidden();
    $this->actingAs($implicado)->get(route('rh.actas.index'))->assertForbidden();
});

test('el período de los hechos se redacta en lenguaje natural', function () {
    expect(ActaService::periodoEnTexto('2026-10-13', null))->toBe('13 de octubre de 2026')
        ->and(ActaService::periodoEnTexto('2026-10-06', '2026-10-08'))->toBe('del 6 al 8 de octubre de 2026')
        ->and(ActaService::periodoEnTexto('2026-09-30', '2026-10-02'))->toBe('del 30 de septiembre al 2 de octubre de 2026')
        ->and(ActaService::horarioEnTexto('09:10', null))->toBe('09:10 horas');
});
