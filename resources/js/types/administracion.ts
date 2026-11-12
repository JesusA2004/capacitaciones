import type { ClasificacionRegionNodo } from './organigrama';
import type { MovimientoLaboralItem } from './rh';

export type EmpresaItem = {
    id: number;
    nombre: string;
    razon_social: string | null;
    rfc: string | null;
    logo_path: string | null;
    logo_url: string | null;
    activo: boolean;
    domicilio_fiscal?: string | null;
    registro_patronal?: string | null;
    codigo_postal_fiscal?: string | null;
    ciudad_firma?: string | null;
    representante_legal_nombre?: string | null;
    representante_legal_cargo?: string | null;
    telefono?: string | null;
    sucursales_count: number;
    colaboradores_count: number;
};

export type SucursalItem = {
    id: number;
    empresa_id: number | null;
    empresa: { id: number; nombre: string } | null;
    nombre: string;
    clave: string;
    direccion: string | null;
    colonia: string | null;
    municipio: string | null;
    codigo_postal: string | null;
    ciudad: string | null;
    estado: string | null;
    telefono: string | null;
    responsable_id: number | null;
    responsable: { id: number; name: string; apellidos: string | null } | null;
    activo: boolean;
    colaboradores_count: number;
    plantilla_permitida?: number;
    plantilla_vacantes?: number;
    plantilla_doble_puesto?: number;
    /** Plazas de Gestor volante autorizadas (null = nunca se han definido). */
    volantes_autorizados?: number | null;
};

export type UsuarioItem = {
    id: number;
    colaborador_id: number | null;
    colaborador: {
        id: number;
        name: string;
        apellidos: string | null;
        estatus: string;
    } | null;
    name: string;
    apellidos: string | null;
    /** Foto oficial de su colaborador (URL protegida) si está en tu alcance. */
    foto_url?: string | null;
    username: string;
    email: string | null;
    roles_nombres: string[];
    acceso_bloqueado_en: string | null;
    email_verified_at: string | null;
    ultimo_acceso: string | null;
    /** Cuenta (acceso) — independiente del estado laboral. */
    estado_cuenta: 'activa' | 'inactiva';
    motivo_inactiva: string | null;
    /** Estado laboral del colaborador (baja en trámite, baja laboral, activo…). */
    estado_colaborador: { clave: string; etiqueta: string };
    puede_revocar: boolean;
    puede_restablecer: boolean;
    /** Por fila (UserPolicy): nunca una cuenta de rol superior. */
    puede_editar: boolean;
    puede_restablecer_password: boolean;
};

export type DepartamentoItem = {
    id: number;
    nombre: string;
    descripcion: string | null;
    activo: boolean;
    puestos_count: number;
    colaboradores_count: number;
};

/** Detalle de un departamento (Organización → Departamentos → abrir). */
export type DepartamentoDetalle = {
    id: number;
    nombre: string;
    descripcion: string | null;
    activo: boolean;
    puestos: number;
};

/** Persona vigente de un departamento, lista para moverse en lote. */
export type PersonaDepartamento = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    puesto: string | null;
    sucursal: string | null;
    foto_url: string | null;
    en_incorporacion: boolean;
    /** false = no se puede mover (p. ej. eres tú). */
    movible: boolean;
    motivo_no_movible: string | null;
};

export type DestinoDepartamento = {
    id: number;
    nombre: string;
    descripcion: string | null;
    personas: number;
};

/** Resultado de «mover personas» (flash `movimientoDepartamento`). */
export type ResultadoMovimientoDepartamento = {
    origen: string;
    destino: string;
    movidos: { id: number; nombre: string }[];
    omitidos: { id: number; nombre: string; motivo: string }[];
};

export type PuestoItem = {
    id: number;
    nombre: string;
    departamento_id: number | null;
    departamento: { id: number; nombre: string } | null;
    descripcion: string | null;
    activo: boolean;
    colaboradores_count: number;
    /** Duración del periodo de prueba (capacitación inicial), en meses. */
    meses_periodo_prueba: number | null;
    /** Plazas autorizadas / ocupadas / vacantes en todas sus ubicaciones (Headcount). */
    plazas?: { autorizadas: number; ocupadas: number; vacantes: number };
    /** Rango vigente del tabulador; solo con salarios.ver (null si no hay). */
    tabulador?: {
        minimo: number;
        medio: number;
        maximo: number;
        compa_ratio: number | null;
    } | null;
};

