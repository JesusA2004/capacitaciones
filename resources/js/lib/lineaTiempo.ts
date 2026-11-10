import {
    Banknote,
    BookOpenCheck,
    Brain,
    ClipboardList,
    Download,
    FileSignature,
    FileText,
    FolderOpen,
    GraduationCap,
    Home,
    KeyRound,
    Mail,
    MonitorSmartphone,
    PencilLine,
    Phone,
    Printer,
    QrCode,
    Route,
    ShieldCheck,
    ShieldX,
    Smartphone,
    StickyNote,
    UserCheck,
    UserRoundCheck,
    UserRoundSearch,
    UserX,
} from '@lucide/vue';
import type { Component } from 'vue';
import { formatearFecha, formatearFechaHora } from '@/lib/fechas';
import type { Tono } from '@/lib/tonos';
import type { ItemTimeline } from '@/types';

/**
 * Línea de tiempo «suavizada» de una persona: cada evento cae en una
 * categoría (color + ícono), los repetidos seguidos del mismo tipo y autor
 * se juntan en un solo renglón («9 secciones guardadas») y se distinguen los
 * hitos clave del resto. No se pierde nada: los grupos conservan sus eventos.
 */
export type CategoriaHistorial =
    | 'reclutamiento'
    | 'aprobaciones'
    | 'contratacion'
    | 'documentos'
    | 'induccion'
    | 'cambios'
    | 'accesos'
    | 'notas'
    | 'cierre'
    | 'otros';

export const CATEGORIAS_HISTORIAL: Record<
    CategoriaHistorial,
    { etiqueta: string; tono: Tono; icono: Component }
> = {
    reclutamiento: {
        etiqueta: 'Reclutamiento',
        tono: 'info',
        icono: UserRoundSearch,
    },
    aprobaciones: {
        etiqueta: 'Aprobaciones',
        tono: 'oro',
        icono: ShieldCheck,
    },
    contratacion: {
        etiqueta: 'Contratación',
        tono: 'primario',
        icono: UserRoundCheck,
    },
    documentos: { etiqueta: 'Documentos', tono: 'neutro', icono: FileText },
    induccion: {
        etiqueta: 'Inducción y prueba',
        tono: 'exito',
        icono: GraduationCap,
    },
    cambios: {
        etiqueta: 'Cambios de datos',
        tono: 'alerta',
        icono: PencilLine,
    },
    accesos: { etiqueta: 'Accesos', tono: 'oro', icono: KeyRound },
    notas: { etiqueta: 'Notas', tono: 'neutro', icono: StickyNote },
    cierre: { etiqueta: 'Baja y reingreso', tono: 'peligro', icono: UserX },
    otros: { etiqueta: 'Otros', tono: 'neutro', icono: ClipboardList },
};

const EVENTOS_DE_CAMBIOS = [
    'cambio_salario',
    'ajuste_vacaciones',
    'jefe_directo_cambiado',
    'colaborador_actualizado',
    'candidato_actualizado',
];

export function categoriaDe(item: ItemTimeline): CategoriaHistorial {
    const { evento, etapa } = item;

    if (evento.startsWith('seguimiento_')) {
        return evento === 'seguimiento_cambio_estado'
            ? 'reclutamiento'
            : 'notas';
    }

    if (
        EVENTOS_DE_CAMBIOS.includes(evento) ||
        evento.startsWith('expediente_datos') ||
        evento.startsWith('correccion_') ||
        evento.startsWith('foto_perfil')
    ) {
        return 'cambios';
    }

    if (
        etapa === 'documentos' ||
        evento.startsWith('documento_') ||
        evento.startsWith('solicitud_empleo')
    ) {
        return 'documentos';
    }

    const porEtapa: Record<string, CategoriaHistorial> = {
        reclutamiento: 'reclutamiento',
        aprobacion: 'aprobaciones',
        intervencion: 'aprobaciones',
        contratacion: 'contratacion',
        accesos: 'accesos',
        onboarding: 'induccion',
        periodo_prueba: 'induccion',
        cierre: 'cierre',
        reingreso: 'cierre',
    };

    return porEtapa[etapa] ?? 'otros';
}

