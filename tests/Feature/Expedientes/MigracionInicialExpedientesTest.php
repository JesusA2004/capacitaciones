<?php

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\ExpedienteHistorico;
use App\Models\MigracionExpedientes;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Expedientes\MigracionInicial\ExpedientesInitialMigrationService;
use App\Services\Expedientes\MigracionInicial\PlanificadorMigracion;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * Migración inicial (docs/MIGRACION_INICIAL_EXPEDIENTES.md). Storage::fake:
 * nunca toca el Synology real. Origen por defecto «sitio»: los expedientes
 * ya están en expedientes/Mr. Lana/{SUCURSAL}/{CARPETA}/.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
    Storage::fake('nas');
    Storage::fake('nas_legacy');
    Storage::fake('local');

    $empresa = Empresa::factory()->create(['nombre' => 'Mr. Lana']);
    $this->cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'empresa_id' => $empresa->id]);
    $this->atlacomulco = Sucursal::factory()->create(['nombre' => 'Atlacomulco', 'empresa_id' => $empresa->id]);
    Sucursal::factory()->create(['nombre' => 'Corporativo', 'empresa_id' => $empresa->id]);
    $this->departamento = Departamento::factory()->create(['nombre' => 'Ventas']);
    $this->puesto = Puesto::factory()->create(['nombre' => 'Gestor', 'departamento_id' => $this->departamento->id]);
    // Quien migra: la migración inicial es solo de super_admin (2026-10-20).
    $this->rh = clUsuario('super_admin');
    $this->servicio = app(ExpedientesInitialMigrationService::class);
});

/**
 * Encabezados de MR_LANA_PEOPLE_BASE_GENERAL_MIGRACION_FINAL.xlsx, en un
 * orden DISTINTO al real (el lector reconoce por nombre, no por posición).
 *
 * @param  list<array<string, string|null>>  $filas
 */
function miExcel(array $filas): string
{
    $libro = new Spreadsheet;
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('BASE_GENERAL');
    $encabezados = ['Clave', 'Puesto', 'Nombre completo', 'Nombre', 'Apellido paterno', 'Apellido materno', 'Departamento', 'Correo electrónico', 'Teléfono BD', 'RFC', 'CURP', 'NSS / Afiliación IMSS', 'Fecha de nacimiento', 'Sexo', 'Fecha de alta', 'Estatus laboral', 'Sucursal origen', 'Sucursal normalizada', 'Teléfono personal (contactos)', 'Condición médica crónica', 'Alergias', 'Contacto de emergencia', 'Parentesco', 'Teléfono emergencia', 'Dirección contacto emergencia', 'Match contacto', 'Score match', 'Migrar expediente NAS', 'Observaciones de calidad', 'Hoja origen', 'Fila origen', 'Empresa', 'Sucursal oficial'];
    $hoja->fromArray($encabezados, null, 'A1');

    foreach ($filas as $i => $f) {
        $hoja->fromArray(array_map(fn ($e) => $f[$e] ?? null, $encabezados), null, 'A'.($i + 2));
    }

    $libro->createSheet()->setTitle('CONTACTOS_SIN_MATCH')->fromArray([['Nombre'], ['Jesus Enrique Ocampo Perez']]);
    $ruta = tempnam(sys_get_temp_dir(), 'mig').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return $ruta;
}

/** @return array<string, string|null> */
function miFila(array $extra = []): array
{
    return [
        'Clave' => '130', 'Nombre' => 'José Alberto', 'Apellido paterno' => 'Carlos', 'Apellido materno' => 'Bueno',
        'Correo electrónico' => 'jose.carlos@mrlana.test', 'CURP' => 'CABA900101HMSRNL09', 'RFC' => null,
        'Fecha de nacimiento' => '01/01/1990', 'Fecha de alta' => '19/02/2024', 'Estatus laboral' => 'Alta',
        'Sucursal oficial' => 'CUERNAVACA', 'Sucursal origen' => 'CUERNAVACA 2', 'Empresa' => 'Mr. Lana', 'Departamento' => 'Ventas',
        'Puesto' => 'Gestor', 'Condición médica crónica' => 'Asma', 'Alergias' => 'Penicilina', 'Match contacto' => 'SI',
        'Contacto de emergencia' => 'MARIA BUENO', 'Parentesco' => 'Madre', 'Teléfono emergencia' => '7771234567', 'Hoja origen' => 'CUERNAVACA', 'Fila origen' => '12',
        ...$extra,
    ];
}

function miPdf(string $ruta, string $contenido = '%PDF-1.4 historico'): void
{
    Storage::disk('nas')->put($ruta, $contenido);
}

test('BD vacía: crea colaborador, vincula su PDF histórico único en sitio y genera su acceso', function () {
    miPdf('expedientes/Mr. Lana/CUERNAVACA/ALBERTO CARLOS BUENO 19-02-2024/José Alberto Carlos Bueno (1).pdf');
    $migracion = $this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh);

    expect(Colaborador::query()->where('curp', 'CABA900101HMSRNL09')->exists())->toBeFalse()
        ->and($migracion->totales['crear'])->toBe(1)
        // La carpeta trae solo «ALBERTO CARLOS BUENO» (alto) pero el PDF trae el nombre completo: exacto.
        ->and($migracion->planArray()['filas'][0]['nas']['tipo'])->toBe('exacto');

    $resultado = $this->servicio->aplicar($migracion, $this->rh);
    $colaborador = Colaborador::query()->where('curp', 'CABA900101HMSRNL09')->firstOrFail();

    expect($resultado['creados'])->toBe(1)
        ->and($resultado['pdfs_registrados'])->toBe(1)
        ->and($resultado['cuentas_creadas'])->toBe(1)
        ->and($colaborador->clave_legacy)->toBe('130')
        ->and($colaborador->numero_empleado)->toStartWith('EMP-')
        ->and($colaborador->estatus)->toBe(EstadoUsuario::Activo)
        ->and($colaborador->estatus_origen)->toBe('Alta')
        ->and($colaborador->expediente_storage_path)->toBe('expedientes/Mr. Lana/CUERNAVACA/ALBERTO CARLOS BUENO 19-02-2024')
        ->and($colaborador->datosMedicos?->alergias)->toBe('Penicilina')
        ->and($colaborador->expedientesHistoricos()->count())->toBe(1)
        // El PDF histórico NO es un documento del checklist.
        ->and($colaborador->documentos()->count())->toBe(0)
        ->and(User::query()->where('username', 'Jose Carlos')->value('email'))->toBe('jose.carlos@mrlana.test');

    // No se copió ni se renombró nada: el archivo sigue donde estaba.
    Storage::disk('nas')->assertExists('expedientes/Mr. Lana/CUERNAVACA/ALBERTO CARLOS BUENO 19-02-2024/José Alberto Carlos Bueno (1).pdf');
    expect(Storage::disk('nas')->allFiles('expedientes'))->toHaveCount(1);

    $credenciales = $this->servicio->credenciales($migracion->fresh());
    expect($credenciales[0]['usuario'])->toBe('Jose Carlos')
        ->and(strlen((string) $credenciales[0]['contrasena']))->toBe(8)
        ->and($credenciales[0]['puesto'])->toBe('Gestor');
});

test('reimportar el mismo Excel es idempotente', function () {
    miPdf('expedientes/Mr. Lana/CUERNAVACA/JOSE ALBERTO CARLOS BUENO/expediente.pdf');
    $excel = miExcel([miFila()]);

    $this->servicio->aplicar($this->servicio->analizar($excel, 'base.xlsx', $this->rh), $this->rh);
    $segunda = $this->servicio->analizar($excel, 'base.xlsx', $this->rh);

    expect($segunda->totales['sin_cambios'])->toBe(1);
    $this->servicio->aplicar($segunda, $this->rh);

    expect(Colaborador::query()->count())->toBe(2) // + la persona del usuario RH de la prueba
        ->and(ExpedienteHistorico::query()->count())->toBe(1)
        ->and(User::query()->where('username', 'Jose Carlos')->count())->toBe(1);
});

