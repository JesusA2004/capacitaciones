<?php

namespace Database\Seeders;

use App\Enums\GrupoPuestoIndicador;
use App\Enums\TipoPuesto;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Services\DocumentosMaestros\CoberturaDocumentalService;
use App\Services\Organigrama\SincronizadorOrganigramaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de puestos. La estructura CONFIRMADA por dirección (2026-09-29)
 * vive en App\Services\Organigrama\SincronizadorOrganigramaService (una
 * sola definición, compartida con `php artisan people:sincronizar-organigrama`):
 *
 * Dirección General
 * └── Dirección Comercial
 *     ├── Asistente de Dirección Comercial   (1 plaza)
 *     ├── Responsable de Sistemas → Monitorista
 *     ├── Gerencia de Recursos Humanos → Administración de Personal, Reclutamiento
 *     ├── Coordinadora Regional → Coordinadora de Sucursal (1 por sucursal, no en Corporativo)
 *     └── Gerente Regional Q1 / Gerente Regional Q3 (cada uno ligado a su región)
 *         └── Gerente de Sucursal → Subgerente → Gestor, Gestor Volante
 *
 * Aquí además se conservan, sin cambios, los puestos que no forman parte
 * de esa estructura (Asistente de Dirección General,
 * Gestor grupal; Mesa de Control y Contraloría ya son parte de ella) y se RETIRAN los de la estructura anterior
 * (ver retirar()): se eliminan solo si nadie los usa; si tienen
 * colaboradores, headcount, vacantes o historial, quedan inactivos y se
 * reporta en consola. Idempotente. Ver docs/ORGANIGRAMA.md.
 */
class PuestoJerarquiaSeeder extends Seeder
{
    /**
     * Puestos de la estructura anterior que dirección ya no usa.
     * "Gerente" era un duplicado de "Gerente de Sucursal".
     */
    private const RETIRADOS = [
        'Gerente',
        'Generalista de RH',
        'Coordinador de Capacitación',
        'Analista de Sistemas',
        'Soporte Técnico',
        'Analista de Nómina',
        'Auxiliar Contable',
        'Responsable administrativo/regional',
        'Supervisor de Operaciones',
        'Ejecutivo de Ventas',
        'Coordinador de Ventas',
    ];

    /**
     * Tablas/columnas que apuntan a `puestos`: si alguna tiene filas, el
     * puesto está en uso y no se borra.
     *
     * @var array<string, list<string>>
     */
    private const REFERENCIAS = [
        'colaboradores' => ['puesto_id'],
        'users' => ['puesto_id'],
        'headcount_targets' => ['puesto_id'],
        'vacantes' => ['puesto_id'],
        'candidatos' => ['puesto_objetivo_id'],
        'altas_digitales' => ['puesto_id'],
        'document_templates' => ['puesto_id'],
        'movimientos_laborales' => ['puesto_anterior_id', 'puesto_nuevo_id'],
        'incorporacion_invitaciones' => ['puesto_id'],
        'nodos_comerciales' => ['puesto_id'],
        'campanas_reclutamiento' => ['puesto_id'],
        'contratos_laborales' => ['puesto_id'],
    ];

