<?php

use App\Models\OfficialFormat;
use App\Models\OfficialFormatGeneration;
use App\Models\User;
use App\Services\Formatos\OfficialFormatOverlayService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Smalot\PdfParser\Parser as PdfParser;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

function crearPdfPrueba(string $texto = 'Documento de prueba'): string
{
    $pdf = new Fpdi;
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 14);
    $pdf->Cell(0, 10, $texto);

    return (string) $pdf->Output('S');
}

function crearFormatoOficialDePrueba(array $atributos = []): OfficialFormat
{
    $ruta = 'formatos-oficiales/originales/'.uniqid('prueba', true).'.pdf';
    Storage::disk('nas')->put($ruta, crearPdfPrueba());

    return OfficialFormat::factory()->create([
        'source_disk' => 'nas',
        'source_path' => $ruta,
        ...$atributos,
    ]);
}

test('el comando importar-originales crea los formatos desde la carpeta local', function () {
    $carpeta = sys_get_temp_dir().'/formatos-prueba-'.uniqid();
    mkdir($carpeta);
    file_put_contents($carpeta.'/formato permiso mr. lana.pdf', crearPdfPrueba());
    config(['formatos_oficiales.origen_local' => $carpeta]);

    $this->artisan('formatos:importar-originales')
        ->assertExitCode(0);

    $formato = OfficialFormat::where('slug', 'formato-permiso')->first();

    expect($formato)->not->toBeNull()
        ->and($formato->nombre)->toBe('Formato de permiso')
        ->and($formato->tipo->value)->toBe('permiso')
        ->and(Storage::disk('nas')->exists($formato->source_path))->toBeTrue();

    unlink($carpeta.'/formato permiso mr. lana.pdf');
    rmdir($carpeta);
});

test('el comando importar-originales es idempotente y no duplica', function () {
    $carpeta = sys_get_temp_dir().'/formatos-prueba-'.uniqid();
    mkdir($carpeta);
    file_put_contents($carpeta.'/formato permiso mr. lana.pdf', crearPdfPrueba());
    config(['formatos_oficiales.origen_local' => $carpeta]);

    $this->artisan('formatos:importar-originales')->assertExitCode(0);
    $this->artisan('formatos:importar-originales')->assertExitCode(0);

    expect(OfficialFormat::where('slug', 'formato-permiso')->count())->toBe(1);

    unlink($carpeta.'/formato permiso mr. lana.pdf');
    rmdir($carpeta);
});

test('el overlay genera un pdf valido a partir del pdf base', function () {
    $formato = crearFormatoOficialDePrueba([
        'overlay_config' => [
            'nombre_completo' => [
                'pagina' => 1, 'x' => 20, 'y' => 30, 'font_size' => 12,
                'align' => 'left', 'max_width' => 150, 'color' => '#000000', 'enabled' => true,
            ],
        ],
    ]);

    $overlay = app(OfficialFormatOverlayService::class);
    $pdf = $overlay->generar($formato, ['nombre_completo' => 'Ana López Martínez']);

    expect(substr($pdf, 0, 4))->toBe('%PDF');

    $texto = (new PdfParser)->parseContent($pdf)->getText();
    expect($texto)->toContain('Ana López Martínez');
});

test('rh_admin puede generar un formato oficial y queda registrado con generated_by_id', function () {
    $formato = crearFormatoOficialDePrueba([
        'overlay_config' => [
            'nombre_completo' => [
                'pagina' => 1, 'x' => 20, 'y' => 30, 'font_size' => 12,
                'align' => 'left', 'max_width' => 150, 'color' => '#000000', 'enabled' => true,
            ],
        ],
    ]);

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create(['name' => 'Carlos', 'apellidos' => 'Ramírez Gómez']);

    $respuesta = $this->actingAs($rh)
        ->postJson(route('rh.formatos-oficiales.generar', $formato), [
            'tipo_sujeto' => 'colaborador',
            'sujeto_id' => $colaborador->id,
        ])
        ->assertOk()
        ->json();

    expect($respuesta['generacion']['id'])->toBeInt();

    $generacion = OfficialFormatGeneration::findOrFail($respuesta['generacion']['id']);

    expect($generacion->official_format_id)->toBe($formato->id)
        ->and($generacion->user_id)->toBe($colaborador->id)
        ->and($generacion->generated_by_id)->toBe($rh->id)
        ->and(Storage::disk('nas')->exists($generacion->generated_path))->toBeTrue();
});

test('generar un formato sin configuracion responde con advertencia y no truena', function () {
    $formato = crearFormatoOficialDePrueba(['overlay_config' => null]);

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create();

    $this->actingAs($rh)
        ->postJson(route('rh.formatos-oficiales.generar', $formato), [
            'tipo_sujeto' => 'colaborador',
            'sujeto_id' => $colaborador->id,
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Este formato necesita configurar dónde se colocarán los datos.']);
});

test('la descarga de un documento generado funciona y no expone rutas fisicas', function () {
    $formato = crearFormatoOficialDePrueba([
        'overlay_config' => [
            'nombre_completo' => [
                'pagina' => 1, 'x' => 20, 'y' => 30, 'font_size' => 12,
                'align' => 'left', 'max_width' => 150, 'color' => '#000000', 'enabled' => true,
            ],
        ],
    ]);

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create();

    $datos = $this->actingAs($rh)
        ->postJson(route('rh.formatos-oficiales.generar', $formato), [
            'tipo_sujeto' => 'colaborador',
            'sujeto_id' => $colaborador->id,
        ])->json();

    $this->actingAs($rh)
        ->get($datos['generacion']['descargar_url'])
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($rh)
        ->get(route('rh.formatos-oficiales.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('formatos', 1)
            ->missing('formatos.0.source_path')
            ->missing('formatos.0.source_disk'));
});

test('solo quien tiene formatos_oficiales.configurar puede guardar la configuracion', function () {
    $formato = crearFormatoOficialDePrueba();

    $auxiliar = User::factory()->create();
    $auxiliar->assignRole('rh_auxiliar');

    $this->actingAs($auxiliar)
        ->post(route('rh.formatos-oficiales.configuracion', $formato), [
            'overlay_config' => [
                'nombre_completo' => [
                    'pagina' => 1, 'x' => 10, 'y' => 10, 'font_size' => 10,
                    'align' => 'left', 'max_width' => 80, 'color' => '#000000', 'enabled' => true,
                ],
            ],
        ])
        ->assertForbidden();

    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $this->actingAs($admin)
        ->post(route('rh.formatos-oficiales.configuracion', $formato), [
            'overlay_config' => [
                'nombre_completo' => [
                    'pagina' => 1, 'x' => 10, 'y' => 10, 'font_size' => 10,
                    'align' => 'left', 'max_width' => 80, 'color' => '#000000', 'enabled' => true,
                ],
            ],
        ])
        ->assertRedirect();

    expect($formato->fresh()->tieneConfiguracion())->toBeTrue();
});
