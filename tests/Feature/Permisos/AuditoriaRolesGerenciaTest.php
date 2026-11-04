<?php

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\TipoTarea;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\NotificacionEntrega;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\TareaRh;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Tareas\TareaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Auditoría de roles (2026-11-04): gerente, gerente_sucursal, regional y
| Dirección Comercial. Cada prueba cubre un hallazgo (P1-1 … P3).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();

    $estructura = clEstructura();
    $this->sucursal = $estructura['sucursal'];
    $this->otraSucursal = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id]);
    $this->puestoGerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal']);
    $this->puestoGestor = Puesto::factory()->create(['nombre' => 'Gestor']);
    $this->base = ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id];
    $this->gerente = clUsuario('gerente_sucursal', [...$this->base, 'puesto_id' => $this->puestoGerente->id]);
});

function argDocumento(Colaborador $persona, array $atributos = []): GeneratedDocument
{
    return GeneratedDocument::query()->create([
        'colaborador_id' => $persona->id,
        'titulo' => 'Documento de prueba',
        'original_name' => 'prueba.pdf',
        'generated_name' => 'prueba.pdf',
        'disk' => 'nas',
        'path' => 'documentos/prueba-'.Str::random(6).'.pdf',
        'mime' => 'application/pdf',
        'checksum' => Str::random(12),
        'estado_flujo' => EstadoFlujoDocumento::Generado,
        ...$atributos,
    ]);
}

function argMigracion(string $archivo): void
{
    $migracion = require database_path("migrations/{$archivo}.php");
    $migracion->up();
}

// ── P1-1 ─────────────────────────────────────────────────────────────

test('el gerente no ve recibos de nómina, finiquitos ni contratos de su gente (detalle y listado)', function () {
    $persona = Colaborador::factory()->create($this->base);
    $recibo = argDocumento($persona, ['categoria' => CategoriaDocumento::NominaInterna, 'payload' => ['neto' => '5000.00']]);
    $finiquito = argDocumento($persona, ['categoria' => CategoriaDocumento::BajaFiniquito, 'clave_plantilla' => 'finiquito', 'payload' => ['neto' => '9000.00']]);
    $convenio = argDocumento($persona, ['categoria' => CategoriaDocumento::BajaFiniquito, 'clave_plantilla' => 'convenio_terminacion']);
    $contrato = argDocumento($persona, ['categoria' => CategoriaDocumento::Contratos]);
    $renuncia = argDocumento($persona, ['categoria' => CategoriaDocumento::BajaFiniquito, 'clave_plantilla' => 'carta_renuncia']);

    Sanctum::actingAs($this->gerente);

    foreach ([$recibo, $finiquito, $convenio, $contrato] as $documento) {
        $respuesta = $this->getJson("/api/v1/rh/documentos-laborales/{$documento->id}")->assertForbidden();
        expect((string) $respuesta->json('message'))->not->toBe('')->and($respuesta->json('data.payload'))->toBeNull();
        $this->getJson("/api/v1/rh/documentos-laborales/{$documento->id}/descargar")->assertForbidden();
    }

    $this->getJson("/api/v1/rh/documentos-laborales/{$renuncia->id}")->assertOk();

    $ids = collect($this->getJson('/api/v1/rh/documentos-laborales')->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$renuncia->id]);
});

test('RH sigue viendo el recibo de nómina con su payload', function () {
    $persona = Colaborador::factory()->create($this->base);
    $recibo = argDocumento($persona, ['categoria' => CategoriaDocumento::NominaInterna, 'payload' => ['neto' => '5000.00']]);

    Sanctum::actingAs(clUsuario('rh_admin', $this->base));

    $this->getJson("/api/v1/rh/documentos-laborales/{$recibo->id}")->assertOk()->assertJsonPath('data.payload.neto', '5000.00');
    expect(collect($this->getJson('/api/v1/rh/documentos-laborales')->json('data'))->pluck('id'))->toContain($recibo->id);
});

// ── P1-3 ─────────────────────────────────────────────────────────────