test('CURP repetida y Clave repetida: la CURP es conflicto, la Clave solo advertencia', function () {
    $plan = $this->servicio->analizar(miExcel([
        miFila(),
        miFila(['Nombre' => 'Otra', 'Apellido paterno' => 'Persona', 'Apellido materno' => 'Igual', 'Correo electrónico' => null]),
        miFila(['Nombre' => 'Ana', 'Apellido paterno' => 'Ruiz', 'Apellido materno' => 'Paz', 'CURP' => 'RUPA900101MMSZZN01', 'Correo electrónico' => null]),
    ]), 'base.xlsx', $this->rh)->planArray();

    expect($plan['filas'][0]['operacion'])->toBe('conflicto')
        ->and($plan['filas'][1]['operacion'])->toBe('conflicto')
        ->and($plan['filas'][2]['operacion'])->toBe('crear')
        ->and(implode(' ', $plan['filas'][2]['advertencias']))->toContain('Clave 130 está repetida');
});

test('sucursal: alias aceptado, desconocida o fuera de whitelist es conflicto, excluida se omite', function () {
    $sucursalesAntes = Sucursal::query()->count();
    $plan = $this->servicio->analizar(miExcel([
        miFila(['Sucursal oficial' => 'ATLACOMULC']),
        miFila(['CURP' => 'AAAA900101HMSRNL01', 'Nombre' => 'Uno', 'Correo electrónico' => null, 'Sucursal oficial' => 'PACHUCA']),
        miFila(['CURP' => 'BBBB900101HMSRNL02', 'Nombre' => 'Dos', 'Correo electrónico' => null, 'Sucursal oficial' => 'AGUASCALIENTES']),
    ]), 'base.xlsx', $this->rh)->planArray();

    expect($plan['filas'][0]['sucursal_id'])->toBe($this->atlacomulco->id)
        ->and($plan['filas'][1]['operacion'])->toBe('conflicto')
        ->and($plan['filas'][2]['operacion'])->toBe('omitir');
    expect(Sucursal::query()->count())->toBe($sucursalesAntes);
});

test('puesto inexistente es conflicto (no se crea)', function () {
    $plan = $this->servicio->analizar(miExcel([miFila(['Puesto' => 'Astronauta'])]), 'base.xlsx', $this->rh)->planArray();

    expect($plan['filas'][0]['operacion'])->toBe('conflicto')
        ->and(implode(' ', $plan['filas'][0]['motivos']))->toContain('PUESTO NO ENCONTRADO');
    expect(Puesto::query()->where('nombre', 'Astronauta')->exists())->toBeFalse();
});

test('estatus Reingreso o Incapacidad quedan activos con el valor original guardado', function () {
    $migracion = $this->servicio->analizar(miExcel([
        miFila(['Estatus laboral' => 'Reingreso']),
        miFila(['CURP' => 'RUPA900101MMSZZN01', 'Nombre' => 'Ana', 'Apellido paterno' => 'Ruiz', 'Apellido materno' => 'Paz', 'Correo electrónico' => null, 'Estatus laboral' => 'Incapacidad']),
    ]), 'base.xlsx', $this->rh);
    $this->servicio->aplicar($migracion, $this->rh);

    expect(Colaborador::query()->where('estatus_origen', 'Reingreso')->value('estatus'))->toBe(EstadoUsuario::Activo)
        ->and(Colaborador::query()->where('estatus_origen', 'Incapacidad')->value('estatus'))->toBe(EstadoUsuario::Activo);
});

test('carpeta ambigua (dos candidatas) queda en revisión manual y no se vincula sola', function () {
    miPdf('expedientes/Mr. Lana/CUERNAVACA/JOSE ALBERTO CARLOS BUENO/a.pdf');
    miPdf('expedientes/Mr. Lana/CUERNAVACA/ALBERTO CARLOS BUENO/b.pdf');
    $migracion = $this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh);

    expect($migracion->planArray()['filas'][0]['nas']['tipo'])->toBe('revision');
    $this->servicio->aplicar($migracion, $this->rh);

    expect(ExpedienteHistorico::query()->whereNotNull('colaborador_id')->count())->toBe(0);
});

test('expediente del NAS que no viene en el Excel se conserva como histórico (baja) sin inventar datos', function () {
    miPdf('expedientes/Mr. Lana/CUERNAVACA/MARIA LOPEZ HERNANDEZ/expediente.pdf');
    $migracion = $this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh);

    expect($migracion->planArray()['carpetas_sin_persona'][0]['accion'])->toBe('historico');
    $this->servicio->aplicar($migracion, $this->rh);

    $historico = Colaborador::query()->where('importado_de', 'nas_historico')->firstOrFail();
    expect($historico->estatus)->toBe(EstadoUsuario::Inactivo)
        ->and($historico->curp)->toBeNull()
        ->and($historico->correo_personal)->toBeNull()
        ->and($historico->user)->toBeNull()
        ->and($historico->expedientesHistoricos()->count())->toBe(1);
    Storage::disk('nas')->assertExists('expedientes/Mr. Lana/CUERNAVACA/MARIA LOPEZ HERNANDEZ/expediente.pdf');
});

test('sucursal excluida y no autorizada en el NAS: no se recorren / no se vinculan', function () {
    miPdf('expedientes/Mr. Lana/AGUASCALIENTES/PEDRO PEREZ PEREZ/a.pdf');
    miPdf('expedientes/Mr. Lana/MR. LANA/JUAN JUAREZ JUAREZ/b.pdf');
    $plan = $this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh)->planArray();

    expect($plan['carpetas_sin_persona'])->toBe([])
        ->and(array_column($plan['sucursales_no_autorizadas_nas'], 'carpeta'))->toBe(['MR. LANA']);
});

test('el dry-run no modifica BD ni NAS', function () {
    miPdf('expedientes/Mr. Lana/CUERNAVACA/JOSE ALBERTO CARLOS BUENO/a.pdf');
    $antes = Storage::disk('nas')->allFiles();

    $this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh);

    expect(Colaborador::query()->count())->toBe(1)
        ->and(ExpedienteHistorico::query()->count())->toBe(0)
        ->and(Storage::disk('nas')->allFiles())->toBe($antes);
});

test('origen legacy: copia verificada; destino igual = duplicado, distinto = conflicto; fallo de copia no deja fila', function () {
    config(['expedientes.migracion_inicial.origen' => 'legacy', 'expedientes.migracion_inicial.ruta_origen' => 'RH/Martha/EXPEDIENTES DIGITALES']);
    Storage::disk('nas_legacy')->put('RH/Martha/EXPEDIENTES DIGITALES/CUERNAVACA/JOSE ALBERTO CARLOS BUENO/exp.pdf', '%PDF uno');

    $migracion = $this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh);
    $resultado = $this->servicio->aplicar($migracion, $this->rh);
    $historico = ExpedienteHistorico::query()->firstOrFail();

    expect($resultado['pdfs_copiados'])->toBe(1)
        ->and($historico->path)->toEndWith('/Historico/Expediente historico unificado.pdf')
        ->and($historico->hash)->toBe(hash('sha256', '%PDF uno'));
    Storage::disk('nas_legacy')->assertExists('RH/Martha/EXPEDIENTES DIGITALES/CUERNAVACA/JOSE ALBERTO CARLOS BUENO/exp.pdf');

    // Destino con contenido distinto y otro origen → conflicto, nunca se sobrescribe.
    Storage::disk('nas_legacy')->put('RH/Martha/EXPEDIENTES DIGITALES/CUERNAVACA/JOSE ALBERTO CARLOS BUENO/exp2.pdf', '%PDF dos');
    Storage::disk('nas')->put(dirname($historico->path).'/Expediente historico unificado (2).pdf', '%PDF otro');
    $r2 = $this->servicio->aplicar($this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh), $this->rh);

    expect($r2['pdfs_conflicto'])->toBe(1)
        ->and(Storage::disk('nas')->get(dirname($historico->path).'/Expediente historico unificado (2).pdf'))->toBe('%PDF otro');
});

