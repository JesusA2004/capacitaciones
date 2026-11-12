import type {
    CampoRegistro,
    DocumentoRegistro,
    EstadoRegistroWeb,
} from '@/types/registro';

/**
 * Lógica PURA del asistente de registro web (Registro/Asistente.vue): la
 * misma organización y las mismas validaciones de formato que la app
 * (mr-lana-people-app src/utils/registroWizard.ts). Qué es obligatorio y qué
 * ya capturó RH lo manda el backend (RegistroAppService::estado); el backend
 * vuelve a validar todo.
 */

export type ClavePaso =
    | 'personales'
    | 'domicilio'
    | 'emergencia'
    | 'medicos'
    | 'foto'
    | 'solicitud'
    | 'documentos'
    | 'revision';

export type PasoDatos = 'personales' | 'domicilio' | 'emergencia';

export interface PasoRegistro {
    clave: ClavePaso;
    titulo: string;
    corto: string;
    descripcion: string;
    opcional?: boolean;
}

export const PASOS_REGISTRO: readonly PasoRegistro[] = [
    {
        clave: 'personales',
        titulo: 'Tus datos personales',
        corto: 'Datos',
        descripcion: 'Como aparecen en tu INE, CURP y constancia del SAT.',
    },
    {
        clave: 'domicilio',
        titulo: 'Tu domicilio',
        corto: 'Domicilio',
        descripcion: 'Como aparece en tu comprobante de domicilio.',
    },
    {
        clave: 'emergencia',
        titulo: 'Contacto de emergencia',
        corto: 'Emergencia',
        descripcion: 'A quién avisamos si te pasa algo, y tu beneficiario.',
    },
    {
        clave: 'medicos',
        titulo: 'Datos médicos',
        corto: 'Médicos',
        descripcion: 'Opcional y confidencial.',
        opcional: true,
    },
    {
        clave: 'foto',
        titulo: 'Fotografía para expediente',
        corto: 'Foto',
        descripcion: 'Será tu foto de perfil y la de tu expediente.',
    },
    {
        clave: 'solicitud',
        titulo: 'Solicitud de empleo',
        corto: 'Solicitud',
        descripcion:
            'Tu solicitud y estudio socioeconómico, por secciones cortas.',
    },
    {
        clave: 'documentos',
        titulo: 'Tus documentos',
        corto: 'Documentos',
        descripcion: 'Toma una foto clara o elige el archivo.',
    },
    {
        clave: 'revision',
        titulo: 'Revisa y termina',
        corto: 'Terminar',
        descripcion: 'Confirma que todo está listo.',
    },
];

export const CAMPOS_DOMICILIO = [
    'domicilio_calle',
    'domicilio_numero_exterior',
    'domicilio_numero_interior',
    'domicilio_colonia',
    'domicilio_cp',
    'domicilio_municipio',
    'domicilio_estado',
] as const;

export const CAMPOS_POR_PASO: Record<PasoDatos, readonly string[]> = {
    personales: [
        'genero',
        'fecha_nacimiento',
        'lugar_nacimiento',
        'nacionalidad',
        'estado_civil',
        'curp',
        'rfc',
        'nss',
        'clave_elector',
        'profesion',
        'telefono',
        'correo_personal',
    ],
    domicilio: CAMPOS_DOMICILIO,
    emergencia: [
        'contacto_emergencia_nombre',
        'contacto_emergencia_parentesco',
        'contacto_emergencia_telefono',
        'beneficiario_nombre',
        'beneficiario_parentesco',
    ],
};

export const CAMPOS_MEDICOS = ['condicion_medica', 'alergias'] as const;

export function esCampoMedico(campo: string): boolean {
    return (CAMPOS_MEDICOS as readonly string[]).includes(campo);
}

