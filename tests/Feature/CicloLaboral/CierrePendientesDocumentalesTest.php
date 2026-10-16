<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\PrioridadTarea;
use App\Enums\TipoBaja;
use App\Enums\TipoTarea;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Models\TareaRh;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use App\Services\Notificaciones\DestinoNotificacionService;
use App\Services\Tareas\TareaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| «Faltan datos para «Formato de renuncia…» del cierre de …»: el pendiente
| se abre cuando la renuncia no pudo generarse al iniciar la baja y debe
| desaparecer en cuanto deja de ser cierto (documento generado o datos
| completados), también para registros viejos (reconciliador). Además, ya
| firmada físicamente la renuncia generada, no se pide subirla ni registrar
| la negativa. Usa los originales reales de Jurídico (docs/formatos_fuente):
| sin ellos, se omite.
*/

function cpdColaborador(array $estructura, array $extra = []): Colaborador
{
    $puesto = Puesto::factory()->create(['nombre' => 'Gestor de cobranza', 'grupo_documental' => 'gestor']);

    return clExpedienteCompleto(Colaborador::factory()->create([
        'name' => 'Adrián',
        'apellidos' => 'Díaz Mena',
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_id' => $puesto->id,
        'genero' => 'masculino',
        'fecha_nacimiento' => '1990-05-10',
        'curp' => 'DIMA900510HHGZNR01',
        'rfc' => 'DIMA900510AB1',
        'nss' => '12345678901',
        'telefono' => '7731234567',
        'domicilio' => 'Calle Hidalgo 5, Col. Centro, Tula, Hidalgo',
        'domicilio_colonia' => 'Centro',
        'domicilio_municipio' => 'Tula de Allende',
        'domicilio_estado' => 'Hidalgo',
        'domicilio_cp' => '42800',
        'nacionalidad' => 'Mexicana',
        'estado_civil' => 'soltero',
        'lugar_nacimiento' => 'Tula, Hidalgo',
        'fecha_ingreso' => '2026-01-05',
        'sueldo_mensual' => 9000,
        ...$extra,
    ]));
}

/** Simula el pendiente que abre el inicio de la baja cuando la renuncia no se pudo generar. */
function cpdAbrirPendiente(CierreLaboral $cierre, string $motivo = 'datos'): TareaRh
{
    return app(TareaService::class)->abrir(TipoTarea::PlantillaFaltante, $cierre, [
        'titulo' => sprintf('Faltan datos para «Formato de renuncia MR. LANA» del cierre de %s', $cierre->colaborador->nombreCompleto()),
        'descripcion' => 'Formato de renuncia: faltan 1 dato(s) requerido(s): Código postal.',
        'prioridad' => PrioridadTarea::Alta,
        'colaborador' => $cierre->colaborador,
        'permiso' => 'plantillas_documentales.administrar',
        'accion' => 'cargar_plantilla',
        'datos' => ['clave' => 'carta_renuncia', 'motivo' => $motivo],
    ]);
}

function cpdBloqueos(Colaborador $colaborador): array
{
    return test()->getJson("/api/v1/rh/colaboradores/{$colaborador->id}/ciclo")->assertOk()->json('data.ciclo.bloqueos');
}

beforeEach(function () {
    if (! is_dir(base_path('docs/formatos_fuente')) || glob(base_path('docs/formatos_fuente').'/*.docx') === []) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente (no se versionan).');
    }

    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->motorFiel = dmMotorFielDePrueba();
    app(ImportadorFormatosJuridicosService::class)->importarCarpeta(base_path('docs/formatos_fuente'));

    $this->rh = clUsuario('rh_admin');
    $this->estructura = clEstructura();
    $this->estructura['sucursal']->update(['direccion' => 'Av. Juárez 10', 'ciudad' => 'Tula de Allende', 'estado' => 'Hidalgo', 'codigo_postal' => '42800']);
    Sanctum::actingAs($this->rh);
});

function cpdCierre(Colaborador $colaborador, int $rhId): CierreLaboral
{
    return CierreLaboral::query()->create([
        'colaborador_id' => $colaborador->id, 'tipo_baja' => TipoBaja::Renuncia, 'motivo' => 'Renuncia voluntaria',
        'fecha_efectiva' => now()->toDateString(), 'estado' => EstadoCierreLaboral::Iniciado, 'iniciado_por' => $rhId, 'autorizado_rh_en' => now(),
    ]);
}

test('faltan datos: solo pide lo que de verdad falta; al completar el C.P. y generar, el aviso desaparece', function () {
    // El formato de renuncia lleva el C.P. de la SUCURSAL (caso real de Tula).
    $this->estructura['sucursal']->update(['codigo_postal' => null]);
    $colaborador = cpdColaborador($this->estructura);
    $cierre = cpdCierre($colaborador, $this->rh->id);
    $tarea = cpdAbrirPendiente($cierre);

    // La verificación es correcta: solo reporta lo que está vacío (nada del colaborador, que está completo).
    $respuesta = $this->postJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/generar", ['clave' => 'carta_renuncia'])
        ->assertUnprocessable()->assertJsonPath('code', 'DATOS_FALTANTES');
    expect(array_map(fn (array $f): string => $f['fuente'].'.'.$f['columna'], $respuesta->json('faltantes')))->toBe(['sucursal.codigo_postal']);

    expect(cpdBloqueos($colaborador))->toContain($tarea->titulo)
        ->and($tarea->refresh()->resuelta_en)->toBeNull();

    $this->postJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/generar", ['clave' => 'carta_renuncia', 'completar' => ['sucursal.codigo_postal' => '42800']])
        ->assertCreated();

    expect($tarea->refresh()->resuelta_en)->not->toBeNull()
        ->and(cpdBloqueos($colaborador))->not->toContain($tarea->titulo);
});

