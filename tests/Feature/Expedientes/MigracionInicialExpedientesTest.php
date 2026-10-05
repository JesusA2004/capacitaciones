<?php

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\ExpedienteHistorico;
use App\Models\MigracionExpedientes;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Expedientes\MigracionInicial\ExpedientesInitialMigrationService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
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
    $this->puesto = Puesto::factory()->create(['nombre' => 'Gestor']);
    $this->rh = clUsuario('rh_admin');
    $this->servicio = app(ExpedientesInitialMigrationService::class);
});

/**
 * @param  list<array<string, string|null>>  $filas
 */
function miExcel(array $filas): string
{
    $libro = new Spreadsheet;
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('BASE_GENERAL');
    $encabezados = ['Clave', 'Nombre', 'Apellido paterno', 'Apellido materno', 'Correo electrónico', 'CURP', 'RFC', 'Fecha de nacimiento', 'Fecha de alta', 'Estatus laboral', 'Sucursal', 'Puesto', 'Condición médica', 'Alergias'];
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
        'Sucursal' => 'CUERNAVACA', 'Puesto' => 'Gestor', 'Condición médica' => 'Asma', 'Alergias' => 'Penicilina',
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
        ->and(User::query()->where('email', 'jose.carlos@mrlana.test')->exists())->toBeTrue();

    // No se copió ni se renombró nada: el archivo sigue donde estaba.
    Storage::disk('nas')->assertExists('expedientes/Mr. Lana/CUERNAVACA/ALBERTO CARLOS BUENO 19-02-2024/José Alberto Carlos Bueno (1).pdf');
    expect(Storage::disk('nas')->allFiles('expedientes'))->toHaveCount(1);

    $credenciales = $this->servicio->credenciales($migracion->fresh());
    expect($credenciales[0]['usuario'])->toBe('jose.carlos@mrlana.test')
        ->and($credenciales[0]['contrasena'])->toStartWith('Lana-')
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
        ->and(User::query()->where('email', 'jose.carlos@mrlana.test')->count())->toBe(1);
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
        miFila(['Sucursal' => 'ATLACOMULC']),
        miFila(['CURP' => 'AAAA900101HMSRNL01', 'Nombre' => 'Uno', 'Correo electrónico' => null, 'Sucursal' => 'PACHUCA']),
        miFila(['CURP' => 'BBBB900101HMSRNL02', 'Nombre' => 'Dos', 'Correo electrónico' => null, 'Sucursal' => 'AGUASCALIENTES']),
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
    $this->actingAs($this->rh)->get('/rh/expedientes/migracion-inicial')->assertOk();

    $migracion = MigracionExpedientes::query()->create(['archivo_nombre' => 'x.xlsx', 'archivo_hash' => 'x', 'estado' => 'analizado', 'plan' => '{}']);
    $this->actingAs($colaborador)->post("/rh/expedientes/migracion-inicial/{$migracion->id}/aplicar", ['modo' => 'copiar', 'confirmacion' => true])->assertForbidden();
    $this->actingAs($colaborador)->get("/rh/expedientes/migracion-inicial/{$migracion->id}/credenciales")->assertForbidden();
});