/** Campos del backend de un paso de datos (lo desconocido cae en «personales»). */
export function camposDelPaso(
    paso: PasoDatos,
    campos: readonly CampoRegistro[],
): CampoRegistro[] {
    const conocidos = new Set<string>([
        ...CAMPOS_POR_PASO.personales,
        ...CAMPOS_POR_PASO.domicilio,
        ...CAMPOS_POR_PASO.emergencia,
    ]);
    const propios = CAMPOS_POR_PASO[paso]
        .map((clave) => campos.find((c) => c.campo === clave))
        .filter((c): c is CampoRegistro => c !== undefined);

    if (paso !== 'personales') {
        return propios;
    }

    return [
        ...propios,
        ...campos.filter(
            (c) => !conocidos.has(c.campo) && !esCampoMedico(c.campo),
        ),
    ];
}

const CURP = /^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/;
const RFC = /^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/;
const CORREO = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const CLAVE_ELECTOR = /^[A-Z]{6}\d{8}[HM]\d{3}$/;

export function edadCumplida(
    fechaIso: string,
    hoy: Date = new Date(),
): number | null {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(fechaIso);

    if (!m) {
        return null;
    }

    const [anio, mes, dia] = [Number(m[1]), Number(m[2]), Number(m[3])];
    let edad = hoy.getFullYear() - anio;

    if (
        hoy.getMonth() + 1 < mes ||
        (hoy.getMonth() + 1 === mes && hoy.getDate() < dia)
    ) {
        edad -= 1;
    }

    return edad;
}

function aammdd(fechaIso: string): string | null {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(fechaIso);

    return m ? `${m[1].slice(2)}${m[2]}${m[3]}` : null;
}

/** Error de formato de UN campo (null = válido o vacío). */
export function validarFormato(
    campo: string,
    valor: string,
    hoy: Date = new Date(),
): string | null {
    const v = valor.trim();

    if (!v) {
        return null;
    }

    switch (campo) {
        case 'curp':
            return CURP.test(v.toUpperCase())
                ? null
                : 'La CURP tiene 18 caracteres (4 letras, 6 números de tu fecha, H o M, 5 letras y 2 caracteres finales).';
        case 'rfc':
            return RFC.test(v.toUpperCase())
                ? null
                : 'El RFC tiene 13 caracteres: 4 letras, 6 números de tu fecha y 3 de homoclave.';
        case 'nss':
            return /^\d{11}$/.test(v)
                ? null
                : 'El NSS son 11 números (viene en tu alta del IMSS).';
        case 'telefono':
        case 'contacto_emergencia_telefono':
            return /^\d{10}$/.test(v)
                ? null
                : 'Escribe el teléfono a 10 dígitos, sin espacios ni lada internacional.';
        case 'correo_personal':
            return CORREO.test(v)
                ? null
                : 'Escribe un correo válido, por ejemplo nombre@gmail.com.';
        case 'clave_elector':
            return CLAVE_ELECTOR.test(v.toUpperCase())
                ? null
                : 'La clave de elector tiene 18 caracteres y viene al frente de tu INE.';
        case 'domicilio_cp':
            return /^\d{5}$/.test(v) ? null : 'El código postal son 5 números.';
        case 'fecha_nacimiento': {
            const edad = edadCumplida(v, hoy);

            if (edad === null) {
                return 'Elige tu fecha de nacimiento.';
            }

            if (edad < 15) {
                return 'Revisa tu fecha de nacimiento: debes tener al menos 15 años.';
            }

            return edad > 100 ? 'Revisa tu fecha de nacimiento.' : null;
        }
        default:
            return null;
    }
}

/**
 * Coherencia (errores típicos al teclear): la fecha dentro de la CURP y del
 * RFC debe ser la fecha de nacimiento, y la letra de sexo de la CURP el
 * sexo elegido.
 */
