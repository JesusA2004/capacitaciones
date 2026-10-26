<?php

namespace App\Services\Permisos;

use Database\Seeders\RolesYPermisosSeeder;
use Spatie\Permission\Models\Permission;

/**
 * Catálogo que presenta «Roles y permisos»: los módulos de
 * config/permisos_modulos.php agrupados por las mismas etapas del sidebar
 * (Inicio, 1 Reclutamiento… Configuración, Mi espacio), cada uno con su
 * acceso, su alcance (Propios…Todos) y TODAS sus acciones con etiqueta y
 * descripción.
 *
 * Lo arma la configuración, no la pantalla: un permiso nuevo que se declare
 * en un módulo aparece solo. Y nada queda oculto: todo permiso de la base o
 * del catálogo del seeder que no esté en ningún módulo se lista en «Otros
 * permisos», agrupado por su prefijo.
 *
 * También define qué permisos se pueden asignar a un rol (validación de
 * StoreRolRequest/UpdateRolRequest).
 */
class CatalogoPermisosService
{
    public const ETAPA_OTROS = 'otros';

    public const ETAPA_MI_ESPACIO = 'mi_espacio';

    private const ETIQUETAS_PERSONALES = [
        'portal.ver' => 'Entrar a Mi espacio',
        'portal.perfil.ver' => 'Ver mi perfil',
        'portal.solicitudes.ver' => 'Ver mis solicitudes',
        'portal.solicitudes.crear' => 'Crear mis solicitudes',
        'portal.notificaciones.ver' => 'Ver mis notificaciones',
    ];

    public function __construct(private readonly AlcanceModuloService $alcanceModulo) {}

    /**
     * @return array{
     *     etapas: list<array{clave: string, etiqueta: string, numero: int|null, modulos: list<array<string, mixed>>}>,
     *     otros: array<string, list<string>>,
     *     niveles: list<array{valor: string, etiqueta: string}>
     * }
     */
    public function paraPantalla(): array
    {
        $descripciones = $this->descripciones();
        $etapas = [];

        foreach ((array) config('permisos_modulos.etapas', []) as $clave => $etapa) {
            $etapas[(string) $clave] = [
                'clave' => (string) $clave,
                'etiqueta' => (string) (is_array($etapa) ? ($etapa['etiqueta'] ?? $clave) : $etapa),
                'numero' => is_array($etapa) && is_numeric($etapa['numero'] ?? null) ? (int) $etapa['numero'] : null,
                'modulos' => [],
            ];
        }

        $etapas[self::ETAPA_OTROS] ??= ['clave' => self::ETAPA_OTROS, 'etiqueta' => 'Otros módulos', 'numero' => null, 'modulos' => []];

        $config = (array) config('permisos_modulos.modulos', []);
        $etapaPorModulo = (array) config('permisos_modulos.etapa_por_modulo', []);

        foreach ($this->alcanceModulo->modulos() as $clave => $modulo) {
            $definicion = is_array($config[$clave] ?? null) ? $config[$clave] : [];
            $etapa = (string) ($definicion['etapa'] ?? $etapaPorModulo[$clave] ?? self::ETAPA_OTROS);
            $etapa = isset($etapas[$etapa]) ? $etapa : self::ETAPA_OTROS;

            $acciones = [];

            foreach ($modulo['acciones'] as $permiso => $etiqueta) {
                $crudo = $definicion['acciones'][$permiso] ?? null;
                $descripcion = is_array($crudo) && is_string($crudo['descripcion'] ?? null) ? $crudo['descripcion'] : null;

                $acciones[] = [
                    'permiso' => $permiso,
                    'etiqueta' => $etiqueta !== '' ? $etiqueta : $permiso,
                    'descripcion' => $descripcion ?? $descripciones[$permiso] ?? null,
                ];
            }

            $etapas[$etapa]['modulos'][] = [
                'clave' => $clave,
                'etiqueta' => $modulo['etiqueta'],
                'descripcion' => isset($definicion['descripcion']) && is_string($definicion['descripcion']) ? $definicion['descripcion'] : null,
                'acceso' => $modulo['acceso'] !== '' ? [
                    'permiso' => $modulo['acceso'],
                    'descripcion' => $descripciones[$modulo['acceso']] ?? null,
                ] : null,
                'alcances' => $modulo['alcances'],
                'acciones' => $acciones,
            ];
        }

        // «Mi espacio»: los permisos personales (portal.*) no son de ningún
        // módulo operativo; se presentan como un módulo propio.
        if (isset($etapas[self::ETAPA_MI_ESPACIO])) {
            $personales = array_values(array_diff(RolesYPermisosSeeder::permisosPersonales(), $this->permisosEnModulos()));

            if ($personales !== []) {
                $etapas[self::ETAPA_MI_ESPACIO]['modulos'][] = [
                    'clave' => 'portal_personal',
                    'etiqueta' => 'Portal personal',
                    'descripcion' => 'Lo que la persona ve de sí misma. Una cuenta enlazada a un colaborador activo ya tiene «Mi espacio» sin estos permisos.',
                    'acceso' => null,
                    'alcances' => [],
                    'acciones' => array_map(fn (string $permiso): array => [
                        'permiso' => $permiso,
                        'etiqueta' => self::ETIQUETAS_PERSONALES[$permiso] ?? $permiso,
                        'descripcion' => $descripciones[$permiso] ?? null,
                    ], $personales),
                ];
            }
        }

        return [
            'etapas' => array_values(array_filter($etapas, fn (array $etapa): bool => $etapa['modulos'] !== [])),
            'otros' => $this->otrosPermisos(),
            'niveles' => [
                ['valor' => 'propios', 'etiqueta' => 'Propios'],
                ['valor' => 'equipo', 'etiqueta' => 'Equipo'],
                ['valor' => 'sucursal', 'etiqueta' => 'Sucursal'],
                ['valor' => 'region', 'etiqueta' => 'Región'],
                ['valor' => 'todos', 'etiqueta' => 'Todos'],
            ],
        ];
    }

