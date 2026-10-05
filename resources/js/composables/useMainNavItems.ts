import { usePage } from '@inertiajs/vue3';
import {
    Activity,
    Briefcase,
    Building2,
    Cake,
    ClipboardList,
    Compass,
    FileStack,
    FolderOpen,
    FolderKanban,
    GitBranch,
    GraduationCap,
    Inbox,
    Landmark,
    LayoutGrid,
    Megaphone,
    PartyPopper,
    QrCode,
    ReceiptText,
    RotateCcw,
    Settings2,
    ShieldCheck,
    Smartphone,
    Sparkles,
    UserRound,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { useNavegacion } from '@/composables/useNavegacion';
import { usePermisos } from '@/composables/usePermisos';
import { dashboard, miExpediente } from '@/routes';
import { index as indexAppReleases } from '@/routes/administracion/app-releases';
import { index as indexConfiguracion } from '@/routes/administracion/configuracion';
import { index as indexDepartamentos } from '@/routes/administracion/departamentos';
import { index as indexEmpresas } from '@/routes/administracion/empresas';
import { index as indexJerarquiaPuestos } from '@/routes/administracion/jerarquia-puestos';
import { index as indexPuestos } from '@/routes/administracion/puestos';
import { index as indexRoles } from '@/routes/administracion/roles';
import { index as indexSucursales } from '@/routes/administracion/sucursales';
import { index as indexUsuarios } from '@/routes/administracion/usuarios';
import { proximamente as capacitacionProximamente } from '@/routes/capacitacion';
import { index as indexMuroFelicitaciones } from '@/routes/celebraciones';
import {
    index as indexPortal,
    recibos as misRecibosNomina,
} from '@/routes/portal';
import { index as indexReportes } from '@/routes/reportes';
import { index as indexAniversarios } from '@/routes/rh/aniversarios';
import { index as indexCampanas } from '@/routes/rh/campanas';
import { index as indexCandidatos } from '@/routes/rh/candidatos';
import { index as indexCumpleanos } from '@/routes/rh/cumpleanos';
import { index as indexDocumentosMaestros } from '@/routes/rh/documentos-maestros';
import { index as indexExpedientes } from '@/routes/rh/expedientes';
import { index as indexIncorporacionInvitaciones } from '@/routes/rh/incorporacion/invitaciones';
import { index as indexNomina } from '@/routes/rh/nomina';
import { configuracion as configuracionOnboarding } from '@/routes/rh/onboarding';
import { index as indexPendientes } from '@/routes/rh/pendientes';
import { index as indexReingresos } from '@/routes/rh/reingresos';
import { index as indexRhSolicitudes } from '@/routes/rh/solicitudes';
import { index as indexVacantes } from '@/routes/rh/vacantes';
import { index as indexSolicitudes } from '@/routes/solicitudes';
import type { NavItem } from '@/types';

/**
 * Navegación compartida por AppSidebar.vue (menú completo) y
 * MobileBottomNav.vue (barra inferior en móvil): un solo lugar que decide
 * qué accesos ve cada usuario según su modo (colaborador/operativo) y sus
 * permisos — ver docs/ROLES_Y_NAVEGACION.md. Nunca dupliques esta lista en
 * otro componente.
 */
export function useMainNavItems() {
    const { tienePermiso } = usePermisos();
    const page = usePage();
    const { esColaborador, tieneAmbosModos, cambiarModo } = useNavegacion();

    const capacitacionActiva = computed(() => page.props.features.capacitacion);

    const navItemsColaborador = computed<NavItem[]>(() => {
        const items: NavItem[] = [
            {
                title: 'Mi portal',
                href: indexPortal(),
                icon: UserRound,
            },
            {
                title: 'Mi expediente',
                href: miExpediente(),
                icon: FolderOpen,
            },
        ];

        if (
            tienePermiso('portal.solicitudes.ver') ||
            tienePermiso('solicitudes.crear')
        ) {
            items.push({
                title: 'Mis solicitudes',
                href: indexSolicitudes(),
                icon: ClipboardList,
            });
        }

        items.push({
            title: 'Mis recibos de nómina',
            href: misRecibosNomina(),
            icon: ReceiptText,
        });

        items.push({
            title: 'Muro de felicitaciones',
            href: indexMuroFelicitaciones(),
            icon: PartyPopper,
        });

        if (capacitacionActiva.value) {
            items.push({
                title: 'Capacitación',
                href: capacitacionProximamente(),
                icon: GraduationCap,
            });
        }

        items.push({ title: 'Ayuda', href: '/ayuda', icon: Compass });

        return items;
    });

    const navItemsOperativo = computed<NavItem[]>(() => {
        const items: NavItem[] = [
            {
                title: 'Inicio',
                href: dashboard(),
                icon: LayoutGrid,
            },
        ];

        // Bandeja de pendientes del ciclo laboral: la ve quien participa en
        // él (preautoriza, autoriza, evalúa, entrega activos u opera bajas).
        if (
            [
                'ciclo.preautorizar',
                'ciclo.autorizar_rh',
                'evaluaciones.capturar',
                'evaluaciones.autorizar',
                'onboarding.entregar_activos',
                'cierres.solicitar',
                'cierres.gestionar',
            ].some((p) => tienePermiso(p))
        ) {
            items.push({
                title: 'Mis pendientes',
                href: indexPendientes(),
                icon: Inbox,
            });
        }

        if (
            tienePermiso('expedientes.ver_todos') ||
            tienePermiso('expedientes.ver_sucursal')
        ) {
            items.push({
                title: 'Expedientes',
                href: indexExpedientes(),
                icon: FolderKanban,
            });
        }

        if (
            tienePermiso('solicitudes.revisar') ||
            tienePermiso('solicitudes.aprobar')
        ) {
            items.push({
                title: 'Solicitudes',
                href: indexRhSolicitudes(),
                icon: ClipboardList,
            });
        }

        if (tienePermiso('organigrama.ver')) {
            items.push({
                title: 'Organigrama',
                href: indexJerarquiaPuestos(),
                icon: GitBranch,
            });
        }

        if (tienePermiso('vacantes.ver')) {
            items.push({
                title: 'Vacantes',
                href: indexVacantes(),
                icon: Briefcase,
            });
        }

        if (tienePermiso('candidatos.ver')) {
            items.push({
                title: 'Candidatos',
                href: indexCandidatos(),
                icon: UserRound,
            });
        }

        if (tienePermiso('reclutamiento.campanas.ver')) {
            items.push({
                title: 'Campañas',
                href: indexCampanas(),
                icon: Megaphone,
            });
        }

        if (tienePermiso('onboarding.gestionar')) {
            items.push({
                title: 'Onboarding',
                href: configuracionOnboarding(),
                icon: Sparkles,
            });
        }

        if (
            tienePermiso('reingresos.solicitar') ||
            tienePermiso('reingresos.gestionar')
        ) {
            items.push({
                title: 'Reingresos',
                href: indexReingresos(),
                icon: RotateCcw,
            });
        }

        if (tienePermiso('rh.incorporacion.invitaciones.ver')) {
            items.push({
                title: 'Invitaciones QR',
                href: indexIncorporacionInvitaciones(),
                icon: QrCode,
            });
        }

        if (tienePermiso('nomina.recibos.ver')) {
            items.push({
                title: 'Recibos de nómina',
                href: indexNomina(),
                icon: ReceiptText,
            });
        }

        if (tienePermiso('reportes_rh.ver')) {
            items.push({
                title: 'Reportes',
                href: indexReportes(),
                icon: Activity,
            });
        }

        if (
            tienePermiso('rh.cumpleanos.ver') ||
            tienePermiso('celebraciones.ver')
        ) {
            items.push({
                title: 'Celebraciones',
                href: tienePermiso('rh.cumpleanos.ver')
                    ? indexCumpleanos()
                    : indexAniversarios(),
                icon: Cake,
            });
        }

        items.push({
            title: 'Muro de felicitaciones',
            href: indexMuroFelicitaciones(),
            icon: PartyPopper,
        });

        if (capacitacionActiva.value) {
            items.push({
                title: 'Capacitación',
                href: capacitacionProximamente(),
                icon: GraduationCap,
            });
        }

        items.push({ title: 'Ayuda', href: '/ayuda', icon: Compass });

        return items;
    });

    const mainNavItems = computed<NavItem[]>(() =>
        esColaborador.value
            ? navItemsColaborador.value
            : navItemsOperativo.value,
    );

    const adminNavItems = computed<NavItem[]>(() => {
        if (esColaborador.value) {
            return [];
        }

        const items: NavItem[] = [];

        // Documentos maestros: solo administración (cargar/versionar/probar
        // los formatos de Jurídico). Los documentos de cada persona se
        // generan en su proceso, no aquí.
        if (tienePermiso('plantillas_documentales.administrar')) {
            items.push({
                title: 'Documentos maestros',
                href: indexDocumentosMaestros(),
                icon: FileStack,
            });
        }

        if (tienePermiso('empresas.ver')) {
            items.push({
                title: 'Empresas',
                href: indexEmpresas(),
                icon: Landmark,
            });
        }

        if (tienePermiso('usuarios.ver')) {
            items.push({
                title: 'Usuarios',
                href: indexUsuarios(),
                icon: Users,
            });
        }

        if (tienePermiso('sucursales.administrar')) {
            items.push({
                title: 'Sucursales',
                href: indexSucursales(),
                icon: Building2,
            });
        }

        if (
            tienePermiso('departamentos.administrar') ||
            tienePermiso('puestos.administrar')
        ) {
            items.push({
                title: 'Departamentos',
                href: indexDepartamentos(),
                icon: Briefcase,
            });
            items.push({
                title: 'Puestos',
                href: indexPuestos(),
                icon: Briefcase,
            });
        }

        if (tienePermiso('roles.administrar')) {
            items.push({
                title: 'Roles y permisos',
                href: indexRoles(),
                icon: ShieldCheck,
            });
        }

        // Jefes directos, ruteo de avisos, parámetros de RH y apariencia
        // (cada sección exige además su permiso configuracion.*).
        if (tienePermiso('configuracion.ver')) {
            items.push({
                title: 'Configuración',
                href: indexConfiguracion(),
                icon: Settings2,
            });
        }

        if (tienePermiso('app_releases.ver')) {
            items.push({
                title: 'Versiones de app',
                href: indexAppReleases(),
                icon: Smartphone,
            });
        }

        return items;
    });

    return {
        capacitacionActiva,
        mainNavItems,
        adminNavItems,
        esColaborador,
        tieneAmbosModos,
        cambiarModo,
    };
}