test('solo quien tiene expedientes.migrar ve y usa la migración', function () {
    $colaborador = clUsuario('colaborador');

    $this->actingAs($colaborador)->get('/rh/expedientes/migracion-inicial')->assertForbidden();
    $this->actingAs($colaborador)->post('/rh/expedientes/migracion-inicial/analizar')->assertForbidden();
    $this->actingAs(clUsuario('rh_admin'))->get('/rh/expedientes/migracion-inicial')->assertForbidden();
    $this->actingAs($this->rh)->get('/rh/expedientes/migracion-inicial')->assertOk();

    $migracion = MigracionExpedientes::query()->create(['archivo_nombre' => 'x.xlsx', 'archivo_hash' => 'x', 'estado' => 'analizado', 'plan' => '{}']);
    $this->actingAs($colaborador)->post("/rh/expedientes/migracion-inicial/{$migracion->id}/aplicar", ['modo' => 'copiar', 'confirmacion' => true])->assertForbidden();
    $this->actingAs($colaborador)->get("/rh/expedientes/migracion-inicial/{$migracion->id}/credenciales")->assertForbidden();
});

test('cuentas: JESUS ENRIQUE + ARIZMENDI → «Jesus Arizmendi»; sin correo también obtiene cuenta; colisiones 2 y 3', function () {
    $migracion = $this->servicio->analizar(miExcel([
        miFila(['Nombre' => 'JESUS ENRIQUE', 'Apellido paterno' => 'ARIZMENDI', 'Apellido materno' => 'PEREZ', 'CURP' => 'AIPJ900101HMSRRS01', 'Correo electrónico' => 'jesus@mrlana.test']),
        miFila(['Nombre' => 'JESUS', 'Apellido paterno' => 'ARIZMENDI', 'Apellido materno' => 'LOPEZ', 'CURP' => 'AILJ900101HMSRRS02', 'Correo electrónico' => null, 'Clave' => '131']),
        miFila(['Nombre' => 'JESÚS MARÍA', 'Apellido paterno' => 'ARIZMENDI', 'Apellido materno' => 'RUIZ', 'CURP' => 'AIRJ900101HMSRRS03', 'Correo electrónico' => null, 'Clave' => '132']),
    ]), 'base.xlsx', $this->rh);

    $plan = $migracion->planArray();
    expect(array_column(array_column($plan['filas'], 'cuenta'), 'usuario'))->toBe(['Jesus Arizmendi', 'Jesus Arizmendi2', 'Jesus Arizmendi3'])
        ->and(array_column(array_column($plan['filas'], 'cuenta'), 'estado'))->toBe(['nueva', 'colision_resuelta', 'colision_resuelta'])
        // Dry-run: no reserva ni escribe cuentas.
        ->and(User::query()->where('username', 'like', 'Jesus Arizmendi%')->count())->toBe(0);

    $resultado = $this->servicio->aplicar($migracion, $this->rh);

    expect($resultado['cuentas_creadas'])->toBe(3);

    $sinCorreo = User::query()->where('username', 'Jesus Arizmendi2')->firstOrFail();
    $conCorreo = User::query()->where('username', 'Jesus Arizmendi')->firstOrFail();

    expect($sinCorreo->email)->toBeNull()
        ->and($conCorreo->email)->toBe('jesus@mrlana.test')
        // Mismo tipo de cuenta, con o sin correo.
        ->and($sinCorreo->debe_cambiar_contrasena)->toBeTrue()
        ->and($conCorreo->debe_cambiar_contrasena)->toBeTrue()
        ->and($sinCorreo->hasRole('colaborador'))->toBeTrue()
        ->and($conCorreo->hasRole('colaborador'))->toBeTrue();

    // users.password es solo el hash; la contraseña en claro solo vive en la lista cifrada.
    $credenciales = collect($this->servicio->credenciales($migracion->fresh()))->keyBy('usuario');
    $temporal = (string) $credenciales['Jesus Arizmendi2']['contrasena'];

    expect($sinCorreo->password)->not->toBe($temporal)
        ->and(Hash::check($temporal, $sinCorreo->password))->toBeTrue()
        ->and(Storage::disk('local')->get((string) $migracion->fresh()->credenciales_path))->not->toContain($temporal);

    // Y con ella entra por usuario (sin correo) y se le pide cambiarla.
    $this->post(route('logout'));
    $this->post(route('login.store'), ['username' => ' jesus arizmendi2 ', 'password' => " {$temporal} "]);
    $this->assertAuthenticatedAs($sinCorreo);
    $this->get(route('dashboard'))->assertRedirect(route('contrasena-temporal.edit'));
});

test('reimportar conserva el username ya asignado y no cambia la contraseña', function () {
    $this->servicio->aplicar($this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh), $this->rh);
    $cuenta = User::query()->where('username', 'Jose Carlos')->firstOrFail();
    $hash = $cuenta->password;

    // Aunque cambie el nombre en el Excel, la cuenta existente conserva su usuario.
    $segunda = $this->servicio->analizar(miExcel([miFila(['Nombre' => 'Alberto'])]), 'base.xlsx', $this->rh);
    expect($segunda->planArray()['filas'][0]['cuenta'])->toBe(['usuario' => 'Jose Carlos', 'estado' => 'existente']);

    $resultado = $this->servicio->aplicar($segunda, $this->rh);

    expect($resultado['cuentas_creadas'])->toBe(0)
        ->and($resultado['cuentas_existentes'])->toBe(1)
        ->and($cuenta->fresh()->username)->toBe('Jose Carlos')
        ->and($cuenta->fresh()->password)->toBe($hash)
        ->and(User::query()->count())->toBe(2);
});

test('una baja no obtiene cuenta nueva; si reingresa reutiliza el colaborador y obtiene cuenta', function () {
    $migracion = $this->servicio->analizar(miExcel([miFila(['Estatus laboral' => 'Baja'])]), 'base.xlsx', $this->rh);

    expect($migracion->planArray()['filas'][0]['cuenta']['estado'])->toBe('baja');
    $this->servicio->aplicar($migracion, $this->rh);

    $colaborador = Colaborador::query()->where('curp', 'CABA900101HMSRNL09')->firstOrFail();
    expect($colaborador->estatus)->toBe(EstadoUsuario::Inactivo)
        ->and(User::query()->where('colaborador_id', $colaborador->id)->exists())->toBeFalse();

    $this->servicio->aplicar($this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh), $this->rh);

    expect(Colaborador::query()->where('curp', 'CABA900101HMSRNL09')->count())->toBe(1)
        ->and($colaborador->fresh()->estatus)->toBe(EstadoUsuario::Activo)
        ->and(User::query()->where('colaborador_id', $colaborador->id)->value('username'))->toBe('Jose Carlos');
});

