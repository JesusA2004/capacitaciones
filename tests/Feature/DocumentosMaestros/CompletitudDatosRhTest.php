<?php

use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use Illuminate\Support\Facades\Cache;

/*
| "Dashboard de RH: datos faltantes" (sección 17/22 del encargo): las
| columnas a revisar salen del mapping REAL de los masters activos (nunca
| una lista de "obligatorios" escrita a mano), y el conteo se hace en
| bloque con una sola consulta SQL — nunca iterando completitudAlta() por
| cada colaborador de la plantilla.
*/

function cdrMaster(array $camposRequeridos): DocumentTemplate
{
    return DocumentTemplate::factory()->create([
        'familia' => 'prueba.cdr_'.uniqid(),
        'estado_master' => 'listo',
        'operativo' => true,
        'activo' => true,
        'motor' => 'docx',
        'mapping' => [
            'motor' => 'docx',
            'instancias' => array_map(fn (string $campo): array => ['campo' => $campo, 'opcional' => false], $camposRequeridos),
        ],
    ]);
}

test('columnasColaboradorRequeridasDe(): solo columnas reales de colaboradores, nunca relación/proceso/manual', function () {
    $master = cdrMaster(['nombre_completo', 'estado_civil', 'puesto_mayusculas', 'fecha_firma_a_los_inicio_contrato', 'testigo_1_nombre']);

    $columnas = app(MotorDocumentalService::class)->columnasColaboradorRequeridasDe($master);

    expect($columnas)->toHaveKey('estado_civil')
        ->and($columnas)->not->toHaveKey('puesto_id')
        ->and($columnas)->not->toHaveKey('contrato')
        ->and($columnas)->not->toHaveKey('testigo_1_nombre');
});

test('contarDatosFaltantesRh(): cuenta en UNA sola consulta a quién le falta algún dato que un master activo requiere', function () {
    Cache::forget('people:completitud-datos:columnas');
    cdrMaster(['estado_civil', 'telefono']);

    $completo = Colaborador::factory()->create(['estado_civil' => 'soltero', 'telefono' => '7771234567']);
    $incompleto = Colaborador::factory()->create(['estado_civil' => null, 'telefono' => '7771234567']);

    $servicio = app(DocumentoProcesoService::class);
    $total = $servicio->contarDatosFaltantesRh(Colaborador::query()->whereIn('id', [$completo->id, $incompleto->id]));

    expect($total)->toBe(1);
});

test('listarDatosFaltantesRh(): sin masters activos con columnas de colaborador, no revisa nada (nunca inventa una lista aparte)', function () {
    Cache::forget('people:completitud-datos:columnas');

    $detalle = app(DocumentoProcesoService::class)->listarDatosFaltantesRh(Colaborador::query());

    expect($detalle)->toBe([]);
});
