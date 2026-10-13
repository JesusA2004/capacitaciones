<?php

use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosMaestros\CatalogoMaestrosService;
use App\Services\DocumentosMaestros\DatosDocumentoService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use App\Services\DocumentosMaestros\MigracionMapeosService;
use App\Services\DocumentosMaestros\Variables\CatalogoVariablesDocumento;
use App\Services\DocumentosMaestros\Variables\ExpresionesDocumentoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/*
| Motor no-code — datos: el contacto de emergencia es explícito (nunca un
| alias que entregue solo el nombre), los datos LEGALES nunca se completan
| con un supuesto, los campos compuestos se arman con piezas seguras, y el
| mapa migrado de config a BD compila IDÉNTICO al vigente.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

function dlValores(Colaborador $colaborador): array
{
    $motor = app(MotorDocumentalService::class);

    return app(DatosDocumentoService::class)->resolver($motor->contextoDesde($colaborador, null, User::factory()->create()));
}

test('contacto de emergencia: cada dato en su lugar y el completo explícito; ya no existe el alias ambiguo', function () {
    $colaborador = Colaborador::factory()->create([
        'contacto_emergencia_nombre' => 'MARÍA LÓPEZ',
        'contacto_emergencia_parentesco' => 'MADRE',
        'contacto_emergencia_telefono' => '7771234567',
        'contacto_emergencia_direccion' => 'CALLE EJEMPLO 123',
    ]);

    $valores = dlValores($colaborador);

    expect($valores['contacto_emergencia_nombre'])->toBe('MARÍA LÓPEZ')
        ->and($valores['contacto_emergencia_parentesco'])->toBe('MADRE')
        ->and($valores['contacto_emergencia_telefono'])->toBe('7771234567')
        ->and($valores['contacto_emergencia_direccion'])->toBe('CALLE EJEMPLO 123')
        ->and($valores['contacto_emergencia_completo'])->toBe('MARÍA LÓPEZ (MADRE) · Tel. 7771234567 · CALLE EJEMPLO 123')
        ->and($valores)->not->toHaveKey('contacto_emergencia')
        ->and($valores)->not->toHaveKey('contacto_emergencia_mayusculas');

    $catalogo = app(CatalogoVariablesDocumento::class);
    expect($catalogo->existe('contacto_emergencia'))->toBeFalse()
        ->and($catalogo->existe('contacto_emergencia_completo'))->toBeTrue();
});

test('datos legales: sin nacionalidad, correo personal, representante o domicilio fiscal capturados quedan VACÍOS (nunca un supuesto)', function () {
    $empresa = Empresa::factory()->create(['razon_social' => null, 'representante_legal_nombre' => null, 'domicilio_fiscal' => null]);
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'nacionalidad' => null, 'correo_personal' => null]);
    User::factory()->create(['colaborador_id' => $colaborador->id, 'email' => 'cuenta.acceso@mrlana.test']);

    $valores = dlValores($colaborador);

    expect($valores['nacionalidad'])->toBe('')
        ->and($valores['correo'])->toBe('')
        ->and($valores['representante_legal_mayusculas'])->toBe('')
        ->and($valores['empresa_domicilio'])->toBe('')
        ->and($valores['empresa_razon_social'])->toBe('');

    $fuente = app(DatosDocumentoService::class)->fuente('representante_legal_mayusculas');
    expect($fuente['fuente'])->toBe('empresa')->and($fuente['etiqueta'])->toBe('Representante legal de la empresa');
});

test('expresiones compuestas: piezas seguras, separadores que no cuelgan y transformación', function () {
    $expresiones = app(ExpresionesDocumentoService::class);
    $especificacion = ['expression' => ['partes' => [
        ['tipo' => 'variable', 'clave' => 'contacto_emergencia_nombre'],
        ['tipo' => 'variable', 'clave' => 'contacto_emergencia_parentesco', 'prefijo' => ' (', 'sufijo' => ')', 'opcional' => true],
        ['tipo' => 'texto', 'valor' => ' - ', 'separador' => true],
        ['tipo' => 'variable', 'clave' => 'contacto_emergencia_telefono', 'opcional' => true],
    ], 'transformar' => 'mayusculas']];

    $completo = ['contacto_emergencia_nombre' => 'María López', 'contacto_emergencia_parentesco' => 'Madre', 'contacto_emergencia_telefono' => '7771234567'];
    $sinTelefono = [...$completo, 'contacto_emergencia_telefono' => ''];

    expect($expresiones->evaluar($especificacion, $completo))->toBe('MARÍA LÓPEZ (MADRE) - 7771234567')
        ->and($expresiones->evaluar($especificacion, $sinTelefono))->toBe('MARÍA LÓPEZ (MADRE)')
        ->and($expresiones->variablesDe($especificacion)['requeridas'])->toBe(['contacto_emergencia_nombre'])
        ->and($expresiones->evaluar(['source_key' => 'nombre_completo', 'transform' => 'titulo'], ['nombre_completo' => 'JUAN PÉREZ']))->toBe('Juan Pérez')
        ->and($expresiones->evaluar(['expression' => ['partes' => [['tipo' => 'variable', 'clave' => 'sueldo_mensual', 'formato' => 'moneda']]]], ['sueldo_mensual' => '9000']))->toBe('9,000.00');

    $existe = fn (string $clave): bool => app(CatalogoVariablesDocumento::class)->existe($clave);
    expect($expresiones->validar($especificacion['expression'], $existe))->toBe([])
        ->and($expresiones->validar(['partes' => [['tipo' => 'variable', 'clave' => 'no_existe']]], $existe))->not->toBe([])
        ->and($expresiones->validar(['partes' => [['tipo' => 'texto', 'valor' => '<script>']]], $existe))->not->toBe([])
        ->and($expresiones->validar(['partes' => [['tipo' => 'variable', 'clave' => 'nombre_completo', 'codigo' => 'php']]], $existe))->not->toBe([])
        ->and($expresiones->validar(['partes' => [['tipo' => 'variable', 'clave' => 'contacto_emergencia']]], $existe))->not->toBe([]);
});

test('migración de mapeos: al importar, cada versión queda con su mapa en BD y compila idéntico al registro heredado', function () {
    if (! is_dir(base_path('docs/formatos_fuente')) || glob(base_path('docs/formatos_fuente').'/*.docx') === []) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente.');
    }

    dmMotorFielDePrueba();
    dlImportar(['formato_permiso.general', 'contrato_capacitacion.gestor', 'acta_negativa_firma.general']);

    $reporte = app(MigracionMapeosService::class)->migrar();

    expect($reporte)->toHaveCount(3);

    foreach ($reporte as $fila) {
        expect($fila['accion'])->toBe('ya_en_bd')
            ->and($fila['campos'])->toBeGreaterThan(5)
            ->and($fila['equivalencia'])->toBe('identica');
    }

    // Re-importar no resetea la validación de diseño (comparación canónica:
    // MySQL reordena las llaves JSON y antes eso se veía como «cambio»).
    $permiso = DocumentTemplate::query()->where('familia', 'formato_permiso.general')->sole();
    $validadoEn = $permiso->visual_checked_at?->toIso8601String();
    expect($validadoEn)->not->toBeNull();

    dlImportar(['formato_permiso.general']);

    expect($permiso->refresh()->visual_checked_at?->toIso8601String())->toBe($validadoEn)
        ->and($permiso->visual_validation_status->value)->toBe('passed');
});

/**
 * @param  list<string>  $familias
 */
function dlImportar(array $familias): void
{
    $catalogo = app(CatalogoMaestrosService::class);

    foreach (glob(base_path('docs/formatos_fuente').'/*') ?: [] as $ruta) {
        foreach ($catalogo->todasPorHash((string) hash_file('sha256', $ruta)) as $fuente) {
            if (in_array($fuente['familia'], $familias, true)) {
                app(ImportadorFormatosJuridicosService::class)->registrarVersion($fuente['familia'], $fuente['version'], (string) file_get_contents($ruta), [basename($ruta)], $fuente, null);
            }
        }
    }
}
