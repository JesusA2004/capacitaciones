<?php

namespace App\Services\Permisos;

use App\Enums\AlcanceDatos;
use App\Support\CatalogoPermisosAppMovil;
use App\Support\CatalogoPermisosPendientes;
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

    /**
     * Permisos anteriores al catálogo por módulos (API de la app de RH,
     * alta digital, solicitudes): siguen vigentes y se muestran en «Otros
     * permisos» con un texto legible.
     */
    private const ETIQUETAS_LEGADO = [
        'candidatos.eliminar' => 'Eliminar candidatos',
        'ciclo.preautorizar' => 'Preautorizar bajas de su equipo',
        'colaborador.incorporacion.ver' => 'Ver su propio proceso de alta (app)',
        'colaborador.incorporacion.documentos.subir' => 'Subir los documentos de su alta (app)',
        'colaborador.incorporacion.documentos.solicitar-cambio' => 'Pedir cambio de un documento de su alta (app)',
        'colaboradores.activar' => 'Activar colaboradores',
        'colaboradores.alta' => 'Dar de alta colaboradores',
        'documentos_laborales.revisar_firmado' => 'Revisar documentos laborales firmados',
        'excepciones.autorizar' => 'Autorizar excepciones del periodo de prueba',
        'expedientes.datos_medicos.ver' => 'Ver los datos médicos del expediente',
        'rh.mobile.dashboard.ver' => 'App de RH: ver el tablero',
        'rh.pendientes.ver' => 'App de RH: ver los pendientes',
        'rh.colaboradores.ver' => 'App de RH: ver colaboradores',
        'rh.colaboradores.detalle' => 'App de RH: ver el detalle de un colaborador',
        'rh.documentos.ver' => 'App de RH: ver documentos por revisar',
        'rh.documentos.detalle' => 'App de RH: ver el detalle de un documento',
        'rh.documentos.ver_archivo' => 'App de RH: abrir el archivo de un documento',
        'rh.documentos.aprobar' => 'App de RH: aprobar documentos',
        'rh.documentos.rechazar' => 'App de RH: rechazar documentos',
        'rh.documentos.extraccion.ver' => 'App de RH: ver los datos leídos de un documento',
        'rh.documentos.extraccion.aplicar' => 'App de RH: aplicar los datos leídos de un documento',
        'rh.documentos.extraccion.ignorar' => 'App de RH: ignorar los datos leídos de un documento',
        'rh.documentos.extraccion.reprocesar' => 'App de RH: volver a leer un documento',
        'rh.expedientes.ver' => 'App de RH: ver expedientes',
        'rh.expedientes.detalle' => 'App de RH: ver el detalle de un expediente',
        'rh.expedientes.documentos.ver' => 'App de RH: ver los documentos del expediente',
        'rh.expedientes.documentos.aprobar' => 'App de RH: aprobar documentos del expediente',
        'rh.expedientes.documentos.rechazar' => 'App de RH: rechazar documentos del expediente',
        'rh.expedientes.documentos.autorizar-cambio' => 'App de RH: autorizar el cambio de un documento',
        'rh.expedientes.incorporacion.aprobar' => 'App de RH: aprobar el alta de un expediente',
        'rh.expedientes.incorporacion.rechazar' => 'App de RH: rechazar el alta de un expediente',
        'rh.incorporaciones.ver' => 'App de RH: ver altas en proceso',
        'rh.incorporaciones.detalle' => 'App de RH: ver el detalle de un alta',
        'rh.incorporaciones.aprobar' => 'App de RH: aprobar altas',
        'rh.incorporaciones.rechazar' => 'App de RH: rechazar altas',
        'rh.solicitudes.ver' => 'App de RH: ver solicitudes',
        'rh.solicitudes.detalle' => 'App de RH: ver el detalle de una solicitud',
        'rh.solicitudes.aprobar' => 'App de RH: aprobar solicitudes',
        'rh.solicitudes.rechazar' => 'App de RH: rechazar solicitudes',
        'rh.solicitudes.correccion' => 'App de RH: pedir corrección de una solicitud',
        'rh.vacaciones.ver' => 'App de RH: ver vacaciones',
        'rh.vacaciones.detalle' => 'App de RH: ver el detalle de unas vacaciones',
        'rh.vacaciones.aprobar' => 'App de RH: aprobar vacaciones',
        'rh.vacaciones.rechazar' => 'App de RH: rechazar vacaciones',
        'solicitudes.adjuntos.subir' => 'Subir adjuntos a las solicitudes',
        'solicitudes.bajas.crear' => 'Crear solicitudes de baja',
        'solicitudes.bajas.aprobar' => 'Aprobar solicitudes de baja',
        'solicitudes.bajas.omitir_finiquito' => 'Cerrar una baja sin finiquito',
        'solicitudes.configuracion.ver' => 'Ver la configuración de solicitudes',
        'vacaciones.reportes' => 'Ver reportes de vacaciones',
    ];

    public function __construct(private readonly AlcanceModuloService $alcanceModulo) {}

    /**
     * @return array{
     *     etapas: list<array{clave: string, etiqueta: string, numero: int|null, modulos: list<array<string, mixed>>}>,
     *     otros: array<string, list<string>>,
     *     etiquetas: array<string, string>,
     *     niveles: list<array{valor: string, etiqueta: string, descripcion: string}>
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
            $grupoDe = $this->grupoDeCadaAccion($definicion);

            foreach ($modulo['acciones'] as $permiso => $etiqueta) {
                $crudo = $definicion['acciones'][$permiso] ?? null;
                $descripcion = is_array($crudo) && is_string($crudo['descripcion'] ?? null) ? $crudo['descripcion'] : null;

                $acciones[] = [
                    'permiso' => $permiso,
                    'etiqueta' => $etiqueta !== '' ? $etiqueta : $permiso,
                    'descripcion' => $descripcion ?? $descripciones[$permiso] ?? null,
                    // Paso del recorrido al que pertenece (grupos_acciones); null = sin agrupar.
                    'grupo' => $grupoDe[$permiso] ?? null,
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

        $otros = $this->otrosPermisos();

        return [
            'etapas' => array_values(array_filter($etapas, fn (array $etapa): bool => $etapa['modulos'] !== [])),
            'otros' => $otros,
            'etiquetas' => $this->etiquetasDe(array_merge(...array_values($otros))),
            'niveles' => array_map(fn (AlcanceDatos $nivel): array => [
                'valor' => $nivel->value,
                'etiqueta' => $nivel->etiqueta(),
                'descripcion' => $nivel->descripcion(),
            ], [AlcanceDatos::Propios, AlcanceDatos::Equipo, AlcanceDatos::Sucursal, AlcanceDatos::SucursalCorporativo, AlcanceDatos::Region, AlcanceDatos::Todos]),
        ];
    }

    /**
     * Permisos que se pueden asignar a un rol o a una persona: TODO el
     * catálogo (seeder + módulos). La pantalla ya los muestra desde la
     * configuración, así que si un deploy no corrió
     * `people:sincronizar-permisos` y faltan filas en la base, se crean aquí
     * con la misma sincronización segura del deploy (crea los faltantes,
     * reparte base/herencia solo de los recién creados, nunca quita nada) en
     * vez de rechazar el guardado con «no existe en el catálogo».
     *
     * @return list<string>
     */
    public function asignables(): array
    {
        $catalogo = RolesYPermisosSeeder::catalogoPermisos();
        $existentes = Permission::query()->where('guard_name', 'web')->pluck('name')->map(fn ($p): string => (string) $p)->all();

        if (array_diff($catalogo, $existentes) !== []) {
            app(SincronizadorPermisosService::class)->sincronizar();
            $existentes = Permission::query()->where('guard_name', 'web')->pluck('name')->map(fn ($p): string => (string) $p)->all();
        }

        return array_values(array_intersect($catalogo, $existentes));
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
     * Texto en español de los permisos que se listan fuera de un módulo
     * («Otros permisos»): nunca se muestra el código técnico. Las etiquetas
     * de la app y de pendientes salen de su catálogo en código, así se leen
     * bien aunque la configuración del servidor esté desactualizada.
     *
     * @param  list<string>  $permisos
     * @return array<string, string>
     */
    private function etiquetasDe(array $permisos): array
    {
        $conocidas = [
            ...self::ETIQUETAS_LEGADO,
            ...CatalogoPermisosPendientes::acciones(),
            ...CatalogoPermisosAppMovil::acciones(),
        ];
        $etiquetas = [];

        foreach ($permisos as $permiso) {
            if (isset($conocidas[$permiso])) {
                $etiquetas[$permiso] = $conocidas[$permiso];
            }
        }

        return $etiquetas;
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

    /**
     * config/permisos_modulos.php → modulos.<clave>.grupos_acciones: título
     * del grupo (p. ej. «Paso 4 · Socioeconómico») => permisos.
     *
     * @param  array<array-key, mixed>  $definicion
     * @return array<string, string> permiso => grupo
     */
    private function grupoDeCadaAccion(array $definicion): array
    {
        $grupoDe = [];

        foreach ((array) ($definicion['grupos_acciones'] ?? []) as $grupo => $permisos) {
            foreach ((array) $permisos as $permiso) {
                if (is_string($grupo) && is_string($permiso)) {
                    $grupoDe[$permiso] = $grupo;
                }
            }
        }

        return $grupoDe;
    }
}