test('reingreso con cuenta previa bloqueada: conserva el username y le devuelve el acceso', function () {
    $this->servicio->aplicar($this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh), $this->rh);
    $cuenta = User::query()->where('username', 'Jose Carlos')->firstOrFail();
    Colaborador::query()->where('id', $cuenta->colaborador_id)->update(['estatus' => EstadoUsuario::Inactivo->value]);
    $cuenta->forceFill(['acceso_bloqueado_en' => now(), 'acceso_bloqueado_motivo' => 'Baja'])->save();

    $resultado = $this->servicio->aplicar($this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh), $this->rh);

    expect($resultado['cuentas_reactivadas'])->toBe(1)
        ->and($cuenta->fresh()->acceso_bloqueado_en)->toBeNull()
        ->and($cuenta->fresh()->username)->toBe('Jose Carlos');
});

test('departamento/puesto/empresa: solo match exacto; desconocido es conflicto y no se crea', function () {
    $antes = [Departamento::query()->count(), Puesto::query()->count()];
    $plan = $this->servicio->analizar(miExcel([
        miFila(['Departamento' => 'Astronáutica']),
        miFila(['CURP' => 'AAAA900101HMSRNL01', 'Nombre' => 'Uno', 'Correo electrónico' => null, 'Puesto' => 'Gestora']),
        miFila(['CURP' => 'BBBB900101HMSRNL02', 'Nombre' => 'Dos', 'Correo electrónico' => null, 'Empresa' => 'Otra SA']),
        miFila(['CURP' => 'CCCC900101HMSRNL03', 'Nombre' => 'Tres', 'Correo electrónico' => null, 'Departamento' => null]),
    ]), 'base.xlsx', $this->rh)->planArray();

    expect(array_column($plan['filas'], 'operacion'))->toBe(['conflicto', 'conflicto', 'conflicto', 'conflicto'])
        ->and(implode(' ', $plan['filas'][0]['motivos']))->toContain('DEPARTAMENTO NO ENCONTRADO')
        ->and(implode(' ', $plan['filas'][1]['motivos']))->toContain('PUESTO NO ENCONTRADO')
        ->and(implode(' ', $plan['filas'][2]['motivos']))->toContain('EMPRESA NO ENCONTRADA')
        ->and(implode(' ', $plan['filas'][3]['motivos']))->toContain('Falta «Departamento»')
        ->and($plan['filas'][0]['cuenta']['estado'])->toBe('no_aplica');
    expect([Departamento::query()->count(), Puesto::query()->count()])->toBe($antes);
});

test('«Sucursal oficial» manda sobre «Sucursal origen»; Match contacto = NO no importa el contacto', function () {
    $fila = $this->servicio->analizar(miExcel([miFila(['Sucursal origen' => 'PACHUCA', 'Match contacto' => 'NO'])]), 'base.xlsx', $this->rh)->planArray()['filas'][0];

    expect($fila['sucursal_id'])->toBe($this->cuernavaca->id)
        ->and($fila['departamento_nombre'])->toBe('Ventas')
        ->and($fila['datos']['contacto_emergencia_nombre'])->toBeNull()
        ->and($fila['datos']['departamento_id'])->toBe($this->departamento->id);
});

test('las contraseñas temporales no aparecen en el log, el manifiesto ni el plan', function () {
    Log::spy();
    $migracion = $this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh);
    $this->servicio->aplicar($migracion, $this->rh);
    $temporal = (string) $this->servicio->credenciales($migracion->fresh())[0]['contrasena'];

    expect(strlen($temporal))->toBe(8)
        ->and(Storage::disk('local')->get((string) $migracion->fresh()->manifiesto_path))->not->toContain($temporal)
        ->and((string) $migracion->fresh()->getRawOriginal('plan'))->not->toContain($temporal);

    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $nivel) {
        Log::shouldNotHaveReceived($nivel, fn ($mensaje, $contexto = []) => str_contains($mensaje.json_encode($contexto), $temporal));
    }
});

test('la lista de credenciales solo la descarga quien tiene expedientes.migrar', function () {
    $migracion = $this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh);
    $this->servicio->aplicar($migracion, $this->rh);

    $this->actingAs(clUsuario('colaborador'))->get("/rh/expedientes/migracion-inicial/{$migracion->id}/credenciales")->assertForbidden();
    $csv = $this->actingAs($this->rh)->get("/rh/expedientes/migracion-inicial/{$migracion->id}/credenciales")->assertOk()->streamedContent();

    expect($csv)->toContain('Usuario,"Contraseña temporal",Estado')
        ->and($csv)->toContain('Jose Carlos');
});

/*
 * Match de carpetas NAS con nombres reales (Córdoba / Cuernavaca). La
 * normalización es SOLO para comparar: nombres, carpetas y PDFs conservan
 * su forma original.
 */
function miPersona(string $nombre, string $paterno, string $materno, string $sucursal, int $n): array
{
    return miFila([
        'Clave' => (string) (500 + $n), 'Nombre' => $nombre, 'Apellido paterno' => $paterno, 'Apellido materno' => $materno,
        'Nombre completo' => "{$nombre} {$paterno} {$materno}", 'CURP' => null, 'Correo electrónico' => "persona{$n}@mrlana.test",
        'Sucursal oficial' => $sucursal, 'Sucursal origen' => $sucursal,
    ]);
}

/** @return array<string, array<string, mixed>> fila por nombre completo */
function miPlanPorNombre(MigracionExpedientes $migracion): array
{
    return collect($migracion->planArray()['filas'])->keyBy('nombre_completo')->all();
}

function miCordoba(Sucursal $referencia): Sucursal
{
    return Sucursal::factory()->create(['nombre' => 'Córdoba', 'empresa_id' => $referencia->empresa_id]);
}

test('nombres reales con acentos, fechas y fechas mal escritas son match exacto aunque la carpeta no tenga PDFs', function () {
    miCordoba($this->cuernavaca);
    $nas = Storage::disk('nas');
    $nas->makeDirectory('expedientes/Mr. Lana/CÓRDOBA/José Alfredo Jiménez Flores 06-09-2022');
    // Acento en forma descompuesta (NFD), como lo guardan algunos clientes SMB/macOS.
    miPdf("expedientes/Mr. Lana/CÓRDOBA/Berenice Jua\u{0301}rez Temoxtle 08-12-21/INE.pdf");
    $nas->makeDirectory('expedientes/Mr. Lana/CUERNAVACA/CESAR EMMANUEL HERNANDEZ ORTIZ 23 - 09-206');
    $nas->makeDirectory('expedientes/Mr. Lana/CUERNAVACA/GUADALUPE MODESTO OCAMPO 05-01-2026');

    $migracion = $this->servicio->analizar(miExcel([
        miPersona('Jose Alfredo', 'Jimenez', 'Flores', 'Córdoba', 1),
        miPersona('Berenice', 'Juarez', 'Temoxtle', 'CÓRDOBA', 2),
        miPersona('Cesar Emmanuel', 'Hernandez', 'Ortiz', 'Cuernavaca', 3),
        miPersona('Guadalupe', 'Modesto', 'Ocampo', 'Cuernavaca', 4),
    ]), 'base.xlsx', $this->rh);
    $filas = miPlanPorNombre($migracion);

    expect($filas['Jose Alfredo Jimenez Flores']['nas']['tipo'])->toBe('exacto')
        ->and($filas['Jose Alfredo Jimenez Flores']['nas']['carpeta'])->toBe('José Alfredo Jiménez Flores 06-09-2022')
        ->and($filas['Jose Alfredo Jimenez Flores']['nas']['pdfs'])->toBe([])
        ->and($filas['Berenice Juarez Temoxtle']['nas']['tipo'])->toBe('exacto')
        ->and($filas['Cesar Emmanuel Hernandez Ortiz']['nas']['tipo'])->toBe('exacto')
        ->and($filas['Cesar Emmanuel Hernandez Ortiz']['nas']['diagnostico']['nombre_normalizado'])->toBe('CESAR EMMANUEL HERNANDEZ ORTIZ')
        ->and($filas['Guadalupe Modesto Ocampo']['nas']['tipo'])->toBe('exacto')
        ->and($migracion->totales['match_exacto'])->toBe(4)
        ->and($migracion->planArray()['carpetas_sin_persona'])->toBe([]);

    // Analizar no modificó nada: el nombre real se conserva con su acento.
    $nas->assertExists('expedientes/Mr. Lana/CÓRDOBA/José Alfredo Jiménez Flores 06-09-2022');
    expect(ExpedienteHistorico::query()->count())->toBe(0);

    // Al aplicar, la carpeta sin PDFs igual queda como expediente del colaborador.
    $this->servicio->aplicar($migracion, $this->rh);
    expect(Colaborador::query()->where('correo_personal', 'persona1@mrlana.test')->value('expediente_storage_path'))
        ->toBe('expedientes/Mr. Lana/CÓRDOBA/José Alfredo Jiménez Flores 06-09-2022');
});

