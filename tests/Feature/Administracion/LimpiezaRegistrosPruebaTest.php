<?php

use App\Enums\EstadoVacante;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\IncorporacionInvitacion;
use App\Models\SeguimientoCandidato;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Administracion\LimpiezaRegistrosPruebaService;
use App\Support\LlavesForaneasEsquema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;

/*
| Limpieza definitiva de los dos candidatos ficticios (mantenimiento:
| limpiar-candidatos-prueba). Corre en la base aislada de pruebas (SQLite en
| memoria) y en un disco NAS falso: nunca contra producción.
*/

const CARPETA_TITO = 'candidatos/Tito Ivan Lopez - 5';
const CARPETA_VICTOR = 'candidatos/Victor Ricardo Arriola - 4';
const EXPEDIENTE_TITO = 'expedientes/MR LANA/Centro/EMP-0133 - Tito Ivan Lopez';
const EXPEDIENTE_VICTOR = 'expedientes/MR LANA/Centro/EMP-0135 - Victor Ricardo Arriola';

beforeEach(function () {
    Notification::fake();
    Storage::fake('nas');
    $this->nas = Storage::disk('nas');
    $this->rh = User::factory()->create(['colaborador_id' => null]);

    // --- Personas de prueba con los ID reales de producción ---------------
    $this->tito = Colaborador::factory()->create(['id' => 133, 'name' => 'Tito Iván', 'apellidos' => 'López', 'numero_empleado' => 'EMP-0133', 'expediente_storage_path' => EXPEDIENTE_TITO]);
    $this->victor = Colaborador::factory()->create(['id' => 135, 'name' => 'Víctor Ricardo', 'apellidos' => 'Arriola', 'numero_empleado' => 'EMP-0135', 'expediente_storage_path' => EXPEDIENTE_VICTOR]);
    $this->cuentaTito = User::factory()->create(['id' => 133, 'colaborador_id' => 133, 'email' => 'tito.prueba@example.test']);

    Candidato::factory()->create(['id' => 5, 'nombre' => 'Tito Iván', 'apellidos' => 'López', 'colaborador_id' => 133, 'carpeta_nas' => CARPETA_TITO, 'cv_disk' => 'nas', 'cv_path' => CARPETA_TITO.'/cv.pdf']);
    Candidato::factory()->create(['id' => 4, 'nombre' => 'Víctor Ricardo', 'apellidos' => 'Arriola', 'colaborador_id' => null, 'carpeta_nas' => CARPETA_VICTOR]);
    DB::table('colaboradores')->where('id', 135)->update(['candidato_id' => 4]);
    DB::table('colaboradores')->where('id', 133)->update(['candidato_id' => 5]);

    IncorporacionInvitacion::factory()->create(['id' => 4, 'candidato_id' => 5, 'colaborador_id' => 133, 'user_id' => 133, 'creado_por_id' => $this->rh->id]);
    IncorporacionInvitacion::factory()->create(['id' => 6, 'candidato_id' => 4, 'colaborador_id' => 135, 'creado_por_id' => $this->rh->id]);

    // Archivos: CV, evidencias, expediente, carta compromiso y un generado suelto.
    $this->nas->put(CARPETA_TITO.'/cv.pdf', 'cv');
    $this->nas->put(CARPETA_TITO.'/generado-sin-registro.pdf', 'x');
    foreach ([[5, CARPETA_TITO], [4, CARPETA_VICTOR]] as [$candidato, $carpeta]) {
        foreach ([1, 2, 3] as $n) {
            $ruta = $carpeta."/evidencias/evidencia-{$n}.pdf";
            $this->nas->put($ruta, 'evidencia');
            DB::table('candidato_evidencias')->insert(['candidato_id' => $candidato, 'tipo' => 'referencia', 'disk' => 'nas', 'path' => $ruta, 'original_name' => "e{$n}.pdf", 'subida_por' => $this->rh->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        SeguimientoCandidato::factory()->create(['candidato_id' => $candidato, 'registrado_por' => $this->rh->id]);
    }

    foreach ([[133, EXPEDIENTE_TITO, 133], [135, EXPEDIENTE_VICTOR, null]] as [$colaborador, $carpeta, $usuario]) {
        foreach (range(1, 8) as $n) {
            $ruta = $carpeta."/doc-{$n}.pdf";
            $this->nas->put($ruta, 'doc');
            EmployeeDocument::factory()->create(['colaborador_id' => $colaborador, 'user_id' => $usuario, 'disk' => 'nas', 'path' => $ruta, 'uploaded_by' => $this->rh->id]);
        }
    }

    $this->nas->put(EXPEDIENTE_TITO.'/carta-compromiso.pdf', 'carta');
    DB::table('cartas_compromiso')->insert(['colaborador_id' => 133, 'estado' => 'vigente', 'documentos' => '[]', 'fecha_limite' => now()->addWeek()->toDateString(), 'generada_en' => now(), 'disk' => 'nas', 'path' => EXPEDIENTE_TITO.'/carta-compromiso.pdf', 'created_at' => now(), 'updated_at' => now()]);

    // Plaza ocupada por Tito, tarea, bitácora, notificación a RH y token de la app.
    $this->vacante = Vacante::factory()->create(['colaborador_contratado_id' => 133, 'candidato_contratado_id' => 5, 'plazas_requeridas' => 1, 'plazas_cubiertas' => 1, 'plazas_disponibles' => 0, 'estado' => EstadoVacante::Cubierta->value, 'generada_automaticamente' => false]);
    DB::table('tareas_rh')->insert(['tipo' => 'revision', 'titulo' => 'Revisar a Tito', 'prioridad' => 'media', 'clave' => 'prueba-tito', 'colaborador_id' => 133, 'candidato_id' => 5, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('activity_log')->insert(['log_name' => 'auditoria', 'description' => 'colaborador_creado', 'properties' => json_encode(['colaborador_id' => 133]), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('notifications')->insert(['id' => 'aaaaaaaa-0000-0000-0000-000000000001', 'type' => 'aviso', 'notifiable_type' => $this->rh->getMorphClass(), 'notifiable_id' => $this->rh->id, 'data' => json_encode(['related_type' => 'Candidato', 'related_id' => 5, 'titulo' => 'Candidato por entrevistar']), 'created_at' => now(), 'updated_at' => now()]);
    $this->cuentaTito->createToken('app');

    // --- Testigos que NO se deben tocar ------------------------------------
    $this->otro = Colaborador::factory()->create(['numero_empleado' => 'EMP-0900', 'jefe_id' => 133]);
    $this->otraCuenta = User::factory()->create(['colaborador_id' => $this->otro->id]);
    $this->otroCandidato = Candidato::factory()->create(['nombre' => 'Real', 'apellidos' => 'Persona']);
    $this->otroDocumento = EmployeeDocument::factory()->create(['colaborador_id' => $this->otro->id, 'user_id' => $this->otraCuenta->id, 'disk' => 'nas', 'path' => 'expedientes/MR LANA/Centro/EMP-0900 - Real/doc.pdf']);
    $this->nas->put('expedientes/MR LANA/Centro/EMP-0900 - Real/doc.pdf', 'real');
    $this->nas->put('candidatos/Real Persona - 99/cv.pdf', 'real');

    $this->respaldo = tempnam(sys_get_temp_dir(), 'respaldo').'.sql';
    file_put_contents($this->respaldo, implode("\n", [
        '-- MariaDB dump 10.19  Distrib 11.4',
        '-- Host: localhost    Database: '.DB::getDatabaseName(),
        'CREATE TABLE `candidatos` (`id` bigint);',
        'CREATE TABLE `colaboradores` (`id` bigint);',
        'CREATE TABLE `users` (`id` bigint);',
        'CREATE TABLE `incorporacion_invitaciones` (`id` bigint);',
        'INSERT INTO `colaboradores` VALUES (133);',
        '-- Dump completed on 2026-11-03 18:00:00',
    ]));
});

afterEach(function () {
    @unlink($this->respaldo);
    File::deleteDirectory(storage_path('app/private/limpiezas'));
});

function ejecutarLimpieza(array $opciones = []): PendingCommand
{
    return test()->artisan('mantenimiento:limpiar-candidatos-prueba', $opciones);
}

function huellaPrueba(): array
{
    return [
        'candidatos' => DB::table('candidatos')->count(),
        'colaboradores' => DB::table('colaboradores')->count(),
        'users' => DB::table('users')->count(),
        'invitaciones' => DB::table('incorporacion_invitaciones')->count(),
        'documentos' => DB::table('employee_documents')->count(),
        'evidencias' => DB::table('candidato_evidencias')->count(),
        'archivos' => count(Storage::disk('nas')->allFiles()),
        'vacante' => DB::table('vacantes')->where('id', test()->vacante->id)->value('colaborador_contratado_id'),
        'tokens' => DB::table('personal_access_tokens')->count(),
    ];
}

test('el catálogo de llaves foráneas coincide exactamente con las migraciones', function () {
    $reales = [];

    foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table'") as $tabla) {
        foreach (DB::select(sprintf('PRAGMA foreign_key_list("%s")', $tabla->name)) as $llave) {
            $regla = ['CASCADE' => 'cascade', 'SET NULL' => 'set_null'][$llave->on_delete] ?? 'restrict';
            $reales[] = implode('|', [$tabla->name, $llave->from, $llave->table, $llave->to, $regla]);
        }
    }

    $catalogo = array_map(fn (array $l): string => implode('|', $l), LlavesForaneasEsquema::LLAVES);
    sort($reales);
    sort($catalogo);

    expect($catalogo)->toBe($reales);
});

test('la simulación muestra todo y no modifica nada', function () {
    $antes = huellaPrueba();

    ejecutarLimpieza(['--dry-run' => true])
        ->expectsOutputToContain('SIMULACIÓN')
        ->expectsOutputToContain('Simulación completa')
        ->assertSuccessful();

    expect(huellaPrueba())->toBe($antes);

    $plan = app(LimpiezaRegistrosPruebaService::class)->inventariar();
    expect($plan->errores)->toBe([])
        ->and($plan->idsDe('employee_documents'))->toHaveCount(16)
        ->and($plan->idsDe('candidato_evidencias'))->toHaveCount(6)
        ->and($plan->idsDe('cartas_compromiso'))->toHaveCount(1)
        ->and($plan->vacantes)->toBe([$this->vacante->id])
        ->and(collect($plan->archivos)->pluck('ruta'))->toContain(CARPETA_TITO.'/generado-sin-registro.pdf')
        ->and(collect($plan->archivos)->pluck('ruta'))->not->toContain('candidatos/Real Persona - 99/cv.pdf');
});

test('borra a las dos personas de BD y NAS, libera la vacante y no toca a nadie más', function () {
    ejecutarLimpieza(['--respaldo' => $this->respaldo, '--confirmar' => LimpiezaRegistrosPruebaService::FRASE_CONFIRMACION])
        ->expectsOutputToContain('Verificación final: no quedan')
        ->assertSuccessful();

    // Personas, cuenta, invitaciones y todo lo suyo: borrado físico.
    expect(DB::table('candidatos')->whereIn('id', [4, 5])->count())->toBe(0)
        ->and(DB::table('colaboradores')->whereIn('id', [133, 135])->count())->toBe(0)
        ->and(DB::table('users')->where('id', 133)->count())->toBe(0)
        ->and(DB::table('incorporacion_invitaciones')->whereIn('id', [4, 6])->count())->toBe(0)
        ->and(DB::table('employee_documents')->whereIn('colaborador_id', [133, 135])->count())->toBe(0)
        ->and(DB::table('candidato_evidencias')->count())->toBe(0)
        ->and(DB::table('seguimientos_candidato')->count())->toBe(0)
        ->and(DB::table('cartas_compromiso')->count())->toBe(0)
        ->and(DB::table('tareas_rh')->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(DB::table('notifications')->where('id', 'aaaaaaaa-0000-0000-0000-000000000001')->count())->toBe(0)
        ->and(DB::table('activity_log')->where('properties', 'like', '%"colaborador_id":133%')->count())->toBe(0);

    // Archivos y carpetas propias fuera del NAS; las generales siguen.
    expect($this->nas->allFiles(CARPETA_TITO))->toBe([])
        ->and($this->nas->allFiles(EXPEDIENTE_TITO))->toBe([])
        ->and($this->nas->allFiles(EXPEDIENTE_VICTOR))->toBe([])
        ->and($this->nas->directoryExists(CARPETA_TITO))->toBeFalse()
        ->and($this->nas->exists('candidatos/Real Persona - 99/cv.pdf'))->toBeTrue()
        ->and($this->nas->exists('expedientes/MR LANA/Centro/EMP-0900 - Real/doc.pdf'))->toBeTrue();

    // Copia recuperable en la cuarentena.
    $manifiestos = File::glob(storage_path('app/private/limpiezas/*/manifiesto.json'));
    expect($manifiestos)->toHaveCount(1);
    $raiz = dirname($manifiestos[0]);
    expect(File::exists($raiz.'/archivos/nas/'.CARPETA_TITO.'/cv.pdf'))->toBeTrue();

    // Vacante liberada (no borrada) y el subordinado sin jefe inexistente.
    $vacante = DB::table('vacantes')->where('id', $this->vacante->id)->first();
    expect($vacante)->not->toBeNull()
        ->and($vacante->colaborador_contratado_id)->toBeNull()
        ->and($vacante->candidato_contratado_id)->toBeNull()
        ->and((int) $vacante->plazas_cubiertas)->toBe(0)
        ->and($vacante->estado)->toBe(EstadoVacante::Abierta->value)
        ->and(DB::table('colaboradores')->where('id', $this->otro->id)->value('jefe_id'))->toBeNull();

    // Testigos intactos.
    expect(DB::table('colaboradores')->where('id', $this->otro->id)->exists())->toBeTrue()
        ->and(DB::table('users')->whereIn('id', [$this->otraCuenta->id, $this->rh->id])->count())->toBe(2)
        ->and(DB::table('candidatos')->where('id', $this->otroCandidato->id)->exists())->toBeTrue()
        ->and(DB::table('employee_documents')->where('id', $this->otroDocumento->id)->exists())->toBeTrue();
});

test('aborta sin tocar nada si un nombre no coincide', function () {
    DB::table('colaboradores')->where('id', 135)->update(['name' => 'Otra']);
    $antes = huellaPrueba();

    ejecutarLimpieza(['--respaldo' => $this->respaldo, '--confirmar' => LimpiezaRegistrosPruebaService::FRASE_CONFIRMACION])
        ->expectsOutputToContain('NO COINCIDE')
        ->assertFailed();

    expect(huellaPrueba())->toBe($antes);
});

test('exige respaldo verificado y la frase exacta', function () {
    $antes = huellaPrueba();

    ejecutarLimpieza(['--confirmar' => LimpiezaRegistrosPruebaService::FRASE_CONFIRMACION])->expectsOutputToContain('Falta --respaldo')->assertFailed();

    file_put_contents($this->respaldo, 'vacío');
    ejecutarLimpieza(['--respaldo' => $this->respaldo, '--confirmar' => LimpiezaRegistrosPruebaService::FRASE_CONFIRMACION])->expectsOutputToContain('Respaldo no válido')->assertFailed();

    expect(huellaPrueba())->toBe($antes);
});

test('rechaza una frase de confirmación incorrecta', function () {
    $antes = huellaPrueba();

    ejecutarLimpieza(['--respaldo' => $this->respaldo, '--confirmar' => 'si'])->expectsOutputToContain('Confirmación incorrecta')->assertFailed();

    expect(huellaPrueba())->toBe($antes);
});

test('aborta si un archivo también lo referencia otra persona', function () {
    EmployeeDocument::factory()->create(['colaborador_id' => $this->otro->id, 'user_id' => $this->otraCuenta->id, 'disk' => 'nas', 'path' => EXPEDIENTE_TITO.'/doc-1.pdf']);
    $antes = huellaPrueba();

    ejecutarLimpieza(['--dry-run' => true])->expectsOutputToContain('no es exclusivo')->assertFailed();

    expect(huellaPrueba())->toBe($antes);
});

test('aborta si la cuenta de prueba creó registros RESTRICT de terceros', function () {
    IncorporacionInvitacion::factory()->create(['creado_por_id' => 133, 'colaborador_id' => $this->otro->id]);

    ejecutarLimpieza(['--dry-run' => true])->expectsOutputToContain('de terceros dependen de la cuenta')->assertFailed();
});
