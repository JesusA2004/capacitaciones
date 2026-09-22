<?php

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DocumentTemplate;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Ciclo laboral (docs/backend-rh-completion.md) — helpers compartidos
|--------------------------------------------------------------------------
| Prefijo "cl" para no chocar con funciones globales de otras pruebas.
*/

/**
 * Cuenta con rol y (por defecto) colaborador activo propio.
 *
 * @param  array<string, mixed>  $colaborador
 */
function clUsuario(string $rol, array $colaborador = []): User
{
    $persona = Colaborador::factory()->create($colaborador);
    $usuario = User::factory()->create(['colaborador_id' => $persona->id]);
    $usuario->assignRole($rol);

    return $usuario;
}

/**
 * Plantilla documental HTML activa (el texto lo aportaría Jurídico; aquí es de prueba).
 *
 * @param  array<string, mixed>  $atributos
 */
function clPlantilla(string $clave, array $atributos = []): DocumentTemplate
{
    return DocumentTemplate::query()->create([
        'clave' => $clave,
        'nombre' => config("contratos.plantillas.{$clave}.nombre", $clave),
        'tipo' => 'otro',
        'motor' => 'html',
        'contenido_html' => '<h1>{{nombre_completo}}</h1><p>Sueldo: {{sueldo_mensual}}. Inicio: {{fecha_inicio_contrato}}. Fin: {{fecha_fin_contrato}}.</p>',
        'version' => 1,
        'activo' => true,
        ...$atributos,
    ]);
}

/**
 * Estructura mínima (empresa → sucursal, departamento, puesto).
 *
 * @return array{empresa: Empresa, sucursal: Sucursal, departamento: Departamento, puesto: Puesto}
 */
function clEstructura(): array
{
    $empresa = Empresa::factory()->create(['nombre' => 'MR LANA']);

    return [
        'empresa' => $empresa,
        'sucursal' => Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Cuernavaca']),
        'departamento' => Departamento::factory()->create(),
        'puesto' => Puesto::factory()->create(),
    ];
}

function clArchivoPdf(string $nombre = 'documento.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($nombre, 20, 'application/pdf');
}
