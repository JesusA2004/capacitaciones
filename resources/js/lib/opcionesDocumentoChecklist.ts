import type { OpcionBuscable } from '@/components/ui/combobox-buscable';

/**
 * Sinónimos con los que RH busca cada documento del checklist (por clave de
 * document_types). «ine» ya encuentra «Identificación oficial» por su clave;
 * esto suma las otras formas de llamarlo.
 */
const ALIAS_POR_CLAVE: Record<string, string[]> = {
    ine: [
        'INE',
        'credencial',
        'credencial para votar',
        'elector',
        'identificacion',
        'pasaporte',
    ],
    curp: ['clave unica'],
    rfc: ['constancia fiscal', 'situacion fiscal', 'SAT'],
    nss: ['IMSS', 'seguro social'],
    acta_nacimiento: ['nacimiento', 'registro civil'],
    fotografia: ['foto', 'fotos', 'infantil'],
    comprobante_domicilio: ['domicilio', 'recibo', 'luz', 'agua', 'CFE'],
    comprobante_estudios: ['estudios', 'certificado', 'titulo', 'escolaridad'],
    cartas_recomendacion: ['recomendacion', 'referencias'],
    datos_bancarios: ['banco', 'cuenta', 'CLABE', 'tarjeta'],
    solicitud_empleo: ['solicitud', 'empleo'],
    contrato: ['contrato laboral'],
    carta_confidencialidad: ['confidencialidad'],
    contrato_no_competencia: ['no competencia'],
    pagare_expediente: ['pagare'],
    carta_antecedentes_no_penales: ['antecedentes', 'no penales', 'penales'],
    licencia_conducir: ['licencia', 'manejo', 'chofer', 'conducir'],
};

type TipoChecklist = {
    id: number;
    nombre: string;
    clave?: string;
    requerido?: boolean;
};

/**
 * Opciones del combobox «Documento del checklist»: agrupadas en Requeridos /
 * Opcionales (chip «Opcional») y, si se indica, con los ya cubiertos
 * deshabilitados («Ya en el expediente»).
 */
export function opcionesDocumentoChecklist(
    tipos: TipoChecklist[],
    deshabilitados: { ids: number[]; nota: string } | null = null,
): OpcionBuscable[] {
    const hayOpcionales = tipos.some((t) => t.requerido === false);

    return tipos.map((t) => {
        const opcional = t.requerido === false;
        const bloqueado = deshabilitados?.ids.includes(t.id) ?? false;

        return {
            value: t.id,
            label: t.nombre,
            grupo: hayOpcionales
                ? opcional
                    ? 'Opcionales'
                    : 'Requeridos'
                : undefined,
            alias: t.clave
                ? [
                      t.clave,
                      t.clave.replace(/_/g, ' '),
                      ...(ALIAS_POR_CLAVE[t.clave] ?? []),
                  ]
                : [],
            chip: opcional ? 'Opcional' : undefined,
            deshabilitado: bloqueado,
            nota: bloqueado ? deshabilitados?.nota : undefined,
        };
    });
}
