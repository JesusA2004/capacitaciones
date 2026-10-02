<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { BellRing, RotateCcw, ShieldAlert } from '@lucide/vue';
import { ref } from 'vue';
import ConfiguracionTabs from '@/components/configuracion/ConfiguracionTabs.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
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
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import {
    restaurar,
    update,
} from '@/routes/administracion/configuracion/notificaciones';
import type { ReglaNotificacion, SeccionConfiguracion } from '@/types';

const props = defineProps<{
    reglas: ReglaNotificacion[];
    tipos: { value: string; etiqueta: string }[];
    permisos: string[];
    usuarios: { id: number; nombre: string; email: string }[];
    secciones: SeccionConfiguracion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Configuración', href: '' },
            { title: 'Notificaciones', href: '' },
        ],
    },
});

const etiquetaTipo = (valor: string) =>
    props.tipos.find((t) => t.value === valor)?.etiqueta ?? valor;

const editando = ref<ReglaNotificacion | null>(null);
const form = useForm<{
    destinatarios: string[];
    fallback: string[];
    permiso: string | null;
    usuario_ids: number[];
    activa: boolean;
}>({
    destinatarios: [],
    fallback: [],
    permiso: null,
    usuario_ids: [],
    activa: true,
});

function editar(regla: ReglaNotificacion) {
    form.clearErrors();
    form.destinatarios = [...regla.destinatarios];
    form.fallback = [...regla.fallback];
    form.permiso = regla.permiso;
    form.usuario_ids = [...regla.usuario_ids];
    form.activa = regla.activa;
    editando.value = regla;
}

function guardar() {
    if (editando.value) {
        form.put(update.url(editando.value.evento), {
            preserveScroll: true,
            onSuccess: () => (editando.value = null),
        });
    }
}

function restaurarRegla(regla: ReglaNotificacion) {
    router.delete(restaurar.url(regla.evento), { preserveScroll: true });
}

const necesitaPermiso = () =>
    form.destinatarios.includes('usuarios_con_permiso') ||
    form.fallback.includes('usuarios_con_permiso');
</script>

