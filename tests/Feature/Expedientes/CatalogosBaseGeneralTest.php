<?php

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Expedientes\MigracionInicial\ExpedientesInitialMigrationService;
use Database\Seeders\CatalogosBaseGeneralSeeder;
use Database\Seeders\DepartamentoSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\EmpresaSeeder;
use Database\Seeders\MatrizComercialSeeder;
use Database\Seeders\PuestoJerarquiaSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Database\Seeders\SucursalSeeder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * BASE_GENERAL real contra los catálogos REALES de producción (los mismos
 * seeders que corrieron tras migrate:fresh + CatalogosBaseGeneralSeeder).
 * Sin DemoSeeder: ninguna persona ni usuario de prueba.
 */
beforeEach(function () {
    Storage::fake('nas');
    Storage::fake('local');

    foreach ([RolesYPermisosSeeder::class, EmpresaSeeder::class, SucursalSeeder::class, DepartamentoSeeder::class, PuestoJerarquiaSeeder::class, CatalogosBaseGeneralSeeder::class, DocumentTypeSeeder::class, MatrizComercialSeeder::class] as $seeder) {
        $this->seed($seeder);
    }

    $this->servicio = app(ExpedientesInitialMigrationService::class);
});

/**
 * Todos los valores únicos de Empresa / Sucursal oficial / Departamento /
 * Puesto que trae el Excel final (incluida la variante «gestor de Credito»),
 * con el encabezado real de BASE_GENERAL.
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $filas  [puesto, departamento, sucursal]
 */
function cbgExcel(array $filas): string
{
    $libro = new Spreadsheet;
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('BASE_GENERAL');
    $encabezados = ['Clave', 'Nombre completo', 'Nombre', 'Apellido paterno', 'Apellido materno', 'Departamento', 'Puesto', 'Correo electrónico', 'CURP', 'Fecha de alta', 'Estatus laboral', 'Sucursal origen', 'Empresa', 'Sucursal oficial'];
    $hoja->fromArray($encabezados, null, 'A1');

    foreach ($filas as $i => [$puesto, $departamento, $sucursal]) {
        $nombre = 'Persona'.chr(65 + intdiv($i, 26)).chr(65 + $i % 26);
        $hoja->fromArray([(string) ($i + 1), "{$nombre} Prueba Real", $nombre, 'Prueba', 'Real', $departamento, $puesto, null, null, '01/10/2025', 'Activo', strtoupper($sucursal), 'Mr. Lana', $sucursal], null, 'A'.($i + 2));
    }

    $ruta = tempnam(sys_get_temp_dir(), 'cbg').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return $ruta;
}

/** @return list<array{0: string, 1: string, 2: string}> */
function cbgCombinaciones(): array
{
    return [
        ['Coordinadora Administrativa', 'Administración', 'Atlacomulco'],
        ['Gerente de Sucursal', 'Operaciones', 'Ixtlahuaca'],
        ['Gestor de Credito', 'Operaciones', 'Tula'],
        ['gestor de Credito', 'Operación', 'Cuernavaca'],
        ['Subgerente', 'Operaciones', 'Miacatlán'],
        ['Regional de Operaciones', 'Operaciones', 'Atlixco'],
        ['Regional de Operaciones', 'Operaciones', 'Cuernavaca'],
        ['Director General', 'Dirección', 'Corporativo'],
        ['Gerente R.H.', 'R.H.', 'Corporativo'],
        ['Monitorista', 'Sistemas', 'Corporativo'],
        ['Tesoreria', 'Contraloria', 'Corporativo'],
        ['Abogado', 'Juridico', 'Corporativo'],
        ['Contralora', 'Contraloria', 'Corporativo'],
        ['Limpieza', 'Mantenimiento', 'Corporativo'],
        ['Auditora', 'Contraloria', 'San Luis Potosí'],
        ['Analista de mesa de control', 'Mesa de Control', 'Corporativo'],
        ['Director Comercial', 'Dirección', 'Corporativo'],
        ['Gerente de Mesa de control', 'Mesa de Control', 'Corporativo'],
        ['Escolta', 'Dirección', 'Corporativo'],
        ['Asistente de Dirección', 'Dirección', 'Corporativo'],
        ['Jardinero', 'Mantenimiento', 'Corporativo'],
        ['Jefe de sistemas', 'Sistemas', 'Corporativo'],
        ['Admon de personal', 'R.H.', 'Corporativo'],
        ['Reclutamiento', 'R.H.', 'Corporativo'],
        ['Regional Administrativa', 'Administración', 'Corporativo'],
        ['Gestor de Credito', 'Operaciones', 'Huamantla'],
        ['Gestor de Credito', 'Operaciones', 'Tlaxcala'],
        ['Gestor de Credito', 'Operaciones', 'Córdoba'],
        ['Gestor de Credito', 'Operaciones', 'Orizaba'],
    ];
}

