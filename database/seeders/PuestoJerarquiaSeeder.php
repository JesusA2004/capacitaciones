<?php

namespace Database\Seeders;

use App\Enums\TipoPuesto;
use App\Models\Departamento;
use App\Models\Puesto;
use Illuminate\Database\Seeder;

/**
 * Siembra el organigrama real de Mr. Lana: una sola raíz (Dirección
 * General) de la que cuelgan las direcciones/áreas de la empresa
 * (Comercial, Recursos Humanos, Contabilidad, Sistemas, Operaciones
 * administrativas), cada una con su propia línea de crecimiento. Separado
 * de PuestoSeeder (catálogo genérico de departamentos) porque este es el
 * árbol de reporte/crecimiento/cobertura real, no solo una lista de
 * puestos por departamento. Ver docs/JERARQUIA_PUESTOS.md.
 */
class PuestoJerarquiaSeeder extends Seeder
{
    public function run(): void
    {
        $ventas = Departamento::where('nombre', 'Ventas')->first();
        $operaciones = Departamento::where('nombre', 'Operaciones')->first();
        $rh = Departamento::where('nombre', 'Recursos Humanos')->first();
        $contabilidad = Departamento::where('nombre', 'Contabilidad')->first();
        $sistemas = Departamento::where('nombre', 'Sistemas')->first();

        // --- Raíz: Dirección General ---
        $direccionGeneral = Puesto::firstOrCreate(
            ['nombre' => 'Dirección General'],
            [
                'departamento_id' => null,
                'descripcion' => 'Cabeza de la organización. Todas las direcciones/áreas reportan aquí.',
                'nivel_jerarquico' => 1,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Dirección estratégica de la empresa, aprobación de decisiones de alto nivel, supervisión de todas las áreas.',
                'activo' => true,
            ],
        );

        // --- Rama Comercial ---
        // Dirección General -> Director comercial -> Gerente regional -> Gerente -> Subgerente -> Gestor fijo -> Gestor volante
        $directorComercial = Puesto::updateOrCreate(
            ['nombre' => 'Director comercial'],
            [
                'departamento_id' => $ventas?->id,
                'descripcion' => 'Vista global del área comercial, reportes generales y decisiones estratégicas.',
                'nivel_jerarquico' => 2,
                'puesto_superior_id' => $direccionGeneral->id,
                'tipo_puesto' => TipoPuesto::Comercial,
                'responsabilidades' => 'Vista global, reportes generales, decisiones estratégicas.',
                'activo' => true,
            ],
        );

        $gerenteRegional = Puesto::updateOrCreate(
            ['nombre' => 'Gerente regional'],
            [
                'departamento_id' => $ventas?->id,
                'descripcion' => 'Supervisa varias sucursales, revisa indicadores y da seguimiento a gerentes.',
                'nivel_jerarquico' => 3,
                'puesto_superior_id' => $directorComercial->id,
                'puesto_crecimiento_id' => $directorComercial->id,
                'tipo_puesto' => TipoPuesto::Comercial,
                'responsabilidades' => 'Supervisa varias sucursales, revisa indicadores, da seguimiento a gerentes.',
                'activo' => true,
            ],
        );

        $gerente = Puesto::updateOrCreate(
            ['nombre' => 'Gerente'],
            [
                'departamento_id' => $ventas?->id,
                'descripcion' => 'Responsable de sucursal, supervisa la operación y participa en la aprobación de candidatos y solicitudes.',
                'nivel_jerarquico' => 4,
                'puesto_superior_id' => $gerenteRegional->id,
                'puesto_crecimiento_id' => $gerenteRegional->id,
                'tipo_puesto' => TipoPuesto::Comercial,
                'esquema_comisiones' => 'Comisión por resultados de sucursal.',
                'responsabilidades' => 'Responsable de sucursal, supervisa operación, participa en aprobación de candidatos y solicitudes.',
                'activo' => true,
            ],
        );

        $subgerente = Puesto::updateOrCreate(
            ['nombre' => 'Subgerente'],
            [
                'departamento_id' => $ventas?->id,
                'descripcion' => 'Apoya al gerente, lo cubre temporalmente y supervisa gestores.',
                'nivel_jerarquico' => 5,
                'puesto_superior_id' => $gerente->id,
                'puesto_crecimiento_id' => $gerente->id,
                'tipo_puesto' => TipoPuesto::Comercial,
                'esquema_comisiones' => 'Comisión por equipo de gestores.',
                'responsabilidades' => 'Apoya gerente, cubre gerente temporalmente, supervisa gestores.',
                'activo' => true,
            ],
        );

        $gestorFijo = Puesto::updateOrCreate(
            ['nombre' => 'Gestor fijo'],
            [
                'departamento_id' => $ventas?->id,
                'descripcion' => 'Gestor de ruta: tiene ruta asignada y es responsable de su cartera.',
                'nivel_jerarquico' => 6,
                'puesto_superior_id' => $subgerente->id,
                'puesto_crecimiento_id' => $subgerente->id,
                'tipo_puesto' => TipoPuesto::Comercial,
                'esquema_comisiones' => 'Comisión por cartera/ruta asignada.',
                'requiere_ruta' => true,
                'responsabilidades' => 'Tiene ruta asignada, puede recibir mayor comisión, responsable de cartera/ruta.',
                'activo' => true,
            ],
        );

        $gestorVolante = Puesto::updateOrCreate(
            ['nombre' => 'Gestor volante'],
            [
                'departamento_id' => $ventas?->id,
                'descripcion' => 'Cubre rutas cuando falta un gestor fijo y apoya rutas lejanas o con carga.',
                'nivel_jerarquico' => 7,
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

        // --- Rama de Recursos Humanos ---
        // Dirección General -> Gerente de Recursos Humanos -> Coordinador de Capacitación / Generalista de RH
        $gerenteRh = Puesto::updateOrCreate(
            ['nombre' => 'Gerente de Recursos Humanos'],
            [
                'departamento_id' => $rh?->id,
                'descripcion' => 'Responsable del área de Recursos Humanos: reclutamiento, nómina, capacitación y clima laboral.',
                'nivel_jerarquico' => 2,
                'puesto_superior_id' => $direccionGeneral->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Dirige reclutamiento, altas/bajas, capacitación y relaciones laborales de toda la empresa.',
                'activo' => true,
            ],
        );

        Puesto::updateOrCreate(
            ['nombre' => 'Generalista de RH'],
            [
                'departamento_id' => $rh?->id,
                'descripcion' => 'Reclutamiento, altas digitales, expedientes y trámites de personal.',
                'nivel_jerarquico' => 3,
                'puesto_superior_id' => $gerenteRh->id,
                'puesto_crecimiento_id' => $gerenteRh->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Reclutamiento, altas digitales, expedientes, trámites de personal.',
                'activo' => true,
            ],
        );

        Puesto::updateOrCreate(
            ['nombre' => 'Coordinador de Capacitación'],
            [
                'departamento_id' => $rh?->id,
                'descripcion' => 'Diseña y da seguimiento a la capacitación e inducción de nuevo ingreso.',
                'nivel_jerarquico' => 3,
                'puesto_superior_id' => $gerenteRh->id,
                'puesto_crecimiento_id' => $gerenteRh->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Diseña cursos, da seguimiento a la capacitación e inducción de nuevo ingreso.',
                'activo' => true,
            ],
        );

        // --- Rama de Contabilidad ---
        // Dirección General -> Gerente de Contabilidad -> Analista de Nómina / Auxiliar Contable
        $gerenteContabilidad = Puesto::updateOrCreate(
            ['nombre' => 'Gerente de Contabilidad'],
            [
                'departamento_id' => $contabilidad?->id,
                'descripcion' => 'Responsable de contabilidad, nómina, pagos y cumplimiento fiscal.',
                'nivel_jerarquico' => 2,
                'puesto_superior_id' => $direccionGeneral->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Dirige contabilidad general, nómina, pagos a proveedores y cumplimiento fiscal.',
                'activo' => true,
            ],
        );

        Puesto::updateOrCreate(
            ['nombre' => 'Analista de Nómina'],
            [
                'departamento_id' => $contabilidad?->id,
                'descripcion' => 'Cálculo y timbrado de nómina, altas/bajas ante IMSS.',
                'nivel_jerarquico' => 3,
                'puesto_superior_id' => $gerenteContabilidad->id,
                'puesto_crecimiento_id' => $gerenteContabilidad->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Cálculo y timbrado de nómina, altas/bajas ante IMSS.',
                'activo' => true,
            ],
        );

        Puesto::updateOrCreate(
            ['nombre' => 'Auxiliar Contable'],
            [
                'departamento_id' => $contabilidad?->id,
                'descripcion' => 'Registro de pólizas, conciliaciones bancarias y apoyo administrativo contable.',
                'nivel_jerarquico' => 3,
                'puesto_superior_id' => $gerenteContabilidad->id,
                'puesto_crecimiento_id' => $gerenteContabilidad->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Registro de pólizas, conciliaciones bancarias, apoyo administrativo contable.',
                'activo' => true,
            ],
        );

        // --- Rama de Sistemas ---
        // Dirección General -> Gerente de Sistemas -> Analista de Sistemas / Soporte Técnico
        $gerenteSistemas = Puesto::updateOrCreate(
            ['nombre' => 'Gerente de Sistemas'],
            [
                'departamento_id' => $sistemas?->id,
                'descripcion' => 'Responsable de la plataforma, infraestructura y soporte técnico de la empresa.',
                'nivel_jerarquico' => 2,
                'puesto_superior_id' => $direccionGeneral->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Dirige el área de sistemas: plataforma interna, infraestructura y soporte.',
                'activo' => true,
            ],
        );

        Puesto::updateOrCreate(
            ['nombre' => 'Analista de Sistemas'],
            [
                'departamento_id' => $sistemas?->id,
                'descripcion' => 'Desarrollo y mantenimiento de sistemas internos.',
                'nivel_jerarquico' => 3,
                'puesto_superior_id' => $gerenteSistemas->id,
                'puesto_crecimiento_id' => $gerenteSistemas->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Desarrollo y mantenimiento de sistemas internos.',
                'activo' => true,
            ],
        );

        Puesto::updateOrCreate(
            ['nombre' => 'Soporte Técnico'],
            [
                'departamento_id' => $sistemas?->id,
                'descripcion' => 'Soporte a usuarios, equipos y conectividad en oficinas y sucursales.',
                'nivel_jerarquico' => 3,
                'puesto_superior_id' => $gerenteSistemas->id,
                'puesto_crecimiento_id' => $gerenteSistemas->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Soporte a usuarios, equipos y conectividad en oficinas y sucursales.',
                'activo' => true,
            ],
        );

        // --- Rama de Operaciones administrativas ---
        // Dirección General -> Responsable administrativo/regional -> Coordinadora regional -> Coordinadora
        $responsableAdministrativo = Puesto::updateOrCreate(
            ['nombre' => 'Responsable administrativo/regional'],
            [
                'departamento_id' => $operaciones?->id,
                'descripcion' => 'Responsable administrativo a nivel regional.',
                'nivel_jerarquico' => 2,
                'puesto_superior_id' => $direccionGeneral->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'activo' => true,
            ],
        );

        $coordinadoraRegional = Puesto::updateOrCreate(
            ['nombre' => 'Coordinadora regional'],
            [
                'departamento_id' => $operaciones?->id,
                'descripcion' => 'Supervisa coordinadoras de varias sucursales.',
                'nivel_jerarquico' => 3,
                'puesto_superior_id' => $responsableAdministrativo->id,
                'puesto_crecimiento_id' => $responsableAdministrativo->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Supervisa coordinadoras de varias sucursales.',
                'activo' => true,
            ],
        );

        Puesto::updateOrCreate(
            ['nombre' => 'Coordinadora'],
            [
                'departamento_id' => $operaciones?->id,
                'descripcion' => 'Cuadre de caja, control administrativo y procesos internos de sucursal.',
                'nivel_jerarquico' => 4,
                'puesto_superior_id' => $coordinadoraRegional->id,
                'puesto_crecimiento_id' => $coordinadoraRegional->id,
                'tipo_puesto' => TipoPuesto::Administrativo,
                'responsabilidades' => 'Cuadre de caja, control administrativo, procesos internos.',
                'activo' => true,
            ],
        );
    }
}
