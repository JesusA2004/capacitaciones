import type { ListaPerfilPuesto } from '@/types';

/** Secciones del perfil de puesto, en el orden en que se muestran. */
export type SeccionPerfil =
    | { clave: 'escolaridad'; titulo: string; tipo: 'linea'; ayuda: string }
    | {
          clave: 'experiencia' | 'objetivo';
          titulo: string;
          tipo: 'parrafo';
          ayuda: string;
      }
    | {
          clave: ListaPerfilPuesto;
          titulo: string;
          tipo: 'lista';
          ayuda: string;
      };

/** Propósito 1: lo que debe tener quien se postula. */
export const SECCIONES_CANDIDATO: SeccionPerfil[] = [
    {
        clave: 'escolaridad',
        titulo: 'Escolaridad',
        tipo: 'linea',
        ayuda: 'Ej. Bachillerato terminado',
    },
    {
        clave: 'experiencia',
        titulo: 'Experiencia',
        tipo: 'parrafo',
        ayuda: 'Ej. 1 año en cobranza o ventas en campo',
    },
    {
        clave: 'conocimientos',
        titulo: 'Conocimientos',
        tipo: 'lista',
        ayuda: 'Uno por renglón. Ej. Manejo de Excel',
    },
    {
        clave: 'habilidades',
        titulo: 'Habilidades',
        tipo: 'lista',
        ayuda: 'Uno por renglón. Ej. Negociación',
    },
    {
        clave: 'competencias',
        titulo: 'Competencias y actitudes',
        tipo: 'lista',
        ayuda: 'Uno por renglón. Ej. Orientación a resultados',
    },
    {
        clave: 'valores',
        titulo: 'Valores',
        tipo: 'lista',
        ayuda: 'Uno por renglón. Ej. Honestidad',
    },
    {
        clave: 'requisitos',
        titulo: 'Requisitos',
        tipo: 'lista',
        ayuda: 'Uno por renglón. Ej. Licencia de motocicleta vigente',
    },
];

/** Propósito 2: el «deber ser» del puesto. */
export const SECCIONES_PUESTO: SeccionPerfil[] = [
    {
        clave: 'objetivo',
        titulo: 'Objetivo del puesto',
        tipo: 'parrafo',
        ayuda: 'Para qué existe el puesto, en una o dos líneas',
    },
    {
        clave: 'funciones',
        titulo: 'Funciones y actividades',
        tipo: 'lista',
        ayuda: 'Una por renglón: lo que hace en su día a día',
    },
    {
        clave: 'responsabilidades',
        titulo: 'Responsabilidades',
        tipo: 'lista',
        ayuda: 'Una por renglón: de qué responde',
    },
    {
        clave: 'indicadores',
        titulo: 'Indicadores de desempeño',
        tipo: 'lista',
        ayuda: 'Uno por renglón. Ej. Cartera vencida menor a 5 %',
    },
];
