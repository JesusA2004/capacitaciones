import type { OpcionSimple } from './administracion';
import type { OpcionEnum } from './reclutamiento';

export type PlantillaItem = {
    id: number;
    nombre: string;
    tipo: string;
    descripcion: string | null;
    empresa: OpcionSimple | null;
    sucursal: OpcionSimple | null;
    puesto: OpcionSimple | null;
    original_name: string;
    version: number;
    activo: boolean;
};

export type DocumentoGeneradoItem = {
    id: number;
    plantilla: { id: number; nombre: string; tipo: string } | null;
    usuario: { id: number; name: string; apellidos: string | null } | null;
    candidato: { id: number; nombre: string; apellidos: string | null } | null;
    generado_por: { id: number; name: string; apellidos: string | null } | null;
    generated_name: string;
    status: string;
    created_at: string;
};

export type OpcionesPlantillas = {
    empresas: OpcionSimple[];
    sucursales: (OpcionSimple & { empresa_id: number | null })[];
    puestos: OpcionSimple[];
    tipos: OpcionEnum[];
};