export function validarCoherencia(
    valores: Record<string, string | undefined>,
): Record<string, string> {
    const errores: Record<string, string> = {};
    const nacimiento = aammdd(valores.fecha_nacimiento ?? '');
    const curp = (valores.curp ?? '').trim().toUpperCase();
    const rfc = (valores.rfc ?? '').trim().toUpperCase();

    if (nacimiento && CURP.test(curp) && curp.slice(4, 10) !== nacimiento) {
        errores.curp =
            'La fecha dentro de tu CURP no coincide con tu fecha de nacimiento. Revisa ambas.';
    }

    if (nacimiento && RFC.test(rfc)) {
        const inicio = rfc.length === 13 ? 4 : 3;

        if (rfc.slice(inicio, inicio + 6) !== nacimiento) {
            errores.rfc =
                'La fecha dentro de tu RFC no coincide con tu fecha de nacimiento. Revisa ambas.';
        }
    }

    const genero = valores.genero;

    if (CURP.test(curp) && (genero === 'femenino' || genero === 'masculino')) {
        const letra = curp.charAt(10);

        if (
            (genero === 'femenino' && letra !== 'M') ||
            (genero === 'masculino' && letra !== 'H')
        ) {
            errores.curp ??=
                'La letra de sexo de tu CURP (H o M) no coincide con el sexo que elegiste.';
        }
    }

    return errores;
}

/** Valida un paso de datos: obligatorios editables, formatos y coherencia. */
export function validarPaso(
    paso: PasoDatos,
    campos: readonly CampoRegistro[],
    valores: Record<string, string | undefined>,
    hoy: Date = new Date(),
): Record<string, string> {
    const errores: Record<string, string> = {};
    const propios = camposDelPaso(paso, campos);

    for (const campo of propios) {
        if (campo.bloqueado) {
            continue;
        }

        const valor = (valores[campo.campo] ?? '').trim();

        if (!valor) {
            if (campo.obligatorio) {
                errores[campo.campo] =
                    campo.tipo === 'opciones' || campo.tipo === 'date'
                        ? 'Elige una opción para continuar.'
                        : 'Falta este dato.';
            }

            continue;
        }

        const formato = validarFormato(campo.campo, valor, hoy);

        if (formato) {
            errores[campo.campo] = formato;
        }
    }

    if (paso === 'personales') {
        for (const [campo, mensaje] of Object.entries(
            validarCoherencia(valores),
        )) {
            const definicion = propios.find((c) => c.campo === campo);

            if (definicion && !definicion.bloqueado && !errores[campo]) {
                errores[campo] = mensaje;
            }
        }
    }

    return errores;
}

export function normalizarValor(campo: string, valor: string): string {
    const limpio = valor.replace(/\s+/g, ' ').trim();

    return ['curp', 'rfc', 'clave_elector'].includes(campo)
        ? limpio.toUpperCase().replace(/\s/g, '')
        : limpio;
}

/** Solo campos editables y distintos de lo guardado. */
export function datosParaEnviar(
    paso: PasoDatos,
    campos: readonly CampoRegistro[],
    valores: Record<string, string | undefined>,
): Record<string, string> {
    const salida: Record<string, string> = {};

    for (const campo of camposDelPaso(paso, campos)) {
        if (campo.bloqueado) {
            continue;
        }

        const valor = normalizarValor(campo.campo, valores[campo.campo] ?? '');

        if (valor === '' && campo.actual === null) {
            continue;
        }

        if (valor !== (campo.actual ?? '')) {
            salida[campo.campo] = valor;
        }
    }

    return salida;
}

export function valoresIniciales(
    campos: readonly CampoRegistro[],
): Record<string, string> {
    const valores: Record<string, string> = {};

    for (const campo of campos) {
        if (!esCampoMedico(campo.campo) && campo.actual !== null) {
            valores[campo.campo] = campo.actual;
        }
    }

    return valores;
}

