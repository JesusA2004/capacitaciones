<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\TipoBaja;
use App\Models\ActaAdministrativa;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Prestamo;
use App\Models\Puesto;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Administracion\PurgaPersonaService;
use App\Support\ReferenciasColaborador;
use App\Support\ReferenciasUsuario;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| PURGA INTEGRAL de una persona (solo super_admin, para registros erróneos o
| de prueba): borra FÍSICAMENTE la cuenta Y el expediente Y todo lo suyo,
| conserva los catálogos, los registros de terceros (con el actor en NULL) y
| los archivos del NAS. No es «Revocar acceso».
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    // Se conserva la instancia del disco falso: tras una visita HTTP el
    // facade puede re-resolver `Storage::disk('nas')` al disco real, así que
    // las aserciones del NAS van contra esta misma instancia.
    $this->nas = Storage::fake('nas');

    $this->super = clUsuario('super_admin');
    $this->rh = clUsuario('rh_admin');

    $this->sucursal = Sucursal::factory()->create();
    $this->departamento = Departamento::factory()->create();
    $this->puesto = Puesto::factory()->create();

    $this->persona = Colaborador::factory()->create([
        'numero_empleado' => '9999',
        'sucursal_principal_id' => $this->sucursal->id,
        'departamento_id' => $this->departamento->id,
        'puesto_id' => $this->puesto->id,
    ]);
    $this->cuenta = User::factory()->create(['username' => 'cuenta.prueba', 'colaborador_id' => $this->persona->id]);
    $this->cuenta->assignRole('colaborador');
});

