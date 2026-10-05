<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowRight, Check, ImageIcon, X } from '@lucide/vue';
import { ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { aprobar, index, rechazar } from '@/routes/rh/cambios-foto';
import type { CambioFotoRevision } from '@/types';

/**
 * Cambios de foto de perfil por revisar: la foto oficial sigue visible
 * hasta que RH aprueba la propuesta (FotoColaboradorService). Al aprobar
 * pasa a ser la oficial; al rechazar se conserva la actual y se avisa el
 * motivo al colaborador.
 */
defineProps<{ cambios: CambioFotoRevision[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cambios de foto', href: index.url() },
        ],
    },
});

const procesando = ref<number | null>(null);
const rechazando = ref<CambioFotoRevision | null>(null);
const motivo = ref('');

function fecha(iso: string): string {
    return new Date(iso).toLocaleString('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function aprobarCambio(cambio: CambioFotoRevision): void {
    procesando.value = cambio.id;
    router.post(
        aprobar.url(cambio.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                procesando.value = null;
            },
        },
    );
}

function abrirRechazo(cambio: CambioFotoRevision): void {
    rechazando.value = cambio;
    motivo.value = '';
}

function confirmarRechazo(): void {
    const cambio = rechazando.value;

    if (!cambio) {
        return;
    }

    procesando.value = cambio.id;
    router.post(
        rechazar.url(cambio.id),
        { motivo: motivo.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                rechazando.value = null;
            },
            onFinish: () => {
                procesando.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Cambios de foto" />

    <div class="pagina-ancha flex flex-col gap-4">
        <CrudPageHeader titulo="Cambios de foto de perfil" :icono="ImageIcon" />

        <CrudEmptyState
            v-if="cambios.length === 0"
            titulo="No hay cambios de foto por revisar"
            descripcion="Cuando un colaborador pida cambiar su foto aparecerá aquí."
        />

        <ul
            v-else
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
            aria-label="Cambios de foto pendientes"
        >
            <li
                v-for="cambio in cambios"
                :key="cambio.id"
                class="flex flex-col gap-4 rounded-2xl border bg-card p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
            >
                <div class="min-w-0">
                    <p class="truncate font-semibold">
                        {{ cambio.colaborador.nombre }}
                    </p>
                    <p class="truncate text-sm text-muted-foreground">
                        {{ cambio.colaborador.puesto ?? 'Sin puesto' }} ·
                        {{ cambio.colaborador.sucursal ?? 'Sin sucursal' }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Solicitado {{ fecha(cambio.solicitada_en) }}
                    </p>
                </div>

                <div class="flex items-center justify-center gap-3">
                    <figure class="flex flex-col items-center gap-1">
                        <ColaboradorAvatar
                            :nombre="cambio.colaborador.nombre"
                            :foto-url="cambio.foto_actual_url"
                            tamano="xl"
                            class="size-28 rounded-2xl text-2xl"
                        />
                        <figcaption class="text-xs text-muted-foreground">
                            Actual
                        </figcaption>
                    </figure>
                    <ArrowRight class="size-5 text-muted-foreground" />
                    <figure class="flex flex-col items-center gap-1">
                        <img
                            :src="cambio.foto_propuesta_url"
                            :alt="`Foto propuesta de ${cambio.colaborador.nombre}`"
                            class="size-28 rounded-2xl object-cover ring-2 ring-primary/40"
                        />
                        <figcaption class="text-xs font-medium">
                            Propuesta
                        </figcaption>
                    </figure>
                </div>

                <div class="mt-auto flex gap-2">
                    <Button
                        class="flex-1"
                        :disabled="procesando !== null"
                        @click="aprobarCambio(cambio)"
                    >
                        <Spinner v-if="procesando === cambio.id" />
                        <Check v-else class="size-4" />
                        Aprobar
                    </Button>
                    <Button
                        variant="outline"
                        class="flex-1"
                        :disabled="procesando !== null"
                        @click="abrirRechazo(cambio)"
                    >
                        <X class="size-4" />
                        Rechazar
                    </Button>
                </div>
            </li>
        </ul>

        <Dialog
            :open="rechazando !== null"
            @update:open="(abierto) => !abierto && (rechazando = null)"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Rechazar cambio de foto</DialogTitle>
                    <DialogDescription>
                        La foto actual de
                        {{ rechazando?.colaborador.nombre }} se conserva. El
                        motivo se le envía en la notificación.
                    </DialogDescription>
                </DialogHeader>
                <div class="flex flex-col gap-2">
                    <Label for="motivo-rechazo">Motivo (opcional)</Label>
                    <Textarea
                        id="motivo-rechazo"
                        v-model="motivo"
                        maxlength="500"
                        placeholder="Ej. La foto no es formal o no se ve el rostro completo."
                    />
                </div>
                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="rechazando = null">
                        Cancelar
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="procesando !== null"
                        @click="confirmarRechazo"
                    >
                        <Spinner v-if="procesando !== null" />
                        Rechazar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
