<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Pencil, Plus } from '@lucide/vue';
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
import { usePermisos } from '@/composables/usePermisos';
import { claseForma } from '@/lib/bajas';
import { cn } from '@/lib/utils';
import causalesRotacion from '@/routes/rh/causales-rotacion';
import type { CausalRotacionAdmin } from '@/types';

/**
 * Catálogo de causales de baja (RN-15, solo RH): dos niveles — forma de
 * salida (Renuncia voluntaria / No renovación / Rescisión) y su causal con
 * clave (RV-01…), ayuda «cuándo usarla», revisión de Jurídico, comentario
 * obligatorio y alerta. Las causales NUNCA se borran: se desactivan. Es la
 * misma pantalla en Bajas y finiquitos → «Causales» y en Configuración →
 * «Catálogo de bajas». La autorización real vive en CausalRotacionPolicy.
 */
const props = defineProps<{
    causales: CausalRotacionAdmin[];
    tipos: { value: string; label: string; forma?: string; token?: string }[];
}>();

// Cada acción del catálogo de bajas con su permiso (el backend lo revalida).
const { tienePermiso } = usePermisos();
const puede = {
    crear: tienePermiso('causales_rotacion.crear'),
    editar: tienePermiso('causales_rotacion.editar'),
    activar: tienePermiso('causales_rotacion.activar'),
    ordenar: tienePermiso('causales_rotacion.ordenar'),
};

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
    requiere_juridico: false,
    comentario_obligatorio: false,
    alerta: '',
    excluye_indemnizacion: false,
    implica_indemnizacion: false,
    activo: true,
});

function nueva(tipo: string) {
    editando.value = null;
    form.clearErrors();
    form.tipo = tipo;
    form.nombre = '';
    form.descripcion = '';
    form.requiere_juridico = false;
    // Rescisión y otras terminaciones (casos sensibles) piden comentario.
    form.comentario_obligatorio =
        tipo === 'rescision' || tipo === 'otra_terminacion';
    form.alerta = '';
    form.excluye_indemnizacion = false;
    form.implica_indemnizacion = false;
    form.activo = true;
    abierto.value = true;
}

