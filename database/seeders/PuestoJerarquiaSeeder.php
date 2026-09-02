<?php

namespace Database\Seeders;

use App\Enums\TipoPuesto;
use App\Models\Departamento;
use App\Models\Puesto;
use Illuminate\Database\Seeder;

/**
 * Siembra la jerarquia comercial y administrativa base del negocio.
 * Separado de PuestoSeeder (catalogo generico de departamentos) porque
 * este es el organigrama real de crecimiento/cobertura de la operacion.
 */
class PuestoJerarquiaSeeder extends Seeder
{
    public function run(): void
    {
        $ventas = Departamento::where('nombre', 'Ventas')->first();
        $operaciones = Departamento::where('nombre', 'Operaciones')->first();

        // --- Linea comercial ---
        // Gestor volante -> Gestor fijo -> Subgerente -> Gerente -> Gerente regional -> Director comercial
        $directorComercial = Puesto::firstOrCreate(
            ['nombre' => 'Director comercial'],
            [
                'departamento_id' => $ventas?->id,
                'descripcion' => 'Vista global del área comercial, reportes generales y decisiones estratégicas.',
                'nivel_jerarquico' => 1,
                'tipo_puesto' => TipoPuesto::Comercial,
                'responsabilidades' => 'Vista global, reportes generales, decisiones estratégicas.',
                'activo' => true,
            ],
        );

        $gerenteRegional = Puesto::firstOrCreate(
            ['nombre' => 'Gerente regional'],
            [
                'departamento_id' => $ventas?->id,
                'descripcion' => 'Supervisa varias sucursales, revisa indicadores y da seguimiento a gerentes.',
                'nivel_jerarquico' => 2,
                'puesto_superior_id' => $directorComercial->id,
                'puesto_crecimiento_id' => $directorComercial->id,
                'tipo_puesto' => TipoPuesto::Comercial,
                'responsabilidades' => 'Supervisa varias sucursales, revisa indicadores, da seguimiento a gerentes.',
                'activo' => true,
            ],
        );

        $gerente = Puesto::firstOrCreate(
            ['nombre' => 'Gerente'],
            [
                'departamento_id' => $ventas?->id,
                'descripcion' => 'Responsable de sucursal, supervisa la operación y participa en la aprobación de candidatos y solicitudes.',
                'nivel_jerarquico' => 3,
                'puesto_superior_id' => $gerenteRegional->id,
                'puesto_crecimiento_id' => $gerenteRegional->id,
                'tipo_puesto' => TipoPuesto::Comercial,
                'esquema_comisiones' => 'Comisión por resultados de sucursal.',
                'responsabilidades' => 'Responsable de sucursal, supervisa operación, participa en aprobación de candidatos y solicitudes.',
                'activo' => true,
            ],
        );

        $subgerente = Puesto::firstOrCreate(
            ['nombre' => 'Subgerente'],
            [
                'departamento_id' => $ventas?->id,
                'descripcion' => 'Apoya al gerente, lo cubre temporalmente y supervisa gestores.',
                'nivel_jerarquico' => 4,
                'puesto_superior_id' => $gerente->id,
                'puesto_crecimiento_id' => $gerente->id,
                'tipo_puesto' => TipoPuesto::Comercial,
                'esquema_comisiones' => 'Comisión por equipo de gestores.',
                'responsabilidades' => 'Apoya gerente, cubre gerente temporalmente, supervisa gestores.',
                'activo' => true,
            ],
        );

        $gestorFijo = Puesto::firstOrCreate(
            ['nombre' => 'Gestor fijo'],
            [
                'departamento_id' => $ventas?->id,
                'descripcion' => 'Gestor de ruta: tiene ruta asignada y es responsable de su cartera.',
                'nivel_jerarquico' => 5,
                'puesto_superior_id' => $subgerente->id,
                'puesto_crecimiento_id' => $subgerente->id,
                'tipo_puesto' => TipoPuesto::Comercial,
                'esquema_comisiones' => 'Comisión por cartera/ruta asignada.',
                'requiere_ruta' => true,
                'responsabilidades' => 'Tiene ruta asignada, puede recibir mayor comisión, responsable de cartera/ruta.',
                'activo' => true,
            ],
        );

        $gestorVolante = Puesto::firstOrCreate(
            ['nombre' => 'Gestor volante'],
            [
                'departamento_id' => $ventas?->id,
                'descripcion' => 'Cubre rutas cuando falta un gestor fijo y apoya rutas lejanas o con carga.',
                'nivel_jerarquico' => 6,
                'puesto_superior_id' => $gestorFijo->id,
                'puesto_crecimiento_id' => $gestorFijo->id,
                'tipo_puesto' => TipoPuesto::Comercial,
                'esquema_comisiones' => 'Comisión variable de apoyo.',
                'requiere_ruta' => false,
                'responsabilidades' => 'Cubre rutas cuando falta gestor fijo, apoya rutas lejanas o con carga, candidato natural cuando se libera una ruta.',
                'activo' => true,
            ],
        );

        // Respaldos (puede cubrir a):
        $gestorVolante->puestosQuePuedeCubrir()->syncWithoutDetaching([$gestorFijo->id]);
        $subgerente->puestosQuePuedeCubrir()->syncWithoutDetaching([$gerente->id]);

        // --- Linea administrativa ---
        // Coordinadora -> Coordinadora regional -> Responsable administrativo/regional
        $responsableAdministrativo = Puesto::firstOrCreate(
            ['nombre' => 'Responsable administrativo/regional'],
            [
                'departamento_id' => $operaciones?->id,
                'descripcion' => 'Responsable administrativo a nivel regional.',
                'nivel_jerarquico' => 1,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'activo' => true,
            ],
        );

        $coordinadoraRegional = Puesto::firstOrCreate(
            ['nombre' => 'Coordinadora regional'],
            [
                'departamento_id' => $operaciones?->id,
                'descripcion' => 'Supervisa coordinadoras de varias sucursales.',
                'nivel_jerarquico' => 2,
                'puesto_superior_id' => $responsableAdministrativo->id,
                'puesto_crecimiento_id' => $responsableAdministrativo->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Supervisa coordinadoras de varias sucursales.',
                'activo' => true,
            ],
        );

        Puesto::firstOrCreate(
            ['nombre' => 'Coordinadora'],
            [
                'departamento_id' => $operaciones?->id,
                'descripcion' => 'Cuadre de caja, control administrativo y procesos internos de sucursal.',
                'nivel_jerarquico' => 3,
                'puesto_superior_id' => $coordinadoraRegional->id,
                'puesto_crecimiento_id' => $coordinadoraRegional->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Cuadre de caja, control administrativo, procesos internos.',
                'activo' => true,
            ],
        );
    }
}
