import type {
    ActaDetalle,
    FormularioCircunstanciada,
    TestigoCaptura,
} from '@/types';

/** Pasos de la captura del acta administrativa circunstanciada. */
export const PASOS_ACTA = [
    { numero: 1, titulo: 'Incidencia', ayuda: 'Tipo, fecha, hora y lugar' },
    { numero: 2, titulo: 'Hechos', ayuda: 'Narración circunstanciada' },
    { numero: 3, titulo: 'Participantes', ayuda: 'Quién interviene' },
    { numero: 4, titulo: 'Manifestaciones', ayuda: 'Lo que dijo cada persona' },
    { numero: 5, titulo: 'Anexos', ayuda: 'Evidencias relacionadas' },
    { numero: 6, titulo: 'Revisión', ayuda: 'Vista previa y acta definitiva' },
] as const;

export type NumeroPaso = (typeof PASOS_ACTA)[number]['numero'];

/** Fecha y hora locales ("YYYY-MM-DD", "HH:mm"). */
export function ahoraLocal(): { fecha: string; hora: string } {
    const d = new Date();
    const dos = (n: number) => String(n).padStart(2, '0');

    return {
        fecha: `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}`,
        hora: `${dos(d.getHours())}:${dos(d.getMinutes())}`,
    };
}

/** Formulario vacío: levantamiento con fecha y hora de ahora. */
export function formularioVacio(): FormularioCircunstanciada {
    const { fecha, hora } = ahoraLocal();

    return {
        fecha,
        hora,
        lugar: '',
        hechos: '',
        circunstanciada: {
            disposiciones: '',
            incidencia: {
                tipo: '',
                otro: '',
                fecha_desde: fecha,
                fecha_hasta: '',
                hora_desde: '',
                hora_hasta: '',
                lugar_hechos: '',
            },
            responsable: { caracter: '', manifestacion: '' },
            jefe: {
                participa: false,
                colaborador_id: null,
                nombre: '',
                puesto: '',
                manifestacion: '',
            },
            testigos_hechos: [],
            testigos_asistencia: [],
            trabajador: { manifesto: null, manifestacion: '', elementos: '' },
            incidencias_levantamiento: '',
            cierre: { fecha: '', hora: '' },
        },
    };
}

/** El acta guardada → formulario editable (sin perder nada de lo capturado). */
export function formularioDesdeActa(
    acta: ActaDetalle,
): FormularioCircunstanciada {
    const base = formularioVacio();
    const c = acta.circunstanciada;
    const t = (v: string | null | undefined) => v ?? '';

    if (!c) {
        return {
            ...base,
            fecha: acta.fecha,
            hora: t(acta.hora),
            lugar: t(acta.lugar),
            hechos: acta.hechos,
        };
    }

    const jefeParticipa = c.jefe?.participa ?? false;
    const sugerido = acta.jefe_sugerido;

    return {
        fecha: acta.fecha,
        hora: t(acta.hora)?.slice(0, 5),
        lugar: t(acta.lugar),
        hechos: acta.hechos,
        circunstanciada: {
            disposiciones: t(c.disposiciones),
            incidencia: {
                tipo: t(c.incidencia?.tipo),
                otro: t(c.incidencia?.otro),
                fecha_desde: t(c.incidencia?.fecha_desde),
                fecha_hasta: t(c.incidencia?.fecha_hasta),
                hora_desde: t(c.incidencia?.hora_desde),
                hora_hasta: t(c.incidencia?.hora_hasta),
                lugar_hechos: t(c.incidencia?.lugar_hechos),
            },
            responsable: {
                caracter: t(c.responsable?.caracter),
                manifestacion: t(c.responsable?.manifestacion),
            },
            // Si no se ha decidido, PEOPLE propone al jefe del organigrama.
            jefe: jefeParticipa
                ? {
                      participa: true,
                      colaborador_id: c.jefe.colaborador_id,
                      nombre: t(c.jefe.nombre),
                      puesto: t(c.jefe.puesto),
                      manifestacion: t(c.jefe.manifestacion),
                  }
                : {
                      participa: false,
                      colaborador_id: sugerido?.id ?? null,
                      nombre: sugerido?.nombre ?? '',
                      puesto: sugerido?.puesto ?? '',
                      manifestacion: '',
                  },
            testigos_hechos: (c.testigos_hechos ?? []).map((x) => ({
                colaborador_id: x.colaborador_id,
                nombre: t(x.nombre),
                puesto: t(x.puesto),
                relacion: t(x.relacion),
                relacion_otro: t(x.relacion_otro),
                manifestacion: t(x.manifestacion),
            })),
            testigos_asistencia: (c.testigos_asistencia ?? []).map((x) => ({
                colaborador_id: x.colaborador_id,
                nombre: t(x.nombre),
                puesto: t(x.puesto),
                manifestacion: t(x.manifestacion),
            })),
            trabajador: {
                manifesto: c.trabajador?.manifesto ?? null,
                manifestacion: t(c.trabajador?.manifestacion),
                elementos: t(c.trabajador?.elementos),
            },
            incidencias_levantamiento: t(c.incidencias_levantamiento),
            cierre: { fecha: t(c.cierre?.fecha), hora: t(c.cierre?.hora) },
        },
    };
}

