import { router } from '@inertiajs/vue3';

/**
 * Navegacion entre paginas de una tabla con paginacion server-side de
 * Laravel. Los enlaces ya incluyen los filtros/orden actuales en su query.
 */
export function usePaginacion() {
    function irA(url: string | null) {
        if (!url) {
            return;
        }

        router.get(
            url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    }

    return { irA };
}