test('la carpeta «EMP-… - Nombre» que crea el sistema no compite con la carpeta histórica (causa de 0 matches)', function () {
    $cordoba = miCordoba($this->cuernavaca);
    $existente = Colaborador::factory()->create(['name' => 'Miguel Angel', 'apellidos' => 'Trejo Peralta', 'sucursal_principal_id' => $cordoba->id, 'correo_personal' => 'persona7@mrlana.test', 'numero_empleado' => 'EMP-0007']);
    Storage::disk('nas')->makeDirectory('expedientes/Mr. Lana/Cordoba/EMP-0007 - Miguel Angel Trejo Peralta');
    Storage::disk('nas')->makeDirectory('expedientes/Mr. Lana/Cordoba/SIN-NUMERO-22 - Otra Persona Distinta');
    miPdf('expedientes/Mr. Lana/CÓRDOBA/Miguel Angel Trejo Peralta 10-02-2025/expediente.pdf');

    $plan = $this->servicio->analizar(miExcel([miPersona('Miguel Angel', 'Trejo', 'Peralta', 'Córdoba', 7)]), 'base.xlsx', $this->rh)->planArray();
    $fila = $plan['filas'][0];

    expect($fila['colaborador_id'])->toBe($existente->id)
        ->and($fila['nas']['tipo'])->toBe('exacto')
        ->and($fila['nas']['carpeta'])->toBe('Miguel Angel Trejo Peralta 10-02-2025')
        ->and($fila['nas']['diagnostico']['candidatos'])->toBe(1)
        ->and(array_column($plan['carpetas_omitidas_nas'], 'motivo', 'carpeta'))->toBe([
            'EMP-0007 - Miguel Angel Trejo Peralta' => 'sistema',
            'SIN-NUMERO-22 - Otra Persona Distinta' => 'sistema',
        ]);
});

test('dos carpetas con variantes de apellido compiten: revisión manual, nunca automático', function () {
    Storage::disk('nas')->makeDirectory('expedientes/Mr. Lana/CUERNAVACA/JAIME VALVERDE ERIVEZ 21-09-26');
    Storage::disk('nas')->makeDirectory('expedientes/Mr. Lana/CUERNAVACA/JAIME VALVERE ERIVES 21-09-26');

    $migracion = $this->servicio->analizar(miExcel([miPersona('Jaime', 'Valverde', 'Erives', 'Cuernavaca', 8)]), 'base.xlsx', $this->rh);
    $nas = $migracion->planArray()['filas'][0]['nas'];

    expect($nas['tipo'])->toBe('revision')
        ->and($nas['diagnostico']['razon'])->toBe('multiples_candidatos')
        ->and($nas['diagnostico']['candidatos'])->toBe(2)
        ->and($migracion->totales['match_exacto'] + $migracion->totales['match_alto'])->toBe(0);

    $this->servicio->aplicar($migracion, $this->rh);
    expect(Colaborador::query()->where('correo_personal', 'persona8@mrlana.test')->value('expediente_storage_path'))->toBeNull();
});

test('una sola variante de apellido (Levenshtein 1) en la misma sucursal es match alto', function () {
    Storage::disk('nas')->makeDirectory('expedientes/Mr. Lana/CUERNAVACA/JAIME VALVERDE ERIVEZ 21-09-26');

    $nas = $this->servicio->analizar(miExcel([miPersona('Jaime', 'Valverde', 'Erives', 'Cuernavaca', 9)]), 'base.xlsx', $this->rh)->planArray()['filas'][0]['nas'];

    expect($nas['tipo'])->toBe('alto')
        ->and($nas['carpeta'])->toBe('JAIME VALVERDE ERIVEZ 21-09-26');
});

test('el mismo nombre exacto en otra sucursal nunca se vincula solo', function () {
    miCordoba($this->cuernavaca);
    Storage::disk('nas')->makeDirectory('expedientes/Mr. Lana/CÓRDOBA/GUADALUPE MODESTO OCAMPO 05-01-2026');

    $nas = $this->servicio->analizar(miExcel([miPersona('Guadalupe', 'Modesto', 'Ocampo', 'Cuernavaca', 10)]), 'base.xlsx', $this->rh)->planArray()['filas'][0]['nas'];

    expect($nas['tipo'])->toBe('revision')
        ->and($nas['diagnostico']['razon'])->toBe('diferente_sucursal')
        ->and($nas['carpeta'])->toBeNull();
});

test('BAJAS, FOTOS y Pendientes de vincular no son candidatas ni expedientes sin persona', function () {
    miPdf('expedientes/Mr. Lana/CUERNAVACA/BAJAS/a.pdf');
    miPdf('expedientes/Mr. Lana/CUERNAVACA/FOTOS/b.pdf');
    miPdf('expedientes/Mr. Lana/CUERNAVACA/Pendientes de vincular/c.pdf');

    $plan = $this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh)->planArray();

    expect($plan['filas'][0]['nas']['diagnostico']['razon'])->toBe('sin_candidato')
        ->and($plan['carpetas_sin_persona'])->toBe([])
        ->and(collect($plan['carpetas_omitidas_nas'])->pluck('motivo')->unique()->values()->all())->toBe(['auxiliar'])
        ->and($plan['carpetas_omitidas_nas'])->toHaveCount(3);
});

test('un nombre contenido en otro con dos palabras de más no es automático', function () {
    Storage::disk('nas')->makeDirectory('expedientes/Mr. Lana/CUERNAVACA/JUAN CARLOS ANTONIO PEREZ LOPEZ');

    $nas = $this->servicio->analizar(miExcel([miPersona('Juan', 'Perez', 'Lopez', 'Cuernavaca', 11)]), 'base.xlsx', $this->rh)->planArray()['filas'][0]['nas'];

    expect($nas['tipo'])->toBe('revision');
});

/*
 * Formato NUEVO por sucursales («BASE DE DATOS MR. LANA PEOPLE SEMANA 41»):
 * dos hojas (Sucursales + Corporativo) con SOLO la identidad del colaborador
 * y un roster nominal aparte que aporta puesto/departamento. El adaptador de
 * entrada (LectorBaseSemana41 + LectorRosterNominal + CombinadorRosterNominal,
 * elegidos por FabricaLectorMigracion) emite la MISMA estructura canónica que
 * el formato único, así que el motor NAS/carpetas/manifiesto no cambia.
 *
 * Los encabezados van en un orden DISTINTO al formato único y no traen puesto
 * ni departamento: se reconocen por nombre (sinónimos), nunca por posición.
 */
