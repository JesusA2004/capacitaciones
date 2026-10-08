<?php

use App\Enums\FamiliaAdministrativa;
use App\Enums\MotorPdf;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DisenoAdministrativoService;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\Solicitudes\ComprobanteSolicitudService;
use App\Services\Solicitudes\SolicitudesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Storage::fake('local');
    Notification::fake();
    $estructura = clEstructura();
    $this->jefe = User::factory()->create();
    $this->jefe->assignRole('jefe_directo');
    $this->persona = Colaborador::factory()->create([
        'name' => 'Ana', 'apellidos' => 'López Ruiz',
        'jefe_id' => $this->jefe->colaborador_id,
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'fecha_ingreso' => now()->subYears(2),
    ]);
    $this->colaborador = User::factory()->create(['colaborador_id' => $this->persona->id]);
    $this->colaborador->assignRole('colaborador');
    $this->rh = clUsuario('rh_admin');
});

function permisoValido(array $extra = []): array
{
    return [
        'tipo' => 'permiso',
        'permiso_tipo' => 'salir_temprano',
        'fecha_inicio' => now()->addDays(3)->toDateString(),
        'hora_salida' => '15:00',
        'permiso_goce' => 'especial',
        'permiso_causal' => 'cumpleanos',
        'observaciones' => 'Comida familiar',
        ...$extra,
    ];
}

test('el catálogo solo ofrece permisos, vacaciones y préstamos (y el permiso trae exactamente sus opciones oficiales)', function () {
    $tipos = collect(app(SolicitudesService::class)->tiposConFormulario(autoservicio: true));

    expect($tipos->where('en_catalogo', true)->pluck('clave')->sort()->values()->all())->toBe(['permiso', 'prestamo', 'vacaciones'])
        ->and($tipos->pluck('clave')->all())->not->toContain('incapacidad', 'solicitud_general', 'permiso_con_goce', 'constancia_laboral');

    $campos = collect($tipos->firstWhere('clave', 'permiso')['campos'])->keyBy('name');

    expect(array_column($campos['permiso_tipo']['opciones'], 'value'))->toBe(['faltar', 'salir_temprano', 'llegar_tarde'])
        ->and(array_column($campos['permiso_goce']['opciones'], 'value'))->toBe(['con_goce', 'sin_goce', 'especial'])
        ->and(array_column($campos['permiso_causal']['opciones'], 'value'))->toBe(['paternidad', 'luto', 'lactancia', 'cumpleanos', 'productividad'])
        ->and($campos['permiso_causal']['mostrar_si'])->toBe(['campo' => 'permiso_goce', 'valores' => ['especial']])
        ->and($campos['hora_salida']['mostrar_si']['valores'])->toBe(['salir_temprano'])
        ->and($campos['duracion_dias']['mostrar_si']['valores'])->toBe(['faltar']);
});