test('todos los valores únicos de BASE_GENERAL tienen catálogo: 0 empresas/sucursales/departamentos/puestos desconocidos', function () {
    $plan = $this->servicio->analizar(cbgExcel(cbgCombinaciones()), 'base.xlsx', null)->planArray();

    $deCatalogo = collect($plan['filas'])->flatMap(fn (array $f) => array_map(fn (string $m) => "Fila {$f['fila']} ({$f['puesto_excel']} / {$f['departamento_excel']} / {$f['sucursal_excel']}): {$m}", $f['motivos']))
        ->filter(fn (string $m) => preg_match('/NO ENCONTRAD|fuera de la whitelist|Falta «(Empresa|Departamento|Puesto|Sucursal)/u', $m) === 1)
        ->values()->all();

    expect($deCatalogo)->toBe([])
        ->and(collect($plan['filas'])->where('operacion', 'conflicto')->flatMap(fn (array $f) => $f['motivos'])->unique()->values()->all())->toBe([])
        ->and(count($plan['filas']))->toBe(count(cbgCombinaciones()));

    $puestos = collect($plan['filas'])->mapWithKeys(fn (array $f) => [sprintf('%s @ %s', $f['puesto_excel'], $f['sucursal_excel']) => $f['puesto_nombre']]);

    expect($puestos['Coordinadora Administrativa @ Atlacomulco'])->toBe('Coordinadora de Sucursal')
        ->and($puestos['gestor de Credito @ Cuernavaca'])->toBe('Gestor')
        ->and($puestos['Regional de Operaciones @ Atlixco'])->toBe('Gerente Regional Q3')
        ->and($puestos['Regional de Operaciones @ Cuernavaca'])->toBe('Gerente Regional Q1')
        ->and($puestos['Director General @ Corporativo'])->toBe('Dirección General')
        ->and($puestos['Director Comercial @ Corporativo'])->toBe('Dirección Comercial')
        ->and($puestos['Gerente R.H. @ Corporativo'])->toBe('Gerencia de Recursos Humanos')
        ->and($puestos['Jefe de sistemas @ Corporativo'])->toBe('Responsable de Sistemas')
        ->and($puestos['Admon de personal @ Corporativo'])->toBe('Administración de Personal')
        ->and($puestos['Tesoreria @ Corporativo'])->toBe('Tesorero')
        ->and($puestos['Contralora @ Corporativo'])->toBe('Gerente de Contraloría')
        ->and($puestos['Asistente de Dirección @ Corporativo'])->toBe('Asistente de Dirección General')
        ->and($puestos['Regional Administrativa @ Corporativo'])->toBe('Coordinadora Regional')
        ->and($puestos['Abogado @ Corporativo'])->toBe('Abogado');

    // El departamento guardado es el del organigrama confirmado (Gestor vive en Ventas).
    $gestor = collect($plan['filas'])->firstWhere('puesto_excel', 'Gestor de Credito');
    expect($gestor['departamento_nombre'])->toBe('Ventas')
        ->and(implode(' ', $gestor['advertencias']))->toContain('pertenece a «Ventas»');
});