export type OpcionSimple = {
    id: number;
    nombre: string;
};

/** Colaborador activo que ocupa un puesto (organigrama). */
export type OcupantePuesto = {
    id: number;
    nombre: string;
    foto_url: string | null;
    sucursal: string | null;
    numero_empleado: string | null;
};

export type PuestoJerarquiaItem = {
    /** Región de la matriz a la que está ligado (puestos "Gerente Regional Qx"). */
    region?: string | null;
    /** Primeros colaboradores activos en el puesto, con foto (miniatura). */
    ocupantes?: OcupantePuesto[];
    id: number;
    nombre: string;
    descripcion: string | null;
    departamento: { id: number; nombre: string } | null;
    nivel_jerarquico: number | null;
    puesto_superior_id: number | null;
    puesto_superior: OpcionSimple | null;
    puesto_crecimiento_id: number | null;
    puesto_crecimiento: OpcionSimple | null;
    tipo_puesto: 'comercial' | 'administrativo' | 'operativo' | 'otro' | null;
    esquema_comisiones: string | null;
    requiere_ruta: boolean;
    responsabilidades: string | null;
    requisitos: string | null;
    respaldos: OpcionSimple[];
    puestos_que_puede_cubrir: OpcionSimple[];
    candidatos: {
        id: number;
        nombre: string;
        apellidos: string | null;
        estado: string;
    }[];
    activo: boolean;
    colaboradores_count: number;
    candidatos_count: number;
    vacantes_abiertas_count: number;
};

export type PuestoJerarquiaFiltros = {
    empresa_id?: string;
    sucursal_id?: string;
    departamento_id?: string;
    tipo_puesto?: string;
    raiz_puesto_id?: string;
};

export type PuestoJerarquiaOpciones = {
    empresas: OpcionSimple[];
    sucursales: (OpcionSimple & { empresa_id: number | null })[];
    departamentos: OpcionSimple[];
};

export type PuestoHistorialCambio = {
    id: number;
    descripcion: string | null;
    cambios: Record<string, unknown>;
    fecha: string | null;
};

export type PuestoHistorialVacante = {
    id: number;
    empresa: OpcionSimple | null;
    sucursal: OpcionSimple | null;
    motivo: string;
    estado: string;
    fecha_apertura: string;
    puesto_id: number | null;
};

export type PuestoHistorialResponse = {
    cambiosJerarquia: PuestoHistorialCambio[];
    movimientos: MovimientoLaboralItem[];
    vacantes: PuestoHistorialVacante[];
};

export type EstadoUsuarioOpcion = {
    value: string;
    etiqueta: string;
};

/** Conteos globales (no solo la página filtrada actual) para CrudStats. */
export type EstadisticasActivoInactivo = {
    total: number;
    activos: number;
    inactivos: number;
    /** Solo Administracion/Usuarios: bajas lógicas (soft-deleted), aparte de "inactivos". */
    bajas?: number;
};

/**
 * Tarjeta del organigrama por personas (OrganigramaPersonasService): una
 * persona, o un puesto "sin ocupar" en su lugar de la cadena.
 */
