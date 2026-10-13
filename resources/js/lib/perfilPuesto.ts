import type { ListaPerfilPuesto } from '@/types';

/**
 * Secciones de los descriptivos y perfiles de puesto. `estilo` decide cómo
 * se ven en lectura y cómo se capturan en edición:
 * - numerada: pasos/funciones (frases largas, en orden).
 * - checklist: responsabilidades y requisitos.
 * - etiquetas: conceptos cortos (habilidades, valores…).
 * - indicadores: tarjetas tipo meta.
 */
export type EstiloLista =
    'numerada' | 'checklist' | 'etiquetas' | 'indicadores';

export type SeccionLista = {
    clave: ListaPerfilPuesto;
    titulo: string;
    estilo: EstiloLista;
    ejemplo: string;
};

export const DESCRIPTIVO: SeccionLista[] = [
    {
        clave: 'funciones',
        titulo: 'Funciones',
        estilo: 'numerada',
        ejemplo: 'Prospectar clientes',
    },
    {
        clave: 'responsabilidades',
        titulo: 'Responsabilidades',
        estilo: 'checklist',
        ejemplo: 'Cuidar el efectivo de la ruta',
    },
    {
        clave: 'indicadores',
        titulo: 'Indicadores',
        estilo: 'indicadores',
        ejemplo: 'Cartera vencida < 5 %',
    },
];

export const PERFIL_CANDIDATO: SeccionLista[] = [
    {
        clave: 'conocimientos',
        titulo: 'Conocimientos',
        estilo: 'etiquetas',
        ejemplo: 'Excel básico',
    },
    {
        clave: 'habilidades',
        titulo: 'Habilidades',
        estilo: 'etiquetas',
        ejemplo: 'Negociación',
    },
    {
        clave: 'competencias',
        titulo: 'Competencias',
        estilo: 'etiquetas',
        ejemplo: 'Orientación a resultados',
    },
    {
        clave: 'valores',
        titulo: 'Valores',
        estilo: 'etiquetas',
        ejemplo: 'Honestidad',
    },
    {
        clave: 'requisitos',
        titulo: 'Requisitos',
        estilo: 'checklist',
        ejemplo: 'Licencia de moto vigente',
    },
];