test('ser gerente involucrado no abre candidatos de otra sucursal (web y API)', function () {
    $ajeno = Candidato::factory()->create(['sucursal_id' => $this->otraSucursal->id, 'gerente_involucrado_id' => $this->gerente->id]);
    $propio = Candidato::factory()->create(['sucursal_id' => $this->sucursal->id]);

    Sanctum::actingAs($this->gerente);
    $respuesta = $this->getJson("/api/v1/rh/candidatos/{$ajeno->id}")->assertForbidden();
    expect((string) $respuesta->json('message'))->toContain('fuera de tu alcance');
    $this->getJson("/api/v1/rh/candidatos/{$propio->id}")->assertOk();

    expect($this->gerente->can('view', $ajeno))->toBeFalse()
        ->and($this->gerente->can('view', $propio))->toBeTrue();
});

test('la bandeja no muestra pendientes asignados de candidatos fuera de alcance y el reconciliador los reasigna', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => $this->otraSucursal->id, 'estado' => EstadoCandidato::EntrevistaPendiente->value]);
    $tarea = TareaRh::query()->create([
        'tipo' => TipoTarea::CandidatoEntrevista, 'titulo' => 'Entrevista', 'prioridad' => 'alta',
        'relacionado_type' => $candidato->getMorphClass(), 'relacionado_id' => $candidato->id,
        'candidato_id' => $candidato->id, 'sucursal_id' => $this->otraSucursal->id,
        'asignado_user_id' => $this->gerente->id, 'clave' => 'prueba-ajena', 'clave_abierta' => 'prueba-ajena',
    ]);

    Sanctum::actingAs($this->gerente);
    expect(collect($this->getJson('/api/v1/tareas')->assertOk()->json('data'))->pluck('id'))->not->toContain($tarea->id);

    expect(app(CandidatoWorkflowService::class)->reconciliarPendientesAsignados())->toBe(1);
    expect($tarea->refresh()->resuelta_en)->not->toBeNull();
    expect(TareaRh::query()->whereNull('resuelta_en')->where('candidato_id', $candidato->id)->where('asignado_user_id', $this->gerente->id)->exists())->toBeFalse();
    // Segunda corrida: nada inválido que recalcular.
    expect(app(CandidatoWorkflowService::class)->reconciliarPendientesAsignados())->toBe(0);
});

// ── P1-4 ─────────────────────────────────────────────────────────────

test('la base de las gerencias en reclutamiento es solo ver y aprobar la entrevista; nadie conserva permisos retirados', function () {
    $base = RolesYPermisosSeeder::permisosBasePorRol();

    foreach (['gerente', 'gerente_sucursal', 'gerente_regional'] as $rol) {
        $candidatos = array_values(array_filter($base[$rol], fn (string $p): bool => str_starts_with($p, 'candidatos.')));
        expect($candidatos)->toEqualCanonicalizing($rol === 'gerente_regional'
            ? ['candidatos.ver', 'candidatos.ver_sucursal', 'candidatos.ver_region', 'candidatos.aprobar_entrevista', 'candidatos.solicitar_intervencion', 'candidatos.intervencion_decidir']
            : ['candidatos.ver', 'candidatos.ver_sucursal', 'candidatos.aprobar_entrevista', 'candidatos.solicitar_intervencion'])
            ->and($base[$rol])->toContain('contratos.subir_firmado')
            ->not->toContain('contratos.ver_documento')->not->toContain('contratos.descargar')->not->toContain('contratos.generar');
    }

    foreach ($base as $permisos) {
        expect(array_intersect($permisos, RolesYPermisosSeeder::retirados()))->toBe([]);
    }

    // El paso que el gerente ya no atiende tiene quién lo atienda: Reclutamiento.
    expect($base['rh_auxiliar'])->toContain('candidatos.revisar_psicometricas')->toContain('candidatos.revisar_socioeconomico');
    expect(config('notificaciones_catalogo.eventos.candidato_revision_psicometricas.defecto'))->not->toContain('gerente');
});

