import type { MovimientoLaboralItem } from './rh';

export type EmpresaItem = {
    id: number;
    nombre: string;
    razon_social: string | null;
    rfc: string | null;
    logo_path: string | null;
    logo_url: string | null;
    activo: boolean;
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
    ciudad: string | null;
    estado: string | null;
    telefono: string | null;
    responsable_id: number | null;
    responsable: { id: number; name: string; apellidos: string | null } | null;
    activo: boolean;
    colaboradores_count: number;
    plantilla_permitida?: number;
    plantilla_vacantes?: number;
};

export type UsuarioItem = {
    id: number;
    colaborador_id: number | null;
    colaborador: { id: number; name: string; apellidos: string | null; estatus: string } | null;
    name: string;
    apellidos: string | null;
    email: string;
    roles_nombres: string[];
    acceso_bloqueado_en: string | null;
    email_verified_at: string | null;
    tiene_2fa: boolean;
    ultimo_acceso: string | null;
};

export type DepartamentoItem = {
    id: number;
    nombre: string;
    descripcion: string | null;
    activo: boolean;
    puestos_count: number;
    colaboradores_count: number;
};

export type PuestoItem = {
    id: number;
    nombre: string;
    departamento_id: number | null;
    departamento: { id: number; nombre: string } | null;
    descripcion: string | null;
    activo: boolean;
    colaboradores_count: number;
};

export type OpcionSimple = {
    id: number;
    nombre: string;
};

export type PuestoJerarquiaItem = {
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

