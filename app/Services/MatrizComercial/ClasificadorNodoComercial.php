<?php

namespace App\Services\MatrizComercial;

use App\Enums\ClaseEntradaMatriz;

/**
 * Clasifica una entrada de zona de la matriz comercial por su nombre tal
 * como lo entregó dirección. La lista mezcla rutas de cobro con posiciones
 * de la sucursal ("CUERNAVACA GTE", "MIACATLAN SUBGERENCIA", "ATLIX-SUBGTE",
 * "VOLANTE CUERNAVACA"): esas NO son rutas asignables a un Gestor.
 *
 * Reglas (en este orden, sobre el nombre en mayúsculas):
 *  1. SUBGERENCIA / SUBGTE               → Subgerencia
 *  2. GERENCIA / GTE (palabra)           → Gerencia
 *  3. empieza con VOLANTE                → Volante
 *  4. (INACTIVA)                         → Ruta inactiva
 *  5. (VENCIDOS) / (CASTIGO)             → Cartera especial (sigue siendo ruta)
 *  6. empieza con GRUPALES               → Operación grupal (sigue siendo ruta)
 *  7. cualquier otro                     → Ruta de cobro
 *
 * Usado por MatrizComercialSeeder (carga) y por
 * `people:sincronizar-organigrama` (reclasifica datos ya existentes).
 */
class ClasificadorNodoComercial
{
    public function clasificar(string $nombreCrudo, bool $activa = true): ClaseEntradaMatriz
    {
        $nombre = mb_strtoupper(trim($nombreCrudo));

        if (preg_match('/SUBGERENCIA|SUBGTE/u', $nombre) === 1) {
            return ClaseEntradaMatriz::Subgerencia;
        }

        if (preg_match('/GERENCIA|(^|[\s\-])GTE(\b|$)/u', $nombre) === 1) {
            return ClaseEntradaMatriz::Gerencia;
        }

        if (str_starts_with($nombre, 'VOLANTE')) {
            return ClaseEntradaMatriz::Volante;
        }

        if (! $activa || str_contains($nombre, '(INACTIVA)')) {
            return ClaseEntradaMatriz::RutaInactiva;
        }

        if (str_contains($nombre, '(VENCIDOS)') || str_contains($nombre, '(CASTIGO)')) {
            return ClaseEntradaMatriz::CarteraEspecial;
        }

        if (str_starts_with($nombre, 'GRUPALES')) {
            return ClaseEntradaMatriz::OperacionGrupal;
        }

        return ClaseEntradaMatriz::RutaCobro;
    }
}