export type NodoOrganigramaPersona = {
    clave: string;
    padre: string | null;
    /**
     * persona: titular del puesto · vacante: nadie lo ocupa ·
     * cobertura: alguien de otra sucursal/región lo cubre temporalmente.
     */
    tipo: 'persona' | 'vacante' | 'cobertura';
    /** Puesto de sucursal (rama propia por sucursal). */
    de_sucursal: boolean;
    puesto: {
        id: number;
        nombre: string;
        nivel: number | null;
        tipo_puesto: string | null;
    };
    sucursal: { id: number; nombre: string } | null;
    /** Región de la matriz comercial (puestos por región: gerente regional). */
    region: { id: number; nombre: string } | null;
    persona: {
        id: number;
        nombre: string;
        foto_url: string | null;
        numero_empleado: string | null;
        expediente_url: string;
        /** Sucursal de su puesto titular. */
        sucursal: string | null;
    } | null;
    /** Solo gestores: la ruta que cobran. */
    rutas: { nombre: string; tipo: string }[];
    cobertura: {
        id: number;
        motivo: string;
        motivo_etiqueta: string;
        desde: string;
        nota: string | null;
        /** Puesto titular de quien cubre (no cambia por cubrir). */
        titular_de?: string | null;
        /** Auditable: quién marcó la cobertura y cuándo. */
        registrada_por?: string | null;
        registrada_en?: string | null;
        /** true = «También ocupa este puesto»; false = «Cubre temporalmente». */
        ocupa_plaza?: boolean;
    } | null;
    /** Solo Gerente de Sucursal: lado Q1/Q3 (o «Por clasificar»). */
    clasificacion?: ClasificacionRegionNodo | null;
    /**
     * Responsable de aprobación EXPLÍCITO (≠ a quién reporta): quien da el
     * visto bueno de sus solicitudes (Organización → Responsables de aprobación).
     */
    aprobador?: {
        id: number;
        nombre: string;
        origen: 'persona' | 'puesto';
    } | null;
    /**
     * Puesto de APOYO (Subgerente, Subgerente Regional): no es jefe directo
     * mientras su superior tenga titular o cobertura (config organigrama.puestos_apoyo).
     */
    apoyo?: boolean;
    /** Ya trabaja pero sigue «en incorporación»: su fase (p. ej. «Inducción»). */
    incorporacion?: string | null;
    /** Plaza vacante con candidato(s) ya autorizados por RH, sin colaborador. */
    por_ingresar?: { id: number; nombre: string }[];
};

/** Puesto activo que no cuelga de Dirección General (PuestosSinUbicarService). */
export type PuestoSinUbicar = {
    id: number;
    nombre: string;
    departamento: string | null;
    superior: string | null;
    motivo: 'sin_superior' | 'superior_inactivo' | 'rama_suelta';
    motivo_etiqueta: string;
    ocupantes: string[];
    total_ocupantes: number;
};

/** «Ubicar / mover puesto»: opciones (UbicacionPuestoService::opciones). */
export type OpcionesUbicacionPuesto = {
    superiores: {
        value: string;
        label: string;
        departamento_id: number | null;
        departamento: string | null;
        ocupantes: string[];
    }[];
    departamentos: { value: string; label: string }[];
    actual: { superior_id: number | null; departamento_id: number | null };
};

/** «Ubicar / mover puesto»: vista previa sin guardar. */
export type VistaPreviaUbicacionPuesto = {
    puesto: string;
    superior_antes: string | null;
    superior_despues: string;
    departamento_antes: string | null;
    departamento_despues: string | null;
    personas: string[];
    cambios_jefe: {
        colaborador: string;
        puesto: string;
        antes: string | null;
        despues: string | null;
    }[];
    sin_cambios: boolean;
};

/** Datos para asignar/terminar coberturas desde el organigrama. */
export type CoberturasOrganigrama = {
    puedeEditar: boolean;
    motivos: { value: string; etiqueta: string }[];
    colaboradores: { value: string; label: string; foto_url?: string | null }[];
};

/**
 * Sección del organigrama como el PDF de Inducción (cap. 4): General,
 * Corporativo, Dirección Comercial y Sucursal. `profundidad` limita los
 * niveles bajo la raíz (Corporativo = Dirección General + sus áreas).
 */
export type SeccionOrganigrama = {
    valor: string;
    etiqueta: string;
    raiz_puesto_id: number | null;
    profundidad: number | null;
};

/** Persona en una lámina del organigrama (LaminasOrganigramaService). */
export type PersonaLamina = {
    id: number | null;
    nombre: string;
    sucursal: string | null;
    /** URL segura (o null: sin foto o sin acceso a ese expediente). */
    foto_url: string | null;
    /** En incorporación: su fase (p. ej. «Inducción»). */
    incorporacion?: string | null;
};

/** Estado de una caja de lámina sin personas. */
export type EstadoCajaLamina = {
    /** false = ningún puesto del catálogo coincide: «Puesto no registrado». */
    registrado?: boolean;
    /** Candidatos autorizados por RH que aún no tienen colaborador. */
    por_ingresar?: string[];
};

