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
import EmptyState from '@/components/Common/EmptyState.vue';
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
import { useAccionEnCurso } from '@/composables/useAccionEnCurso';
import { useAlertas } from '@/composables/useAlertas';
import { vTablaTarjetas } from '@/lib/tablaTarjetas';
import { dashboard } from '@/routes';
import {
    descargar,
    despublicar,
    destroy,
    index,
    publicar,
} from '@/routes/administracion/app-releases';

const accion = useAccionEnCurso();

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
    /** Mínimo entre MOBILE_APK_MAX_MB y lo que acepta el PHP-FPM real. */
    limiteEfectivoMb: number;
    /** Valores reales del PHP-FPM que atiende la página (solo quien sube). */
    limitesServidor: {
        sapi: string;
        upload_max_filesize: string;
        post_max_size: string;
        memory_limit: string;
        max_input_time: string;
        max_execution_time: string;
    } | null;
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

    accion.ejecutar('publicarRelease', (estado) => {
        router.post(
            publicar.url(release.id),
            {},
            { ...estado, preserveScroll: true },
        );
    });
}

function despublicarRelease(release: Release) {
    accion.ejecutar('despublicarRelease', (estado) => {
        router.post(
            despublicar.url(release.id),
            {},
            { ...estado, preserveScroll: true },
        );
    });
}

async function eliminarRelease(release: Release) {
    const confirmado = await confirmarEliminacion(
        `la versión ${release.version}`,
    );

    if (!confirmado) {
        return;
    }

    accion.ejecutar('eliminarRelease', (estado) => {
        router.delete(destroy.url(release.id), {
            ...estado,
            preserveScroll: true,
        });
    });
}
</script>

<template>
    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Versiones de app"
            descripcion="Publica el APK de MR. LANA PEOPLE para descarga directa mientras no esté en Play Store."
            :icono="Smartphone"
        >
            <Button v-if="permisos.crear" @click="dialogoAbierto = true">
                <Upload class="size-4" /> Subir versión
            </Button>
        </CrudPageHeader>

        <!-- Límites reales de subida (PHP-FPM, no CLI): diagnóstico del 413. -->
        <p
            v-if="limitesServidor"
            class="rounded-xl px-3 py-2 text-xs"
            :class="
                limiteEfectivoMb < maxUploadMb
                    ? 'bg-warning-soft'
                    : 'bg-muted/40 text-muted-foreground'
            "
            data-testid="limites-subida"
        >
            Límite de la app: {{ maxUploadMb }} MB · PHP ({{
                limitesServidor.sapi
            }}): upload_max_filesize {{ limitesServidor.upload_max_filesize }},
            post_max_size {{ limitesServidor.post_max_size }}, max_input_time
            {{ limitesServidor.max_input_time }} s.
            <template v-if="limiteEfectivoMb < maxUploadMb">
                <strong
                    >Hoy solo caben {{ limiteEfectivoMb }} MB: PHP acepta menos
                    que la app. Pide a Sistemas subir esos valores en
                    PHP-FPM.</strong
                >
            </template>
        </p>

        <div
            v-if="releases.length === 0"
            class="rounded-2xl border border-dashed border-border/70 bg-card"
        >
            <EmptyState
                :icono="Smartphone"
                titulo="Todavía no hay versiones"
                descripcion="Aún no se ha subido ninguna versión del APK. Cuando subas una aparecerá aquí para publicarla."
            >
                <Button v-if="permisos.crear" @click="dialogoAbierto = true">
                    <Upload class="size-4" /> Subir versión
                </Button>
            </EmptyState>
        </div>

        <div
            v-else
            v-tabla-tarjetas
            data-tour="tabla"
            class="tabla-scroll rounded-2xl border border-border/60 bg-card max-sm:border-0 max-sm:bg-transparent"
        >
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
                                <Badge
                                    v-if="release.is_latest"
                                    variant="secondary"
                                    class="aparece-zoom"
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
                            <div
                                class="flex flex-wrap items-center justify-end gap-1"
                            >
                                <Button
                                    v-if="permisos.descargar"
                                    as-child
                                    size="icon"
                                    variant="ghost"
                                >
                                    <a
                                        :href="descargar.url(release.id)"
                                        title="Descargar APK"
                                        :aria-label="`Descargar APK de la versión ${release.version}`"
                                    >
                                        <Download class="size-4" />
                                    </a>
                                </Button>
                                <Button
                                    :disabled="accion.ocupado.value"
                                    v-if="
                                        permisos.publicar &&
                                        !release.is_published
                                    "
                                    size="sm"
                                    variant="outline"
                                    @click="publicarRelease(release)"
                                >
                                    Publicar
                                </Button>
                                <Button
                                    :disabled="accion.ocupado.value"
                                    v-if="
                                        permisos.publicar &&
                                        release.is_published
                                    "
                                    size="sm"
                                    variant="outline"
                                    @click="despublicarRelease(release)"
                                >
                                    Despublicar
                                </Button>
                                <Button
                                    :disabled="accion.ocupado.value"
                                    v-if="permisos.eliminar"
                                    size="icon"
                                    variant="ghost"
                                    title="Eliminar versión"
                                    :aria-label="`Eliminar la versión ${release.version}`"
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
    </div>

    <AppReleaseFormDialog
        v-model:open="dialogoAbierto"
        :max-upload-mb="limiteEfectivoMb"
    />
</template>
