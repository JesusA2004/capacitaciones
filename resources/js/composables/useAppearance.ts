/**
 * MR. LANA PEOPLE es SOLO claro: no hay modo oscuro ni selector de
 * apariencia, y nunca se respeta `prefers-color-scheme: dark`. El login
 * es oscuro por diseño (AuthSimpleLayout), no por un tema.
 *
 * initializeTheme() limpia cualquier rastro de la preferencia anterior
 * (clase `dark`, localStorage y cookie `appearance`) para que un navegador
 * que la tenía guardada no vuelva a pintarse oscuro.
 */
export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    document.documentElement.classList.remove('dark');
    document.documentElement.style.colorScheme = 'light';

    try {
        localStorage.removeItem('appearance');
    } catch {
        // Almacenamiento bloqueado (modo privado): no hay nada que limpiar.
    }

    document.cookie = 'appearance=;path=/;max-age=0;SameSite=Lax';
}