/** Ícono propio del evento (por prefijo); si no hay, el de su categoría. */
const ICONOS_POR_PREFIJO: [string, Component][] = [
    ['seguimiento_cambio_estado', Route],
    ['seguimiento_llamada', Phone],
    ['seguimiento_correo', Mail],
    ['seguimiento_documento', FileText],
    ['candidato_psicometricas', Brain],
    ['candidato_referencia', UserCheck],
    ['candidato_socioeconomico', Home],
    ['candidato_cv', FileText],
    ['candidato_evidencias', FolderOpen],
    ['candidato_contratacion_iniciada', QrCode],
    ['candidato_qr', QrCode],
    ['invitacion_qr', QrCode],
    ['aprobacion_rechazada', ShieldX],
    ['intervencion_rechazo', ShieldX],
    ['solicitud_empleo', ClipboardList],
    ['registro_app', Smartphone],
    ['aviso_sistemas', MonitorSmartphone],
    ['sistemas_alta', MonitorSmartphone],
    ['documento_impreso', Printer],
    ['documento_descargado', Download],
    ['expediente_descargado', Download],
    ['documento_firmado', FileSignature],
    ['documento_pendiente_firma', FileSignature],
    ['documento_enviado_a_firma', FileSignature],
    ['contrato_', FileSignature],
    ['cambio_salario', Banknote],
    ['finiquito_', Banknote],
    ['recibo_nomina', Banknote],
    ['induccion_', BookOpenCheck],
    ['evaluacion_', BookOpenCheck],
];

export function iconoDe(item: ItemTimeline): Component {
    return (
        ICONOS_POR_PREFIJO.find(([prefijo]) =>
            item.evento.startsWith(prefijo),
        )?.[1] ?? CATEGORIAS_HISTORIAL[categoriaDe(item)].icono
    );
}

/** Hitos clave: lo que mueve a la persona de etapa (lo demás es detalle). */
const HITOS = [
    'seguimiento_cambio_estado',
    'candidato_contratacion_iniciada',
    'candidato_contratado',
    'registro_app_completado',
    'colaborador_alta',
    'alta_estado',
    'aviso_sistemas_alta_enviado',
    'sistemas_alta_atendida',
    'onboarding_iniciado',
    'onboarding_completado',
    'induccion_casos_calificados',
    'colaborador_activado',
    'evaluacion_autorizada',
    'contrato_renovado',
    'cambio_salario',
    'cierre_laboral_solicitado',
    'cierre_laboral_aplicado',
    'cierre_laboral_baja',
    'finiquito_pagado',
    'reingreso_solicitado',
    'reingreso_autorizado',
    'reingreso_completado',
];

export function esHito(item: ItemTimeline): boolean {
    return (
        HITOS.includes(item.evento) ||
        item.etapa === 'aprobacion' ||
        item.etapa === 'intervencion'
    );
}

/** Título de un grupo de N eventos iguales seguidos. */
const PLURALES: Record<string, (n: number) => string> = {
    solicitud_empleo_seccion_guardada: (n) =>
        `Solicitud de empleo: ${n} secciones guardadas`,
    registro_app_datos_capturados: (n) =>
        `Registro en la app: datos capturados ${n} veces`,
    documento_generado: (n) => `${n} documentos generados`,
    documento_impreso: (n) => `${n} documentos impresos`,
    documento_descargado: (n) => `${n} descargas de documentos`,
    documento_consultado: (n) => `${n} consultas de documentos`,
    documento_enviado_a_firma: (n) => `${n} documentos enviados a firma`,
    documento_pendiente_firma_fisica: (n) =>
        `${n} documentos listos para firma física`,
    documento_firmado_fisicamente: (n) =>
        `${n} documentos con firma física y huella`,
    documento_firmado_digitalmente: (n) =>
        `${n} documentos firmados digitalmente`,
    documento_archivado: (n) => `${n} originales archivados`,
    contrato_creado: (n) => `${n} contratos registrados`,
    jefe_directo_cambiado: (n) => `Jefe directo actualizado ${n} veces`,
};