test('registro viejo: el reconciliador resuelve el «Faltan datos» de una renuncia que ya se generó', function () {
    $colaborador = cpdColaborador($this->estructura);
    $cierre = cpdCierre($colaborador, $this->rh->id);
    $this->postJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/generar", ['clave' => 'carta_renuncia'])->assertCreated();

    // Pendiente que quedó abierto antes de la regla (sin «motivo» en datos).
    $tarea = cpdAbrirPendiente($cierre);
    $tarea->update(['datos' => ['clave' => 'carta_renuncia']]);

    // El aviso «Faltan datos…» que quedó en la campana.
    $aviso = new DatabaseNotification(['data' => ['type' => 'plantilla_faltante', 'related_type' => 'CierreLaboral', 'resource_id' => $cierre->id]]);
    expect(app(DestinoNotificacionService::class)->resolver($aviso, $this->rh)['atendida'])->toBeFalse();

    $this->artisan('notificaciones:reconciliar')->assertExitCode(0);

    expect($tarea->refresh()->resuelta_en)->not->toBeNull()
        ->and(app(DestinoNotificacionService::class)->resolver($aviso, $this->rh)['atendida'])->toBeTrue();
});

test('datos ya completos sin generar: el pendiente de datos se resuelve (la tarjeta ofrece «Generar»); uno de permiso no', function () {
    $colaborador = cpdColaborador($this->estructura);
    $cierre = cpdCierre($colaborador, $this->rh->id);
    $datos = cpdAbrirPendiente($cierre);

    expect(cpdBloqueos($colaborador))->not->toContain($datos->titulo)
        ->and($datos->refresh()->resuelta_en)->not->toBeNull();

    $permiso = cpdAbrirPendiente($cierre, 'permiso');
    cpdBloqueos($colaborador);

    expect($permiso->refresh()->resuelta_en)->toBeNull();
});

test('renuncia generada y firmada físicamente: sin «Registrar renuncia», sin subir archivo, sin negativa; historial y tarjeta con el mismo estado', function () {
    $colaborador = cpdColaborador($this->estructura);
    $cierre = cpdCierre($colaborador, $this->rh->id);
    $this->postJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}/generar", ['clave' => 'carta_renuncia'])->assertCreated();
    $documento = GeneratedDocument::query()->where('clave_plantilla', 'carta_renuncia')->firstOrFail();

    $acciones = fn (): array => array_column($this->getJson("/api/v1/rh/cierres/{$cierre->id}")->assertOk()->json('data.acciones_permitidas'), 'clave');
    $seccion = fn (): array => $this->getJson("/api/v1/rh/documentos-proceso/cierre/{$cierre->id}")->assertOk()->json('data');

    // La renuncia la genera el sistema: nunca se ofrece subirla.
    expect($acciones())->not->toContain('registrar_aviso');
    $this->post("/api/v1/rh/cierres/{$cierre->id}/aviso", ['archivo' => clArchivoPdf('renuncia.pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('archivo');

    // Antes de firmar: la negativa es la alternativa legal. Historial = tarjeta.
    expect(array_column($seccion()['acciones'], 'clave'))->toContain('registrar_negativa');
    $tarjeta = collect($seccion()['documentos'])->firstWhere('clave', 'carta_renuncia');
    $historial = collect($this->getJson("/api/v1/rh/colaboradores/{$colaborador->id}/ciclo")->json('data.documentos'))->firstWhere('id', $documento->id);
    expect($historial['estado_etiqueta'])->toBe($tarjeta['estado_etiqueta']);

    $flujo = app(FlujoDocumentalService::class);
    $flujo->marcarImpreso($documento, $this->rh);
    $flujo->registrarFirmaFisica($documento->refresh(), $this->rh, ['huella_registrada' => true]);

    $tarjeta = collect($seccion()['documentos'])->firstWhere('clave', 'carta_renuncia');
    $historial = collect($this->getJson("/api/v1/rh/colaboradores/{$colaborador->id}/ciclo")->json('data.documentos'))->firstWhere('id', $documento->id);

    expect(array_column($seccion()['acciones'], 'clave'))->not->toContain('registrar_negativa')
        ->and($acciones())->not->toContain('registrar_aviso')
        ->and($tarjeta['estado_etiqueta'])->toBe('Firmado')
        ->and($historial['estado_etiqueta'])->toBe('Firmado')
        ->and($historial['acciones'])->toBe([]);

    $this->postJson("/api/v1/rh/cierres/{$cierre->id}/procedimiento/negativa", ['documentos' => ['carta_renuncia']])->assertUnprocessable();
});
