<?php

use App\Enums\EstadoUsuario;
use App\Enums\TipoAsignacionNodoComercial;
use App\Models\AsignacionNodoComercial;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Organigrama\SincronizadorOrganigramaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * La plantilla completa que deja `php artisan db:seed` (local/testing,
 * incluye DemoSeeder) respeta la estructura confirmada.
 */
test('la plantilla demo cumple la estructura confirmada', function () {
    // El demo genera PDFs de expediente: disco falso para no chocar con
    // otras corridas en paralelo sobre el mismo NAS de pruebas.
    Storage::fake('nas');
    $this->seed(DatabaseSeeder::class);

    $id = fn (string $nombre) => Puesto::query()->where('nombre', $nombre)->value('id');
    $activos = fn (string $puesto) => Colaborador::query()->where('estatus', EstadoUsuario::Activo->value)->where('puesto_id', $id($puesto));
    $corporativo = Sucursal::query()->where('clave', 'CORP01')->value('id');

    foreach (Sucursal::query()->where('activo', true)->where('id', '!=', $corporativo)->get() as $sucursal) {
        $gerentes = $activos('Gerente de Sucursal')->where('sucursal_principal_id', $sucursal->id)->count();
        $cubierta = CoberturaPuesto::query()->where('activa', true)->where('puesto_id', $id('Gerente de Sucursal'))->where('sucursal_id', $sucursal->id)->exists();

        expect($gerentes + ($cubierta ? 1 : 0))->toBe(1, "Gerente en {$sucursal->nombre}")
            ->and($activos('Subgerente')->where('sucursal_principal_id', $sucursal->id)->count())->toBe(1, "Subgerente en {$sucursal->nombre}");
    }

    expect($activos('Coordinadora de Sucursal')->where('sucursal_principal_id', $corporativo)->count())->toBe(0)
        ->and($activos('Asistente de Dirección Comercial')->count())->toBeLessThanOrEqual(1)
        ->and($activos('Monitorista')->count())->toBe(1);

    $rutasPorGestor = AsignacionNodoComercial::query()
        ->where('activo', true)
        ->where('tipo_asignacion', TipoAsignacionNodoComercial::Gestor->value)
        ->selectRaw('colaborador_id, count(*) as total')
        ->groupBy('colaborador_id')
        ->pluck('total');

    expect($rutasPorGestor->max())->toBe(1);

    // Cobertura regional demo: Q3 cubierta por la titular de Q1, sin cambiarle el puesto.
    $cobertura = CoberturaPuesto::query()->where('activa', true)->where('puesto_id', $id('Gerente Regional Q3'))->first();
    expect($cobertura)->not->toBeNull()
        ->and($cobertura->colaborador->puesto_id)->toBe($id('Gerente Regional Q1'));

    expect(app(SincronizadorOrganigramaService::class)->sincronizar(simular: true)['conflictos'])->toBe([]);
});
