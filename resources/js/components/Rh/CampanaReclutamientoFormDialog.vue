<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { FileText, ImageIcon, Paperclip, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { store, update } from '@/routes/rh/campanas';
import { destroy as eliminarAdjuntoRuta } from '@/routes/rh/campanas/adjuntos';
import type { CampanaReclutamientoItem, OpcionesCampanas } from '@/types';

/**
 * Una campaña parte de una VACANTE REAL: empresa, sucursal, departamento y
 * puesto salen de la vacante (backend), aquí ya no se piden.
 */
const props = defineProps<{
    open: boolean;
    campana?: CampanaReclutamientoItem | null;
    opciones: OpcionesCampanas;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const hoy = new Date().toISOString().slice(0, 10);
const texto = (v: number | string | null | undefined) =>
    v === null || v === undefined ? '' : String(v);

const form = useForm<{
    vacante_id: string;
    nombre: string;
    canal: string;
    fecha_inicio: string;
    fecha_fin: string;
    presupuesto: string;
    monto: string;
    sueldo_publicado: string;
    copy: string;
    url: string;
    responsable_id: string;
    candidatos_generados: string;
    observaciones: string;
    adjuntos: File[];
}>({
    vacante_id: texto(props.campana?.vacante_id),
    nombre: props.campana?.nombre ?? '',
    canal: props.campana?.canal ?? '',
    fecha_inicio: props.campana?.fecha_inicio?.slice(0, 10) ?? hoy,
    fecha_fin: props.campana?.fecha_fin?.slice(0, 10) ?? '',
    presupuesto: texto(props.campana?.presupuesto),
    monto: props.campana ? texto(props.campana.monto) : '0',
    sueldo_publicado: texto(props.campana?.sueldo_publicado),
    copy: props.campana?.copy ?? '',
    url: props.campana?.url ?? '',
    responsable_id: texto(props.campana?.responsable_id),
    candidatos_generados: texto(props.campana?.candidatos_generados),
    observaciones: props.campana?.observaciones ?? '',
    adjuntos: [],
});

const vacanteElegida = computed(() =>
    props.opciones.vacantes.find((v) => String(v.id) === form.vacante_id),
);

function alElegirArchivos(evento: Event) {
    const entrada = evento.target as HTMLInputElement;
    form.adjuntos = [...form.adjuntos, ...Array.from(entrada.files ?? [])];
    entrada.value = '';
}

function quitarArchivo(indice: number) {
    form.adjuntos = form.adjuntos.filter((_, i) => i !== indice);
}

function eliminarAdjunto(id: number) {
    if (!props.campana) {
        return;
    }

    router.delete(eliminarAdjuntoRuta.url([props.campana.id, id]), {
        preserveScroll: true,
    });
}

function enviar() {
    const transformado = form.transform((datos) => ({
        ...datos,
        fecha_fin: datos.fecha_fin || null,
        presupuesto: datos.presupuesto || null,
        sueldo_publicado: datos.sueldo_publicado || null,
        responsable_id: datos.responsable_id || null,
        candidatos_generados: datos.candidatos_generados || null,
        ...(props.campana ? { _method: 'put' } : {}),
    }));

    const opciones = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => emit('update:open', false),
    };

    // Con archivos: POST + _method=put (multipart).
    transformado.post(
        props.campana ? update.url(props.campana.id) : store.url(),
        opciones,
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{
                    campana ? 'Editar campaña' : 'Nueva campaña'
                }}</DialogTitle>
                <DialogDescription>
                    La empresa, sucursal, departamento y puesto salen de la
                    vacante elegida.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-5" @submit.prevent="enviar">
                <section class="grid gap-3 rounded-2xl border border-border/60 p-4">
                    <p class="text-sm font-semibold">Vacante y canal</p>
                    <div class="grid gap-2">
                        <Label>Vacante real</Label>
                        <Select v-model="form.vacante_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Elige la vacante" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in opciones.vacantes"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.etiqueta }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <p
                            v-if="!opciones.vacantes.length"
                            class="text-xs text-muted-foreground"
                        >
                            No hay vacantes con plaza disponible
                            (plantilla autorizada − ocupados).
                        </p>
                        <p
                            v-else-if="vacanteElegida"
                            class="text-xs text-muted-foreground"
                        >
                            {{ vacanteElegida.etiqueta }}
                        </p>
                        <InputError :message="form.errors.vacante_id" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label>Canal</Label>
                            <Select v-model="form.canal">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="Selecciona un canal" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="opcion in opciones.canales"
                                        :key="opcion.value"
                                        :value="opcion.value"
                                        >{{ opcion.etiqueta }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.canal" />
                        </div>
                        <div class="grid gap-2">
                            <Label>Responsable</Label>
                            <Select v-model="form.responsable_id">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="¿Quién la lleva?" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="opcion in opciones.responsables"
                                        :key="opcion.id"
                                        :value="String(opcion.id)"
                                        >{{ opcion.name }}
                                        {{ opcion.apellidos ?? '' }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.responsable_id" />
                        </div>
                        <div class="grid gap-2">
                            <Label>Inicio</Label>
                            <DatePicker v-model="form.fecha_inicio" />
                            <InputError :message="form.errors.fecha_inicio" />
                        </div>
                        <div class="grid gap-2">
                            <Label>Fin (opcional)</Label>
                            <DatePicker v-model="form.fecha_fin" />
                            <InputError :message="form.errors.fecha_fin" />
                        </div>
                    </div>
                </section>

                <section class="grid gap-3 rounded-2xl border border-border/60 p-4">
                    <p class="text-sm font-semibold">Dinero</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="presupuesto">Presupuesto</Label>
                            <Input id="presupuesto" v-model="form.presupuesto" type="number" min="0" step="0.01" placeholder="0.00" />
                            <InputError :message="form.errors.presupuesto" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="monto">Gasto real</Label>
                            <Input id="monto" v-model="form.monto" type="number" min="0" step="0.01" placeholder="0.00" />
                            <InputError :message="form.errors.monto" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="sueldo">Sueldo publicado</Label>
                            <Input id="sueldo" v-model="form.sueldo_publicado" type="number" min="0" step="0.01" placeholder="0.00" />
                            <InputError :message="form.errors.sueldo_publicado" />
                        </div>
                    </div>
                </section>

                <section class="grid gap-3 rounded-2xl border border-border/60 p-4">
                    <p class="text-sm font-semibold">Publicación</p>
                    <div class="grid gap-2">
                        <Label for="nombre">Nombre de la campaña (opcional)</Label>
                        <Input id="nombre" v-model="form.nombre" placeholder="Ej. Gestores Córdoba octubre" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="copy">Texto del anuncio (copy)</Label>
                        <Textarea id="copy" v-model="form.copy" rows="4" />
                        <InputError :message="form.errors.copy" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="url">Liga del anuncio</Label>
                        <Input id="url" v-model="form.url" type="url" placeholder="https://" />
                        <InputError :message="form.errors.url" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Arte y documentos (PDF o imagen, privados)</Label>
                        <div
                            v-if="campana?.adjuntos_lista?.length"
                            class="flex flex-wrap gap-2"
                        >
                            <span
                                v-for="adjunto in campana.adjuntos_lista"
                                :key="adjunto.id"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-2 py-1 text-xs"
                            >
                                <component :is="adjunto.mime.startsWith('image/') ? ImageIcon : FileText" class="size-3.5" />
                                <a :href="adjunto.url" target="_blank" class="max-w-40 truncate hover:underline">{{ adjunto.nombre }}</a>
                                <button type="button" class="text-destructive" aria-label="Eliminar archivo" @click="eliminarAdjunto(adjunto.id)">
                                    <Trash2 class="size-3.5" />
                                </button>
                            </span>
                        </div>
                        <label
                            class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground hover:bg-muted/40"
                        >
                            <Paperclip class="size-4" /> Agregar archivos
                            <input type="file" class="sr-only" multiple accept=".pdf,image/jpeg,image/png,image/webp" @change="alElegirArchivos" />
                        </label>
                        <div v-if="form.adjuntos.length" class="flex flex-wrap gap-2">
                            <span
                                v-for="(archivo, i) in form.adjuntos"
                                :key="`${archivo.name}-${i}`"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-muted px-2 py-1 text-xs"
                            >
                                {{ archivo.name }}
                                <button type="button" aria-label="Quitar" @click="quitarArchivo(i)"><Trash2 class="size-3.5" /></button>
                            </span>
                        </div>
                        <InputError :message="form.errors.adjuntos" />
                    </div>
                </section>

                <section class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="candidatos_generados">Candidatos generados (opcional)</Label>
                        <Input id="candidatos_generados" v-model="form.candidatos_generados" type="number" min="0" placeholder="Auto" />
                        <p class="text-xs text-muted-foreground">
                            Vacío: se cuentan los candidatos registrados con esta campaña.
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="observaciones">Observaciones</Label>
                        <Textarea id="observaciones" v-model="form.observaciones" rows="3" />
                    </div>
                </section>

                <DialogFooter>
                    <Button type="button" variant="secondary" @click="emit('update:open', false)">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
