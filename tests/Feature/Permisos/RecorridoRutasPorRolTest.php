<?php

use App\Models\Colaborador;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Routing\Route as RutaLaravel;
use Illuminate\Support\Facades\Route;

/*
 * Recorre TODAS las pantallas GET sin parámetros del portal (no una lista
 * fija de 2 rutas) con cada rol administrativo y falla si alguna responde
 * 403 donde no debe. Las rutas personales ("Mi espacio") dependen de tener
 * un Colaborador real vinculado, así que para una cuenta sin colaborador se
 * excluyen (el menú tampoco las ofrece).
 */

/**
 * @return list<RutaLaravel>
 */
function rrRutasGet(): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), function (RutaLaravel $ruta): bool {
        $uri = $ruta->uri();

        return in_array('GET', $ruta->methods(), true)
            && ! str_contains($uri, '{')
            && in_array('auth', $ruta->gatherMiddleware(), true)
            // Recursos con URL firmada (segmentos de video, audio): no son
            // pantallas y sin firma responden 403 por diseño.
            && ! in_array('signed', $ruta->gatherMiddleware(), true)
            && ! str_starts_with($uri, 'api/')
            && ! str_contains($uri, 'logout');
    }));
}

function rrEsPersonal(RutaLaravel $ruta): bool
{
    return in_array('can:modo-colaborador', $ruta->gatherMiddleware(), true);
}

/**
 * @return list<string>
 */
function rrProhibidas(User $usuario, bool $incluirPersonales): array
{
    $prohibidas = [];

    foreach (rrRutasGet() as $ruta) {
        if (! $incluirPersonales && rrEsPersonal($ruta)) {
            continue;
        }

        $respuesta = test()->actingAs($usuario)->get('/'.$ruta->uri());

        if ($respuesta->getStatusCode() === 403) {
            $prohibidas[] = '/'.$ruta->uri().' ['.$ruta->getName().']';
        }
    }

    return $prohibidas;
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('super_admin SIN colaborador vinculado nunca recibe 403 en una herramienta administrativa/operativa', function () {
    $admin = User::factory()->create(['colaborador_id' => null]);
    $admin->assignRole('super_admin');

    expect(rrProhibidas($admin, false))->toBe([]);
});

test('super_admin CON colaborador nunca recibe 403, ni en Mi espacio', function () {
    $admin = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $admin->assignRole('super_admin');

    expect(rrProhibidas($admin, true))->toBe([]);
});

test('rh_admin no recibe 403 en ninguna pantalla PEOPLE/RH', function () {
    $rh = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $rh->assignRole('rh_admin');

    // Fuera de PEOPLE/RH por diseño: roles y apariencia son de super_admin;
    // capacitación (cursos, asignaciones, calificaciones…) es otro módulo
    // y en producción está detrás del feature flag `capacitacion`.
    $fueraDeRh = ['/administracion/roles', '/administracion/configuracion/apariencia', '/cursos', '/asignaciones', '/multimedia', '/bancos-preguntas', '/calificaciones', '/reportes/cumplimiento'];

    $prohibidas = array_values(array_filter(rrProhibidas($rh, false), function (string $ruta) use ($fueraDeRh): bool {
        foreach ($fueraDeRh as $prefijo) {
            if (str_starts_with($ruta, $prefijo)) {
                return false;
            }
        }

        return true;
    }));

    expect($prohibidas)->toBe([]);
});