function editar(c: CausalRotacionAdmin) {
    editando.value = c;
    form.clearErrors();
    form.tipo = c.tipo;
    form.nombre = c.nombre;
    form.descripcion = c.descripcion ?? '';
    form.requiere_juridico = c.requiere_juridico;
    form.comentario_obligatorio = c.comentario_obligatorio;
    form.alerta = c.alerta ?? '';
    form.excluye_indemnizacion = c.excluye_indemnizacion;
    form.implica_indemnizacion = c.implica_indemnizacion;
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
</script>

<template>
    <div class="flex flex-col gap-4">
        <p class="text-sm text-[var(--mrl-texto-suave)]">
            Al registrar una baja se elige primero cómo se va (forma de salida)
            y después por qué (la causal de esa forma); la ayuda de cada causal
            se muestra en pantalla. Las causales nunca se borran: desactívala
            para que ya no se ofrezca; el historial la conserva.
        </p>

        <section
            v-for="g in grupos"
            :key="g.value"
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
        >
            <header
                class="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--mrl-borde)] px-4 py-3"
            >
                <h3 class="flex items-center gap-2 text-sm font-semibold">
                    <span
                        :class="
                            cn('size-3 rounded-full', claseForma(g.token).fondo)
                        "
                        aria-hidden="true"
                    />
                    {{ g.forma ? `${g.forma} · ` : '' }}{{ g.label }}
                    <span class="font-normal text-[var(--mrl-texto-suave)]"
                        >· {{ g.causales.length }} causales</span
                    >
                </h3>
                <Button
                    v-if="puede.crear"
                    size="sm"
                    variant="outline"
                    @click="nueva(g.value)"
                >
                    <Plus class="size-4" />Agregar causal
                </Button>
            </header>

            <p
                v-if="!g.causales.length"
                class="px-4 py-6 text-center text-sm text-[var(--mrl-texto-suave)]"
            >
                Sin causales. Agrega al menos una para poder elegirla.
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
                            <span
                                v-if="c.clave"
                                class="font-mono text-xs text-[var(--mrl-texto-suave)]"
                                >{{ c.clave }}</span
                            >
                            {{ c.nombre }}
                            <Badge v-if="!c.activo" variant="secondary"
                                >Inactiva</Badge
                            >
                            <Badge v-if="c.requiere_juridico" variant="outline"
                                >Integrar expediente probatorio</Badge
                            >
                            <Badge
                                v-if="c.implica_indemnizacion"
                                variant="outline"
                                >Implica indemnización</Badge
                            >
                            <Badge
                                v-if="c.comentario_obligatorio"
                                variant="outline"
                                >Comentario obligatorio</Badge
                            >
                            <Badge v-if="c.alerta" variant="warning"
                                >Alerta</Badge
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
                                    ? `Usada en ${c.usos} ${c.usos === 1 ? 'baja' : 'bajas'}`
                                    : 'Sin usar'
                            }}<template v-if="c.descripcion">
                                · {{ c.descripcion }}</template
                            >
                        </p>
                        <p v-if="c.alerta" class="text-xs text-warning">
                            {{ c.alerta }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <Button
                            v-if="puede.ordenar"
                            size="icon"
                            variant="ghost"
                            :disabled="i === 0"
                            aria-label="Subir"
                            @click="mover(c, 'arriba')"
                            ><ArrowUp class="size-4"
                        /></Button>
                        <Button
                            v-if="puede.ordenar"
                            size="icon"
                            variant="ghost"
                            :disabled="i === g.causales.length - 1"
                            aria-label="Bajar"
                            @click="mover(c, 'abajo')"
                            ><ArrowDown class="size-4"
                        /></Button>
                        <Button
                            v-if="puede.activar"
                            size="sm"
                            variant="outline"
                            @click="alternar(c)"
                            >{{ c.activo ? 'Desactivar' : 'Activar' }}</Button
                        >
                        <Button
                            v-if="puede.editar"
                            size="icon"
                            variant="ghost"
                            aria-label="Editar"
                            @click="editar(c)"
                            ><Pencil class="size-4"
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
                    editando
                        ? `Editar causal ${editando.clave ?? ''}`
                        : 'Nueva causal'
                }}</DialogTitle>
                <DialogDescription
                    >Se elige al registrar una baja de esta forma de
                    salida.</DialogDescription
                >
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="guardar">
                <div class="grid gap-1.5">
                    <Label for="causal-tipo">Forma de salida</Label>
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
                        Ya se usó en bajas: no cambia de forma de salida.
                    </p>
                    <InputError :message="form.errors.tipo" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="causal-nombre">Nombre</Label>
                    <Input
                        id="causal-nombre"
                        v-model="form.nombre"
                        maxlength="80"
                    />
                    <InputError :message="form.errors.nombre" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="causal-descripcion"
                        >Cuándo usarla (ayuda en pantalla)</Label
                    >
                    <Textarea
                        id="causal-descripcion"
                        v-model="form.descripcion"
                        rows="2"
                        maxlength="300"
                    />
                    <InputError :message="form.errors.descripcion" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="causal-alerta">Alerta (opcional)</Label>
                    <Input
                        id="causal-alerta"
                        v-model="form.alerta"
                        maxlength="200"
                        placeholder="p. ej. Aviso a Contraloría"
                    />
                    <InputError :message="form.errors.alerta" />
                </div>
                <label class="flex items-start gap-2 text-sm">
                    <Casilla v-model="form.requiere_juridico" />
                    <span
                        >Integrar expediente probatorio (aviso para que RH
                        resguarde evidencia por si hay demanda; no es una
                        autorización de Jurídico)</span
                    >
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <Casilla v-model="form.implica_indemnizacion" />
                    <span
                        >Implica indemnización: el finiquito se calcula como
                        liquidación (sujeto a validación jurídica de cada
                        caso)</span
                    >
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <Casilla v-model="form.comentario_obligatorio" />
                    <span>Comentario obligatorio (mínimo 20 caracteres)</span>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <Casilla v-model="form.excluye_indemnizacion" />
                    <span
                        >No estimar indemnización en el finiquito (p. ej. faltas
                        o abandono). La renuncia nunca la estima.</span
                    >
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <Casilla v-model="form.activo" />
                    <span>Activa (se ofrece al registrar una baja)</span>
                </label>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="abierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">{{
                        editando ? 'Guardar cambios' : 'Agregar causal'
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
