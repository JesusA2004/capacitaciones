<?php

namespace Database\Seeders;

use App\Enums\TipoPuesto;
use App\Models\Departamento;
use App\Models\Puesto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura real de puestos de Mr. Lana (definida por dirección):
 *
 * Dirección General
 * ├── Asistente de Dirección General      (el puesto existe aunque no esté ocupado)
 * └── Director comercial                  (Dirección Comercial de Mr. Lana)
 *     ├── Asistente de Dirección Comercial
 *     ├── Gerente de Sistemas
 *     │   └── Monitorista
 *     ├── Gerente de Mesa de Control
 *     │   └── Analista de Mesa de Control
 *     ├── Gerente de Recursos Humanos
 *     │   ├── Administración de Personal
 *     │   └── Reclutamiento
 *     ├── Gerente de Contraloría
 *     │   ├── Tesorero
 *     │   └── Contador
 *     ├── Gerente regional                (División comercial)
 *     │   └── Gerente de Sucursal
 *     │       └── Subgerente
 *     │           ├── Gestor (con su ruta: la cartera que cobra)
 *     │           │   └── Gestor volante
 *     │           └── Gestor grupal
 *     └── Coordinadora regional           (una sola para todas las sucursales)
 *         └── Coordinadora                (de sucursal — viene en el Excel real de headcount)
 *
 * Idempotente (updateOrCreate por nombre). Los puestos de la estructura
 * anterior que ya no existen se RETIRAN (ver retirar()): se eliminan solo
 * si nadie los usa; si tienen colaboradores, headcount, vacantes o
 * historial, quedan inactivos y se reporta en consola para reasignarlos —
 * borrar un puesto en uso vaciaría el historial de movimientos y borraría
 * en cascada su headcount. Ver docs/JERARQUIA_PUESTOS.md.
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
        $departamento = fn (string $nombre): ?int => Departamento::where('nombre', $nombre)->value('id');

        // Renombres de la estructura anterior (conservan su id, su gente y
        // su historial).
        $this->renombrar('Gerente de Contabilidad', 'Gerente de Contraloría');
        // Se revirtió: dirección lo llama "Coordinadora regional" (una sola
        // para todas las sucursales).
        $this->renombrar('Gerente administrativo regional', 'Coordinadora regional');
        // Todos los gestores de ruta son simplemente "Gestor".
        $this->renombrar('Gestor fijo', 'Gestor');

        // --- Dirección General ---
        $direccionGeneral = $this->puesto('Dirección General', [
            'departamento_id' => $departamento('Dirección'),
            'descripcion' => 'Cabeza de la organización.',
            'nivel_jerarquico' => 1,
            'puesto_superior_id' => null,
            'tipo_puesto' => TipoPuesto::Administrativo,
            'responsabilidades' => 'Dirección estratégica de la empresa y aprobación de decisiones de alto nivel.',
        ]);

        $this->puesto('Asistente de Dirección General', [
            'departamento_id' => $departamento('Dirección'),
            'descripcion' => 'Asistencia directa a Dirección General.',
            'nivel_jerarquico' => 2,
            'puesto_superior_id' => $direccionGeneral->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        // --- Dirección Comercial de Mr. Lana ---
        $directorComercial = $this->puesto('Director comercial', [
            'departamento_id' => $departamento('Ventas'),
            'descripcion' => 'Dirección Comercial de Mr. Lana. A su cargo están las gerencias corporativas y la división comercial.',
            'nivel_jerarquico' => 2,
            'puesto_superior_id' => $direccionGeneral->id,
            'tipo_puesto' => TipoPuesto::Comercial,
            'responsabilidades' => 'Vista global, reportes generales y decisiones estratégicas.',
        ]);

        $this->puesto('Asistente de Dirección Comercial', [
            'departamento_id' => $departamento('Ventas'),
            'descripcion' => 'Asistencia directa a la Dirección Comercial.',
            'nivel_jerarquico' => 3,
            'puesto_superior_id' => $directorComercial->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        // --- Gerencias bajo Dirección Comercial (mismo nivel) ---
        $gerenteSistemas = $this->puesto('Gerente de Sistemas', [
            'departamento_id' => $departamento('Sistemas'),
            'descripcion' => 'Responsable de la plataforma, infraestructura y monitoreo.',
            'nivel_jerarquico' => 3,
            'puesto_superior_id' => $directorComercial->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        $this->puesto('Monitorista', [
            'departamento_id' => $departamento('Sistemas'),
            'descripcion' => 'Monitoreo de sistemas y operación.',
            'nivel_jerarquico' => 4,
            'puesto_superior_id' => $gerenteSistemas->id,
            'puesto_crecimiento_id' => $gerenteSistemas->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        $gerenteMesaControl = $this->puesto('Gerente de Mesa de Control', [
            'departamento_id' => $departamento('Mesa de Control'),
            'descripcion' => 'Responsable de la mesa de control.',
            'nivel_jerarquico' => 3,
            'puesto_superior_id' => $directorComercial->id,
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

        $gerenteRh = $this->puesto('Gerente de Recursos Humanos', [
            'departamento_id' => $departamento('Recursos Humanos'),
            'descripcion' => 'Responsable de Recursos Humanos.',
            'nivel_jerarquico' => 3,
            'puesto_superior_id' => $directorComercial->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        $this->puesto('Administración de Personal', [
            'departamento_id' => $departamento('Recursos Humanos'),
            'descripcion' => 'Expedientes, altas, bajas y trámites de personal.',
            'nivel_jerarquico' => 4,
            'puesto_superior_id' => $gerenteRh->id,
            'puesto_crecimiento_id' => $gerenteRh->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        $this->puesto('Reclutamiento', [
            'departamento_id' => $departamento('Recursos Humanos'),
            'descripcion' => 'Atracción y selección de candidatos.',
            'nivel_jerarquico' => 4,
            'puesto_superior_id' => $gerenteRh->id,
            'puesto_crecimiento_id' => $gerenteRh->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        $gerenteContraloria = $this->puesto('Gerente de Contraloría', [
            'departamento_id' => $departamento('Contraloría'),
            'descripcion' => 'Responsable de contraloría.',
            'nivel_jerarquico' => 3,
            'puesto_superior_id' => $directorComercial->id,
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

        // --- División comercial: Gerente regional → sucursal ---
        $gerenteRegional = $this->puesto('Gerente regional', [
            'departamento_id' => $departamento('Ventas'),
            'descripcion' => 'Gerente regional de la división comercial: supervisa varias sucursales.',
            'nivel_jerarquico' => 3,
            'puesto_superior_id' => $directorComercial->id,
            'puesto_crecimiento_id' => $directorComercial->id,
            'tipo_puesto' => TipoPuesto::Comercial,
            'responsabilidades' => 'Supervisa varias sucursales, revisa indicadores y da seguimiento a gerentes.',
        ]);

        $gerenteSucursal = $this->puesto('Gerente de Sucursal', [
            'departamento_id' => $departamento('Ventas'),
            'descripcion' => 'Responsable de la sucursal.',
            'nivel_jerarquico' => 4,
            'puesto_superior_id' => $gerenteRegional->id,
            'puesto_crecimiento_id' => $gerenteRegional->id,
            'tipo_puesto' => TipoPuesto::Comercial,
            'esquema_comisiones' => 'Comisión por resultados de sucursal.',
            'responsabilidades' => 'Supervisa la operación de la sucursal y participa en la aprobación de candidatos y solicitudes.',
        ]);

        $subgerente = $this->puesto('Subgerente', [
            'departamento_id' => $departamento('Ventas'),
            'descripcion' => 'Apoya al gerente de sucursal, lo cubre temporalmente y supervisa gestores.',
            'nivel_jerarquico' => 5,
            'puesto_superior_id' => $gerenteSucursal->id,
            'puesto_crecimiento_id' => $gerenteSucursal->id,
            'tipo_puesto' => TipoPuesto::Comercial,
            'esquema_comisiones' => 'Comisión por equipo de gestores.',
        ]);

        $gestor = $this->puesto('Gestor', [
            'departamento_id' => $departamento('Ventas'),
            'descripcion' => 'Gestor de crédito: tiene su ruta asignada, que es la cartera que cobra. Es el único puesto con ruta.',
            'nivel_jerarquico' => 6,
            'puesto_superior_id' => $subgerente->id,
            'puesto_crecimiento_id' => $subgerente->id,
            'tipo_puesto' => TipoPuesto::Comercial,
            'esquema_comisiones' => 'Comisión por cartera/ruta asignada.',
            'requiere_ruta' => true,
        ]);

        $gestorVolante = $this->puesto('Gestor volante', [
            'departamento_id' => $departamento('Ventas'),
            'descripcion' => 'Apoya a los gestores y cubre una ruta cuando falta su gestor; no tiene ruta propia.',
            'nivel_jerarquico' => 7,
            'puesto_superior_id' => $gestor->id,
            'puesto_crecimiento_id' => $gestor->id,
            'tipo_puesto' => TipoPuesto::Comercial,
            'esquema_comisiones' => 'Comisión variable de apoyo.',
            'requiere_ruta' => false,
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

        // Respaldos (puede cubrir a):
        $gestorVolante->puestosQuePuedeCubrir()->syncWithoutDetaching([$gestor->id]);
        $subgerente->puestosQuePuedeCubrir()->syncWithoutDetaching([$gerenteSucursal->id]);

        // --- Coordinadora regional (una para todas las sucursales) ---
        $coordinadoraRegional = $this->puesto('Coordinadora regional', [
            'departamento_id' => $departamento('Operaciones'),
            'descripcion' => 'Coordinadora regional: una sola para todas las sucursales; supervisa a las coordinadoras de sucursal.',
            'nivel_jerarquico' => 3,
            'puesto_superior_id' => $directorComercial->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
        ]);

        $this->puesto('Coordinadora', [
            'departamento_id' => $departamento('Operaciones'),
            'descripcion' => 'Coordinadora de sucursal: cuadre de caja, control administrativo y procesos internos.',
            'nivel_jerarquico' => 4,
            'puesto_superior_id' => $coordinadoraRegional->id,
            'puesto_crecimiento_id' => $coordinadoraRegional->id,
            'tipo_puesto' => TipoPuesto::Administrativo,
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

    private function renombrar(string $anterior, string $nuevo): void
    {
        if (Puesto::where('nombre', $nuevo)->exists()) {
            return;
        }

        Puesto::where('nombre', $anterior)->update(['nombre' => $nuevo]);
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