test('casos ambiguos que no se pueden decidir se reportan como conflicto explícito, no como catálogo faltante', function () {
    $plan = $this->servicio->analizar(cbgExcel([
        ['Regional de Operaciones', 'Operaciones', 'Corporativo'],
        ['Asistente de Dirección', 'Sistemas', 'Corporativo'],
        ['Coordinadora Administrativa', 'Administración', 'Corporativo'],
    ]), 'base.xlsx', null)->planArray();

    expect(array_column($plan['filas'], 'operacion'))->toBe(['conflicto', 'conflicto', 'conflicto'])
        ->and(implode(' ', $plan['filas'][0]['motivos']))->toContain('Q1 o Q3')
        ->and(implode(' ', $plan['filas'][1]['motivos']))->toContain('Dirección General o Comercial')
        ->and(implode(' ', $plan['filas'][2]['motivos']))->toContain('Corporativo no tiene Coordinadora de Sucursal');
});

test('CatalogosBaseGeneralSeeder es idempotente, no duplica por acentos y no crea personas', function () {
    $antes = [Departamento::query()->count(), Puesto::query()->count()];
    $this->seed(CatalogosBaseGeneralSeeder::class);

    expect([Departamento::query()->count(), Puesto::query()->count()])->toBe($antes)
        ->and(Departamento::query()->whereIn('nombre', ['Jurídico', 'Mantenimiento'])->count())->toBe(2)
        ->and(Puesto::query()->whereIn('nombre', ['Abogado', 'Auditora', 'Escolta', 'Limpieza', 'Jardinero'])->count())->toBe(5)
        ->and(Colaborador::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        // migrate:fresh crea Corporativo antes que la empresa: el seeder lo liga a Mr. Lana.
        ->and(Sucursal::query()->where('clave', 'CORP01')->first()?->empresa?->nombre)->toBe('Mr. Lana');

    // Un «Juridico» sin acento ya existente no se duplica.
    Departamento::query()->where('nombre', 'Jurídico')->update(['nombre' => 'Juridico']);
    $this->seed(CatalogosBaseGeneralSeeder::class);
    expect(Departamento::query()->whereIn('nombre', ['Jurídico', 'Juridico'])->count())->toBe(1);
});

test('NAS en sitio: expedientes/Mr. Lana existe → origen_existe = true y se detectan las carpetas', function () {
    Storage::disk('nas')->put('expedientes/Mr. Lana/Atlixco/MARIA LOPEZ HERNANDEZ/expediente.pdf', '%PDF-1.4 historico');

    $plan = $this->servicio->analizar(cbgExcel([['Gestor de Credito', 'Operaciones', 'Atlixco']]), 'base.xlsx', null)->planArray();

    expect($plan['origen']['ruta'])->toBe('expedientes/Mr. Lana')
        ->and($plan['origen']['disco'])->toBe('nas')
        ->and($plan['origen']['existe'])->toBeTrue()
        ->and($plan['origen']['diagnostico'])->toBeNull()
        ->and($plan['carpetas_total'])->toBe(1)
        ->and(array_column($plan['carpetas_sin_persona'], 'carpeta'))->toContain('MARIA LOPEZ HERNANDEZ');
});

test('cada análisis vuelve a inventariar el NAS: uno sin carpeta y el siguiente con carpeta', function () {
    $excel = cbgExcel([['Gestor de Credito', 'Operaciones', 'Atlixco']]);
    $primero = $this->servicio->analizar($excel, 'base.xlsx', null)->planArray();

    expect($primero['origen']['existe'])->toBeFalse()
        // Sin la carpeta, el plan dice por qué (disco, raíz, ruta, usuario del proceso…).
        ->and($primero['origen']['diagnostico'])->toHaveKeys(['disco', 'driver', 'root', 'ruta', 'usuario_proceso', 'open_basedir']);

    Storage::disk('nas')->put('expedientes/Mr. Lana/Atlixco/MARIA LOPEZ HERNANDEZ/expediente.pdf', '%PDF-1.4');
    $segundo = $this->servicio->analizar($excel, 'base.xlsx', null)->planArray();

    expect($segundo['origen']['existe'])->toBeTrue()
        ->and($segundo['carpetas_total'])->toBe(1);
});