    /**
     * Permisos que se pueden asignar a un rol: todo el catálogo (seeder +
     * módulos) que ya exista en la base.
     *
     * @return list<string>
     */
    public function asignables(): array
    {
        $existentes = Permission::query()->where('guard_name', 'web')->pluck('name')->map(fn ($p): string => (string) $p)->all();

        return array_values(array_intersect(RolesYPermisosSeeder::catalogoPermisos(), $existentes));
    }

    /**
     * Permisos de la base o del catálogo que ningún módulo presenta,
     * agrupados por su prefijo (lo que va antes del primer punto).
     *
     * @return array<string, list<string>>
     */
    public function otrosPermisos(): array
    {
        $existentes = Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->map(fn ($p): string => (string) $p)->all();
        $todos = array_values(array_unique([...$existentes, ...RolesYPermisosSeeder::catalogoPermisos()]));
        // Los retirados (generales ya divididos en acciones) no se ofrecen:
        // asignarlos no habilitaría nada.
        $mapeados = array_flip([...$this->permisosEnModulos(), ...RolesYPermisosSeeder::permisosPersonales(), ...RolesYPermisosSeeder::retirados()]);
        sort($todos);

        $grupos = [];

        foreach ($todos as $permiso) {
            if (isset($mapeados[$permiso])) {
                continue;
            }

            $grupos[explode('.', $permiso)[0]][] = $permiso;
        }

        ksort($grupos);

        return $grupos;
    }

    /**
     * @return list<string>
     */
    private function permisosEnModulos(): array
    {
        $permisos = [];

        foreach ($this->alcanceModulo->modulos() as $modulo) {
            $permisos[] = $modulo['acceso'];
            array_push($permisos, ...array_values($modulo['alcances']), ...array_keys($modulo['acciones']));
        }

        return array_values(array_filter(array_unique($permisos), fn (string $p): bool => $p !== ''));
    }

    /**
     * @return array<string, string>
     */
    private function descripciones(): array
    {
        $descripciones = [];

        foreach ((array) config('permisos_modulos.descripciones', []) as $permiso => $texto) {
            if (is_string($permiso) && is_string($texto)) {
                $descripciones[$permiso] = $texto;
            }
        }

        return $descripciones;
    }
}