<template>
    <Head title="Notificaciones" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Notificaciones"
            descripcion="A quién avisa cada evento"
            :icono="BellRing"
        />
        <ConfiguracionTabs :secciones="secciones" actual="notificaciones" />

        <div
            class="flex gap-3 rounded-xl border border-[var(--mrl-gold)]/40 bg-[var(--mrl-gold)]/10 p-3 text-sm"
        >
            <ShieldAlert class="size-5 shrink-0 text-[var(--mrl-gold-dark)]" />
            <p>
                Aquí solo se decide <strong>quién recibe el aviso</strong>.
                Quién puede autorizar no cambia: lo controlan los permisos, el
                alcance y el motor de aprobaciones, y
                <strong>RH siempre es la autorización final</strong>. Los
                destinatarios se resuelven con el organigrama real de personas
                (ver Jefes directos), nunca por el nombre de un rol.
            </p>
        </div>

        <ul class="flex flex-col gap-2">
            <li
                v-for="r in reglas"
                :key="r.evento"
                class="flex flex-wrap items-start gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-medium">
                        {{ r.etiqueta }}
                        <span
                            v-if="!r.activa"
                            class="ml-1 rounded-full bg-[var(--mrl-danger)]/10 px-2 py-0.5 text-xs text-[var(--mrl-danger)]"
                            >sin avisos</span
                        >
                        <span
                            v-if="r.personalizada"
                            class="ml-1 rounded-full bg-[var(--mrl-gold)]/15 px-2 py-0.5 text-xs text-[var(--mrl-gold-dark)]"
                            >personalizada</span
                        >
                    </p>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        {{ r.descripcion }}
                    </p>
                    <p class="mt-2 flex flex-wrap gap-1">
                        <span
                            v-for="d in r.destinatarios"
                            :key="d"
                            class="rounded-full bg-[var(--mrl-primary)]/10 px-2 py-0.5 text-xs text-[var(--mrl-primary)]"
                        >
                            {{ etiquetaTipo(d)
                            }}<template
                                v-if="d === 'usuarios_con_permiso' && r.permiso"
                                >: {{ r.permiso }}</template
                            >
                        </span>
                        <span
                            v-if="!r.destinatarios.length"
                            class="text-xs text-[var(--mrl-texto-suave)]"
                            >Nadie</span
                        >
                    </p>
                    <p
                        v-if="r.fallback.length"
                        class="mt-1 text-xs text-[var(--mrl-texto-suave)]"
                    >
                        Si nadie aplica:
                        {{ r.fallback.map(etiquetaTipo).join(', ') }}
                    </p>
                </div>
                <div class="flex gap-1">
                    <Button
                        v-if="r.personalizada"
                        size="sm"
                        variant="ghost"
                        title="Volver a la regla de fábrica"
                        @click="restaurarRegla(r)"
                        ><RotateCcw class="size-4"
                    /></Button>
                    <Button size="sm" variant="outline" @click="editar(r)"
                        >Editar</Button
                    >
                </div>
            </li>
        </ul>
    </div>

    <Dialog
        :open="editando !== null"
        @update:open="(abierto: boolean) => !abierto && (editando = null)"
    >
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ editando?.etiqueta }}</DialogTitle>
                <DialogDescription>{{
                    editando?.descripcion
                }}</DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="guardar">
                <fieldset class="grid gap-1.5 text-sm">
                    <legend class="mb-1 font-medium">Avisar a</legend>
                    <label
                        v-for="t in tipos"
                        :key="t.value"
                        class="flex items-center gap-2"
                    >
                        <input
                            v-model="form.destinatarios"
                            type="checkbox"
                            :value="t.value"
                        />
                        {{ t.etiqueta }}
                    </label>
                    <InputError :message="form.errors.destinatarios" />
                </fieldset>

                <div v-if="necesitaPermiso()" class="grid gap-1.5">
                    <Label for="regla-permiso">Permiso requerido</Label>
                    <select
                        id="regla-permiso"
                        v-model="form.permiso"
                        class="h-9 rounded-md border bg-transparent px-3 text-sm"
                    >
                        <option :value="null">Elige un permiso</option>
                        <option v-for="p in permisos" :key="p" :value="p">
                            {{ p }}
                        </option>
                    </select>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        Solo quienes tengan el permiso Y alcance sobre la
                        persona.
                    </p>
                    <InputError :message="form.errors.permiso" />
                </div>

                <div
                    v-if="form.destinatarios.includes('usuario_especifico')"
                    class="grid gap-1.5"
                >
                    <Label for="regla-usuarios">Usuarios específicos</Label>
                    <select
                        id="regla-usuarios"
                        v-model="form.usuario_ids"
                        multiple
                        class="min-h-28 rounded-md border bg-transparent px-2 py-1 text-sm"
                    >
                        <option v-for="u in usuarios" :key="u.id" :value="u.id">
                            {{ u.nombre }} ({{ u.email }})
                        </option>
                    </select>
                    <InputError :message="form.errors.usuario_ids" />
                </div>

                <fieldset class="grid gap-1.5 text-sm">
                    <legend class="mb-1 font-medium">
                        Si nadie aplica, avisar a
                    </legend>
                    <label
                        v-for="t in tipos.filter(
                            (x) => x.value !== 'usuario_especifico',
                        )"
                        :key="t.value"
                        class="flex items-center gap-2"
                    >
                        <input
                            v-model="form.fallback"
                            type="checkbox"
                            :value="t.value"
                        />
                        {{ t.etiqueta }}
                    </label>
                </fieldset>

                <label class="flex items-center gap-2 text-sm"
                    ><input v-model="form.activa" type="checkbox" /> Regla
                    activa (si se apaga, este evento no avisa a nadie)</label
                >

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="editando = null"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing"
                        >Guardar regla</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
