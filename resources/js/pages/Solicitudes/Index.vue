<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ClipboardList, Eye, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
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
    ColaboradorParaBaja,
    RespuestaPaginada,
    SaldoVacaciones,
    SolicitudInternaItem,
    TipoSolicitudInternaFormulario,
} from '@/types';

const props = defineProps<{
    solicitudes: RespuestaPaginada<SolicitudInternaItem>;
    tipos: TipoSolicitudInternaFormulario[];
    saldoVacaciones: SaldoVacaciones;
    colaboradoresParaBaja: ColaboradorParaBaja[];
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
    dias_solicitados: '',
    monto_solicitado: '',
    plazo_meses: '',
    colaborador_objetivo_id: '',
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

const tipoActual = computed(() =>
    props.tipos.find((t) => t.clave === form.tipo),
);

function nombreTipo(clave: string): string {
    return props.tipos.find((t) => t.clave === clave)?.nombre ?? clave;
}
</script>

<template>
    <Head title="Mis solicitudes" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Mis solicitudes"
            descripcion="Vacaciones, permisos, préstamos, incapacidades y otros trámites internos, todo en un solo lugar."
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
                            {{ nombreTipo(solicitud.tipo) }}
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
                                {{ tipoActual?.nombre ?? '' }}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="tipo in tipos"
                                :key="tipo.clave"
                                :value="tipo.clave"
                            >
                                {{ tipo.nombre }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.tipo" class="text-sm text-destructive">
                        {{ form.errors.tipo }}
                    </p>
                </div>

                <!-- Vacaciones: saldo disponible + días a solicitar -->
                <div
                    v-if="tipoActual?.requiere_dias"
                    class="rounded-lg border border-border/60 bg-muted/40 p-3 text-sm"
                >
                    Días disponibles:
                    <span class="font-semibold">{{
                        saldoVacaciones.dias_disponibles
                    }}</span>
                    de {{ saldoVacaciones.dias_generados }} generados este
                    periodo.
                </div>

                <div
                    v-if="tipoActual?.requiere_fechas || tipoActual?.requiere_horario"
                    class="grid grid-cols-2 gap-4"
                >
                    <div class="grid gap-2">
                        <Label for="fecha_inicio">{{
                            tipoActual?.requiere_fechas
                                ? 'Fecha de inicio'
                                : 'Fecha'
                        }}</Label>
                        <Input
                            id="fecha_inicio"
                            v-model="form.fecha_inicio"
                            type="date"
                        />
                        <p
                            v-if="form.errors.fecha_inicio"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.fecha_inicio }}
                        </p>
                    </div>
                    <div v-if="tipoActual?.requiere_fechas" class="grid gap-2">
                        <Label for="fecha_fin">Fecha de fin</Label>
                        <Input
                            id="fecha_fin"
                            v-model="form.fecha_fin"
                            type="date"
                        />
                        <p
                            v-if="form.errors.fecha_fin"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.fecha_fin }}
                        </p>
                    </div>
                </div>

                <div v-if="tipoActual?.requiere_dias" class="grid gap-2">
                    <Label for="dias_solicitados">Días a solicitar</Label>
                    <Input
                        id="dias_solicitados"
                        v-model="form.dias_solicitados"
                        type="number"
                        min="1"
                        :max="saldoVacaciones.dias_disponibles"
                    />
                    <p
                        v-if="form.errors.dias_solicitados"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.dias_solicitados }}
                    </p>
                </div>

                <!-- Préstamo interno: monto y plazo -->
                <div v-if="tipoActual?.requiere_monto" class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="monto_solicitado">Monto solicitado</Label>
                        <Input
                            id="monto_solicitado"
                            v-model="form.monto_solicitado"
                            type="number"
                            min="1"
                            step="0.01"
                        />
                        <p
                            v-if="form.errors.monto_solicitado"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.monto_solicitado }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="plazo_meses">Plazo (meses)</Label>
                        <Input
                            id="plazo_meses"
                            v-model="form.plazo_meses"
                            type="number"
                            min="1"
                            max="36"
                        />
                    </div>
                </div>

                <!-- Baja de colaborador: a quién se solicita dar de baja -->
                <div
                    v-if="tipoActual?.requiere_colaborador_objetivo"
                    class="grid gap-2"
                >
                    <Label for="colaborador_objetivo_id">Colaborador</Label>
                    <Select v-model="form.colaborador_objetivo_id">
                        <SelectTrigger id="colaborador_objetivo_id" class="w-full">
                            <SelectValue placeholder="Selecciona un colaborador" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="colaborador in colaboradoresParaBaja"
                                :key="colaborador.id"
                                :value="String(colaborador.id)"
                            >
                                {{ colaborador.name }}
                                {{ colaborador.apellidos ?? '' }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="form.errors.colaborador_objetivo_id"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.colaborador_objetivo_id }}
                    </p>
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