/** Nodo del organigrama General (composición de las láminas). */
export type NodoGeneral = {
    clave: string;
    titulo: string;
    personas: PersonaLamina[];
    /** Rama de apoyo (línea punteada), p. ej. Coordinación en sucursal. */
    apoyo?: boolean;
    /** Agrupador sin personas propias (p. ej. «Sucursales»). */
    grupo?: boolean;
    hijos: NodoGeneral[];
};

export type CajaLamina = EstadoCajaLamina & {
    titulo: string;
    icono: string | null;
    destacado: boolean;
    personas: PersonaLamina[];
};

/** Láminas como el PDF de Inducción (hojas 17 · 18 · 19). */
export type LaminasOrganigrama = {
    corporativo: {
        direccion_general: EstadoCajaLamina & {
            titulo: string;
            personas: PersonaLamina[];
        };
        areas: (EstadoCajaLamina & {
            sigla: string;
            titulo: string;
            icono: string;
            personas: PersonaLamina[];
        })[];
        nota: string | null;
    };
    comercial: {
        director: CajaLamina;
        apoyo: CajaLamina[];
        columnas: (CajaLamina & {
            cadena: CajaLamina[];
            hijos: CajaLamina[];
        })[];
        leyenda: { directa?: string; apoyo?: string };
    };
    sucursal: {
        puestos: Record<
            'gerente' | 'subgerente' | 'coordinacion' | 'gestores',
            { titulo: string; funciones: string[]; registrado?: boolean }
        >;
        sucursales: ({
            id: number;
            nombre: string;
            por_ingresar?: Partial<
                Record<
                    'gerente' | 'subgerente' | 'coordinacion' | 'gestores',
                    string[]
                >
            >;
        } & Record<
            'gerente' | 'subgerente' | 'coordinacion' | 'gestores',
            PersonaLamina[]
        >)[];
        leyenda: { directa?: string; apoyo?: string };
    };
};

/** Perfil de puesto (PerfilPuestoService). */
export type ListaPerfilPuesto =
    | 'funciones'
    | 'responsabilidades'
    | 'indicadores'
    | 'conocimientos'
    | 'habilidades'
    | 'competencias'
    | 'valores'
    | 'requisitos';

export type DatosPerfilPuesto = {
    objetivo: string | null;
    escolaridad: string | null;
    experiencia: string | null;
} & Record<ListaPerfilPuesto, string[]>;

export type PuestoConPerfil = {
    id: number;
    nombre: string;
    departamento: string | null;
    reporta_a: string | null;
    ocupantes: number;
    avance: number;
    actualizado_en: string | null;
};

export type PerfilPuestoDetalle = {
    puesto: {
        id: number;
        nombre: string;
        departamento: string | null;
        reporta_a: string | null;
        supervisa: string[];
        ocupantes: number;
    };
    perfil: DatosPerfilPuesto;
    es_borrador: boolean;
    /** documento = borrador del PDF oficial; rh = ajustado por RH. */
    origen: 'documento' | 'rh' | null;
    descriptivo: DescriptivoOficial | null;
    avance: number;
    actualizado_por: string | null;
    actualizado_en: string | null;
};

/** Resumen del perfil en la ficha del candidato. */
export type PerfilPuestoCandidato = {
    puesto_id: number;
    puesto: string;
    perfil: DatosPerfilPuesto | null;
} | null;

/** Contenido estructurado de un descriptivo oficial (PDF). */
export type DatosDescriptivo = {
    nombre_puesto: string | null;
    area: string | null;
    jefe_inmediato: string | null;
    edad: string | null;
    sexo: string | null;
    tipo_empleado: string | null;
    personal_a_cargo: string | null;
    formacion: { minima: string | null; deseable: string | null };
    experiencia: {
        minima: string | null;
        deseable: string | null;
        puestos_referencia: string[];
    };
    conocimientos: {
        conocimiento: string;
        funcion: string | null;
        anios: string | null;
    }[];
    competencias: { competencia: string; funcion: string | null }[];
    objetivo: string | null;
    funciones: string[];
    actividades_criticas: string[];
    responsables_ausencia: string[];
    facultades: string[];
    plan_formacion: string[];
    compromisos_culturales: string[];
    metadatos: Partial<
        Record<
            | 'elaboro'
            | 'reviso'
            | 'autorizo'
            | 'fecha_elaboracion'
            | 'fecha_revision',
            string | null
        >
    >;
};

