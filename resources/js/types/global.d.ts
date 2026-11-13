import type { Auth } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            features: {
                capacitacion: boolean;
                recibos_colaborador: boolean;
                /** «Mis documentos laborales» y «Mis contratos» del colaborador (apagado: copia física). */
                documentos_laborales_colaborador: boolean;
                // Módulos de Desarrollo (etapa 6) aún no liberados: se ocultan
                // del menú hasta tener ruta + pantalla reales (no pantallas
                // falsas, ver docs/ROLES_Y_NAVEGACION.md §Desarrollo).
                evaluacion_desempeno: boolean;
                nine_box: boolean;
                certificacion: boolean;
                /** Entra al Directorio (`directorio.ver` o personal de Corporativo). */
                directorio: boolean;
            };
            // Modo de navegación (App\Services\Navigation\NavigationService):
            // separa la experiencia personal ("colaborador") de la operativa
            // ("operativo"). null cuando no hay sesión iniciada.
            navegacion: {
                modoActual: 'colaborador' | 'operativo';
                modosDisponibles: Array<'colaborador' | 'operativo'>;
            } | null;
            // Notificaciones de escritorio (Web Push): null si no hay sesión
            // o el servidor no tiene llaves VAPID (composables/useWebPush.ts).
            webPush?: { clavePublica: string | null };
            environment: string;
            // Conteo único de aprobaciones que esta cuenta puede hacer AHORA
            // (AprobacionOrganizacionalService::conteoPendientes): badge de
            // «Pendientes» en el sidebar.
            aprobacionesPendientes?: number;
            // Personas por dar de alta en los demás sistemas (solo para
            // sistemas.altas.atender): badge de «Pendientes de alta».
            altasSistemasPendientes?: number;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
