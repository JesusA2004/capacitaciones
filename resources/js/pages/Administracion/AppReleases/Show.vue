<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Download, Trash2 } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import {
    descargar,
    despublicar,
    destroy,
    index,
    publicar,
} from '@/routes/administracion/app-releases';

type Release = {
    id: number;
    version: string;
    build_number: string | null;
    file_size: number | null;
    sha256: string | null;
    changelog: string | null;
    is_published: boolean;
    is_latest: boolean;
    minimum_required: boolean;
    published_at: string | null;
    subido_por: { name: string; apellidos: string | null } | null;
};

const props = defineProps<{
    release: Release;
    permisos: { publicar: boolean; eliminar: boolean; descargar: boolean };
}>();

// `layout` recibe una funcion (no un objeto estatico) porque
// `defineOptions()` se compila fuera del scope de setup() y no puede
// referenciar variables locales como `props`; Inertia la invoca con las
// props actuales de la pagina en cada render (ver @inertiajs/vue3).
defineOptions({
    layout: (pageProps: { release: Release }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Versiones de app', href: index.url() },
            { title: pageProps.release.version, href: '' },
        ],
    }),
});

const { confirmarEliminacion, confirmarPublicacion } = useAlertas();

async function publicarRelease() {
    if (!(await confirmarPublicacion(`la versión ${props.release.version}`))) {
return;
}

    router.post(publicar.url(props.release.id), {}, { preserveScroll: true });
}

function despublicarRelease() {
    router.post(
        despublicar.url(props.release.id),
        {},
        { preserveScroll: true },
    );
}

async function eliminarRelease() {
    if (!(await confirmarEliminacion(`la versión ${props.release.version}`))) {
return;
}

    router.delete(destroy.url(props.release.id), {
        onSuccess: () => router.visit(index.url()),
    });
}
</script>

<template>
    <Link
        :href="index.url()"
        class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
    >
        <ArrowLeft class="size-4" /> Volver a versiones de app
    </Link>

    <Card>
        <CardContent class="flex flex-col gap-3 pt-6">
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-semibold">
                    Versión {{ release.version }}
                </h2>
                <Badge :variant="release.is_published ? 'default' : 'outline'">
                    {{ release.is_published ? 'Publicada' : 'Sin publicar' }}
                </Badge>
                <Badge v-if="release.is_latest" variant="secondary"
                    >Más reciente</Badge
                >
            </div>

            <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Build</dt>
                    <dd>{{ release.build_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Tamaño</dt>
                    <dd>
                        {{
                            release.file_size
                                ? `${(release.file_size / (1024 * 1024)).toFixed(1)} MB`
                                : '—'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">SHA-256</dt>
                    <dd class="break-all font-mono text-xs">
                        {{ release.sha256 ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Subido por</dt>
                    <dd>
                        {{
                            release.subido_por
                                ? `${release.subido_por.name} ${release.subido_por.apellidos ?? ''}`
                                : '—'
                        }}
                    </dd>
                </div>
            </dl>

            <div v-if="release.changelog" class="mt-2">
                <p class="mb-1 text-sm font-medium">Notas de versión</p>
                <p class="text-sm whitespace-pre-line text-muted-foreground">
                    {{ release.changelog }}
                </p>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <Button v-if="permisos.descargar" as-child>
                    <a :href="descargar.url(release.id)">
                        <Download class="size-4" /> Descargar APK
                    </a>
                </Button>
                <Button
                    v-if="permisos.publicar && !release.is_published"
                    variant="outline"
                    @click="publicarRelease"
                    >Publicar</Button
                >
                <Button
                    v-if="permisos.publicar && release.is_published"
                    variant="outline"
                    @click="despublicarRelease"
                    >Despublicar</Button
                >
                <Button
                    v-if="permisos.eliminar"
                    variant="outline"
                    class="text-destructive"
                    @click="eliminarRelease"
                >
                    <Trash2 class="size-4" /> Eliminar
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
