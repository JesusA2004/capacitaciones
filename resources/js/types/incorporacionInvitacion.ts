import type { OpcionSimple } from './administracion';

type PersonaBasica = { id: number; name: string; apellidos: string | null };

export type IncorporacionInvitacionItem = {
    id: number;
    uuid: string;
    codigo_legible: string | null;
    email: string | null;
    telefono: string | null;
    nombre_prellenado: string | null;
    empresa: OpcionSimple | null;
    sucursal: OpcionSimple | null;
    departamento: OpcionSimple | null;
    puesto: OpcionSimple | null;
    candidato: { id: number; nombre: string; apellidos: string | null } | null;
    usuario: PersonaBasica | null;
    creado_por: PersonaBasica | null;
    usado_por: PersonaBasica | null;
    regenerada_desde: { id: number; uuid: string; estado: string } | null;
    expires_at: string;
    used_at: string | null;
    revoked_at: string | null;
    max_usos: number;
    usos_count: number;
    estado: 'activo' | 'usado' | 'vencido' | 'revocado';
    metadata: { observaciones?: string } | null;
    created_at: string;
};

export type OpcionesIncorporacionInvitacion = {
    empresas: OpcionSimple[];
    sucursales: (OpcionSimple & { empresa_id: number | null })[];
    departamentos: OpcionSimple[];
    puestos: (OpcionSimple & { departamento_id: number | null })[];
};