export function pasoIncompleto(
    paso: PasoDatos,
    estado: EstadoRegistroWeb,
): boolean {
    const faltan = new Set(estado.datos.faltan.map((f) => f.campo));

    return camposDelPaso(paso, estado.datos.campos).some((c) =>
        faltan.has(c.campo),
    );
}

export function fotoPendiente(estado: EstadoRegistroWeb): boolean {
    return estado.foto.obligatoria && !estado.foto.completo;
}

export function solicitudPendiente(estado: EstadoRegistroWeb): boolean {
    return Boolean(
        estado.solicitud_empleo?.exigida && !estado.solicitud_empleo.completo,
    );
}

const ESTADOS_POR_SUBIR = [
    'pendiente',
    'rechazado',
    'requiere_correccion',
    'vencido',
    'cambio_autorizado',
];

/** Documentos que faltan subir (nunca los que genera PEOPLE). */
export function documentosPorSubir(
    estado: EstadoRegistroWeb,
): DocumentoRegistro[] {
    return estado.documentos.lista.filter(
        (d) =>
            d.obligatorio &&
            d.generado_por_sistema === null &&
            ESTADOS_POR_SUBIR.includes(d.estado),
    );
}

export function documentosSinTener(
    estado: EstadoRegistroWeb,
    sinTener: readonly number[],
): DocumentoRegistro[] {
    return documentosPorSubir(estado).filter((d) => sinTener.includes(d.id));
}

export function documentosSinResolver(
    estado: EstadoRegistroWeb,
    sinTener: readonly number[],
): DocumentoRegistro[] {
    return documentosPorSubir(estado).filter((d) => !sinTener.includes(d.id));
}

/** Marcas «No tengo» que siguen aplicando (si ya lo subió, se descarta). */
export function sinTenerVigentes(
    estado: EstadoRegistroWeb,
    sinTener: readonly number[],
): number[] {
    return documentosSinTener(estado, sinTener).map((d) => d.id);
}

export function puedeFinalizarRegistro(
    estado: EstadoRegistroWeb,
    sinTener: readonly number[],
): boolean {
    if (fotoPendiente(estado) || solicitudPendiente(estado)) {
        return false;
    }

    if (estado.puede_finalizar) {
        return true;
    }

    return (
        estado.aplica &&
        estado.datos.completo &&
        documentosSinTener(estado, sinTener).length > 0 &&
        documentosSinResolver(estado, sinTener).length === 0
    );
}

/** ¿El paso ya está listo? (para la palomita del riel). */
export function pasoListo(
    clave: ClavePaso,
    estado: EstadoRegistroWeb,
    sinTener: readonly number[],
): boolean {
    switch (clave) {
        case 'personales':
        case 'domicilio':
        case 'emergencia':
            return !pasoIncompleto(clave, estado);
        case 'medicos':
            return estado.datos_medicos.capturados;
        case 'foto':
            return !fotoPendiente(estado) && estado.foto.completo;
        case 'solicitud':
            return !solicitudPendiente(estado);
        case 'documentos':
            return documentosSinResolver(estado, sinTener).length === 0;
        case 'revision':
            return false;
    }
}

/** Paso donde reanudar: el primero con algo pendiente (mismo orden que la app). */
export function pasoParaReanudar(
    estado: EstadoRegistroWeb,
    sinTener: readonly number[],
): number {
    const indice = (clave: ClavePaso) =>
        PASOS_REGISTRO.findIndex((p) => p.clave === clave);

    for (const paso of ['personales', 'domicilio', 'emergencia'] as const) {
        if (pasoIncompleto(paso, estado)) {
            return indice(paso);
        }
    }

    if (fotoPendiente(estado)) {
        return indice('foto');
    }

    if (solicitudPendiente(estado)) {
        return indice('solicitud');
    }

    if (documentosSinResolver(estado, sinTener).length > 0) {
        return indice('documentos');
    }

    return indice('revision');
}

export function textoDocumentos(n: number): string {
    return n === 1 ? '1 documento' : `${n} documentos`;
}
