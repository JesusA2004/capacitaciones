<?php

use App\Enums\TipoTarea;
use App\Models\User;
use App\Services\Notificaciones\PoliticaNotificacionRolService;
use Database\Seeders\RolesYPermisosSeeder;
use Symfony\Component\Finder\Finder;

/*
| El catálogo de config/notificaciones_catalogo.php es la fuente única del
| checklist: si se emite una clave fuera de él, esta prueba falla.
*/

/**
 * @return list<string>
 */
function cnClavesEmitidas(): array
{
    $patrones = [
        '/new Aviso\(\s*(?:evento:\s*)?\'([a-z_0-9]+)\'/',
        '/notificarEvento\(\s*\'([a-z_0-9]+)\'/',
        '/->notificar\(\s*(?:\[[^\]]*\]|\$[a-zA-Z]+|\$this->[^,]+\([^)]*\))\s*,\s*\'([a-z_0-9]+)\'/s',
        '/\$aprueba \? \'([a-z_0-9]+)\' : \'[a-z_0-9]+\'/',
        '/\$aprueba \? \'[a-z_0-9]+\' : \'([a-z_0-9]+)\'/',
        '/->avisar\(\$[a-zA-Z\[\]\'"]+, \'([a-z_0-9]+)\'/',
        '/->aviso\(\$[a-zA-Z]+, \'([a-z_0-9]+)\'/',
        '/->avisar\(\'(colaborador_[a-z]+)\'/',
        '/\$this->avisar\(\'([a-z_0-9]+)\', \$colaborador/',
    ];
    $claves = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $archivo) {
        foreach ($patrones as $patron) {
            preg_match_all($patron, $archivo->getContents(), $coincidencias);
            array_push($claves, ...$coincidencias[1]);
        }
    }

    return array_values(array_unique(array_diff($claves, ['pendiente_'])));
}

test('toda clave que emite el código está en el catálogo', function () {
    $politica = app(PoliticaNotificacionRolService::class);
    $claves = cnClavesEmitidas();

    // Si el rastreo se rompe, esta prueba no debe pasar sin revisar nada.
    expect(count($claves))->toBeGreaterThan(60);

    $fuera = array_values(array_filter($claves, fn (string $clave): bool => $politica->resolverClave($clave) === null));

    expect($fuera)->toBe([]);
});

test('una clave inventada queda fuera del catálogo, y el aviso se entrega con advertencia', function () {
    $politica = app(PoliticaNotificacionRolService::class);

    expect($politica->resolverClave('evento_que_no_existe'))->toBeNull()
        ->and($politica->permiteClave(new User, 'evento_que_no_existe'))->toBeTrue();
});

test('todo pendiente vigente resuelve su aviso, directo o del reconciliador', function () {
    $politica = app(PoliticaNotificacionRolService::class);

    $fuera = array_values(array_filter(TipoTarea::cases(), fn (TipoTarea $tipo): bool => ! $tipo->esLegacy()
        && ($politica->resolverClave($tipo->value) === null || $politica->resolverClave('pendiente_'.$tipo->value) === null)));

    expect(array_map(fn (TipoTarea $tipo): string => $tipo->value, $fuera))->toBe([]);
});

test('el catálogo es coherente: módulo real, audiencia válida, roles sembrados, obligatorias marcadas de fábrica', function () {
    $sembrados = array_keys(RolesYPermisosSeeder::permisosBasePorRol());
    $modulos = array_keys((array) config('notificaciones_catalogo.modulos'));
    $vistos = [];

    foreach ((array) config('notificaciones_catalogo.eventos') as $clave => $evento) {
        expect($evento['etiqueta'])->toBeString()->not->toBe('')
            ->and($modulos)->toContain($evento['modulo'])
            ->and(['personal', 'asignada', 'operativa'])->toContain($evento['audiencia']);

        foreach ([...($evento['defecto'] ?? []), ...($evento['obligatorios'] ?? [])] as $rol) {
            if ($rol !== '*') {
                expect($sembrados)->toContain($rol);
            }
        }

        foreach ($evento['obligatorios'] ?? [] as $rol) {
            expect($evento['defecto'])->toContain($rol);
        }

        // Una acción, una clave: un alias no puede pertenecer a dos.
        foreach ([$clave, ...($evento['alias'] ?? [])] as $nombre) {
            expect($vistos)->not->toContain($nombre);
            $vistos[] = $nombre;
        }
    }
});

test('RhVacacionCreadaNotification ya no existe y los eventos sin emisor salieron de la configuración', function () {
    expect(class_exists('App\\Notifications\\Mobile\\RhVacacionCreadaNotification'))->toBeFalse()
        ->and(config('configuracion_sistema.eventos'))
        ->not->toHaveKey('candidato_preautorizado')
        ->not->toHaveKey('baja_por_revisar')
        ->not->toHaveKey('baja_revision_juridico');
});