function miExcelSemana41(array $sucursales, array $corporativo = []): string
{
    $libro = new Spreadsheet;
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('Sucursales');
    $encabezadosSuc = ['Sucursal oficial', 'Estatus laboral', 'CURP', 'Nombre completo', 'Apellido materno', 'Apellido paterno', 'Nombre', 'Correo electrónico', 'Fecha de nacimiento', 'Clave'];
    $hoja->fromArray($encabezadosSuc, null, 'A1');

    foreach ($sucursales as $i => $f) {
        $hoja->fromArray(array_map(fn ($e) => $f[$e] ?? null, $encabezadosSuc), null, 'A'.($i + 2));
    }

    // Corporativo: sin columna de sucursal (el adaptador la fija en «Corporativo»).
    $corp = $libro->createSheet();
    $corp->setTitle('Corporativo');
    $encabezadosCorp = ['Estatus laboral', 'CURP', 'Nombre completo', 'Apellido materno', 'Apellido paterno', 'Nombre', 'Correo electrónico', 'Fecha de nacimiento', 'Clave'];
    $corp->fromArray($encabezadosCorp, null, 'A1');

    foreach ($corporativo as $i => $f) {
        $corp->fromArray(array_map(fn ($e) => $f[$e] ?? null, $encabezadosCorp), null, 'A'.($i + 2));
    }

    $ruta = tempnam(sys_get_temp_dir(), 'migs41').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return $ruta;
}

/** @return array<string, string|null> */
function miFilaS41(array $extra = []): array
{
    return [
        'Nombre' => 'José Alberto', 'Apellido paterno' => 'Carlos', 'Apellido materno' => 'Bueno',
        'Nombre completo' => 'José Alberto Carlos Bueno', 'Correo electrónico' => 'jose.carlos@mrlana.test',
        'CURP' => 'CABA900101HMSRNL09', 'Fecha de nacimiento' => '01/01/1990', 'Estatus laboral' => 'Alta',
        'Sucursal oficial' => 'CUERNAVACA', 'Clave' => '130',
        ...$extra,
    ];
}

/** Roster columnar simple (una fila por persona): Clave/Nombre completo/Puesto/Departamento. */
function miRosterNominal(array $personas): string
{
    $libro = new Spreadsheet;
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('Roster');
    $encabezados = ['Departamento', 'Puesto', 'Nombre completo', 'Clave'];
    $hoja->fromArray($encabezados, null, 'A1');

    foreach ($personas as $i => $p) {
        $hoja->fromArray(array_map(fn ($e) => $p[$e] ?? null, $encabezados), null, 'A'.($i + 2));
    }

    $ruta = tempnam(sys_get_temp_dir(), 'roster').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return $ruta;
}

/** @return array<string, string|null> */
function miRosterFila(string $clave, string $nombre, string $puesto = 'Gestor', string $departamento = 'Ventas'): array
{
    return ['Clave' => $clave, 'Nombre completo' => $nombre, 'Puesto' => $puesto, 'Departamento' => $departamento];
}

test('SEMANA 41: identidad que ya existe (misma CURP) + roster queda sin cambios y no se duplica', function () {
    $excel = miExcelSemana41([miFilaS41()]);
    $roster = miRosterNominal([miRosterFila('130', 'José Alberto Carlos Bueno')]);

    $this->servicio->aplicar($this->servicio->analizar($excel, 'base.xlsx', $this->rh, $roster, 'roster.xlsx'), $this->rh);

    $segunda = $this->servicio->analizar($excel, 'base.xlsx', $this->rh, $roster, 'roster.xlsx');
    $fila = $segunda->planArray()['filas'][0];

    expect($segunda->totales['sin_cambios'])->toBe(1)
        ->and($fila['operacion'])->toBe('sin_cambios')
        ->and($fila['puesto_nombre'])->toBe('Gestor')
        ->and($fila['departamento_nombre'])->toBe('Ventas');

    $this->servicio->aplicar($segunda, $this->rh);
    expect(Colaborador::query()->where('curp', 'CABA900101HMSRNL09')->count())->toBe(1);
});

test('SEMANA 41 + roster: alta nueva crea colaborador, su cuenta y registra el PDF del NAS', function () {
    miPdf('expedientes/Mr. Lana/CUERNAVACA/JOSE ALBERTO CARLOS BUENO/expediente.pdf');
    $excel = miExcelSemana41([miFilaS41()]);
    $roster = miRosterNominal([miRosterFila('130', 'José Alberto Carlos Bueno')]);

    $migracion = $this->servicio->analizar($excel, 'base.xlsx', $this->rh, $roster, 'roster.xlsx');
    $fila = $migracion->planArray()['filas'][0];

    expect($fila['operacion'])->toBe('crear')
        ->and($fila['hoja_origen'])->toBe('Sucursales')
        ->and($fila['puesto_nombre'])->toBe('Gestor')
        ->and($fila['departamento_nombre'])->toBe('Ventas')
        ->and($fila['nas']['tipo'])->toBe('exacto');

    $resultado = $this->servicio->aplicar($migracion, $this->rh);
    $colaborador = Colaborador::query()->where('curp', 'CABA900101HMSRNL09')->firstOrFail();

    expect($resultado['creados'])->toBe(1)
        ->and($resultado['cuentas_creadas'])->toBe(1)
        ->and($resultado['pdfs_registrados'])->toBe(1)
        ->and($colaborador->puesto?->nombre)->toBe('Gestor')
        ->and($colaborador->clave_legacy)->toBe('130');
});

test('SEMANA 41: identidad válida sin puesto en el roster queda EN REVISIÓN y no se aplica', function () {
    $excel = miExcelSemana41([miFilaS41()]);
    // El roster no trae a esta persona: sin puesto/departamento → en revisión.
    $roster = miRosterNominal([miRosterFila('999', 'Otra Persona Distinta')]);

    $migracion = $this->servicio->analizar($excel, 'base.xlsx', $this->rh, $roster, 'roster.xlsx');
    $fila = $migracion->planArray()['filas'][0];

    expect($fila['operacion'])->toBe('en_revision')
        ->and($fila['puesto_nombre'])->toBeNull()
        ->and($migracion->totales['en_revision'])->toBe(1)
        ->and(implode(' ', $fila['motivos']))->toContain('pendiente');

    $resultado = $this->servicio->aplicar($migracion, $this->rh);

    expect($resultado['creados'])->toBe(0)
        ->and($resultado['revision_estructural'])->toBe(1)
        ->and(Colaborador::query()->where('curp', 'CABA900101HMSRNL09')->exists())->toBeFalse();
});

test('SEMANA 41: alias de sucursal resuelve, fuera de whitelist es conflicto, nunca crea sucursales', function () {
    $cordoba = miCordoba($this->cuernavaca);
    $sucursalesAntes = Sucursal::query()->count();

    $excel = miExcelSemana41([
        miFilaS41(['Sucursal oficial' => 'ATLACOMULC']),
        miFilaS41(['Sucursal oficial' => 'CÓRDOBA', 'CURP' => 'MEQR880215MDFLNS03', 'Nombre completo' => 'Maria Perez Luna', 'Nombre' => 'Maria', 'Apellido paterno' => 'Perez', 'Apellido materno' => 'Luna', 'Correo electrónico' => 'maria@mrlana.test', 'Clave' => '131']),
        miFilaS41(['Sucursal oficial' => 'PACHUCA', 'CURP' => 'LOGA850320HMCRRN08', 'Nombre completo' => 'Luis Gomez Ramirez', 'Nombre' => 'Luis', 'Apellido paterno' => 'Gomez', 'Apellido materno' => 'Ramirez', 'Correo electrónico' => 'luis@mrlana.test', 'Clave' => '132']),
    ]);
    $roster = miRosterNominal([
        miRosterFila('130', 'José Alberto Carlos Bueno'),
        miRosterFila('131', 'Maria Perez Luna'),
        miRosterFila('132', 'Luis Gomez Ramirez'),
    ]);

    $plan = $this->servicio->analizar($excel, 'base.xlsx', $this->rh, $roster, 'roster.xlsx')->planArray();

    expect($plan['filas'][0]['sucursal_id'])->toBe($this->atlacomulco->id)
        ->and($plan['filas'][0]['operacion'])->toBe('crear')
        ->and($plan['filas'][1]['sucursal_id'])->toBe($cordoba->id)
        ->and($plan['filas'][1]['operacion'])->toBe('crear')
        ->and($plan['filas'][2]['operacion'])->toBe('conflicto');
    expect(Sucursal::query()->count())->toBe($sucursalesAntes);
});

