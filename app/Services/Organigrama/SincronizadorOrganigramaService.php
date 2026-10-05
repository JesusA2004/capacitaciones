<?php

namespace App\Services\Organigrama;

use App\Enums\ClaseEntradaMatriz;
use App\Enums\EstadoUsuario;
use App\Enums\TipoAsignacionNodoComercial;
use App\Enums\TipoNodoComercial;
use App\Enums\TipoPuesto;
use App\Models\AsignacionNodoComercial;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\MatrizComercial\ClasificadorNodoComercial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura organizacional CONFIRMADA por dirección (2026-09-29) y su
 * sincronización segura con una base existente (producción incluida). La
 * usan PuestoJerarquiaSeeder y `php artisan people:sincronizar-organigrama`
 * (con --simular no escribe nada). Ver docs/ORGANIGRAMA.md.
 *
 *   Dirección General
 *   └── Dirección Comercial
 *       ├── Asistente de Dirección Comercial        (1 plaza)
 *       ├── Responsable de Sistemas
 *       │   └── Monitorista                          (hoy 1)
 *       ├── Gerencia de Recursos Humanos
 *       │   ├── Administración de Personal
 *       │   └── Reclutamiento
 *       ├── Gerente de Mesa de Control
 *       │   └── Analista de Mesa de Control
 *       ├── Gerente de Contraloría
 *       │   ├── Auditora
 *       │   ├── Tesorero
 *       │   └── Contador
 *       ├── Coordinadora Regional                    (vive en Corporativo)
 *       │   └── Coordinadora de Sucursal             (1 por sucursal; Corporativo no tiene)
 *       ├── Gerente Regional Q1   ─┐  cada una ligada a su región de la matriz
 *       └── Gerente Regional Q3   ─┘  (Q2 no existe)
 *           └── Gerente de Sucursal                  (1 por sucursal, depende del regional de SU región)
 *               └── Subgerente                       (1 por sucursal)
 *                   ├── Gestor                       (1 ruta de cobro vigente, 1 plaza)
 *                   └── Gestor Volante               (plaza de plantilla, sin ruta fija)
 *
 * Reglas de seguridad: nunca borra datos en uso ni asigna personas; solo
 * crea/renombra puestos (conserva id, gente e historial), corrige
 * "reporta a", liga regiones con su puesto regional y reclasifica las
 * entradas de la matriz. Todo lo que no puede decidir solo lo REPORTA como
 * conflicto. Los puestos fuera de la estructura confirmada (Contraloría,
 * Mesa de Control, Gestor grupal, Asistente de Dirección General) se
 * conservan sin tocar, por decisión de dirección.
 */
class SincronizadorOrganigramaService
{
    public const REGIONES = ['Q1', 'Q3'];

    /** nombre anterior => nombre confirmado (conserva id e historial). */
    private const RENOMBRES = [
        'Gerente de Contabilidad' => 'Gerente de Contraloría',
        'Gerente administrativo regional' => 'Coordinadora Regional',
        'Gestor fijo' => 'Gestor',
        'Director comercial' => 'Dirección Comercial',
        'Gerente de Sistemas' => 'Responsable de Sistemas',
        'Gerente de Recursos Humanos' => 'Gerencia de Recursos Humanos',
        'Coordinadora regional' => 'Coordinadora Regional',
        'Coordinadora' => 'Coordinadora de Sucursal',
        'Gestor volante' => 'Gestor Volante',
    ];

    /**
     * El puesto genérico anterior de gerente regional. Si nadie lo usa se
     * convierte en "Gerente Regional Q1"; si alguien lo ocupa NO se adivina
     * su región: queda como está y se reporta para reasignarlo a Q1 o Q3.
     */
    private const REGIONAL_ANTERIOR = 'Gerente regional';

