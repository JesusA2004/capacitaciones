export type SucursalItem = {
    id: number;
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

export type UsuarioItem = {
    id: number;
    name: string;
    apellidos: string | null;
    numero_empleado: string | null;
    email: string;
    telefono: string | null;
    sucursal_principal_id: number | null;
    sucursalPrincipal: { id: number; nombre: string } | null;
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
