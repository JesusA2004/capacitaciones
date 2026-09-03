<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ClipboardList, Eye, Plus } from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { index, show, store } from '@/routes/solicitudes';
import type {
    RespuestaPaginada,
    SolicitudInternaItem,
    TipoSolicitudInterna,
} from '@/types';

const props = defineProps<{
    solicitudes: RespuestaPaginada<SolicitudInternaItem>;
    tipos: TipoSolicitudInterna[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mis solicitudes', href: index.url() },
        ],
    },
});

const dialogoAbierto = ref(false);

const form = useForm({
    tipo: '',
    motivo: '',
    observaciones: '',
    fecha_inicio: '',
    fecha_fin: '',
});

function enviar() {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            dialogoAbierto.value = false;
            form.reset();
        },
    });
}

const tipoSeleccionado = () =>
    props.tipos.find((t) => t.value === form.tipo)?.label ?? '';
</script>

<template>
    <Head title="Mis solicitudes" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Mis solicitudes"
            descripcion="Permisos, incapacidades, constancias y otros trámites internos."
            :icono="ClipboardList"
        >
            <Button @click="dialogoAbierto = true">
                <Plus class="size-4" />
                Nueva solicitud
            </Button>
        </CrudPageHeader>

        <CrudEmptyState
            v-if="!solicitudes.data.length"
            :icono="ClipboardList"
            titulo="Todavía no tienes solicitudes"
            descripcion="Crea tu primera solicitud interna con el botón de arriba."
        >
            <Button @click="dialogoAbierto = true">
                <Plus class="size-4" />
                Crear la primera
            </Button>
        </CrudEmptyState>

        <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="solicitud in solicitudes.data"
                :key="solicitud.id"
                :href="show.url(solicitud.id)"
                class="group flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ solicitud.folio }}
                        </p>
                        <p class="text-sm font-semibold">
                            {{
                                tipos.find((t) => t.value === solicitud.tipo)
                                    ?.label ?? solicitud.tipo
                            }}
                        </p>
                    </div>
                    <EstadoBadge :estado="solicitud.estado" />
                </div>
                <p class="line-clamp-2 text-sm text-muted-foreground">
                    {{ solicitud.motivo }}
                </p>
                <div
                    class="mt-1 flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>{{ solicitud.created_at }}</span>
                    <Eye
                        class="size-4 opacity-60 transition-opacity md:opacity-0 md:group-hover:opacity-100"
                    />
                </div>
            </Link>
        </div>
    </div>

    <Dialog v-model:open="dialogoAbierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Nueva solicitud</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="tipo">Tipo de solicitud</Label>
                    <Select v-model="form.tipo">
                        <SelectTrigger id="tipo" class="w-full">
                            <SelectValue placeholder="Selecciona un tipo">
                                {{ tipoSeleccionado() }}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="tipo in tipos"
                                :key="tipo.value"
                                :value="tipo.value"
                            >
                                {{ tipo.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.tipo" class="text-sm text-destructive">
                        {{ form.errors.tipo }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="fecha_inicio"
                            >Fecha de inicio (si aplica)</Label
                        >
                        <Input
                            id="fecha_inicio"
                            v-model="form.fecha_inicio"
                            type="date"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="fecha_fin">Fecha de fin (si aplica)</Label>
                        <Input
                            id="fecha_fin"
                            v-model="form.fecha_fin"
                            type="date"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="motivo">Motivo</Label>
                    <Textarea id="motivo" v-model="form.motivo" rows="3" />
                    <p
                        v-if="form.errors.motivo"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.motivo }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="observaciones">Observaciones (opcional)</Label>
                    <Textarea
                        id="observaciones"
                        v-model="form.observaciones"
                        rows="2"
                    />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="dialogoAbierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Enviar solicitud
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