export type GrupoHistorial = {
    clave: string;
    categoria: CategoriaHistorial;
    icono: Component;
    titulo: string;
    /** Solo cuando es un evento suelto (los grupos lo muestran al abrirse). */
    descripcion: string | null;
    actor: string | null;
    /** «6 nov 2026, 4:10 p. m.» o el rango «…, 4:10 p. m. – 4:36 p. m.». */
    cuando: string;
    /** Fecha del evento más reciente del grupo (ISO). */
    fecha: string | null;
    esHito: boolean;
    /** Eventos que lo forman, del más reciente al más antiguo. */
    eventos: ItemTimeline[];
};

function dia(valor: string | null): string {
    return valor ? valor.slice(0, 10) : '';
}

function hora(valor: string | null): string {
    if (!valor) {
        return '';
    }

    const fecha = new Date(valor);

    return Number.isNaN(fecha.getTime())
        ? ''
        : fecha.toLocaleTimeString('es-MX', {
              hour: 'numeric',
              minute: '2-digit',
          });
}

function seJuntan(a: ItemTimeline, b: ItemTimeline): boolean {
    return (
        !a.evento.startsWith('seguimiento_') &&
        a.evento === b.evento &&
        a.titulo === b.titulo &&
        a.actor === b.actor &&
        dia(a.fecha) === dia(b.fecha)
    );
}

function cuandoDe(eventos: ItemTimeline[]): string {
    const reciente = eventos[0];
    const antiguo = eventos[eventos.length - 1];

    if (!reciente.fecha) {
        return '';
    }

    const inicio = hora(antiguo.fecha);
    const fin = hora(reciente.fecha);

    if (eventos.length === 1 || inicio === fin) {
        return formatearFechaHora(reciente.fecha);
    }

    // Mismo día (así se agrupan): la fecha una vez y el rango de horas.

    return `${formatearFecha(reciente.fecha)}, ${inicio} – ${fin}`;
}

/**
 * De la lista cronológica del backend (antiguo → reciente) a renglones del
 * más reciente al más antiguo, juntando los repetidos consecutivos.
 */
export function agruparHistorial(items: ItemTimeline[]): GrupoHistorial[] {
    const lotes: ItemTimeline[][] = [];

    for (const item of [...items].reverse()) {
        const ultimo = lotes[lotes.length - 1];

        if (ultimo && seJuntan(ultimo[0], item)) {
            ultimo.push(item);
        } else {
            lotes.push([item]);
        }
    }

    return lotes.map((eventos, indice) => {
        const primero = eventos[0];
        const n = eventos.length;

        return {
            clave: `${primero.evento}-${primero.fecha ?? ''}-${indice}`,
            categoria: categoriaDe(primero),
            icono: iconoDe(primero),
            titulo:
                n > 1
                    ? (PLURALES[primero.evento]?.(n) ??
                      `${primero.titulo} (${n} veces)`)
                    : primero.titulo,
            descripcion: n === 1 ? primero.descripcion : null,
            actor: primero.actor,
            cuando: cuandoDe(eventos),
            fecha: primero.fecha,
            esHito: esHito(primero),
            eventos,
        };
    });
}

/** «Noviembre 2026» — separador de la lista completa. */
export function mesDe(valor: string | null): string {
    if (!valor) {
        return 'Sin fecha';
    }

    const fecha = new Date(valor);

    if (Number.isNaN(fecha.getTime())) {
        return 'Sin fecha';
    }

    const texto = fecha.toLocaleDateString('es-MX', {
        month: 'long',
        year: 'numeric',
    });

    return texto.charAt(0).toUpperCase() + texto.slice(1);
}

export { hora as horaDe };
