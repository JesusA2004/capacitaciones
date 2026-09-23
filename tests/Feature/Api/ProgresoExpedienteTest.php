<?php

use App\Enums\EstadoDocumento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Expedientes\ProgresoExpediente;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;

/**
 * Regla ÚNICA del avance del expediente: solo "aprobado" cuenta, floor(),
 * y nunca 100 % mientras falte un obligatorio.
 */
function tiposYVigentes(array $estados, int $opcionales = 0): array
{
    $tipos = new Collection;
    $vigentes = new Collection;
    $id = 1;

    foreach ($estados as $estado) {
        $tipo = (new DocumentType)->forceFill(['id' => $id, 'requerido' => true]);
        $tipos->push($tipo);
        if ($estado !== null) {
            $vigentes->put($id, (new EmployeeDocument)->forceFill(['document_type_id' => $id, 'status' => $estado]));
        }
        $id++;
    }

    for ($i = 0; $i < $opcionales; $i++, $id++) {
        $tipos->push((new DocumentType)->forceFill(['id' => $id, 'requerido' => false]));
        $vigentes->put($id, (new EmployeeDocument)->forceFill(['document_type_id' => $id, 'status' => EstadoDocumento::Aprobado]));
    }

    return [$tipos, $vigentes];
}

function aprobados(int $n): array
{
    return array_fill(0, $n, EstadoDocumento::Aprobado);
}

test('porcentajes base: 0/10, 5/10, 9/10, 10/10', function (int $completos, int $esperado) {
    [$tipos, $vigentes] = tiposYVigentes(array_merge(aprobados($completos), array_fill(0, 10 - $completos, null)));

    $p = ProgresoExpediente::calcular($tipos, $vigentes);

    expect($p['porcentaje'])->toBe($esperado)
        ->and($p['total_obligatorios'])->toBe(10)
        ->and($p['completos'])->toBe($completos)
        ->and($p['completo'])->toBe($completos === 10);
})->with([[0, 0], [5, 50], [9, 90], [10, 100]]);

test('8 aprobados, 1 en revision y 2 faltantes NO es 100 (y en revision no cuenta como aprobado)', function () {
    [$tipos, $vigentes] = tiposYVigentes(array_merge(aprobados(8), [EstadoDocumento::EnRevision, null, null]));

    $p = ProgresoExpediente::calcular($tipos, $vigentes);

    expect($p)->toMatchArray([
        'total_obligatorios' => 11,
        'completos' => 8,
        'faltantes' => 2,
        'en_revision' => 1,
        'rechazados' => 0,
        'pendientes' => 3,
        'porcentaje' => 72,
        'completo' => false,
    ]);
});

test('nunca redondea hacia arriba: 199 de 200 es 99', function () {
    [$tipos, $vigentes] = tiposYVigentes(array_merge(aprobados(199), [EstadoDocumento::EnRevision]));

    expect(ProgresoExpediente::calcular($tipos, $vigentes)['porcentaje'])->toBe(99);
});

test('rechazado, vencido, requiere correccion, cargado y cambios no cuentan como completos', function () {
    [$tipos, $vigentes] = tiposYVigentes([
        EstadoDocumento::Rechazado,
        EstadoDocumento::Vencido,
        EstadoDocumento::RequiereCorreccion,
        EstadoDocumento::Cargado,
        EstadoDocumento::CambioSolicitado,
        EstadoDocumento::CambioAutorizado,
    ]);

    $p = ProgresoExpediente::calcular($tipos, $vigentes);

    expect($p['completos'])->toBe(0)
        ->and($p['porcentaje'])->toBe(0)
        ->and($p['rechazados'])->toBe(3)
        ->and($p['en_revision'])->toBe(2)
        ->and($p['faltantes'])->toBe(1);
});

test('los opcionales no inflan el porcentaje', function () {
    [$tipos, $vigentes] = tiposYVigentes([EstadoDocumento::Aprobado, null], opcionales: 5);

    expect(ProgresoExpediente::calcular($tipos, $vigentes)['porcentaje'])->toBe(50);
});

test('sin obligatorios configurados: 0 % y bandera sin_obligatorios (nunca un 100 decorativo)', function () {
    [$tipos, $vigentes] = tiposYVigentes([], opcionales: 2);

    $p = ProgresoExpediente::calcular($tipos, $vigentes);

    expect($p['porcentaje'])->toBe(0)
        ->and($p['sin_obligatorios'])->toBeTrue()
        ->and($p['completo'])->toBeTrue();
});

test('la api del colaborador y el expediente documental usan la misma regla', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $tipos = DocumentType::factory()->count(11)->create(['requerido' => true, 'activo' => true]);
    foreach ($tipos->take(8) as $tipo) {
        EmployeeDocument::factory()->create(['user_id' => $usuario->id, 'colaborador_id' => $usuario->colaborador_id, 'document_type_id' => $tipo->id, 'status' => EstadoDocumento::Aprobado->value]);
    }
    EmployeeDocument::factory()->create(['user_id' => $usuario->id, 'colaborador_id' => $usuario->colaborador_id, 'document_type_id' => $tipos[8]->id, 'status' => EstadoDocumento::EnRevision->value]);

    Sanctum::actingAs($usuario);

    $progreso = $this->getJson('/api/v1/colaborador/incorporacion')->assertOk()->json('progreso');
    expect($progreso['porcentaje'])->toBe(72)
        ->and($progreso['completos'])->toBe(8)
        ->and($progreso['total_obligatorios'])->toBe(11)
        ->and($progreso['en_revision'])->toBe(1)
        ->and($progreso['faltantes'])->toBe(2)
        ->and($progreso['completo'])->toBeFalse();

    $documental = $this->getJson('/api/v1/colaborador/expediente')->assertOk()->json('data.expediente');
    expect((int) $documental['porcentaje'])->toBe(72)
        ->and($documental['completo'])->toBeFalse();
});
