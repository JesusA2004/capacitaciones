<?php

namespace Database\Seeders;

use App\Enums\TipoPuesto;
use App\Models\Departamento;
use App\Models\Puesto;
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
 * de esa estructura (Asistente de Dirección General, Mesa de Control,
 * Contraloría, Gestor grupal) y se RETIRAN los de la estructura anterior
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
        $direccionComercial = Puesto::where('nombre', 'Dirección Comercial')->firstOrFail();
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

        $gerenteMesaControl = $this->puesto('Gerente de Mesa de Control', [
            'departamento_id' => $departamento('Mesa de Control'),
            'descripcion' => 'Responsable de la mesa de control.',
            'nivel_jerarquico' => 3,
            'puesto_superior_id' => $direccionComercial->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        $this->puesto('Analista de Mesa de Control', [
            'departamento_id' => $departamento('Mesa de Control'),
            'descripcion' => 'Análisis y validación en mesa de control.',
            'nivel_jerarquico' => 4,
            'puesto_superior_id' => $gerenteMesaControl->id,
            'puesto_crecimiento_id' => $gerenteMesaControl->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        $gerenteContraloria = $this->puesto('Gerente de Contraloría', [
            'departamento_id' => $departamento('Contraloría'),
            'descripcion' => 'Responsable de contraloría.',
            'nivel_jerarquico' => 3,
            'puesto_superior_id' => $direccionComercial->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        $this->puesto('Tesorero', [
            'departamento_id' => $departamento('Contraloría'),
            'descripcion' => 'Tesorería: flujo de efectivo, pagos y fondeo.',
            'nivel_jerarquico' => 4,
            'puesto_superior_id' => $gerenteContraloria->id,
            'puesto_crecimiento_id' => $gerenteContraloria->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        $this->puesto('Contador', [
            'departamento_id' => $departamento('Contraloría'),
            'descripcion' => 'Contabilidad general y cumplimiento fiscal.',
            'nivel_jerarquico' => 4,
            'puesto_superior_id' => $gerenteContraloria->id,
            'puesto_crecimiento_id' => $gerenteContraloria->id,
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