/** Lo que se envía: vacíos como null y solo los datos que el backend usa. */
export function cargaActa(f: FormularioCircunstanciada) {
    const n = (v: string) => (v.trim() === '' ? null : v);
    const c = f.circunstanciada;

    return {
        fecha: f.fecha,
        hora: n(f.hora),
        lugar: n(f.lugar),
        hechos: f.hechos,
        circunstanciada: {
            disposiciones: n(c.disposiciones),
            incidencia: {
                tipo: n(c.incidencia.tipo),
                otro:
                    c.incidencia.tipo === 'otra' ? n(c.incidencia.otro) : null,
                fecha_desde: n(c.incidencia.fecha_desde),
                fecha_hasta: n(c.incidencia.fecha_hasta),
                hora_desde: n(c.incidencia.hora_desde),
                hora_hasta: n(c.incidencia.hora_hasta),
                lugar_hechos: n(c.incidencia.lugar_hechos),
            },
            responsable: {
                caracter: n(c.responsable.caracter),
                manifestacion: n(c.responsable.manifestacion),
            },
            jefe: {
                participa: c.jefe.participa && c.jefe.colaborador_id !== null,
                colaborador_id: c.jefe.participa ? c.jefe.colaborador_id : null,
                manifestacion: c.jefe.participa
                    ? n(c.jefe.manifestacion)
                    : null,
            },
            testigos_hechos: c.testigos_hechos
                .filter((x) => x.colaborador_id !== null)
                .map((x) => ({
                    colaborador_id: x.colaborador_id,
                    relacion: n(x.relacion),
                    relacion_otro:
                        x.relacion === 'otra' ? n(x.relacion_otro) : null,
                    manifestacion: n(x.manifestacion),
                })),
            testigos_asistencia: c.testigos_asistencia
                .filter((x) => x.colaborador_id !== null)
                .map((x) => ({
                    colaborador_id: x.colaborador_id,
                    manifestacion: n(x.manifestacion),
                })),
            trabajador: {
                manifesto: c.trabajador.manifesto,
                manifestacion:
                    c.trabajador.manifesto === true
                        ? n(c.trabajador.manifestacion)
                        : null,
                elementos: n(c.trabajador.elementos),
            },
            incidencias_levantamiento: n(c.incidencias_levantamiento),
            cierre: { fecha: n(c.cierre.fecha), hora: n(c.cierre.hora) },
        },
    };
}

/**
 * Por qué una persona no puede intervenir en ese lugar: es el trabajador o
 * ya interviene con otro carácter (misma regla que GuardarActaRequest).
 */
export function conflictoPersona(
    f: FormularioCircunstanciada,
    trabajadorId: number,
    id: number | null,
    propio: TestigoCaptura | 'jefe',
): string | null {
    if (id === null) {
        return null;
    }

    if (id === trabajadorId) {
        return 'El trabajador involucrado no puede intervenir como testigo de su propia acta.';
    }

    const c = f.circunstanciada;
    const otros: { id: number | null; rol: string; ref: unknown }[] = [
        ...(c.jefe.participa
            ? [
                  {
                      id: c.jefe.colaborador_id,
                      rol: 'jefe inmediato',
                      ref: 'jefe',
                  },
              ]
            : []),
        ...c.testigos_hechos.map((x) => ({
            id: x.colaborador_id,
            rol: 'testigo de hechos',
            ref: x,
        })),
        ...c.testigos_asistencia.map((x) => ({
            id: x.colaborador_id,
            rol: 'testigo de asistencia',
            ref: x,
        })),
    ];
    const repetido = otros.find((o) => o.ref !== propio && o.id === id);

    return repetido ? `Ya interviene como ${repetido.rol}.` : null;
}

/** Espejo de ActaPdfService::faltante (el backend vuelve a validarlo). */
export function faltantesActa(f: FormularioCircunstanciada): string[] {
    const c = f.circunstanciada;
    const faltan: string[] = [];

    if (!c.incidencia.tipo) {
        faltan.push('Indica el tipo de incidencia.');
    } else if (c.incidencia.tipo === 'otra' && !c.incidencia.otro.trim()) {
        faltan.push('Describe el tipo de incidencia.');
    }

    if (!c.incidencia.fecha_desde) {
        faltan.push('Indica la fecha o el período de los hechos.');
    }

    if (!f.hechos.trim()) {
        faltan.push('Describe los hechos de manera circunstanciada.');
    }

    if (!c.responsable.caracter) {
        faltan.push(
            'Indica el carácter con el que interviene quien levanta el acta.',
        );
    }

    if (c.trabajador.manifesto === null) {
        faltan.push(
            'Registra la manifestación del trabajador o que no quiso manifestar.',
        );
    } else if (c.trabajador.manifesto && !c.trabajador.manifestacion.trim()) {
        faltan.push('Captura lo que manifestó el trabajador.');
    }

    if (
        c.testigos_hechos.some((x) => x.colaborador_id !== null && !x.relacion)
    ) {
        faltan.push('Indica la relación de cada testigo con los hechos.');
    }

    return faltan;
}
