<?php

namespace Database\Seeders;

use App\Enums\GrupoPuestoIndicador;
use App\Enums\TipoPuesto;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Services\Expedientes\MigracionInicial\Normalizador;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos REALES que faltan para importar BASE_GENERAL
 * (MR_LANA_PEOPLE_BASE_GENERAL_MIGRACION_FINAL.xlsx, docs/MIGRACION_INICIAL_EXPEDIENTES.md).
 * Apto para PRODUCCIÓN: no crea personas, usuarios, solicitudes ni datos demo.
 *
 * Solo agrega lo que existe en el Excel y NO tiene equivalente en el
 * organigrama confirmado (App\Services\Organigrama\SincronizadorOrganigramaService):
 *   - departamentos: Jurídico, Mantenimiento;
 *   - puestos: Abogado, Escolta, Limpieza, Jardinero (Auditora es parte de la
 *     estructura canónica de SincronizadorOrganigramaService: Contraloría).
 * Los nombres legacy con equivalente canónico NO se crean: se traducen con
 * los alias de config/expedientes.php (migracion_inicial.alias_*) y las
 * reglas de contexto de ResolutorCatalogoMigracion.
 *
 * Idempotente: busca por nombre normalizado (sin acentos/mayúsculas), nunca
 * duplica y nunca modifica un registro que ya existe. Requiere haber corrido
 * DepartamentoSeeder y PuestoJerarquiaSeeder (estructura confirmada).
 */
class CatalogosBaseGeneralSeeder extends Seeder
{
    public const DEPARTAMENTOS = ['Jurídico', 'Mantenimiento'];

    /**
     * Puesto => [departamento, superior, nivel, tipo, descripción].
     *
     * @var array<string, array{0: string, 1: string, 2: int, 3: TipoPuesto, 4: string}>
     */
    public const PUESTOS = [
        'Abogado' => ['Jurídico', 'Dirección General', 3, TipoPuesto::Administrativo, 'Asuntos jurídicos y laborales de la empresa (Corporativo).'],
        'Escolta' => ['Dirección', 'Dirección General', 3, TipoPuesto::Operativo, 'Seguridad y acompañamiento de la Dirección.'],
        'Limpieza' => ['Mantenimiento', 'Dirección Comercial', 4, TipoPuesto::Operativo, 'Limpieza de instalaciones (Corporativo/sucursales).'],
        'Jardinero' => ['Mantenimiento', 'Dirección Comercial', 4, TipoPuesto::Operativo, 'Mantenimiento de áreas verdes.'],
    ];

    public function run(): void
    {
        foreach (self::DEPARTAMENTOS as $nombre) {
            if ($this->departamento($nombre) === null) {
                Departamento::query()->create(['nombre' => $nombre, 'activo' => true]);
                $this->command?->info("Departamento creado: {$nombre}");
            }
        }

        foreach (self::PUESTOS as $nombre => [$departamento, $superior, $nivel, $tipo, $descripcion]) {
            if ($this->puesto($nombre) !== null) {
                continue;
            }

            $superiorPuesto = $this->puesto($superior);

            if ($superiorPuesto === null) {
                $this->command?->warn(sprintf('No existe «%s» (corre PuestoJerarquiaSeeder primero): «%s» se crea sin superior.', $superior, $nombre));
            }

            $atributos = [
                'nombre' => $nombre,
                'departamento_id' => $this->departamento($departamento)?->id,
                'descripcion' => $descripcion,
                'nivel_jerarquico' => $nivel,
                'puesto_superior_id' => $superiorPuesto?->id,
                'tipo_puesto' => $tipo,
                'requiere_ruta' => false,
                'activo' => true,
            ];

            $puesto = Puesto::query()->create($atributos);

            if (Schema::hasColumn('puestos', 'grupo_indicador')) {
                $puesto->forceFill(['grupo_indicador' => GrupoPuestoIndicador::Otros->value])->save();
            }

            $this->command?->info("Puesto creado: {$nombre}");
        }
    }

    private function departamento(string $nombre): ?Departamento
    {
        $clave = Normalizador::clave($nombre);

        return Departamento::query()->get(['id', 'nombre'])->first(fn (Departamento $d) => Normalizador::clave($d->nombre) === $clave);
    }

    private function puesto(string $nombre): ?Puesto
    {
        $clave = Normalizador::clave($nombre);

        return Puesto::query()->get(['id', 'nombre'])->first(fn (Puesto $p) => Normalizador::clave($p->nombre) === $clave);
    }
}
