<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import { Textarea } from '@/components/ui/textarea';
import causalesRotacion from '@/routes/rh/causales-rotacion';
import type { CausalRotacionAdmin } from '@/types';

/**
 * Bajas y finiquitos → «Causales de rotación»: el motivo de cada uno de los
 * tres tipos de baja (renuncia, rescisión, fin de contrato). Alta, edición,
 * activar/desactivar y orden; una causal ya usada no se elimina, se
 * desactiva (CausalRotacionService). La autorización real vive en
 * CausalRotacionPolicy.
 */
const props = defineProps<{
    causales: CausalRotacionAdmin[];
    tipos: { value: string; label: string }[];
}>();

const grupos = computed(() =>
    props.tipos.map((t) => ({
        ...t,
        causales: props.causales.filter((c) => c.tipo === t.value),
    })),
);

const abierto = ref(false);
const editando = ref<CausalRotacionAdmin | null>(null);
const form = useForm({
    tipo: '',
    nombre: '',
    descripcion: '',
    excluye_indemnizacion: false,
    activo: true,
});

function nueva(tipo: string) {
    editando.value = null;
    form.clearErrors();
    form.tipo = tipo;
    form.nombre = '';
    form.descripcion = '';
    form.excluye_indemnizacion = false;
    form.activo = true;
    abierto.value = true;
}

function editar(c: CausalRotacionAdmin) {
    editando.value = c;
    form.clearErrors();
    form.tipo = c.tipo;
    form.nombre = c.nombre;
    form.descripcion = c.descripcion ?? '';
    form.excluye_indemnizacion = c.excluye_indemnizacion;
    form.activo = c.activo;
    abierto.value = true;
}

function guardar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => (abierto.value = false),
    };

    if (editando.value) {
        form.put(causalesRotacion.update.url(editando.value.id), opciones);

        return;
    }

    form.post(causalesRotacion.store.url(), opciones);
}

const errorFila = ref<string | null>(null);

function alternar(c: CausalRotacionAdmin) {
    router.patch(
        causalesRotacion.activo.url(c.id),
        { activo: !c.activo },
        { preserveScroll: true },
    );
}

function mover(c: CausalRotacionAdmin, direccion: 'arriba' | 'abajo') {
    router.post(
        causalesRotacion.mover.url(c.id),
        { direccion },
        { preserveScroll: true },
    );
}

function eliminar(c: CausalRotacionAdmin) {
    if (!window.confirm(`¿Eliminar el motivo «${c.nombre}»?`)) {
        return;
    }

    errorFila.value = null;
    router.delete(causalesRotacion.destroy.url(c.id), {
        preserveScroll: true,
        onError: (e) => (errorFila.value = e.causal ?? null),
    });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <p class="text-sm text-[var(--mrl-texto-suave)]">
            Al iniciar una baja se elige el tipo (renuncia, rescisión o fin de
            contrato) y, debajo, su motivo. Un motivo que ya se usó no se
            elimina: desactívalo para que ya no se ofrezca; el historial lo
            conserva.
        </p>
        <InputError :message="errorFila ?? ''" />

        <section
            v-for="g in grupos"
            :key="g.value"
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
        >
            <header
                class="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--mrl-borde)] px-4 py-3"
            >
                <h3 class="text-sm font-semibold">
                    {{ g.label }}
                    <span class="font-normal text-[var(--mrl-texto-suave)]"
                        >· {{ g.causales.length }} motivos</span
                    >
                </h3>
                <Button size="sm" variant="outline" @click="nueva(g.value)">
                    <Plus class="size-4" />Agregar motivo
                </Button>
            </header>

            <p
                v-if="!g.causales.length"
                class="px-4 py-6 text-center text-sm text-[var(--mrl-texto-suave)]"
            >
                Sin motivos. Agrega al menos uno para poder elegirlo al iniciar
                una baja de este tipo.
            </p>

            <ul v-else class="divide-y divide-[var(--mrl-borde)]">
                <li
                    v-for="(c, i) in g.causales"
                    :key="c.id"
                    class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center"
                >
                    <div class="min-w-0 flex-1">
                        <p
                            class="flex flex-wrap items-center gap-2 text-sm font-medium"
                            :class="
                                !c.activo && 'text-[var(--mrl-texto-suave)]'
                            "
                        >
                            {{ c.nombre }}
                            <Badge v-if="!c.activo" variant="secondary"
                                >Inactivo</Badge
                            >
                            <Badge
                                v-if="c.excluye_indemnizacion"
                                variant="outline"
                                >Sin indemnización</Badge
                            >
                        </p>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            {{
                                c.usos
                                    ? `Usado en ${c.usos} ${c.usos === 1 ? 'baja' : 'bajas'}`
                                    : 'Sin usar'
                            }}<template v-if="c.descripcion">
                                · {{ c.descripcion }}</template
                            >
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <Button
                            size="icon"
                            variant="ghost"
                            :disabled="i === 0"
                            aria-label="Subir"
                            @click="mover(c, 'arriba')"
                            ><ArrowUp class="size-4"
                        /></Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            :disabled="i === g.causales.length - 1"
                            aria-label="Bajar"
                            @click="mover(c, 'abajo')"
                            ><ArrowDown class="size-4"
                        /></Button>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="alternar(c)"
                            >{{ c.activo ? 'Desactivar' : 'Activar' }}</Button
                        >
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label="Editar"
                            @click="editar(c)"
                            ><Pencil class="size-4"
                        /></Button>
                        <Button
                            v-if="!c.usos && !c.clave"
                            size="icon"
                            variant="ghost"
                            aria-label="Eliminar"
                            @click="eliminar(c)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                </li>
            </ul>
        </section>
    </div>

    <Dialog v-model:open="abierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    editando ? 'Editar motivo' : 'Nuevo motivo'
                }}</DialogTitle>
                <DialogDescription
                    >Causal de rotación que se elige al iniciar una
                    baja.</DialogDescription
                >
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="guardar">
                <div class="grid gap-1.5">
                    <Label for="causal-tipo">Tipo de baja</Label>
                    <SelectSimple
                        id="causal-tipo"
                        v-model="form.tipo"
                        :opciones="tipos"
                        :disabled="Boolean(editando?.usos)"
                    />
                    <p
                        v-if="editando?.usos"
                        class="text-xs text-[var(--mrl-texto-suave)]"
                    >
                        Ya se usó en bajas: no cambia de tipo.
                    </p>
                    <InputError :message="form.errors.tipo" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="causal-nombre">Nombre</Label>
                    <Input
                        id="causal-nombre"
                        v-model="form.nombre"
                        maxlength="150"
                    />
                    <InputError :message="form.errors.nombre" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="causal-descripcion"
                        >Descripción (opcional)</Label
                    >
                    <Textarea
                        id="causal-descripcion"
                        v-model="form.descripcion"
                        rows="2"
                        maxlength="500"
                    />
                    <InputError :message="form.errors.descripcion" />
                </div>
                <label class="flex items-start gap-2 text-sm">
                    <Casilla v-model="form.excluye_indemnizacion" />
                    <span
                        >No estimar indemnización en el finiquito (p. ej.
                        abandono de trabajo). La renuncia nunca la estima.</span
                    >
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <Casilla v-model="form.activo" />
                    <span>Activo (se ofrece al iniciar una baja)</span>
                </label>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="abierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">{{
                        editando ? 'Guardar cambios' : 'Agregar motivo'
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