test('SEMANA 41: la hoja Corporativo se procesa con sucursal «Corporativo» y su ausencia no da de baja', function () {
    // El seeder deja una sucursal «Corporativo» sin empresa; se vincula a la
    // monoempresa para que coincida con la que trae el archivo (Mr. Lana).
    Sucursal::query()->where('nombre', 'Corporativo')->update(['empresa_id' => Empresa::query()->where('nombre', 'Mr. Lana')->value('id')]);

    $excel = miExcelSemana41(
        [miFilaS41()],
        [miFilaS41(['CURP' => 'LOGA850320HMCRRN08', 'Nombre completo' => 'Luis Gomez Ramirez', 'Nombre' => 'Luis', 'Apellido paterno' => 'Gomez', 'Apellido materno' => 'Ramirez', 'Correo electrónico' => 'luis@mrlana.test', 'Clave' => '200'])],
    );
    $roster = miRosterNominal([
        miRosterFila('130', 'José Alberto Carlos Bueno'),
        miRosterFila('200', 'Luis Gomez Ramirez'),
    ]);

    $migracion = $this->servicio->analizar($excel, 'base.xlsx', $this->rh, $roster, 'roster.xlsx');
    $filas = collect($migracion->planArray()['filas'])->keyBy('nombre_completo');

    expect($filas['Luis Gomez Ramirez']['hoja_origen'])->toBe('Corporativo')
        ->and($filas['Luis Gomez Ramirez']['sucursal_nombre'])->toBe('Corporativo')
        ->and($filas['Luis Gomez Ramirez']['operacion'])->toBe('crear')
        ->and($migracion->totales['corporativo'])->toBe(1);

    $this->servicio->aplicar($migracion, $this->rh);
    $corp = Colaborador::query()->where('curp', 'LOGA850320HMCRRN08')->firstOrFail();
    expect($corp->sucursalPrincipal?->nombre)->toBe('Corporativo')
        ->and($corp->estatus)->toBe(EstadoUsuario::Activo);

    // Reanalizar/aplicar SIN la fila de Corporativo: ausencia nunca es baja.
    $this->servicio->aplicar($this->servicio->analizar(miExcelSemana41([miFilaS41()]), 'base.xlsx', $this->rh, miRosterNominal([miRosterFila('130', 'José Alberto Carlos Bueno')]), 'roster.xlsx'), $this->rh);
    expect($corp->fresh()->estatus)->toBe(EstadoUsuario::Activo);
});

test('SEMANA 41: un colaborador activo en BD ausente del archivo sigue activo (ausencia ≠ baja)', function () {
    $otra = Colaborador::factory()->create(['sucursal_principal_id' => $this->cuernavaca->id, 'estatus' => EstadoUsuario::Activo->value, 'curp' => 'ROHM920710MMCDRN05']);
    $excel = miExcelSemana41([miFilaS41()]);
    $roster = miRosterNominal([miRosterFila('130', 'José Alberto Carlos Bueno')]);

    $this->servicio->aplicar($this->servicio->analizar($excel, 'base.xlsx', $this->rh, $roster, 'roster.xlsx'), $this->rh);

    expect($otra->fresh()->estatus)->toBe(EstadoUsuario::Activo)
        ->and(Colaborador::query()->where('curp', 'CABA900101HMSRNL09')->value('estatus'))->toBe(EstadoUsuario::Activo);
});

test('SEMANA 41 + roster: reanalizar y aplicar el mismo par es idempotente', function () {
    miPdf('expedientes/Mr. Lana/CUERNAVACA/JOSE ALBERTO CARLOS BUENO/expediente.pdf');
    $excel = miExcelSemana41([miFilaS41()]);
    $roster = miRosterNominal([miRosterFila('130', 'José Alberto Carlos Bueno')]);

    $this->servicio->aplicar($this->servicio->analizar($excel, 'base.xlsx', $this->rh, $roster, 'roster.xlsx'), $this->rh);
    $segunda = $this->servicio->analizar($excel, 'base.xlsx', $this->rh, $roster, 'roster.xlsx');

    expect($segunda->totales['sin_cambios'])->toBe($segunda->totales['filas']);
    $this->servicio->aplicar($segunda, $this->rh);

    expect(Colaborador::query()->where('curp', 'CABA900101HMSRNL09')->count())->toBe(1)
        ->and(ExpedienteHistorico::query()->count())->toBe(1)
        ->and(User::query()->where('username', 'Jose Carlos')->count())->toBe(1);
});

test('detección de formato: BASE_GENERAL usa el lector único; Sucursales/Corporativo usa el de SEMANA 41', function () {
    $base = $this->servicio->analizar(miExcel([miFila()]), 'base.xlsx', $this->rh);
    expect($base->planArray()['excel']['hoja'])->toBe('BASE_GENERAL')
        ->and($base->planArray()['filas'][0]['operacion'])->toBe('crear');

    $excel = miExcelSemana41([miFilaS41()]);
    $roster = miRosterNominal([miRosterFila('130', 'José Alberto Carlos Bueno')]);
    $s41 = $this->servicio->analizar($excel, 'base.xlsx', $this->rh, $roster, 'roster.xlsx');
    expect($s41->planArray()['excel']['hoja'])->toBe('Sucursales + Corporativo')
        ->and($s41->planArray()['filas'][0]['operacion'])->toBe('crear');
});

/*
 * Forma REAL de los archivos del negocio (docs/datosReales, no versionados):
 * la hoja Sucursales trae la sucursal en «Clasificación» y el encabezado en la
 * fila 5; el directorio de gerencias (mismas personas, por «NO. NOI» = Clave)
 * trae su encabezado en la fila 11, renglones separadores por sucursal y el
 * contacto corporativo que alimenta el módulo Directorio.
 */