test('la migración quita a las gerencias solo los pasos de reclutamiento, respeta lo demás y lo audita', function () {
    $viejos = ['candidatos.aprobar', 'candidatos.evaluar', 'candidatos.rechazar', 'candidatos.revisar_psicometricas', 'candidatos.registrar_socioeconomico',
        'candidatos.revisar_socioeconomico', 'candidatos.validar_referencias', 'candidatos.cerrar_proceso', 'candidatos.adjuntar'];

    foreach (['gerente', 'gerente_sucursal', 'gerente_regional'] as $rol) {
        // Estado de producción + algo que un administrador le agregó a mano.
        Role::findByName($rol)->givePermissionTo([...$viejos, 'reportes_rh.datos_sensibles']);
    }
    Role::findByName('rh_admin')->givePermissionTo(['candidatos.evaluar', 'candidatos.rechazar']);
    Role::findByName('rh_auxiliar')->revokePermissionTo(['candidatos.revisar_psicometricas', 'candidatos.revisar_socioeconomico']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Aviso viejo de psicométricas sin leer para el gerente.
    $candidato = Candidato::factory()->create(['sucursal_id' => $this->sucursal->id, 'estado' => EstadoCandidato::RevisionPsicometricas->value]);
    $notificacionId = (string) Str::uuid();
    DB::table('notifications')->insert(['id' => $notificacionId, 'type' => 'prueba', 'notifiable_type' => $this->gerente->getMorphClass(), 'notifiable_id' => $this->gerente->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    NotificacionEntrega::query()->create(['clave' => sha1($notificacionId), 'user_id' => $this->gerente->id, 'categoria' => 'pendiente', 'evento' => 'candidato_revision_psicometricas',
        'related_type' => 'Candidato', 'related_id' => $candidato->id, 'notification_id' => $notificacionId, 'origen' => 'directo']);

    argMigracion('2026_11_13_500100_gerente_solo_aprueba_entrevista_y_sube_contrato');
    argMigracion('2026_11_13_500100_gerente_solo_aprueba_entrevista_y_sube_contrato');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['gerente', 'gerente_sucursal', 'gerente_regional'] as $rol) {
        $permisos = Role::findByName($rol)->permissions()->pluck('name')->all();
        expect(array_intersect($permisos, $viejos))->toBe([])
            ->and($permisos)->toContain('candidatos.aprobar_entrevista')->toContain('reportes_rh.datos_sensibles')->toContain('contratos.subir_firmado');
    }

    expect(Role::findByName('rh_admin')->hasPermissionTo('candidatos.evaluar'))->toBeFalse()
        ->and(Role::findByName('rh_admin')->hasPermissionTo('candidatos.revisar_psicometricas'))->toBeTrue()
        ->and(Role::findByName('rh_auxiliar')->hasPermissionTo('candidatos.revisar_psicometricas'))->toBeTrue();
    expect(Activity::query()->where('event', 'permisos_rol_ajustados')->count())->toBeGreaterThanOrEqual(3);
    expect(DB::table('notifications')->where('id', $notificacionId)->value('read_at'))->not->toBeNull();

    // El gerente ya no puede revisar psicométricas: el backend lo rechaza.
    expect(fn () => app(CandidatoWorkflowService::class)->revisarPsicometricas($candidato, $this->gerente->refresh(), true, null))
        ->toThrow(AuthorizationException::class);
});

// ── P1-2 ─────────────────────────────────────────────────────────────

test('la migración devuelve a Dirección Comercial y al gerente los permisos base que les faltaban', function () {
    $faltantesDireccion = ['candidatos.aprobar_entrevista', 'agenda.ver', 'agenda.ver_todos', 'agenda.registrar_resultado', 'agenda.ver_datos_candidato'];
    $faltantesGerente = ['actas.ver', 'actas.crear', 'actas.descargar', 'actas.ver_sucursal'];
    Role::findByName('director_comercial')->revokePermissionTo($faltantesDireccion);
    Role::findByName('gerente')->revokePermissionTo($faltantesGerente);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    argMigracion('2026_11_13_500000_otorga_permisos_base_faltantes_a_direccion_comercial_y_gerente');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::findByName('director_comercial')->permissions()->pluck('name')->all())->toContain(...$faltantesDireccion)
        ->and(Role::findByName('gerente')->permissions()->pluck('name')->all())->toContain(...$faltantesGerente);
});

test('sin drift: tras sembrar y correr las migraciones de datos, cada rol sembrado tiene exactamente su base', function () {
    argMigracion('2026_11_13_500000_otorga_permisos_base_faltantes_a_direccion_comercial_y_gerente');
    argMigracion('2026_11_13_500100_gerente_solo_aprueba_entrevista_y_sube_contrato');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (RolesYPermisosSeeder::permisosBasePorRol() as $rol => $permisos) {
        $enBase = Role::findByName($rol)->permissions()->pluck('name')->all();

        expect(array_values(array_diff($permisos, $enBase)))->toBe([], "A {$rol} le faltan permisos del seeder")
            ->and(array_values(array_diff($enBase, $permisos)))->toBe([], "{$rol} tiene permisos fuera del seeder");
    }
});

// ── P2-1 ─────────────────────────────────────────────────────────────

test('el gerente solo restablece la contraseña de su gente; nunca de pares ni de RH, y el titular recibe aviso', function () {
    $gestor = clUsuario('colaborador', [...$this->base, 'puesto_id' => $this->puestoGestor->id, 'jefe_id' => $this->gerente->colaborador_id]);
    $par = clUsuario('gerente_sucursal', [...$this->base, 'puesto_id' => $this->puestoGerente->id]);
    $rh = clUsuario('rh_auxiliar', $this->base);

    Sanctum::actingAs($this->gerente);

    $this->postJson("/api/v1/rh/colaboradores/{$gestor->colaborador_id}/credenciales")->assertOk()->assertJsonStructure(['data' => ['usuario', 'contrasena']]);
    expect($gestor->refresh()->debe_cambiar_contrasena)->toBeTrue()
        ->and(NotificacionEntrega::query()->where('user_id', $gestor->id)->where('evento', 'cuenta_contrasena_restablecida')->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'credenciales_generadas')->where('subject_id', $gestor->id)->exists())->toBeTrue();

    foreach ([$par, $rh] as $objetivo) {
        $respuesta = $this->postJson("/api/v1/rh/colaboradores/{$objetivo->colaborador_id}/credenciales", ['password' => 'NuevaClave2026!x'])->assertForbidden();
        expect((string) $respuesta->json('message'))->not->toBe('');
    }

    expect(Hash::check('NuevaClave2026!x', (string) $par->refresh()->password))->toBeFalse();
});

