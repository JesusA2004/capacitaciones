export type EmpresaItem = {
    id: number;
    nombre: string;
    razon_social: string | null;
    rfc: string | null;
    logo_path: string | null;
    logo_url: string | null;
    activo: boolean;
    sucursales_count: number;
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
    usuarios_count: number;
};

export type DepartamentoItem = {
    id: number;
    nombre: string;
    descripcion: string | null;
    activo: boolean;
    puestos_count: number;
    usuarios_count: number;
};

export type PuestoItem = {
    id: number;
    nombre: string;
    departamento_id: number | null;
    departamento: { id: number; nombre: string } | null;
    descripcion: string | null;
    activo: boolean;
    usuarios_count: number;
};

export type OpcionSimple = {
    id: number;
    nombre: string;
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
};

export type UsuarioItem = {
    id: number;
    name: string;
    apellidos: string | null;
    numero_empleado: string | null;
    email: string;
    telefono: string | null;
    sucursal_principal_id: number | null;
    sucursal_principal: { id: number; nombre: string } | null;
    departamento_id: number | null;
    departamento: { id: number; nombre: string } | null;
    puesto_id: number | null;
    puesto: { id: number; nombre: string } | null;
    jefe_id: number | null;
    fecha_ingreso: string | null;
    estatus: string;
    zona_horaria: string;
    roles?: string[];
};
