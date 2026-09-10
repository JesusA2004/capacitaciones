<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Download,
    Smartphone,
    Trash2,
    Upload,
    XCircle,
} from '@lucide/vue';
import { ref } from 'vue';
import AppReleaseFormDialog from '@/components/Administracion/AppReleaseFormDialog.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
    platform: string;
    version: string;
    build_number: string | null;
    file_size: number | null;
    sha256: string | null;
    changelog: string | null;
    is_published: boolean;
    is_latest: boolean;
    minimum_required: boolean;
    published_at: string | null;
    created_at: string;
    subido_por: { name: string; apellidos: string | null } | null;
};

defineProps<{
    releases: Release[];
    maxUploadMb: number;
    permisos: {
        crear: boolean;
        publicar: boolean;
        eliminar: boolean;
        descargar: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Versiones de app', href: index.url() },
        ],
    },
});

const { confirmarEliminacion, confirmarPublicacion } = useAlertas();

const dialogoAbierto = ref(false);

function formatearTamano(bytes: number | null): string {
    if (bytes === null) {
return '—';
}

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

async function publicarRelease(release: Release) {
    const confirmado = await confirmarPublicacion(
        `la versión ${release.version}`,
    );

    if (!confirmado) {
return;
}

    router.post(publicar.url(release.id), {}, { preserveScroll: true });
}

function despublicarRelease(release: Release) {
    router.post(despublicar.url(release.id), {}, { preserveScroll: true });
}

async function eliminarRelease(release: Release) {
    const confirmado = await confirmarEliminacion(
        `la versión ${release.version}`,
    );

    if (!confirmado) {
return;
}

    router.delete(destroy.url(release.id), { preserveScroll: true });
}
</script>

<template>
    <CrudPageHeader
        titulo="Versiones de app"
        descripcion="Publica el APK de MR. LANA PEOPLE para descarga directa mientras no esté en Play Store."
        :icono="Smartphone"
    >
        <Button v-if="permisos.crear" @click="dialogoAbierto = true">
            <Upload class="size-4" /> Subir versión
        </Button>
    </CrudPageHeader>

    <div
        v-if="releases.length === 0"
        class="mt-6 rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground"
    >
        Todavía no se ha subido ninguna versión del APK.
    </div>

    <div v-else class="mt-6 overflow-x-auto rounded-lg border">
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Versión</TableHead>
                    <TableHead>Tamaño</TableHead>
                    <TableHead>Estado</TableHead>
                    <TableHead>Subido por</TableHead>
                    <TableHead>Publicada</TableHead>
                    <TableHead class="text-right">Acciones</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="release in releases" :key="release.id">
                    <TableCell>
                        <p class="font-medium">
                            {{ release.version }}
                            <span
                                v-if="release.build_number"
                                class="text-xs text-muted-foreground"
                                >(build {{ release.build_number }})</span
                            >
                        </p>
                        <p
                            v-if="release.minimum_required"
                            class="text-xs text-destructive"
                        >
                            Actualización obligatoria
                        </p>
                    </TableCell>
                    <TableCell>{{
                        formatearTamano(release.file_size)
                    }}</TableCell>
                    <TableCell>
                        <div class="flex flex-wrap gap-1">
                            <Badge
                                :variant="
                                    release.is_published
                                        ? 'default'
                                        : 'outline'
                                "
                            >
                                <CheckCircle2
                                    v-if="release.is_published"
                                    class="size-3"
                                />
                                <XCircle v-else class="size-3" />
                                {{
                                    release.is_published
                                        ? 'Publicada'
                                        : 'Sin publicar'
                                }}
                            </Badge>
                            <Badge v-if="release.is_latest" variant="secondary"
                                >Más reciente</Badge
                            >
                        </div>
                    </TableCell>
                    <TableCell class="text-sm text-muted-foreground">
                        {{
                            release.subido_por
                                ? `${release.subido_por.name} ${release.subido_por.apellidos ?? ''}`
                                : '—'
                        }}
                    </TableCell>
                    <TableCell class="text-sm text-muted-foreground">
                        {{
                            release.published_at
                                ? new Date(
                                      release.published_at,
                                  ).toLocaleDateString('es-MX')
                                : '—'
                        }}
                    </TableCell>
                    <TableCell class="text-right">
                        <div class="flex justify-end gap-1">
                            <Button
                                v-if="permisos.descargar"
                                as-child
                                size="icon"
                                variant="ghost"
                            >
                                <a
                                    :href="descargar.url(release.id)"
                                    title="Descargar APK"
                                >
                                    <Download class="size-4" />
                                </a>
                            </Button>
                            <Button
                                v-if="
                                    permisos.publicar && !release.is_published
                                "
                                size="sm"
                                variant="outline"
                                @click="publicarRelease(release)"
                            >
                                Publicar
                            </Button>
                            <Button
                                v-if="
                                    permisos.publicar && release.is_published
                                "
                                size="sm"
                                variant="outline"
                                @click="despublicarRelease(release)"
                            >
                                Despublicar
                            </Button>
                            <Button
                                v-if="permisos.eliminar"
                                size="icon"
                                variant="ghost"
                                @click="eliminarRelease(release)"
                            >
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </div>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>

    <AppReleaseFormDialog
        v-model:open="dialogoAbierto"
        :max-upload-mb="maxUploadMb"
    />
</template>
