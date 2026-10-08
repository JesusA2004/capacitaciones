import { usePage } from '@inertiajs/vue3';
import {
    Activity,
    BellRing,
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
    ClipboardCheck,
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
import { index as indexAvisosColaborador } from '@/routes/avisos';
import { proximamente as capacitacionProximamente } from '@/routes/capacitacion';
import { index as indexMuroFelicitaciones } from '@/routes/celebraciones';
import {
    index as indexPortal,
    recibos as misRecibosNomina,
} from '@/routes/portal';
import { index as indexReportes } from '@/routes/reportes';
import { index as indexAniversarios } from '@/routes/rh/aniversarios';
import { index as indexAvisos } from '@/routes/rh/avisos';
import { index as indexCampanas } from '@/routes/rh/campanas';
import { index as indexCandidatos } from '@/routes/rh/candidatos';
import { index as indexCumpleanos } from '@/routes/rh/cumpleanos';
import { index as indexDocumentosMaestros } from '@/routes/rh/documentos-maestros';
import { index as indexEvaluaciones } from '@/routes/rh/evaluaciones';
import { index as indexExpedientes } from '@/routes/rh/expedientes';
import { index as indexIncorporacionInvitaciones } from '@/routes/rh/incorporacion/invitaciones';
import { index as indexLotesNomina } from '@/routes/rh/nomina/lotes';
import { configuracion as configuracionOnboarding } from '@/routes/rh/onboarding';
import { index as indexPendientes } from '@/routes/rh/pendientes';
import { index as indexReingresos } from '@/routes/rh/reingresos';
import { index as indexRhSolicitudes } from '@/routes/rh/solicitudes';
import { index as indexVacantes } from '@/routes/rh/vacantes';
import { index as indexSolicitudes } from '@/routes/solicitudes';
import type { NavItem } from '@/types';

/** Grupo del sidebar: el proceso al que pertenecen sus accesos. */
export type NavGroup = { titulo: string; items: NavItem[] };

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
            {
                title: 'Avisos',
                href: indexAvisosColaborador(),
                icon: BellRing,
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

    /**
     * Modo operativo ordenado POR PROCESO (sidebar final):
     * Reclutamiento → Desarrollo → Personal → Organización → Administración.
     * «Cambios de foto» ya no tiene acceso propio: vive en Mis pendientes.
     */
    const gruposOperativo = computed<NavGroup[]>(() => {
        const grupo = (titulo: string, items: (NavItem | false)[]): NavGroup => ({
            titulo,
            items: items.filter((i): i is NavItem => i !== false),
        });

        const inicio: (NavItem | false)[] = [
            { title: 'Inicio', href: dashboard(), icon: LayoutGrid },
            [
                'ciclo.preautorizar',
                'ciclo.autorizar_rh',
                'evaluaciones.capturar',
                'evaluaciones.autorizar',
                'onboarding.entregar_activos',
                'cierres.solicitar',
                'cierres.gestionar',
                'expedientes.revisar',
                'solicitudes.revisar',
                'solicitudes.aprobar',
                'nomina.recibos.crear',
            ].some((p) => tienePermiso(p)) && {
                title: 'Mis pendientes',
                href: indexPendientes(),
                icon: Inbox,
            },
        ];

        return [
            grupo('Inicio', inicio),
            grupo('Reclutamiento', [
                tienePermiso('reclutamiento.campanas.ver') && { title: 'Campañas', href: indexCampanas(), icon: Megaphone },
                tienePermiso('vacantes.ver') && { title: 'Vacantes', href: indexVacantes(), icon: Briefcase },
                tienePermiso('candidatos.ver') && { title: 'Candidatos', href: indexCandidatos(), icon: UserRound },
                tienePermiso('rh.incorporacion.invitaciones.ver') && { title: 'Invitaciones QR', href: indexIncorporacionInvitaciones(), icon: QrCode },
            ]),
            grupo('Desarrollo', [
                tienePermiso('onboarding.gestionar') && { title: 'Onboarding', href: configuracionOnboarding(), icon: Sparkles },
                (tienePermiso('evaluaciones.capturar') || tienePermiso('evaluaciones.autorizar')) && {
                    title: 'Evaluación de capacitación inicial',
                    href: indexEvaluaciones(),
                    icon: ClipboardCheck,
                },
                (tienePermiso('reingresos.solicitar') || tienePermiso('reingresos.gestionar')) && { title: 'Reingresos', href: indexReingresos(), icon: RotateCcw },
                capacitacionActiva.value && { title: 'Capacitación', href: capacitacionProximamente(), icon: GraduationCap },
            ]),
            grupo('Personal', [
                (tienePermiso('expedientes.ver_todos') || tienePermiso('expedientes.ver_sucursal')) && { title: 'Expedientes', href: indexExpedientes(), icon: FolderKanban },
                (tienePermiso('solicitudes.revisar') || tienePermiso('solicitudes.aprobar')) && { title: 'Solicitudes', href: indexRhSolicitudes(), icon: ClipboardList },
                tienePermiso('nomina.recibos.ver') && { title: 'Recibos de nómina', href: indexLotesNomina(), icon: ReceiptText },
                tienePermiso('avisos.enviar') && { title: 'Avisos', href: indexAvisos(), icon: BellRing },
                (tienePermiso('rh.cumpleanos.ver') || tienePermiso('celebraciones.ver')) && {
                    title: 'Celebraciones',
                    href: tienePermiso('rh.cumpleanos.ver') ? indexCumpleanos() : indexAniversarios(),
                    icon: Cake,
                },
            ]),
            grupo('Organización', [
                tienePermiso('organigrama.ver') && { title: 'Organigrama', href: indexJerarquiaPuestos(), icon: GitBranch },
                tienePermiso('reportes_rh.ver') && { title: 'Reportes', href: indexReportes(), icon: Activity },
            ]),
        ].filter((g) => g.items.length > 0);
    });

    const navItemsOperativo = computed<NavItem[]>(() =>
        gruposOperativo.value.flatMap((g) => g.items),
    );

    const mainNavItems = computed<NavItem[]>(() =>
        esColaborador.value
            ? navItemsColaborador.value
            : navItemsOperativo.value,
    );

    const adminNavItems = computed<NavItem[]>(() => {
        if (esColaborador.value) {
            return [];
        }

        const items: (NavItem | false)[] = [
            // Documentos maestros: cargar/versionar/probar los formatos.
            tienePermiso('plantillas_documentales.administrar') && { title: 'Documentos maestros', href: indexDocumentosMaestros(), icon: FileStack },
            tienePermiso('empresas.ver') && { title: 'Empresas', href: indexEmpresas(), icon: Landmark },
            tienePermiso('usuarios.ver') && { title: 'Usuarios', href: indexUsuarios(), icon: Users },
            tienePermiso('sucursales.administrar') && { title: 'Sucursales', href: indexSucursales(), icon: Building2 },
            tienePermiso('departamentos.administrar') && { title: 'Departamentos', href: indexDepartamentos(), icon: Briefcase },
            tienePermiso('puestos.administrar') && { title: 'Puestos', href: indexPuestos(), icon: Briefcase },
            tienePermiso('roles.administrar') && { title: 'Roles', href: indexRoles(), icon: ShieldCheck },
            tienePermiso('configuracion.ver') && { title: 'Configuración', href: indexConfiguracion(), icon: Settings2 },
            tienePermiso('app_releases.ver') && { title: 'Versiones app', href: indexAppReleases(), icon: Smartphone },
        ];

        return items.filter((i): i is NavItem => i !== false);
    });

    /**
     * Grupos que pinta el sidebar. Modo colaborador: un solo grupo («Mi
     * espacio»); modo operativo: por proceso + Administración + Ayuda.
     */
    const navGroups = computed<NavGroup[]>(() => {
        if (esColaborador.value) {
            return [{ titulo: 'Mi espacio', items: navItemsColaborador.value }];
        }

        return [
            ...gruposOperativo.value,
            ...(adminNavItems.value.length ? [{ titulo: 'Administración', items: adminNavItems.value }] : []),
            { titulo: 'Ayuda', items: [{ title: 'Ayuda', href: '/ayuda', icon: Compass }] },
        ];
    });

    return {
        capacitacionActiva,
        mainNavItems,
        adminNavItems,
        navGroups,
        esColaborador,
        tieneAmbosModos,
        cambiarModo,
    };
}
