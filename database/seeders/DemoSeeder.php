<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Datos de DEMOSTRACIÓN: cuentas con credenciales conocidas
 * (@mrlana.test / "Capacitacion2026!"), dashboard y solicitudes de
 * ejemplo, y gestores demo en la matriz comercial.
 *
 * NUNCA debe correr en producción. Se ejecuta solo cuando DatabaseSeeder
 * detecta local/testing o SEED_DEMO_DATA=true (ver DatabaseSeeder::run()).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UsuarioDemoSeeder::class,
            DashboardDemoSeeder::class,
            // Documentos/contratos/versionado/bajas de expediente: necesita
            // los colaboradores de UsuarioDemoSeeder ya creados.
            ExpedienteDemoSeeder::class,
            SolicitudesDemoSeeder::class,
            // Colaborador sin cuenta con saldo de vacaciones real, préstamo
            // ya activo con movimiento y recibo histórico (Parte B, ver
            // docs/SOLICITUDES_UNIFICADAS.md): necesita rh.admin ya creado
            // y corre después de SolicitudesDemoSeeder para no chocar con
            // el préstamo pendiente_entrega que esa ya deja en colaborador4.
            PrestamoReciboDemoSeeder::class,
            // Vacantes/candidatos/altas/QR: necesita catálogo organizacional
            // y (para el flujo completo demo) colaboradores ya existentes.
            ReclutamientoDemoSeeder::class,
            // Gasto de campañas de reclutamiento: necesita sucursal/puesto/
            // usuario rh_admin ya creados (catálogo organizacional + UsuarioDemoSeeder).
            CampanaReclutamientoDemoSeeder::class,
            // Al final: completa cada sucursal (gerente, subgerente,
            // coordinadora, un gestor por ruta y volante), el corporativo y
            // las coberturas de ejemplo — reutiliza primero a los gestores que
            // los seeders anteriores ya crearon sin ruta.
            OrganigramaDemoSeeder::class,
        ]);
    }
}
