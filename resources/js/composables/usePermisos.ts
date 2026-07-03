import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Auth } from '@/types';

export function usePermisos() {
    const page = usePage<{ auth: Auth }>();

    const roles = computed(() => page.props.auth.user?.roles ?? []);
    const permisos = computed(() => page.props.auth.user?.permissions ?? []);

    function tienePermiso(permiso: string): boolean {
        return permisos.value.includes(permiso);
    }

    function tieneAlgunPermiso(lista: string[]): boolean {
        return lista.some((permiso) => permisos.value.includes(permiso));
    }

    function tieneTodosLosPermisos(lista: string[]): boolean {
        return lista.every((permiso) => permisos.value.includes(permiso));
    }

    function tieneRol(rol: string): boolean {
        return roles.value.includes(rol);
    }

    function tieneAlgunRol(lista: string[]): boolean {
        return lista.some((rol) => roles.value.includes(rol));
    }

    return {
        roles,
        permisos,
        tienePermiso,
        tieneAlgunPermiso,
        tieneTodosLosPermisos,
        tieneRol,
        tieneAlgunRol,
    };
}
