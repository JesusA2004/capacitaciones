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
            // Después de UsuarioDemoSeeder: necesita colaboradores activos
            // ya creados para tener a quién asignar como gestor de ruta.
            GestoresDemoSeeder::class,
            DashboardDemoSeeder::class,
            // Documentos/contratos/versionado/bajas de expediente: necesita
            // los colaboradores de UsuarioDemoSeeder ya creados.
            ExpedienteDemoSeeder::class,
            SolicitudesDemoSeeder::class,
            // Vacantes/candidatos/altas/QR: necesita catálogo organizacional
            // y (para el flujo completo demo) colaboradores ya existentes.
            ReclutamientoDemoSeeder::class,
            // Gasto de campañas de reclutamiento: necesita sucursal/puesto/
            // usuario rh_admin ya creados (catálogo organizacional + UsuarioDemoSeeder).
            CampanaReclutamientoDemoSeeder::class,
        ]);
    }
}
