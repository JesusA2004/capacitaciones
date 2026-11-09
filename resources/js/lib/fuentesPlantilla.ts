/**
 * Origen de un cambio de plantilla autorizada (headcount_target_historial.fuente)
 * en texto para RH. Mismo listado en Sucursales y en la ficha del puesto.
 */
export function fuentePlantillaEtiqueta(fuente: string): string {
    if (fuente === 'captura' || fuente === 'manual') {
        return 'Captura de RH';
    }

    if (fuente === 'organigrama') {
        return 'Plaza automática (puesto sin titular)';
    }

    if (fuente === 'excel:plazas') {
        return 'Excel de plazas autorizadas';
    }

    if (fuente === 'puesto_eliminado') {
        return 'Puesto eliminado del catálogo';
    }

    return 'Excel de headcount';
}