    public function run(): void
    {
        // Estructura confirmada por dirección (renombres, "reporta a",
        // Q1/Q3, regiones ↔ puesto regional, reclasificación de la matriz):
        // una sola definición compartida con `people:sincronizar-organigrama`.
        $reporte = app(SincronizadorOrganigramaService::class)->sincronizar();

        foreach ($reporte['conflictos'] as $conflicto) {
            $this->command->warn('Organigrama: '.$conflicto);
        }

        $departamento = fn (string $nombre): ?int => Departamento::where('nombre', $nombre)->value('id');
        $direccionGeneral = Puesto::where('nombre', 'Dirección General')->firstOrFail();
        $subgerente = Puesto::where('nombre', 'Subgerente')->firstOrFail();

        // --- Puestos fuera de la estructura confirmada: se conservan tal
        // cual estaban (decisión de dirección, 2026-09-29). ---
        $this->puesto('Asistente de Dirección General', [
            'departamento_id' => $departamento('Dirección'),
            'descripcion' => 'Asistencia directa a Dirección General.',
            'nivel_jerarquico' => 2,
            'puesto_superior_id' => $direccionGeneral->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        $this->puesto('Gestor grupal', [
            'departamento_id' => $departamento('Ventas'),
            'descripcion' => 'Responsable de cartera de crédito grupal en sucursal.',
            'nivel_jerarquico' => 6,
            'puesto_superior_id' => $subgerente->id,
            'puesto_crecimiento_id' => $subgerente->id,
            'tipo_puesto' => TipoPuesto::Comercial,
            'requiere_ruta' => false,
        ]);

        foreach (self::RETIRADOS as $nombre) {
            $this->retirar($nombre);
        }

        $this->parametrosCicloLaboral();

        // Grupo documental inicial (qué variante de contrato le toca a cada
        // puesto). La migración que lo siembra corre ANTES de que este seeder
        // cree los puestos en un migrate:fresh --seed; sin esto todos
        // quedaban sin grupo y la contratación mostraba "formato no cargado".
        // Nunca pisa la decisión que RH ya tomó.
        if (Schema::hasColumn('puestos', 'no_requiere_documentos_laborales')) {
            app(CoberturaDocumentalService::class)->aplicarGruposIniciales();
        }
    }

    /**
     * Duración del contrato de capacitación/inducción (config
     * ciclo_laboral.periodo_prueba.meses_por_puesto) y grupo para
     * indicadores. Solo llena lo vacío: nunca pisa lo que RH ya ajustó.
     */
    private function parametrosCicloLaboral(): void
    {
        if (! Schema::hasColumn('puestos', 'meses_periodo_prueba')) {
            return;
        }

        /** @var array<string, int> $meses */
        $meses = config('ciclo_laboral.periodo_prueba.meses_por_puesto', []);

        foreach ($meses as $nombre => $valor) {
            Puesto::where('nombre', $nombre)->whereNull('meses_periodo_prueba')->update(['meses_periodo_prueba' => $valor]);
        }

        $grupos = [
            GrupoPuestoIndicador::Gestores->value => ['Gestor', 'Gestor Volante', 'Gestor grupal'],
            GrupoPuestoIndicador::Coordinadoras->value => ['Coordinadora de Sucursal', 'Coordinadora Regional'],
            GrupoPuestoIndicador::GerenciaSucursal->value => ['Gerente de Sucursal', 'Subgerente'],
            GrupoPuestoIndicador::Regionales->value => ['Gerente Regional Q1', 'Gerente Regional Q3', 'Gerente regional'],
            GrupoPuestoIndicador::DireccionComercial->value => ['Dirección Comercial', 'Asistente de Dirección Comercial'],
        ];

        foreach ($grupos as $grupo => $nombres) {
            Puesto::whereIn('nombre', $nombres)->whereNull('grupo_indicador')->update(['grupo_indicador' => $grupo]);
        }

        Puesto::whereNull('grupo_indicador')->update(['grupo_indicador' => GrupoPuestoIndicador::Otros->value]);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function puesto(string $nombre, array $atributos): Puesto
    {
        return Puesto::updateOrCreate(
            ['nombre' => $nombre],
            [
                'puesto_crecimiento_id' => null,
                'requiere_ruta' => false,
                ...$atributos,
                'activo' => true,
            ],
        );
    }

    /**
     * Elimina el puesto si nadie lo usa; si está en uso, lo deja inactivo y
     * fuera del árbol, y reporta dónde se usa para reasignarlo a mano.
     */
    private function retirar(string $nombre): void
    {
        $puesto = Puesto::where('nombre', $nombre)->first();

        if ($puesto === null) {
            return;
        }

        $usos = [];

        foreach (self::REFERENCIAS as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $total = DB::table($tabla)
                ->where(fn ($query) => collect($columnas)->each(fn (string $columna) => $query->orWhere($columna, $puesto->id)))
                ->count();

            if ($total > 0) {
                $usos[] = sprintf('%s: %d', $tabla, $total);
            }
        }

        // Nadie cuelga de un puesto retirado.
        Puesto::where('puesto_superior_id', $puesto->id)->update(['puesto_superior_id' => null]);
        Puesto::where('puesto_crecimiento_id', $puesto->id)->update(['puesto_crecimiento_id' => null]);

        if ($usos === []) {
            $puesto->respaldos()->detach();
            $puesto->puestosQuePuedeCubrir()->detach();
            $puesto->delete();

            return;
        }

        $puesto->update(['activo' => false, 'puesto_superior_id' => null, 'puesto_crecimiento_id' => null]);

        $this->command->warn(sprintf(
            'Puesto «%s» retirado de la estructura pero EN USO (%s): quedó inactivo, reasigna esos registros a un puesto vigente.',
            $nombre,
            implode(', ', $usos),
        ));
    }
}