export type DescriptivoOficial = {
    id: number;
    version: number;
    versiones: number;
    archivo: string;
    paginas: number;
    fecha: string | null;
    importado_en: string | null;
    datos: DatosDescriptivo;
    advertencias: string[];
};

/* ── Puestos y salarios ─────────────────────────────────────────────── */

/** Posición de un sueldo frente al rango del tabulador. */
export type BandaSalarial =
    'bajo_minimo' | 'minimo_a_medio' | 'medio_a_maximo' | 'sobre_maximo';

export type RangoTabulador = {
    minimo: number;
    medio: number;
    maximo: number;
};

export type RangoTabuladorVigente = RangoTabulador & {
    fuente: string;
    fecha_actualizacion: string;
    version: number;
};

export type VersionTabulador = RangoTabulador & {
    version: number;
    fuente: string;
    fecha_actualizacion: string;
    vigente_desde: string;
    vigente_hasta: string | null;
    origen: string;
    usuario: string | null;
};

export type PuestoDetalle = {
    id: number;
    nombre: string;
    descripcion: string | null;
    departamento_id: number | null;
    departamento: string | null;
    activo: boolean;
    meses_periodo_prueba: number | null;
};

/** Persona vigente de un puesto; los importes solo llegan con salarios.ver. */
export type PersonaPuesto = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    sucursal: string | null;
    departamento: string | null;
    foto_url: string | null;
    en_incorporacion: boolean;
    fecha_ingreso: string | null;
    movible: boolean;
    motivo_no_movible: string | null;
    sueldo?: number | null;
    compa_ratio?: number | null;
    banda?: BandaSalarial | null;
    banda_etiqueta?: string | null;
    desempeno_etiqueta?: string | null;
    tope?: number | null;
    margen?: number | null;
};

export type DestinoPuesto = {
    id: number;
    nombre: string;
    departamento: string | null;
    personas: number;
    rango: RangoTabulador | null;
};

/** Resultado de «trasladar personas» (flash `movimientoPuesto`). */
export type ResultadoMovimientoPuesto = ResultadoMovimientoDepartamento;

export type FilaImportacionTabulador = {
    fila: number;
    puesto: string;
    minimo: number | null;
    medio: number | null;
    maximo: number | null;
    fuente: string;
    fecha_actualizacion: string | null;
    estado: 'nuevo' | 'cambio' | 'sin_cambio' | 'error';
    errores: string[];
};

export type ImportacionTabulador = {
    id: number;
    nombre_archivo: string;
    estado: string;
    total_filas: number;
    filas_revision: number;
    filas_sin_cambios: number;
    filas_error: number;
    detalle: FilaImportacionTabulador[];
};

export type MatrizMeritoCeldas = Record<
    string,
    Record<string, number | string>
>;

/** Lo salarial del módulo (solo con salarios.ver). */
export type SalariosModulo = {
    resumen: {
        puestos_total: number;
        puestos_con_tabulador: number;
        puestos_sin_tabulador: number;
        compa_ratio_promedio: number | null;
        bajo_minimo: number;
        sobre_maximo: number;
        margen: number | null;
        con_evaluacion: number;
        colaboradores: number;
        sin_sueldo: number;
    };
    fuera_de_rango: {
        id: number;
        nombre: string;
        puesto_id: number | null;
        puesto: string | null;
        sucursal: string | null;
        sueldo: number | null;
        banda: BandaSalarial;
        banda_etiqueta: string;
        compa_ratio: number | null;
    }[];
    sin_tabulador: {
        puesto_id: number;
        puesto: string;
        colaboradores: number;
    }[];
    matriz: MatrizMeritoCeldas | null;
    historial_matriz: {
        id: number;
        vigente_desde: string;
        vigente_hasta: string | null;
        motivo: string | null;
        usuario: string | null;
    }[];
    importacion: ImportacionTabulador | null;
};