/** Crea una persona con historial de negocio variado (lo que debe desaparecer). */
function plHistorialVariado(User $cuenta, Colaborador $persona, User $rh): void
{
    SolicitudInterna::factory()->create(['user_id' => $cuenta->id, 'colaborador_id' => $persona->id]);
    ActaAdministrativa::factory()->create(['colaborador_id' => $persona->id]);
    Prestamo::factory()->create(['colaborador_id' => $persona->id]);

    $cierre = CierreLaboral::query()->create([
        'colaborador_id' => $persona->id,
        'tipo_baja' => TipoBaja::Renuncia->value,
        'motivo' => 'Renuncia voluntaria.',
        'estado' => EstadoCierreLaboral::Iniciado->value,
        'fecha_efectiva' => now()->toDateString(),
        'iniciado_por' => $rh->id,
    ]);

    // Expediente de baja propio (RESTRICT a colaboradores y al cierre), con
    // documento y bitácora capturados por un tercero (RH): se elimina con la
    // persona y NO bloquea la purga.
    $bajaId = DB::table('bajas')->insertGetId([
        'folio' => 'BAJ-PURGA-1',
        'colaborador_id' => $persona->id,
        'cierre_laboral_id' => $cierre->id,
        'nombre_colaborador' => 'Persona de prueba',
        'estatus' => 'cerrada',
        'creado_por' => $rh->id,
        'solicitado_por' => $cuenta->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('baja_documentos')->insert([
        'baja_id' => $bajaId,
        'nombre' => 'Carta de renuncia',
        'nombre_original' => 'renuncia.pdf',
        'disco' => 'nas',
        'ruta' => 'bajas/renuncia.pdf',
        'cargado_por' => $rh->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('baja_bitacora')->insert([
        'baja_id' => $bajaId,
        'user_id' => $cuenta->id,
        'accion' => 'creada',
        'created_at' => now(),
    ]);

    DB::table('onboarding_procesos')->insert([
        'colaborador_id' => $persona->id,
        'estado' => 'en_proceso',
        'iniciado_en' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $responsivaId = DB::table('responsivas')->insertGetId([
        'colaborador_id' => $persona->id,
        'tipo' => 'bien_mueble',
        'lugar' => 'Cuernavaca',
        'fecha_entrega' => now()->toDateString(),
        'entregado_por' => $rh->id, // tercero: no debe bloquear
        'snapshot' => json_encode(['a' => 1]),
        'estado' => 'generada',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('responsiva_activos')->insert([
        'responsiva_id' => $responsivaId,
        'orden' => 1,
        'descripcion' => 'Laptop',
        'estado' => 'nuevo',
        'cantidad' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Prueba',
        'notifiable_type' => $cuenta->getMorphClass(),
        'notifiable_id' => $cuenta->id,
        'data' => json_encode(['x' => 1]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('los mapas de referencias coinciden con las llaves foráneas reales de las migraciones', function () {
    $reales = ['users' => [], 'colaboradores' => []];

    foreach (Schema::getTableListing(schemaQualified: false) as $tabla) {
        foreach (Schema::getForeignKeys($tabla) as $fk) {
            $destino = $fk['foreign_table'];

            if (! isset($reales[$destino])) {
                continue;
            }

            $accion = strtolower((string) $fk['on_delete']);
            $reales[$destino][] = [$tabla, $fk['columns'][0], $accion === 'cascade' ? 'cascade' : ($accion === 'set null' ? 'set_null' : 'restrict')];
        }
    }

    $ordenar = fn (array $l) => collect($l)->map(fn ($r) => implode('|', array_slice($r, 0, 3)))->sort()->values()->all();

    expect($ordenar(ReferenciasUsuario::COLUMNAS))->toBe($ordenar($reales['users']))
        ->and($ordenar(ReferenciasColaborador::COLUMNAS))->toBe($ordenar($reales['colaboradores']));
});

test('solo super_admin puede revisar y ejecutar la purga integral', function () {
    $this->actingAs($this->rh)->getJson(route('administracion.usuarios.purga.revisar', $this->cuenta))->assertForbidden();
    $this->actingAs($this->rh)
        ->delete(route('administracion.usuarios.purga.eliminar', $this->cuenta), ['frase' => PurgaPersonaService::FRASE_CONFIRMACION, 'identidad' => '9999'])
        ->assertForbidden();

    expect(User::withTrashed()->whereKey($this->cuenta->id)->exists())->toBeTrue();

    $this->actingAs($this->super)->getJson(route('administracion.usuarios.purga.revisar', $this->cuenta))
        ->assertOk()->assertJsonPath('data.puede', true)->assertJsonPath('data.frase', PurgaPersonaService::FRASE_CONFIRMACION);
});

test('exige la frase exacta y la identidad de la persona', function () {
    $this->actingAs($this->super)
        ->delete(route('administracion.usuarios.purga.eliminar', $this->cuenta), ['frase' => 'ELIMINAR', 'identidad' => '9999'])
        ->assertSessionHasErrors('frase');

    $this->actingAs($this->super)
        ->delete(route('administracion.usuarios.purga.eliminar', $this->cuenta), ['frase' => PurgaPersonaService::FRASE_CONFIRMACION, 'identidad' => 'quien.sea'])
        ->assertSessionHasErrors('identidad');

    expect(User::withTrashed()->whereKey($this->cuenta->id)->exists())->toBeTrue();
});

test('purga integral: borra físicamente cuenta y expediente y todo su historial; conserva catálogos, terceros y el NAS', function () {
    plHistorialVariado($this->cuenta, $this->persona, $this->rh);

    // Terceros y catálogos que deben SOBREVIVIR.
    $subordinado = Colaborador::factory()->create(['jefe_id' => $this->persona->id]);
    $vacante = Vacante::factory()->create(['colaborador_contratado_id' => $this->persona->id]);
    $otraPersona = Colaborador::factory()->create();
    $solicitudAjena = SolicitudInterna::factory()->create([
        'user_id' => $this->rh->id,
        'colaborador_id' => $otraPersona->id,
        'objetivo_colaborador_id' => $this->persona->id,
    ]);

    // Archivo en el NAS que NO debe tocarse.
    $this->nas->put('expedientes/9999/contrato.pdf', 'contenido');

    $actividadAntes = Activity::query()->count();

    $this->actingAs($this->super)
        ->delete(route('administracion.usuarios.purga.eliminar', $this->cuenta), ['frase' => PurgaPersonaService::FRASE_CONFIRMACION, 'identidad' => 'cuenta.prueba'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administracion.usuarios.index'));

    // (a) cuenta y persona FÍSICAMENTE ausentes (no solo soft-deleted).
    expect(User::withTrashed()->whereKey($this->cuenta->id)->exists())->toBeFalse()
        ->and(Colaborador::withTrashed()->whereKey($this->persona->id)->exists())->toBeFalse()
        ->and(DB::table('colaboradores')->where('id', $this->persona->id)->exists())->toBeFalse();

    // (b) todos los dependientes exclusivos eliminados.
    expect(DB::table('solicitudes_internas')->where('colaborador_id', $this->persona->id)->count())->toBe(0)
        ->and(DB::table('actas_administrativas')->where('colaborador_id', $this->persona->id)->count())->toBe(0)
        ->and(DB::table('prestamos')->where('colaborador_id', $this->persona->id)->count())->toBe(0)
        ->and(DB::table('cierres_laborales')->where('colaborador_id', $this->persona->id)->count())->toBe(0)
        ->and(DB::table('bajas')->where('colaborador_id', $this->persona->id)->count())->toBe(0)
        ->and(DB::table('baja_documentos')->count())->toBe(0)
        ->and(DB::table('baja_bitacora')->count())->toBe(0)
        ->and(DB::table('onboarding_procesos')->where('colaborador_id', $this->persona->id)->count())->toBe(0)
        ->and(DB::table('responsivas')->where('colaborador_id', $this->persona->id)->count())->toBe(0)
        ->and(DB::table('responsiva_activos')->count())->toBe(0)
        ->and(DB::table('notifications')->where('notifiable_id', $this->cuenta->id)->count())->toBe(0)
        ->and(DB::table('model_has_roles')->where('model_id', $this->cuenta->id)->where('model_type', $this->cuenta->getMorphClass())->count())->toBe(0);

    // (c) catálogos compartidos y registros de terceros INTACTOS (actor NULL).
    expect(Sucursal::whereKey($this->sucursal->id)->exists())->toBeTrue()
        ->and(Puesto::whereKey($this->puesto->id)->exists())->toBeTrue()
        ->and(Departamento::whereKey($this->departamento->id)->exists())->toBeTrue()
        ->and(User::whereKey($this->rh->id)->exists())->toBeTrue()
        ->and(Colaborador::whereKey($subordinado->id)->value('jefe_id'))->toBeNull()
        ->and(Vacante::whereKey($vacante->id)->value('colaborador_contratado_id'))->toBeNull()
        ->and(SolicitudInterna::whereKey($solicitudAjena->id)->value('objetivo_colaborador_id'))->toBeNull();

    // (d) el disco NAS no se tocó (los blobs quedan como respaldo histórico).
    expect($this->nas->exists('expedientes/9999/contrato.pdf'))->toBeTrue()
        ->and($this->nas->allFiles())->toBe(['expedientes/9999/contrato.pdf']);

    // No deja bitácora de la purga.
    expect(Activity::query()->count())->toBe($actividadAntes);
});

test('salvaguardas: no a sí mismo, sí a otro super_admin cuando quedan más de uno', function () {
    // (e) nadie puede purgarse a sí mismo.
    $this->actingAs($this->super)->getJson(route('administracion.usuarios.purga.revisar', $this->super))->assertForbidden();
    $this->actingAs($this->super)
        ->delete(route('administracion.usuarios.purga.eliminar', $this->super), ['frase' => PurgaPersonaService::FRASE_CONFIRMACION, 'identidad' => $this->super->username])
        ->assertForbidden();
    expect(User::withTrashed()->whereKey($this->super->id)->exists())->toBeTrue();

    // (f) con dos super_admin, se puede purgar a otro (no es el último).
    $otroSuper = clUsuario('super_admin');
    $this->actingAs($this->super)
        ->delete(route('administracion.usuarios.purga.eliminar', $otroSuper), ['frase' => PurgaPersonaService::FRASE_CONFIRMACION, 'identidad' => $otroSuper->username])
        ->assertSessionHasNoErrors();
    expect(User::withTrashed()->whereKey($otroSuper->id)->exists())->toBeFalse()
        ->and(User::role('super_admin')->count())->toBeGreaterThanOrEqual(1);

    // Guard del último super_admin (ya solo queda uno): el servicio lo impide.
    expect(fn () => app(PurgaPersonaService::class)->purgar($this->super, $this->super))
        ->toThrow(AuthorizationException::class);
});

test('el material de explicación de la inducción subido por la persona detiene la purga (contenido compartido)', function () {
    $moduloId = DB::table('onboarding_modulos')->insertGetId([
        'titulo' => 'Inducción Mr. Lana',
        'tipo' => 'institucional',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('induccion_materiales_explicacion')->insert([
        'onboarding_modulo_id' => $moduloId,
        'tipo' => 'audio',
        'activo' => true,
        'subido_por' => $this->cuenta->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $revision = app(PurgaPersonaService::class)->revisar($this->cuenta, $this->super);

    expect($revision['puede'])->toBeFalse()
        ->and(implode(' ', $revision['bloqueos']))->toContain('materiales de explicación de la inducción');

    expect(fn () => app(PurgaPersonaService::class)->purgar($this->cuenta, $this->super))
        ->toThrow(ValidationException::class);
    expect(User::query()->whereKey($this->cuenta->id)->exists())->toBeTrue()
        ->and(DB::table('induccion_materiales_explicacion')->count())->toBe(1);
});

test('haber actuado en la baja de OTRA persona detiene la purga (auditoría ajena, FK restrict)', function () {
    $otraPersona = Colaborador::factory()->create();
    $bajaId = DB::table('bajas')->insertGetId([
        'folio' => 'BAJ-AJENA-1',
        'colaborador_id' => $otraPersona->id,
        'nombre_colaborador' => 'Otra persona',
        'estatus' => 'cerrada',
        'creado_por' => $this->rh->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('baja_bitacora')->insert([
        'baja_id' => $bajaId,
        'user_id' => $this->cuenta->id,
        'accion' => 'validada',
        'created_at' => now(),
    ]);

    $revision = app(PurgaPersonaService::class)->revisar($this->cuenta, $this->super);

    expect($revision['puede'])->toBeFalse()
        ->and(implode(' ', $revision['bloqueos']))->toContain('bitácora de bajas de otras personas');

    expect(fn () => app(PurgaPersonaService::class)->purgar($this->cuenta, $this->super))
        ->toThrow(ValidationException::class);
    expect(DB::table('baja_bitacora')->where('baja_id', $bajaId)->count())->toBe(1);
});

test('un super_admin en papelera se puede purgar si queda otro super_admin vigente', function () {
    $enPapelera = clUsuario('super_admin');
    $enPapelera->delete();

    app(PurgaPersonaService::class)->purgar($enPapelera, $this->super);

    expect(User::withTrashed()->whereKey($enPapelera->id)->exists())->toBeFalse()
        ->and(User::role('super_admin')->whereKey($this->super->id)->exists())->toBeTrue();
});
