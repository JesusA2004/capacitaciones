/**
 * Texto del mensaje de felicitación para copiar al portapapeles. Centralizado
 * aquí porque tanto el calendario (`Rh/Cumpleanos/Index.vue`) como la vista
 * de una felicitación puntual (`Rh/Cumpleanos/Felicitacion.vue`) lo usan.
 */
export function mensajeFelicitacion(nombre: string): string {
    return `¡Feliz cumpleaños, ${nombre}! De parte de todo el equipo MR. LANA. 🎉`;
}