test('ya no se pueden crear tipos históricos de solicitud', function () {
    Sanctum::actingAs($this->colaborador);

    $this->postJson('/api/v1/solicitudes', ['tipo' => 'permiso_con_goce', 'motivo' => 'x', 'fecha_inicio' => now()->addDay()->toDateString(), 'duracion_dias' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('tipo');
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'incapacidad', 'motivo' => 'x', 'fecha_inicio' => now()->addDay()->toDateString(), 'duracion_dias' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('tipo');
});

test('el permiso pide solo lo necesario según su modalidad, goce y causal', function () {
    Sanctum::actingAs($this->colaborador);

    $this->postJson('/api/v1/solicitudes', permisoValido(['hora_salida' => null]))->assertJsonValidationErrors('hora_salida');
    $this->postJson('/api/v1/solicitudes', permisoValido(['permiso_causal' => null]))->assertJsonValidationErrors('permiso_causal');
    $this->postJson('/api/v1/solicitudes', permisoValido(['permiso_goce' => 'con_goce']))->assertJsonValidationErrors('permiso_causal');
    $this->postJson('/api/v1/solicitudes', permisoValido(['permiso_causal' => 'boda']))->assertJsonValidationErrors('permiso_causal');
    $this->postJson('/api/v1/solicitudes', permisoValido(['permiso_tipo' => 'faltar', 'hora_salida' => null]))->assertJsonValidationErrors('duracion_dias');

    $this->postJson('/api/v1/solicitudes', permisoValido(['permiso_tipo' => 'llegar_tarde', 'hora_salida' => '15:00', 'hora_entrada' => '11:00', 'permiso_goce' => 'sin_goce', 'permiso_causal' => null]))
        ->assertCreated();

    $permiso = SolicitudInterna::query()->latest('id')->firstOrFail();
    expect($permiso->permiso_tipo)->toBe('llegar_tarde')
        ->and(substr((string) $permiso->hora_entrada, 0, 5))->toBe('11:00')
        // La hora que no corresponde a la modalidad no se guarda.
        ->and($permiso->hora_salida)->toBeNull()
        ->and($permiso->permiso_causal)->toBeNull()
        ->and($permiso->fecha_inicio->equalTo($permiso->fecha_fin))->toBeTrue();
});

test('el formato de permiso NO se libera sin RH; tras la autorización de RH sí, lleno y con las casillas correctas', function () {
    Sanctum::actingAs($this->colaborador);
    $this->postJson('/api/v1/solicitudes', permisoValido())->assertCreated();
    $permiso = SolicitudInterna::query()->latest('id')->firstOrFail();

    // Recién creado: nadie lo puede imprimir.
    $this->getJson("/api/v1/solicitudes/{$permiso->id}/permiso-pdf")->assertForbidden();

    // Gerente/jefe da el visto bueno: todavía no está liberado.
    $this->actingAs($this->jefe)->post(route('rh.solicitudes.visto-bueno', $permiso), ['aprobado' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->jefe)->get(route('rh.solicitudes.permiso-pdf', $permiso))->assertForbidden();
    expect(GeneratedDocument::query()->where('clave_plantilla', ComprobanteSolicitudService::CLAVE_PERMISO)->count())->toBe(0);

    // RH autoriza: se genera el formato oficial y ya se puede ver/imprimir.
    $this->actingAs($this->rh)->post(route('rh.solicitudes.aprobar', $permiso))->assertSessionHasNoErrors();
    $permiso->refresh();
    expect($permiso->estado->value)->toBe('aprobada');

    $documento = GeneratedDocument::query()->where('clave_plantilla', ComprobanteSolicitudService::CLAVE_PERMISO)->firstOrFail();
    expect($documento->documentable_id)->toBe($permiso->id)
        ->and($documento->master_familia)->toBe('administrativo.permiso');

    $this->actingAs($this->jefe)->get(route('rh.solicitudes.permiso-pdf', $permiso))->assertOk();
    Sanctum::actingAs($this->colaborador);
    $this->getJson("/api/v1/solicitudes/{$permiso->id}/permiso-pdf")->assertOk();
    $this->getJson("/api/v1/solicitudes/{$permiso->id}")->assertJsonPath('permiso.autorizado_por_rh', true)
        ->assertJsonPath('permiso.causal_etiqueta', 'Cumpleaños');

    // Idempotente: abrirlo varias veces no duplica el documento.
    expect(GeneratedDocument::query()->where('clave_plantilla', ComprobanteSolicitudService::CLAVE_PERMISO)->count())->toBe(1);

    // El formato sale lleno con los datos del trabajador y la casilla correcta marcada.
    $familia = FamiliaAdministrativa::Permiso;
    $html = app(DocumentoAdministrativoService::class)->html($familia, app(DatosDocumentoAdministrativo::class)->permiso($permiso, $this->persona), app(DisenoAdministrativoService::class)->porDefecto($familia), MotorPdf::DomPdf);
    $marcada = fn (string $opcion) => preg_match('/<span class="chk">X<\/span><span class="opcion">'.preg_quote($opcion, '/').'/u', $html) === 1;

    expect($html)->toContain('SOLICITUD DE PERMISO')
        ->toContain('ANA LÓPEZ RUIZ')
        ->toContain('Cuernavaca')
        ->toContain('15:00')
        ->toContain('Comida familiar')
        ->toContain('Firma jefe inmediato')
        ->toContain('Firma Recursos Humanos')
        ->toContain('Firma colaborador')
        ->toContain('Autorizado en MR. LANA PEOPLE por')
        ->and($marcada('Permiso para salir temprano'))->toBeTrue()
        ->and($marcada('Permiso para faltar'))->toBeFalse()
        ->and($marcada('Permiso especial'))->toBeTrue()
        ->and($marcada('Con goce de sueldo'))->toBeFalse()
        ->and($marcada('Cumpleaños'))->toBeTrue()
        ->and($marcada('Luto'))->toBeFalse();
});
