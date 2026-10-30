<?php

namespace App\Services\Organigrama;

use App\Enums\ClaseEntradaMatriz;
use App\Enums\EstadoUsuario;
use App\Enums\TipoAsignacionNodoComercial;
use App\Enums\TipoNodoComercial;
use App\Enums\TipoPuesto;
use App\Models\AsignacionNodoComercial;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\HeadcountTarget;
use App\Models\HeadcountTargetHistorial;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\MatrizComercial\ClasificadorNodoComercial;
use App\Services\Vacantes\VacanteAutoGenerationService;
use App\Support\FechaMexico;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura organizacional CONFIRMADA por dirección (2026-10-06, reemplaza
 * la versión anterior del 2026-09-29: Sistemas, Recursos Humanos y
 * Contraloría dejaron de depender de Dirección Comercial — ahora son áreas
 * directas de Dirección General, al mismo nivel que ella) y su
 * sincronización segura con una base existente (producción incluida). La
 * usan PuestoJerarquiaSeeder y `php artisan people:sincronizar-organigrama`
 * (con --simular no escribe nada). Ver docs/ORGANIGRAMA.md.
 *
 *   Dirección General
 *   ├── Dirección Comercial
 *   │   ├── Asistente de Dirección Comercial        (1 plaza)
 *   │   ├── Gerente de Mesa de Control
 *   │   │   └── Analista de Mesa de Control
 *   │   ├── Coordinadora Regional                    (vive en Corporativo)
 *   │   │   └── Coordinadora de Sucursal             (1 por sucursal; Corporativo no tiene)
 *   │   └── Gerente Regional                         (1 plaza de empresa)
 *   │       └── Subgerente Regional                  (1 plaza de empresa)
 *   │           └── Gerente de Sucursal              (1 por sucursal)
 *   │               └── Subgerente                   (1 por sucursal)
 *   │                   ├── Gestor                   (1 ruta de cobro vigente, 1 plaza)
 *   │                   └── Gestor Volante           (plaza de plantilla, sin ruta fija)
 *   │   └── Monitorista (de cámaras)              (hoy 1; apoyo directo a Dirección Comercial)
 *   ├── Responsable de Sistemas
 *   ├── Gerencia de Recursos Humanos
 *   │   ├── Administración de Personal
 *   │   └── Reclutamiento
 *   └── Gerente de Contraloría
 *       ├── Auditora
 *       ├── Tesorero
 *       └── Contador
 *
 * Reglas de seguridad: nunca borra datos en uso ni asigna personas; solo
 * crea/renombra puestos (conserva id, gente e historial), corrige
 * "reporta a", une los regionales Q1/Q3 en la cadena regional y reclasifica
 * las entradas de la matriz. Todo lo que no puede decidir solo lo REPORTA como
 * conflicto. Los puestos fuera de la estructura confirmada (Asistente de
 * Dirección General, Gestor grupal) se conservan sin tocar, por decisión de
 * dirección.
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

    /** Puestos regionales anteriores que se unen en «Gerente Regional». */
    private const REGIONALES_ANTERIORES = ['Gerente Regional Q1', 'Gerente Regional Q3'];

    /** Puesto genérico anterior: solo difiere del nuevo en una mayúscula. */
    private const REGIONAL_GENERICO = 'Gerente regional';

    /** Plazas únicas de empresa que siempre deben existir en plantilla. */
    private const PLAZAS_REGIONALES = ['Gerente Regional', 'Subgerente Regional'];

    /**
     * Estructura confirmada, de arriba hacia abajo (un superior siempre va
     * antes que sus subordinados).
     *
     * @var array<string, array{superior: string|null, departamento: string, nivel: int, tipo: TipoPuesto, descripcion: string, extra?: array<string, mixed>}>
     */
    private const ESTRUCTURA = [
        'Dirección General' => ['superior' => null, 'departamento' => 'Dirección', 'nivel' => 1, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Cabeza de la organización.', 'extra' => ['responsabilidades' => 'Dirección estratégica de la empresa y aprobación de decisiones de alto nivel.']],
        'Dirección Comercial' => ['superior' => 'Dirección General', 'departamento' => 'Ventas', 'nivel' => 2, 'tipo' => TipoPuesto::Comercial, 'descripcion' => 'Dirección Comercial de Mr. Lana: de ella dependen la asistente, la Coordinación Regional y las Gerencias Regionales.', 'extra' => ['responsabilidades' => 'Vista global, reportes generales y decisiones estratégicas.']],
        'Asistente de Dirección Comercial' => ['superior' => 'Dirección Comercial', 'departamento' => 'Ventas', 'nivel' => 3, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Asistencia directa a la Dirección Comercial. Una sola plaza.'],
        // Sistemas, Recursos Humanos y Contraloría son áreas directas de
        // Dirección General, al mismo nivel que Dirección Comercial — nunca
        // subordinadas a ella (CLAUDE.md §27, decisión de dirección que
        // reemplaza la estructura anterior). Sus propios subordinados
        // CONSERVAN su nivel original (4): AprobacionJerarquicaService::esGerenciaOSuperior()
        // usa nivel_jerarquico <= 3 como "es gerencia" — Auditora, Tesorero,
        // Contador, etc. no son gerencia y nunca deben cruzar ese umbral
        // solo porque su jefe cambió de nivel.
        'Responsable de Sistemas' => ['superior' => 'Dirección General', 'departamento' => 'Sistemas', 'nivel' => 2, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Responsable de la plataforma, infraestructura y monitoreo.'],
        // Monitorista de cámaras: apoyo con reporte directo a Dirección
        // Comercial (lámina 18 de Inducción, decisión de RH 2026-10-14).
        'Monitorista' => ['superior' => 'Dirección Comercial', 'departamento' => 'Sistemas', 'nivel' => 3, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Monitoreo de cámaras; apoyo directo a Dirección Comercial.', 'extra' => ['crecimiento' => 'Responsable de Sistemas']],
        'Gerencia de Recursos Humanos' => ['superior' => 'Dirección General', 'departamento' => 'Recursos Humanos', 'nivel' => 2, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Responsable de Recursos Humanos.'],
        'Administración de Personal' => ['superior' => 'Gerencia de Recursos Humanos', 'departamento' => 'Recursos Humanos', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Expedientes, altas, bajas y trámites de personal.', 'extra' => ['crecimiento' => 'Gerencia de Recursos Humanos']],
        'Reclutamiento' => ['superior' => 'Gerencia de Recursos Humanos', 'departamento' => 'Recursos Humanos', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Atracción y selección de candidatos.', 'extra' => ['crecimiento' => 'Gerencia de Recursos Humanos']],
        // Mesa de Control sigue bajo Dirección Comercial (no forma parte del
        // cambio de nivel §27). Mesa de Control y Contraloría son áreas
        // DISTINTAS; la Auditora es de Contraloría, nunca de Mesa de Control.
        'Gerente de Mesa de Control' => ['superior' => 'Dirección Comercial', 'departamento' => 'Mesa de Control', 'nivel' => 3, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Responsable de la mesa de control: validación de operaciones de crédito.'],
        'Analista de Mesa de Control' => ['superior' => 'Gerente de Mesa de Control', 'departamento' => 'Mesa de Control', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Análisis y validación en mesa de control.', 'extra' => ['crecimiento' => 'Gerente de Mesa de Control']],
        'Gerente de Contraloría' => ['superior' => 'Dirección General', 'departamento' => 'Contraloría', 'nivel' => 2, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Responsable de contraloría: auditoría, tesorería y contabilidad.'],
        'Auditora' => ['superior' => 'Gerente de Contraloría', 'departamento' => 'Contraloría', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Auditoría interna de sucursales y procesos.', 'extra' => ['crecimiento' => 'Gerente de Contraloría']],
        'Tesorero' => ['superior' => 'Gerente de Contraloría', 'departamento' => 'Contraloría', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Tesorería: flujo de efectivo, pagos y fondeo.', 'extra' => ['crecimiento' => 'Gerente de Contraloría']],
        'Contador' => ['superior' => 'Gerente de Contraloría', 'departamento' => 'Contraloría', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Contabilidad general y cumplimiento fiscal.', 'extra' => ['crecimiento' => 'Gerente de Contraloría']],
        'Coordinadora Regional' => ['superior' => 'Dirección Comercial', 'departamento' => 'Operaciones', 'nivel' => 3, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Coordinadora Regional: una sola, ubicada en Corporativo; de ella dependen las coordinadoras de sucursal.'],
        'Coordinadora de Sucursal' => ['superior' => 'Coordinadora Regional', 'departamento' => 'Operaciones', 'nivel' => 4, 'tipo' => TipoPuesto::Administrativo, 'descripcion' => 'Una por sucursal (Corporativo no tiene): cuadre de caja, control administrativo y procesos internos.', 'extra' => ['crecimiento' => 'Coordinadora Regional']],
        'Gerente Regional' => ['superior' => 'Dirección Comercial', 'departamento' => 'Ventas', 'nivel' => 3, 'tipo' => TipoPuesto::Comercial, 'descripcion' => 'Gerente Regional: una sola plaza en la empresa; de él depende el Subgerente Regional.', 'extra' => ['crecimiento' => 'Dirección Comercial']],
        'Subgerente Regional' => ['superior' => 'Gerente Regional', 'departamento' => 'Ventas', 'nivel' => 3, 'tipo' => TipoPuesto::Comercial, 'descripcion' => 'Subgerente Regional: apoya al Gerente Regional; de él dependen los gerentes de sucursal.', 'extra' => ['crecimiento' => 'Gerente Regional']],
        'Gerente de Sucursal' => ['superior' => 'Subgerente Regional', 'departamento' => 'Ventas', 'nivel' => 4, 'tipo' => TipoPuesto::Comercial, 'descripcion' => 'Responsable de la sucursal (1 por sucursal). Reporta al Subgerente Regional.', 'extra' => ['esquema_comisiones' => 'Comisión por resultados de sucursal.', 'responsabilidades' => 'Supervisa la operación de la sucursal y participa en la aprobación de candidatos y solicitudes.']],
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

    public function __construct(
        private readonly ClasificadorNodoComercial $clasificador,
        private readonly VacanteAutoGenerationService $vacantes,
    ) {}

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
            'cadena_regional' => [],
            'plazas_regionales' => [],
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

            $this->unirRegionales();
            $this->asegurarEstructura();
            $this->asegurarPlazasRegionales();
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

    /**
     * Une Gerente Regional Q1/Q3 (y el genérico) en «Gerente Regional». El
     * único puesto con titular conserva id, gente e historial; los vacíos se
     * desactivan y sus coberturas pasan a la plaza nueva. Con titulares en más
     * de uno NO se elige a nadie: quedan como están y se reporta el conflicto.
     */
    private function unirRegionales(): void
    {
        $genericos = Puesto::query()->where('nombre', self::REGIONAL_GENERICO)->get();
        $anteriores = Puesto::query()->whereIn('nombre', self::REGIONALES_ANTERIORES)->orderBy('nombre')->get();
        $candidatos = $genericos->merge($anteriores);

        $operaciones = Puesto::query()->where('nombre', 'Regional de Operaciones')->where('activo', true)->first();

        if ($operaciones !== null) {
            $this->reporte['conflictos'][] = sprintf('Existe «Regional de Operaciones» con %d titular(es): no se une solo; cámbialos a «Gerente Regional» o «Subgerente Regional».', $this->titularesVigentes($operaciones)->count());
        }

        if ($anteriores->isEmpty()) {
            return;
        }

        $ocupados = $candidatos->filter(fn (Puesto $p) => $this->titularesVigentes($p)->isNotEmpty())->values();

        if ($ocupados->count() > 1) {
            $this->reporte['conflictos'][] = sprintf(
                'Hay titulares en varios puestos regionales (%s): NO se elige Gerente Regional. Asigna a cada quien «Gerente Regional» o «Subgerente Regional» y vuelve a sincronizar.',
                $ocupados->map(fn (Puesto $p) => sprintf('«%s»: %s', $p->nombre, $this->titularesVigentes($p)->map(fn (Colaborador $c) => $c->nombreCompleto())->implode(', ')))->implode('; '),
            );

            return;
        }

        $generico = $genericos->first();
        $conservado = $ocupados->first() ?? $generico ?? $anteriores->first();

        // MariaDB compara sin mayúsculas: el genérico vacío estorbaría al nombre nuevo.
        if ($generico !== null && $generico->id !== $conservado->id) {
            $this->retirarPuesto($generico, 'Gerente regional (anterior)');
        }

        if ($conservado->nombre !== 'Gerente Regional') {
            $this->reporte['puestos_renombrados'][] = sprintf('«%s» -> «Gerente Regional»', $conservado->nombre);

            if ($this->simular) {
                $this->renombradosSimulados['Gerente Regional'] = $conservado->nombre;
            } else {
                $conservado->update(['nombre' => 'Gerente Regional']);
            }
        }

        $hayTitular = $ocupados->isNotEmpty();

        foreach ($anteriores->reject(fn (Puesto $p) => $p->id === $conservado->id) as $retirado) {
            $this->moverCoberturas($retirado, $conservado, $hayTitular);
            $this->retirarPuesto($retirado, null);
        }

        // Una cobertura de la plaza conservada ya no es «de región».
        $this->moverCoberturas($conservado, $conservado, $hayTitular);
    }

    /**
     * @return Collection<int, Colaborador>
     */
    private function titularesVigentes(Puesto $puesto): Collection
    {
        return Colaborador::query()->whereIn('estatus', EstadoUsuario::valoresVigentes())->where('puesto_id', $puesto->id)->get()->toBase();
    }

    /**
     * Desactiva (nunca borra) un puesto regional anterior y reporta lo que
     * todavía apunte a él.
     */
    private function retirarPuesto(Puesto $puesto, ?string $nombreNuevo): void
    {
        $this->reporte['cadena_regional'][] = sprintf('«%s» sin titulares: se conserva inactivo.', $puesto->nombre);

        $plazas = (int) HeadcountTarget::query()->where('puesto_id', $puesto->id)->sum('plantilla_autorizada');

        if ($plazas > 0) {
            $this->reporte['conflictos'][] = sprintf('«%s» tenía %d plaza(s) autorizada(s): revisa la plantilla, el puesto queda inactivo.', $puesto->nombre, $plazas);
        }

        if (! $this->simular) {
            $puesto->update(array_filter(['activo' => false, 'nombre' => $nombreNuevo], fn ($valor) => $valor !== null));
        }
    }

    /**
     * Pasa las coberturas vigentes a la plaza única; si ésta ya tiene titular
     * o cobertura, la de sobra se cierra (auditable en el reporte y en
     * fecha_fin) porque ya no hay plaza vacante que cubrir.
     */
    private function moverCoberturas(Puesto $origen, Puesto $destino, bool $destinoConTitular): void
    {
        foreach (CoberturaPuesto::query()->where('puesto_id', $origen->id)->where('activa', true)->orderBy('id')->get() as $cobertura) {
            $persona = Colaborador::query()->where('id', $cobertura->colaborador_id)->first()?->nombreCompleto() ?? 'Una persona';
            $ocupada = $destinoConTitular || CoberturaPuesto::query()->where('puesto_id', $destino->id)->where('activa', true)->where('id', '!=', $cobertura->id)->exists();

            if ($ocupada) {
                $this->reporte['cadena_regional'][] = sprintf('Se cierra la cobertura de %s en «%s»: la plaza única ya está atendida.', $persona, $origen->nombre);

                if (! $this->simular) {
                    $cobertura->update(['activa' => false, 'fecha_fin' => FechaMexico::hoy()]);
                }

                continue;
            }

            $this->reporte['cadena_regional'][] = sprintf('La cobertura de %s pasa de «%s» a la plaza de Gerente Regional.', $persona, $origen->nombre);

            if (! $this->simular) {
                $cobertura->update(['puesto_id' => $destino->id, 'region_id' => null]);
            }
        }
    }

    /**
     * Sin plaza autorizada no hay vacante automática: Gerente y Subgerente
     * Regional tienen 1 plaza. Vive en Corporativo, salvo que quien la ocupa
     * esté registrado en otra sucursal: se autoriza ahí (la plaza se cuenta
     * por sucursal y abrir una vacante falsa en Corporativo sería peor) y se
     * reporta. Nunca pisa una plaza ya capturada ni cambia a nadie de sucursal.
     */
    private function asegurarPlazasRegionales(): void
    {
        $corporativo = Sucursal::query()->where('clave', self::CLAVE_CORPORATIVO)->first();

        foreach (self::PLAZAS_REGIONALES as $nombre) {
            $puesto = Puesto::query()->where('nombre', $this->nombreEnBase($nombre))->first();

            if ($puesto === null) {
                $this->reporte['plazas_regionales'][] = sprintf('«%s»: se autoriza 1 plaza al crear el puesto.', $nombre);

                continue;
            }

            if (HeadcountTarget::query()->where('puesto_id', $puesto->id)->exists()) {
                continue;
            }

            $titular = $this->titularesVigentes($puesto)->first();
            $sucursal = $titular?->sucursal_principal_id !== null
                ? Sucursal::query()->where('id', $titular->sucursal_principal_id)->first()
                : $corporativo;

            if ($sucursal === null) {
                $this->reporte['conflictos'][] = sprintf('«%s»: no existe Corporativo (%s) para autorizar su plaza.', $nombre, self::CLAVE_CORPORATIVO);

                continue;
            }

            if ($titular !== null && $corporativo !== null && $sucursal->id !== $corporativo->id) {
                $this->reporte['conflictos'][] = sprintf('%s es «%s» y su sucursal es «%s»: la plaza se autoriza ahí; si debe vivir en Corporativo, cambia su sucursal y su plantilla.', $titular->nombreCompleto(), $nombre, $sucursal->nombre);
            }

            $this->reporte['plazas_regionales'][] = sprintf('«%s»: 1 plaza autorizada en «%s»%s.', $nombre, $sucursal->nombre, $titular === null ? ' (sin titular: se abre su vacante)' : '');

            if ($this->simular) {
                continue;
            }

            $target = HeadcountTarget::query()->create([
                'empresa_id' => $sucursal->empresa_id,
                'sucursal_id' => $sucursal->id,
                'puesto_id' => $puesto->id,
                'plantilla_autorizada' => 1,
                'fuente' => 'organigrama',
                'fecha_corte' => FechaMexico::hoy(),
            ]);

            HeadcountTargetHistorial::query()->create([
                'headcount_target_id' => $target->id,
                'sucursal_id' => $sucursal->id,
                'puesto_id' => $puesto->id,
                'valor_anterior' => null,
                'valor_nuevo' => 1,
                'fuente' => 'organigrama',
                'motivo' => 'Plaza única de la cadena regional (estructura confirmada).',
            ]);

            $this->vacantes->sincronizar($sucursal->id, $puesto->id);
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
            foreach (['Gestor Volante' => 'Gestor', 'Subgerente' => 'Gerente de Sucursal'] as $respaldo => $cubierto) {
                if (isset($ids[$cubierto])) {
                    Puesto::query()->where('nombre', $respaldo)->first()?->puestosQuePuedeCubrir()->syncWithoutDetaching([$ids[$cubierto]]);
                }
            }
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
            if ($region === null) {
                $this->reporte['regiones'][] = sprintf('Región %s no existe en la matriz (corre MatrizComercialSeeder).', $codigo);

                continue;
            }

            // Q1/Q3 quedan como dato territorial: ningún puesto cuelga de ellas.
            if ($region->puesto_id !== null) {
                $this->reporte['regiones'][] = sprintf('Región %s: se desliga de su puesto regional (sigue en la matriz)', $codigo);

                if (! $this->simular) {
                    $region->update(['puesto_id' => null]);
                }
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