    /**
     * Estructura confirmada, de arriba hacia abajo (un superior siempre va
     * antes que sus subordinados).
     *
     * @var array<string, array{superior: string|null, departamento: string, nivel: int, tipo: TipoPuesto, descripcion: string, extra?: array<string, mixed>}>
     */
    private const ESTRUCTURA = [
        'Dirección General' => ['superior' => null, 'departamento' => 'Dirección', 'nivel' => 1, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Cabeza de la organización.', 'extra' => ['responsabilidades' => 'Dirección estratégica de la empresa y aprobación de decisiones de alto nivel.']],
        'Dirección Comercial' => ['superior' => 'Dirección General', 'departamento' => 'Ventas', 'nivel' => 2, 'tipo' => TipoPuesto::Comercial, 'descripcion' => 'Dirección Comercial de Mr. Lana: de ella dependen la asistente, Sistemas, Recursos Humanos, la Coordinación Regional y las Gerencias Regionales.', 'extra' => ['responsabilidades' => 'Vista global, reportes generales y decisiones estratégicas.']],
        'Asistente de Dirección Comercial' => ['superior' => 'Dirección Comercial', 'departamento' => 'Ventas', 'nivel' => 3, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Asistencia directa a la Dirección Comercial. Una sola plaza.'],
        'Responsable de Sistemas' => ['superior' => 'Dirección Comercial', 'departamento' => 'Sistemas', 'nivel' => 3, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Responsable de la plataforma, infraestructura y monitoreo.'],
        'Monitorista' => ['superior' => 'Responsable de Sistemas', 'departamento' => 'Sistemas', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Monitoreo de sistemas y operación.', 'extra' => ['crecimiento' => 'Responsable de Sistemas']],
        'Gerencia de Recursos Humanos' => ['superior' => 'Dirección Comercial', 'departamento' => 'Recursos Humanos', 'nivel' => 3, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Responsable de Recursos Humanos.'],
        'Administración de Personal' => ['superior' => 'Gerencia de Recursos Humanos', 'departamento' => 'Recursos Humanos', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Expedientes, altas, bajas y trámites de personal.', 'extra' => ['crecimiento' => 'Gerencia de Recursos Humanos']],
        'Reclutamiento' => ['superior' => 'Gerencia de Recursos Humanos', 'departamento' => 'Recursos Humanos', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Atracción y selección de candidatos.', 'extra' => ['crecimiento' => 'Gerencia de Recursos Humanos']],
        // Mesa de Control y Contraloría son áreas DISTINTAS, cada una con su
        // gerente bajo Dirección Comercial. La Auditora es de Contraloría:
        // nunca cuelga de Mesa de Control ni comparte su nodo.
        'Gerente de Mesa de Control' => ['superior' => 'Dirección Comercial', 'departamento' => 'Mesa de Control', 'nivel' => 3, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Responsable de la mesa de control: validación de operaciones de crédito.'],
        'Analista de Mesa de Control' => ['superior' => 'Gerente de Mesa de Control', 'departamento' => 'Mesa de Control', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Análisis y validación en mesa de control.', 'extra' => ['crecimiento' => 'Gerente de Mesa de Control']],
        'Gerente de Contraloría' => ['superior' => 'Dirección Comercial', 'departamento' => 'Contraloría', 'nivel' => 3, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Responsable de contraloría: auditoría, tesorería y contabilidad.'],
        'Auditora' => ['superior' => 'Gerente de Contraloría', 'departamento' => 'Contraloría', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Auditoría interna de sucursales y procesos.', 'extra' => ['crecimiento' => 'Gerente de Contraloría']],
        'Tesorero' => ['superior' => 'Gerente de Contraloría', 'departamento' => 'Contraloría', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Tesorería: flujo de efectivo, pagos y fondeo.', 'extra' => ['crecimiento' => 'Gerente de Contraloría']],
        'Contador' => ['superior' => 'Gerente de Contraloría', 'departamento' => 'Contraloría', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Contabilidad general y cumplimiento fiscal.', 'extra' => ['crecimiento' => 'Gerente de Contraloría']],
        'Coordinadora Regional' => ['superior' => 'Dirección Comercial', 'departamento' => 'Operaciones', 'nivel' => 3, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Coordinadora Regional: una sola, ubicada en Corporativo; de ella dependen las coordinadoras de sucursal.'],
        'Coordinadora de Sucursal' => ['superior' => 'Coordinadora Regional', 'departamento' => 'Operaciones', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Una por sucursal (Corporativo no tiene): cuadre de caja, control administrativo y procesos internos.', 'extra' => ['crecimiento' => 'Coordinadora Regional']],
        'Gerente Regional Q1' => ['superior' => 'Dirección Comercial', 'departamento' => 'Ventas', 'nivel' => 3, 'tipo' => TipoPuesto::Comercial, 'descripcion' => 'Gerente de la Región Q1: de él dependen los gerentes de las sucursales de Q1.', 'extra' => ['crecimiento' => 'Dirección Comercial']],
        'Gerente Regional Q3' => ['superior' => 'Dirección Comercial', 'departamento' => 'Ventas', 'nivel' => 3, 'tipo' => TipoPuesto::Comercial, 'descripcion' => 'Gerente de la Región Q3: de él dependen los gerentes de las sucursales de Q3.', 'extra' => ['crecimiento' => 'Dirección Comercial']],
        // Reporta al gerente regional de SU región (se resuelve por la
        // región de la sucursal); "Gerente Regional Q1" es solo el superior
        // de referencia en el catálogo.
        'Gerente de Sucursal' => ['superior' => 'Gerente Regional Q1', 'departamento' => 'Ventas', 'nivel' => 4, 'tipo' => TipoPuesto::Comercial, 'descripcion' => 'Responsable de la sucursal (1 por sucursal). Reporta al gerente regional de su región.', 'extra' => ['esquema_comisiones' => 'Comisión por resultados de sucursal.', 'responsabilidades' => 'Supervisa la operación de la sucursal y participa en la aprobación de candidatos y solicitudes.']],
        'Subgerente' => ['superior' => 'Gerente de Sucursal', 'departamento' => 'Ventas', 'nivel' => 5, 'tipo' => TipoPuesto::Comercial, 'descripcion' => 'Uno por sucursal: apoya al gerente, lo cubre temporalmente y supervisa a los gestores.', 'extra' => ['crecimiento' => 'Gerente de Sucursal', 'esquema_comisiones' => 'Comisión por equipo de gestores.']],
        'Gestor' => ['superior' => 'Subgerente', 'departamento' => 'Ventas', 'nivel' => 6, 'tipo' => TipoPuesto::Comercial, 'descripcion' => 'Gestor de crédito: una plaza de plantilla y UNA ruta de cobro vigente (la ruta es una asignación de la matriz comercial, no parte del nombre del puesto).', 'extra' => ['crecimiento' => 'Subgerente', 'esquema_comisiones' => 'Comisión por cartera/ruta asignada.', 'requiere_ruta' => true]],
        'Gestor Volante' => ['superior' => 'Subgerente', 'departamento' => 'Ventas', 'nivel' => 6, 'tipo' => TipoPuesto::Comercial, 'descripcion' => 'Cuenta en la plantilla autorizada pero no tiene ruta fija: cubre la ruta de un gestor que faltó, una vacante temporal o apoya la operación.', 'extra' => ['crecimiento' => 'Gestor', 'esquema_comisiones' => 'Comisión variable de apoyo.']],
    ];

    /**
     * Puestos que existen pero NO forman parte de la estructura confirmada:
     * se conservan sin tocar (decisión de dirección) y solo se reportan.
     */
    private const FUERA_DE_ESTRUCTURA = [
        'Asistente de Dirección General',
        'Gestor grupal',
    ];

    private const CLAVE_CORPORATIVO = 'CORP01';

    private bool $simular = false;

    /** @var array<string, list<string>> */
    private array $reporte = [];

    /**
     * En --simular los renombres no se escriben: nombre confirmado =>
     * nombre que todavía tiene en la base, para seguir encontrándolo.
     *
     * @var array<string, string>
     */
    private array $renombradosSimulados = [];

    public function __construct(private readonly ClasificadorNodoComercial $clasificador) {}

    /**
     * @return array<string, list<string>> sección => líneas del reporte
     */
    public function sincronizar(bool $simular = false): array
    {
        $this->simular = $simular;
        $this->renombradosSimulados = [];
        $this->reporte = [
            'puestos_nuevos' => [],
            'puestos_renombrados' => [],
            'relaciones_actualizadas' => [],
            'fuera_de_estructura' => [],
            'regiones' => [],
            'q2' => [],
            'rutas_clasificadas' => [],
            'nodos_legacy' => [],
            'conflictos' => [],
        ];

        $ejecutar = function (): void {
            foreach (self::RENOMBRES as $anterior => $nuevo) {
                $this->renombrar($anterior, $nuevo);
            }

            $this->convertirRegionalAnterior();
            $this->asegurarEstructura();
            $this->reportarFueraDeEstructura();
            $this->sincronizarMatriz();
            $this->detectarConflictos();
        };

        if ($simular) {
            // Las lecturas ven exactamente lo que hay; nada se escribe.
            $ejecutar();
        } else {
            DB::transaction($ejecutar);
        }

        return $this->reporte;
    }

    /**
     * Definición confirmada: nombre => superior (para pruebas y docs).
     *
     * @return array<string, string|null>
     */
    public static function superiores(): array
    {
        return array_map(fn (array $d) => $d['superior'], self::ESTRUCTURA);
    }

    // ------------------------------------------------------------------
    // Puestos
    // ------------------------------------------------------------------

    /**
     * Renombra conservando el id. La comparación del nombre final es exacta
     * (MariaDB compara sin distinguir mayúsculas: "Gestor volante" y
     * "Gestor Volante" son "iguales" para la base pero no para la UI).
     */
    private function renombrar(string $anterior, string $nuevo): void
    {
        $actual = Puesto::query()->where('nombre', $anterior)->get()->first(fn (Puesto $p) => $p->nombre === $anterior);

        if ($actual === null) {
            return;
        }

        $otro = Puesto::query()->where('nombre', $nuevo)->where('id', '!=', $actual->id)->exists();

        if ($otro) {
            $this->reporte['conflictos'][] = sprintf('Existen «%s» y «%s» como puestos distintos: reasigna a la gente de «%s» y desactívalo.', $anterior, $nuevo, $anterior);

            return;
        }

        $this->reporte['puestos_renombrados'][] = sprintf('«%s» -> «%s»', $anterior, $nuevo);

        if ($this->simular) {
            $this->renombradosSimulados[$nuevo] = $anterior;
        } else {
            $actual->update(['nombre' => $nuevo]);
        }
    }

    /** Nombre con el que el puesto está HOY en la base (ver --simular). */
    private function nombreEnBase(string $nombre): string
    {
        return $this->renombradosSimulados[$nombre] ?? $nombre;
    }

    private function convertirRegionalAnterior(): void
    {
        $anterior = Puesto::query()->where('nombre', self::REGIONAL_ANTERIOR)->first();

        if ($anterior === null) {
            return;
        }

        $ocupantes = Colaborador::query()->where('puesto_id', $anterior->id)->where('estatus', EstadoUsuario::Activo->value)->get(['id', 'name', 'apellidos']);
        $existeQ1 = Puesto::query()->where('nombre', 'Gerente Regional Q1')->exists();

        if ($ocupantes->isEmpty() && ! $existeQ1) {
            $this->reporte['puestos_renombrados'][] = sprintf('«%s» (sin ocupantes) -> «Gerente Regional Q1»', self::REGIONAL_ANTERIOR);

            if ($this->simular) {
                $this->renombradosSimulados['Gerente Regional Q1'] = self::REGIONAL_ANTERIOR;
            } else {
                $anterior->update(['nombre' => 'Gerente Regional Q1']);
            }

            return;
        }

        if ($ocupantes->isNotEmpty()) {
            $this->reporte['conflictos'][] = sprintf(
                'Puesto anterior «%s» ocupado por %s: NO se adivina su región. Cambia su puesto a «Gerente Regional Q1» o «Gerente Regional Q3» (y registra la otra región como cobertura temporal si cubre ambas).',
                self::REGIONAL_ANTERIOR,
                $ocupantes->map(fn (Colaborador $c) => trim(sprintf('%s %s', $c->name, $c->apellidos ?? '')))->implode(', '),
            );

            return;
        }

        $this->reporte['nodos_legacy'][] = sprintf('Puesto «%s» sin ocupantes y ya existe Q1: se conserva inactivo.', self::REGIONAL_ANTERIOR);

        if (! $this->simular) {
            $anterior->update(['activo' => false]);
        }
    }

    private function asegurarEstructura(): void
    {
        // Departamentos de la estructura (Mesa de Control, Contraloría…): se
        // crean si faltan; nunca se renombran ni se borran.
        foreach (array_unique(array_column(self::ESTRUCTURA, 'departamento')) as $nombreDepartamento) {
            if (Departamento::query()->where('nombre', $nombreDepartamento)->doesntExist()) {
                $this->reporte['puestos_nuevos'][] = sprintf('Departamento «%s»', $nombreDepartamento);

                if (! $this->simular) {
                    Departamento::query()->create(['nombre' => $nombreDepartamento, 'activo' => true]);
                }
            }
        }

        $departamentos = Departamento::query()->pluck('id', 'nombre');
        $ids = [];

        foreach (self::ESTRUCTURA as $nombre => $definicion) {
            $puesto = Puesto::query()->where('nombre', $this->nombreEnBase($nombre))->first();
            $superiorId = $definicion['superior'] !== null ? ($ids[$definicion['superior']] ?? null) : null;
            $crecimiento = $definicion['extra']['crecimiento'] ?? null;

            $atributos = [
                'departamento_id' => $departamentos[$definicion['departamento']] ?? null,
                'descripcion' => $definicion['descripcion'],
                'nivel_jerarquico' => $definicion['nivel'],
                'puesto_superior_id' => $superiorId,
                'puesto_crecimiento_id' => $crecimiento !== null ? ($ids[$crecimiento] ?? null) : null,
                'tipo_puesto' => $definicion['tipo'],
                'requiere_ruta' => (bool) ($definicion['extra']['requiere_ruta'] ?? false),
                'activo' => true,
                ...array_intersect_key($definicion['extra'] ?? [], array_flip(['responsabilidades', 'esquema_comisiones'])),
            ];

            if ($puesto === null) {
                $this->reporte['puestos_nuevos'][] = $nombre;

                if ($this->simular) {
                    // Id ficticio y negativo solo para seguir resolviendo superiores.
                    $ids[$nombre] = -1 * (count($ids) + 1);

                    continue;
                }

                $puesto = Puesto::query()->create(['nombre' => $nombre, ...$atributos]);
            } else {
                if ($puesto->puesto_superior_id !== $superiorId && $superiorId !== null && $superiorId > 0) {
                    $this->reporte['relaciones_actualizadas'][] = sprintf(
                        '«%s» ahora reporta a «%s» (antes: %s)',
                        $nombre,
                        $definicion['superior'],
                        $puesto->puesto_superior_id !== null ? sprintf('«%s»', Puesto::query()->whereKey($puesto->puesto_superior_id)->value('nombre')) : 'nadie',
                    );
                }

                if (! $this->simular) {
                    $puesto->update($atributos);
                }
            }

            $ids[$nombre] = $puesto->id;
        }

        if (! $this->simular) {
            // Respaldos: el volante cubre al gestor; el subgerente al gerente.
            Puesto::query()->where('nombre', 'Gestor Volante')->first()?->puestosQuePuedeCubrir()->syncWithoutDetaching([$ids['Gestor']]);
            Puesto::query()->where('nombre', 'Subgerente')->first()?->puestosQuePuedeCubrir()->syncWithoutDetaching([$ids['Gerente de Sucursal']]);
        }
    }

    private function reportarFueraDeEstructura(): void
    {
        foreach (Puesto::query()->whereIn('nombre', self::FUERA_DE_ESTRUCTURA)->where('activo', true)->withCount(['colaboradores' => fn ($q) => $q->where('estatus', EstadoUsuario::Activo->value)])->get() as $puesto) {
            $this->reporte['fuera_de_estructura'][] = sprintf('«%s» (%d ocupante(s)) — se conserva sin cambios', $puesto->nombre, $puesto->colaboradores_count);
        }
    }

    // ------------------------------------------------------------------
    // Matriz comercial
    // ------------------------------------------------------------------

    private function sincronizarMatriz(): void
    {
        if (! Schema::hasTable('nodos_comerciales')) {
            return;
        }

        $this->eliminarQ2();

        foreach (self::REGIONES as $codigo) {
            $region = NodoComercial::query()->where('tipo', TipoNodoComercial::Region->value)->where('nombre', "Región {$codigo}")->first();
            $puestoId = Puesto::query()->where('nombre', $this->nombreEnBase("Gerente Regional {$codigo}"))->value('id');

            if ($region === null) {
                $this->reporte['regiones'][] = sprintf('Región %s no existe en la matriz (corre MatrizComercialSeeder).', $codigo);

                continue;
            }

            $this->reporte['regiones'][] = sprintf('Región %s -> «Gerente Regional %s»%s', $codigo, $codigo, $puestoId === null ? ' (el puesto se crea en esta sincronización)' : '');

            if (! $this->simular && $puestoId !== null && $region->puesto_id !== $puestoId) {
                $region->update(['puesto_id' => $puestoId]);
            }
        }

        $this->clasificarEntradas();
    }

    private function eliminarQ2(): void
    {
        $q2 = NodoComercial::query()
            ->where(fn ($q) => $q->where('nombre', 'Región Q2')->orWhere('region', 'Q2')->orWhere('nombre', 'AGUASCALIENTES'))
            ->get(['id', 'nombre']);

        if ($q2->isEmpty()) {
            $this->reporte['q2'][] = 'Q2 no existe (correcto).';

            return;
        }

        $ids = $q2->pluck('id')->all();
        $pendientes = $ids;

        while ($pendientes !== []) {
            $hijos = NodoComercial::query()->whereIn('parent_id', $pendientes)->pluck('id')->all();
            $ids = array_values(array_unique([...$ids, ...$hijos]));
            $pendientes = $hijos;
        }

        $this->reporte['q2'][] = sprintf('Q2/Aguascalientes: se elimina con su descendencia (%d nodos).', count($ids));

        if ($this->simular) {
            return;
        }

        AsignacionNodoComercial::query()->whereIn('nodo_comercial_id', $ids)->delete();
        foreach (array_reverse($ids) as $id) {
            NodoComercial::query()->whereKey($id)->delete();
        }
    }

    /**
     * Reclasifica cada entrada de zona (ruta de cobro, posición de
     * gerente/subgerente, volante, inactiva, cartera especial, grupal).
     * Una entrada reclasificada como posición conserva su id e historial;
     * si tenía un gestor activo asignado se reporta (no se cierra solo).
     */
    private function clasificarEntradas(): void
    {
        $tiposEntrada = [TipoNodoComercial::Ruta->value, TipoNodoComercial::Gerencia->value, TipoNodoComercial::Subgerencia->value, TipoNodoComercial::Volante->value];
        $conteo = [];
        $reclasificadas = 0;

        foreach (NodoComercial::query()->whereIn('tipo', $tiposEntrada)->with('padre:id,nombre')->get() as $nodo) {
            $clase = $this->clasificador->clasificar($nodo->nombre, $nodo->activa);
            $conteo[$clase->value] = ($conteo[$clase->value] ?? 0) + 1;
            $tipoNuevo = $clase->tipoNodo();

            if ($nodo->tipo !== $tipoNuevo) {
                $reclasificadas++;
                $this->reporte['nodos_legacy'][] = sprintf('«%s» (%s): %s -> %s', $nodo->nombre, $nodo->padre->nombre, $nodo->tipo->etiqueta(), $tipoNuevo->etiqueta());

                if ($tipoNuevo->esPosicion()) {
                    $gestorActivo = AsignacionNodoComercial::query()
                        ->where('nodo_comercial_id', $nodo->id)
                        ->where('activo', true)
                        ->where('tipo_asignacion', TipoAsignacionNodoComercial::Gestor->value)
                        ->with('colaborador:id,name,apellidos')
                        ->first();

                    if ($gestorActivo !== null) {
                        $this->reporte['conflictos'][] = sprintf('«%s» era tratada como ruta y tiene como gestor a %s: es una %s, revisa su puesto en el Organigrama y cierra esa asignación.', $nodo->nombre, $gestorActivo->colaborador?->nombreCompleto() ?? 'un colaborador', mb_strtolower($tipoNuevo->etiqueta()));
                    }
                }
            }

            if (! $this->simular && ($nodo->tipo !== $tipoNuevo || ($nodo->metadata['clase'] ?? null) !== $clase->value)) {
                $nodo->update([
                    'tipo' => $tipoNuevo->value,
                    'metadata' => [...($nodo->metadata ?? []), 'clase' => $clase->value],
                ]);
            }
        }

        foreach (ClaseEntradaMatriz::cases() as $clase) {
            $this->reporte['rutas_clasificadas'][] = sprintf('%s: %d', $clase->etiqueta(), $conteo[$clase->value] ?? 0);
        }

        $this->reporte['rutas_clasificadas'][] = sprintf('Entradas que cambian de tipo: %d', $reclasificadas);
    }

    // ------------------------------------------------------------------
    // Validaciones de la estructura real (solo reporta)
    // ------------------------------------------------------------------

    private function detectarConflictos(): void
    {
        $idsPorNombre = Puesto::query()->pluck('id', 'nombre');
        $activos = fn (string $puesto) => Colaborador::query()->where('estatus', EstadoUsuario::Activo->value)->where('puesto_id', $idsPorNombre[$this->nombreEnBase($puesto)] ?? 0);
        $corporativo = Sucursal::query()->where('clave', self::CLAVE_CORPORATIVO)->value('id');

        foreach (['Gerente de Sucursal', 'Subgerente'] as $unoPorSucursal) {
            $repetidos = $activos($unoPorSucursal)
                ->selectRaw('sucursal_principal_id, count(*) as total')
                ->groupBy('sucursal_principal_id')
                ->having('total', '>', 1)
                ->get();

            foreach ($repetidos as $fila) {
                $this->reporte['conflictos'][] = sprintf('%s: %d titulares en «%s» (debe haber 1 por sucursal).', $unoPorSucursal, (int) $fila->getAttribute('total'), Sucursal::query()->whereKey($fila->sucursal_principal_id)->value('nombre') ?? 'sin sucursal');
            }
        }

        if ($corporativo !== null && $activos('Coordinadora de Sucursal')->where('sucursal_principal_id', $corporativo)->exists()) {
            $this->reporte['conflictos'][] = 'Corporativo tiene Coordinadora de Sucursal: ahí solo vive la Coordinadora Regional.';
        }

        foreach (['Asistente de Dirección Comercial' => 1, 'Monitorista' => 1] as $puesto => $maximo) {
            $total = $activos($puesto)->count();

            if ($total > $maximo) {
                $this->reporte['conflictos'][] = sprintf('«%s»: %d ocupantes (la estructura confirmada tiene %d).', $puesto, $total, $maximo);
            }
        }

        if (Schema::hasTable('user_nodo_comercial') && isset($idsPorNombre['Gestor'])) {
            $rutasPorGestor = AsignacionNodoComercial::query()
                ->where('activo', true)
                ->where('tipo_asignacion', TipoAsignacionNodoComercial::Gestor->value)
                ->selectRaw('colaborador_id, count(*) as total')
                ->groupBy('colaborador_id')
                ->having('total', '>', 1)
                ->get();

            foreach ($rutasPorGestor as $fila) {
                $this->reporte['conflictos'][] = sprintf('%s tiene %d rutas vigentes como gestor (debe ser 1).', Colaborador::query()->whereKey($fila->colaborador_id)->first()?->nombreCompleto() ?? 'Un colaborador', (int) $fila->getAttribute('total'));
            }
        }
    }
}