test('SEMANA 41 real: «Clasificación» es la sucursal y el directorio aporta puesto, correo y teléfono corporativos', function () {
    $ventas = $this->departamento;
    Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'departamento_id' => $ventas->id]);
    $operaciones = Departamento::factory()->create(['nombre' => 'Operaciones']);
    Puesto::factory()->create(['nombre' => 'Coordinadora de Sucursal', 'departamento_id' => $operaciones->id]);

    $libro = new Spreadsheet;
    $suc = $libro->getActiveSheet();
    $suc->setTitle('Sucursales');
    $suc->fromArray(['Clave', 'Nombre', 'Apellido paterno', 'Apellido materno', 'CURP', 'Clasificación', 'Estatus'], null, 'A5');
    $suc->fromArray(['20', 'Mario', 'Prueba', 'Uno', 'PRUM900101HMSRNL01', 'Cuernavaca', 'Alta'], null, 'A6');
    $suc->fromArray(['8', 'Laura', 'Prueba', 'Dos', 'PRDL900101MMSRNL02', 'Atlacomulc', 'Alta'], null, 'A7');
    $suc->fromArray(['99', 'Sin', 'Directorio', 'Nadie', 'SIDN900101HMSRNL01', 'Cuernavaca', 'Alta'], null, 'A8');
    $excel = tempnam(sys_get_temp_dir(), 'migs41real').'.xlsx';
    (new Xlsx($libro))->save($excel);

    $dir = new Spreadsheet;
    $hoja = $dir->getActiveSheet();
    $hoja->setTitle('Directorio');
    $hoja->setCellValue('A6', 'DIRECTORIO DE GERENCIAS Y COORDINACIONES');
    $hoja->fromArray(['NO. NOI', 'NOMBRE DEL COLABORADOR', 'SUCURSAL', 'PUESTO', 'CORREO INSTITUCIONAL', 'NO. TELEFÓNICO'], null, 'A11');
    $hoja->fromArray(['▎ SUCURSAL CUERNAVACA', null, null, null, '1 colaboradores'], null, 'A12');
    $hoja->fromArray(['20', 'MARIO PRUEBA UNO', 'CUERNAVACA', 'GERENTE', 'mario.prueba@mrlana.test', '5550000001'], null, 'A13');
    $hoja->fromArray(['8', 'LAURA PRUEBA DOS', 'ATLACOMULCO', 'COORDINADORA', 'laura.prueba@mrlana.test', '555 000 0002'], null, 'A14');
    $roster = tempnam(sys_get_temp_dir(), 'directorio').'.xlsx';
    (new Xlsx($dir))->save($roster);

    $filas = collect($this->servicio->analizar($excel, 'base.xlsx', $this->rh, $roster, 'directorio.xlsx')->planArray()['filas'])->keyBy(fn ($f) => $f['datos']['clave_legacy']);

    expect($filas['20']['operacion'])->toBe('crear')
        ->and($filas['20']['puesto_nombre'])->toBe('Gerente de Sucursal')
        ->and($filas['20']['datos']['sucursal_principal_id'])->toBe($this->cuernavaca->id)
        ->and($filas['20']['datos']['departamento_id'])->toBe($ventas->id)
        ->and($filas['20']['datos']['correo_corporativo'])->toBe('mario.prueba@mrlana.test')
        ->and($filas['8']['puesto_nombre'])->toBe('Coordinadora de Sucursal')
        ->and($filas['8']['datos']['sucursal_principal_id'])->toBe($this->atlacomulco->id)
        ->and($filas['8']['datos']['correo_corporativo'])->toBe('laura.prueba@mrlana.test')
        // Quien no está en el directorio no recibe un puesto inventado.
        ->and($filas['99']['operacion'])->toBe('en_revision')
        ->and($filas['99']['datos']['puesto_id'])->toBeNull();
});

/*
 * Persistencia del plan (producción: json_encode devolvía false por bytes
 * UTF-8 inválidos en nombres del NAS y la corrida quedaba con plan "0").
 */
function miPlanCrudo(MigracionExpedientes $m): array
{
    return json_decode((string) $m->fresh()->getRawOriginal('plan'), true, 512, JSON_THROW_ON_ERROR);
}

test('SEMANA 41 sin roster: el plan persistido es JSON válido y las filas sin puesto quedan en revisión', function () {
    $excel = miExcelSemana41([miFilaS41(), miFilaS41(['CURP' => 'PRUE900101HMSRNL05', 'Nombre' => 'Otro', 'Nombre completo' => 'Otro Carlos Bueno', 'Correo electrónico' => null, 'Clave' => '131'])]);

    $m = $this->servicio->analizar($excel, 'base.xlsx', $this->rh);
    $plan = miPlanCrudo($m);

    expect($plan['filas'])->toHaveCount(2)
        ->and(array_unique(array_column($plan['filas'], 'operacion')))->toBe(['en_revision'])
        ->and($m->planArray())->toBe($plan);
});

test('SEMANA 41 + roster: plan válido, cruza por Clave y el roster no crea personas nuevas', function () {
    $excel = miExcelSemana41([miFilaS41()]);
    $roster = miRosterNominal([miRosterFila('130', 'Otro Nombre Distinto'), miRosterFila('999', 'Persona Solo En Roster')]);

    $m = $this->servicio->analizar($excel, 'base.xlsx', $this->rh, $roster, 'roster.xlsx');
    $plan = miPlanCrudo($m);

    expect($plan['filas'])->toHaveCount(1)
        ->and($plan['filas'][0]['operacion'])->toBe('crear')
        ->and($plan['filas'][0]['puesto_nombre'])->toBe('Gestor');

    $this->servicio->aplicar($m, $this->rh);
    expect(Colaborador::query()->where('clave_legacy', '999')->exists())->toBeFalse();
});

test('UTF-8 inválido en nombres/rutas del NAS: el plan se guarda y se vuelve a leer (nunca "0")', function () {
    $excel = miExcelSemana41([miFilaS41()]);
    $real = app(PlanificadorMigracion::class);
    $this->mock(PlanificadorMigracion::class, function ($mock) use ($real) {
        $mock->shouldReceive('planificar')->andReturnUsing(function (array $excel) use ($real) {
            $plan = $real->planificar($excel);
            $plan['carpetas_sin_persona'][] = ['ruta' => "expedientes/Mr. Lana/CUERNAVACA/JOS\xC9 P\xC9REZ", 'pdfs' => [['nombre' => "acta \xD1.pdf"]]];

            return $plan;
        });
    });

    $m = app(ExpedientesInitialMigrationService::class)->analizar($excel, 'base.xlsx', $this->rh);
    $plan = miPlanCrudo($m);

    expect($m->fresh()->getRawOriginal('plan'))->not->toBe('0')
        ->and($plan['carpetas_sin_persona'][0]['ruta'])->toBe("expedientes/Mr. Lana/CUERNAVACA/JOS\u{FFFD} P\u{FFFD}REZ")
        ->and($plan['filas'])->toHaveCount(1);
});

test('un plan guardado dañado no se esconde: planArray falla y la pantalla muestra el error', function () {
    $m = $this->servicio->analizar(miExcelSemana41([miFilaS41()]), 'base.xlsx', $this->rh);
    MigracionExpedientes::query()->whereKey($m->id)->update(['plan' => '0']);

    expect(fn () => $m->fresh()->planArray())->toThrow(RuntimeException::class);

    $this->actingAs($this->rh)->get(route('rh.expedientes.migracion.index', ['migracion' => $m->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('migracion.plan', null)->where('migracion.plan_error', fn ($e) => str_contains((string) $e, 'vuelve a analizar')));
});

test('POST web: llegan la base y el roster, y Inertia renderiza el plan guardado', function () {
    $base = new UploadedFile(miExcelSemana41([miFilaS41()]), 'BASE SEMANA 41.xlsx', null, null, true);
    $roster = new UploadedFile(miRosterNominal([miRosterFila('130', 'José Alberto Carlos Bueno')]), 'DIRECTORIO_GERENCIAS_MRLANA.xlsx', null, null, true);

    $this->actingAs($this->rh)->post(route('rh.expedientes.migracion.analizar'), ['archivo' => $base, 'roster' => $roster])->assertRedirect();

    $m = MigracionExpedientes::query()->latest('id')->firstOrFail();
    expect($m->archivo_nombre)->toBe('BASE SEMANA 41.xlsx + DIRECTORIO_GERENCIAS_MRLANA.xlsx')
        ->and(miPlanCrudo($m)['filas'][0]['puesto_nombre'])->toBe('Gestor');

    $this->actingAs($this->rh)->get(route('rh.expedientes.migracion.index', ['migracion' => $m->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Rh/Expedientes/MigracionInicial')
            ->has('migracion.plan.filas', 1)
            ->where('migracion.plan.filas.0.operacion', 'crear')
            ->where('migracion.plan_error', null));
});