test('RH restablece la contraseña de cualquier cuenta de su alcance', function () {
    $par = clUsuario('gerente_sucursal', [...$this->base, 'puesto_id' => $this->puestoGerente->id]);

    Sanctum::actingAs(clUsuario('rh_admin', $this->base));

    $this->postJson("/api/v1/rh/colaboradores/{$par->colaborador_id}/credenciales")->assertOk();
});

// ── P2-2 ─────────────────────────────────────────────────────────────

test('Dirección Comercial: la lista de colaboradores usa el mismo alcance que el detalle', function () {
    Colaborador::factory()->count(3)->create($this->base);
    $director = clUsuario('director_comercial', $this->base);

    Sanctum::actingAs($director);

    $ids = collect($this->getJson('/api/v1/rh/colaboradores')->assertOk()->json('data'))->pluck('id');

    foreach ($ids as $id) {
        if ($id !== $director->colaborador_id) {
            $this->getJson("/api/v1/rh/colaboradores/{$id}")->assertOk();
        }
    }

    expect($ids->reject(fn ($id) => $id === $director->colaborador_id))->toBeEmpty();
});

// ── P3 ───────────────────────────────────────────────────────────────

test('un 403 de la API nunca regresa sin mensaje', function () {
    Sanctum::actingAs(clUsuario('colaborador', $this->base));

    $respuesta = $this->getJson('/api/v1/rh/documentos-laborales')->assertForbidden();

    expect((string) $respuesta->json('message'))->toContain('pide acceso a RH');
});

test('los pasos que el gerente ya no atiende se asignan a Reclutamiento/RH, nunca quedan huérfanos', function () {
    $reclutadora = clUsuario('rh_auxiliar', $this->base);
    $workflow = app(CandidatoWorkflowService::class);

    foreach ([EstadoCandidato::RevisionPsicometricas, EstadoCandidato::SocioeconomicoPendiente] as $estado) {
        $candidato = Candidato::factory()->create(['sucursal_id' => $this->sucursal->id, 'estado' => $estado->value, 'gerente_involucrado_id' => $this->gerente->id]);
        $workflow->actualizarPendientes($candidato, $reclutadora);

        $tarea = TareaRh::query()->whereNull('resuelta_en')->where('candidato_id', $candidato->id)->firstOrFail();
        $destinatarios = app(TareaService::class)->destinatarios($tarea)->pluck('id');

        expect($tarea->asignado_user_id)->toBeNull()
            ->and($tarea->asignado_permiso)->not->toBeNull()
            ->and($destinatarios)->toContain($reclutadora->id)
            ->and($destinatarios)->not->toContain($this->gerente->id)
            ->and($workflow->responsableActual($candidato)['rol'])->not->toBe('Gerente de sucursal');
    }
});
