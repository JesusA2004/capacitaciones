<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    GitBranch,
    Search,
    UserRound,
    Users,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { jerarquia } from '@/routes/administracion/configuracion';
import {
    buscar,
    detalle,
    update,
} from '@/routes/administracion/configuracion/jerarquia';
import type {
    PersonaJerarquia,
    PosibleJefe,
    SeccionConfiguracion,
} from '@/types';

type Paginado<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Nodo = { id: number; nombre: string; puesto: string | null };

const props = defineProps<{
    personas: Paginado<PersonaJerarquia>;
    filtros: {
        busqueda: string | null;
        sucursal_id: number | null;
        solo_sin_jefe: boolean;
    };
    sucursales: { id: number; nombre: string }[];
    secciones: SeccionConfiguracion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Configuración', href: '' },
            { title: 'Jefes directos', href: '' },
        ],
    },
});

const busqueda = ref(props.filtros.busqueda ?? '');
const sucursal = ref(
    props.filtros.sucursal_id ? String(props.filtros.sucursal_id) : '',
);
const soloSinJefe = ref(props.filtros.solo_sin_jefe);

function filtrar() {
    router.get(
        jerarquia.url(),
        {
            busqueda: busqueda.value || undefined,
            sucursal_id: sucursal.value || undefined,
            solo_sin_jefe: soloSinJefe.value ? 1 : undefined,
        },
        { preserveState: true, replace: true },
    );
}

const filtrarDiferido = useDebounceFn(filtrar, 350);
watch(busqueda, () => filtrarDiferido());
watch([sucursal, soloSinJefe], filtrar);

async function obtener<T>(url: string): Promise<T> {
    const respuesta = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!respuesta.ok) {
        throw new Error(`HTTP ${respuesta.status}`);
    }

    return ((await respuesta.json()) as { data: T }).data;
}

// --- Edición del jefe directo ---
const editando = ref<PersonaJerarquia | null>(null);
const termino = ref('');
const opciones = ref<PosibleJefe[]>([]);
const elegido = ref<PosibleJefe | null>(null);
const cadenaJefe = ref<Nodo[]>([]);
const subordinados = ref<Nodo[]>([]);
const form = useForm<{ jefe_id: number | null; motivo: string }>({
    jefe_id: null,
    motivo: '',
});

async function editar(persona: PersonaJerarquia) {
    editando.value = persona;
    form.reset();
    form.clearErrors();
    form.jefe_id = persona.jefe?.id ?? null;
    elegido.value = persona.jefe
        ? {
              id: persona.jefe.id,
              nombre: persona.jefe.nombre,
              puesto: persona.jefe.puesto,
              sucursal: null,
          }
        : null;
    termino.value = '';
    opciones.value = [];
    subordinados.value = [];
    cadenaJefe.value = [];

    subordinados.value = (
        await obtener<{ subordinados: Nodo[] }>(detalle.url(persona.id))
    ).subordinados;

    if (elegido.value) {
        await cargarCadena(elegido.value.id);
    }
}

const buscarDiferido = useDebounceFn(async () => {
    if (!editando.value || termino.value.trim().length < 2) {
        opciones.value = [];

        return;
    }

    opciones.value = await obtener<PosibleJefe[]>(
        buscar.url({ query: { q: termino.value, excluir: editando.value.id } }),
    );
}, 300);
watch(termino, () => buscarDiferido());

async function cargarCadena(jefeId: number) {
    cadenaJefe.value = (
        await obtener<{ cadena: Nodo[] }>(detalle.url(jefeId))
    ).cadena;
}

async function elegir(jefe: PosibleJefe | null) {
    elegido.value = jefe;
    form.jefe_id = jefe?.id ?? null;
    opciones.value = [];
    termino.value = '';
    cadenaJefe.value = [];

    if (jefe) {
        await cargarCadena(jefe.id);
    }
}

function guardar() {
    if (!editando.value) {
        return;
    }

    form.put(update.url(editando.value.id), {
        preserveScroll: true,
        onSuccess: () => (editando.value = null),
    });
}
</script>

<template>
    <Head title="Jefes directos" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Jefes directos"
            descripcion="A quién reporta cada persona"
            :icono="GitBranch"
        />
        <ConfiguracionTabs :secciones="secciones" actual="jerarquia" />

        <p class="text-sm text-[var(--mrl-texto-suave)]">
            El rol de una cuenta no define a su jefe: aquí se captura la
            relación real entre personas. De ella salen las preautorizaciones,
            las evaluaciones y a quién llegan los avisos. Un cambio aplica a las
            solicitudes NUEVAS; las anteriores conservan a su aprobador
            original.
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-60 flex-1">
                <Search
                    class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-[var(--mrl-texto-suave)]"
                />
                <Input
                    v-model="busqueda"
                    class="pl-8"
                    placeholder="Nombre o número de empleado"
                    aria-label="Buscar persona"
                />
            </div>
            <select
                v-model="sucursal"
                class="h-9 rounded-md border bg-transparent px-3 text-sm"
                aria-label="Sucursal"
            >
                <option value="">Todas las sucursales</option>
                <option
                    v-for="s in sucursales"
                    :key="s.id"
                    :value="String(s.id)"
                >
                    {{ s.nombre }}
                </option>
            </select>
            <label class="flex items-center gap-2 text-sm"
                ><input v-model="soloSinJefe" type="checkbox" /> Solo sin jefe
                que deberían tenerlo</label
            >
        </div>

        <ul data-tour="configuracion-jefes" class="flex flex-col gap-2">
            <li
                v-for="p in personas.data"
                :key="p.id"
                class="flex flex-wrap items-center gap-3 rounded-xl border bg-[var(--mrl-superficie)] p-3 text-sm"
                :class="
                    p.advertencia
                        ? 'border-[var(--mrl-gold)]'
                        : 'border-[var(--mrl-borde)]'
                "
            >
                <UserRound class="size-5 shrink-0 text-[var(--mrl-primary)]" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium">
                        {{ p.nombre }}
                        <span class="text-xs text-[var(--mrl-texto-suave)]">{{
                            p.numero_empleado
                        }}</span>
                    </p>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        {{ p.puesto ?? 'Sin puesto' }} ·
                        {{ p.departamento ?? '—' }} ·
                        {{ p.sucursal ?? 'Sin sucursal' }}
                    </p>
                    <p class="mt-1 text-xs">
                        <span class="text-[var(--mrl-texto-suave)]"
                            >Cadena:</span
                        >
                        {{
                            p.cadena.length
                                ? p.cadena.join(' → ')
                                : 'nivel raíz (sin jefe)'
                        }}
                    </p>
                    <p
                        v-if="p.advertencia"
                        class="mt-1 flex items-center gap-1 text-xs text-[var(--mrl-gold-dark)]"
                    >
                        <AlertTriangle class="size-3.5" /> {{ p.advertencia }}
                    </p>
                </div>
                <div class="text-right text-xs">
                    <p>
                        Jefe: <strong>{{ p.jefe?.nombre ?? '—' }}</strong>
                    </p>
                    <p class="text-[var(--mrl-texto-suave)]">
                        <Users class="inline size-3.5" />
                        {{ p.subordinados_directos }} a su cargo
                    </p>
                </div>
                <Button size="sm" variant="outline" @click="editar(p)"
                    >Cambiar jefe</Button
                >
            </li>
        </ul>
        <p
            v-if="!personas.data.length"
            class="text-sm text-[var(--mrl-texto-suave)]"
        >
            Sin personas con esos filtros.
        </p>

        <nav
            v-if="personas.last_page > 1"
            class="flex items-center gap-2 text-sm"
            aria-label="Paginación"
        >
            <Button
                size="sm"
                variant="ghost"
                :disabled="!personas.prev_page_url"
                @click="
                    personas.prev_page_url &&
                    router.get(
                        personas.prev_page_url,
                        {},
                        { preserveState: true },
                    )
                "
            >
                Anterior
            </Button>
            <span
                >Página {{ personas.current_page }} de
                {{ personas.last_page }} · {{ personas.total }} personas</span
            >
            <Button
                size="sm"
                variant="ghost"
                :disabled="!personas.next_page_url"
                @click="
                    personas.next_page_url &&
                    router.get(
                        personas.next_page_url,
                        {},
                        { preserveState: true },
                    )
                "
            >
                Siguiente
            </Button>
        </nav>
    </div>

    <Dialog
        :open="editando !== null"
        @update:open="(abierto: boolean) => !abierto && (editando = null)"
    >
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle
                    >Jefe directo de {{ editando?.nombre }}</DialogTitle
                >
                <DialogDescription
                    >{{ editando?.puesto ?? 'Sin puesto' }} ·
                    {{
                        editando?.sucursal ?? 'Sin sucursal'
                    }}</DialogDescription
                >
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="guardar">
                <div class="grid gap-1.5">
                    <Label for="buscar-jefe">Buscar persona activa</Label>
                    <Input
                        id="buscar-jefe"
                        v-model="termino"
                        placeholder="Escribe al menos 2 letras"
                        autocomplete="off"
                    />
                    <ul
                        v-if="opciones.length"
                        class="max-h-48 overflow-y-auto rounded-md border border-[var(--mrl-borde)]"
                    >
                        <li v-for="o in opciones" :key="o.id">
                            <button
                                type="button"
                                class="w-full px-3 py-2 text-left text-sm hover:bg-[var(--mrl-fondo)]"
                                @click="elegir(o)"
                            >
                                {{ o.nombre }}
                                <span
                                    class="text-xs text-[var(--mrl-texto-suave)]"
                                    >· {{ o.puesto ?? '—' }} ·
                                    {{ o.sucursal ?? '—' }}</span
                                >
                            </button>
                        </li>
                    </ul>
                    <InputError :message="form.errors.jefe_id" />
                </div>

                <div class="rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm">
                    <p v-if="elegido">
                        Jefe elegido: <strong>{{ elegido.nombre }}</strong>
                        <span class="text-xs text-[var(--mrl-texto-suave)]">{{
                            elegido.puesto
                        }}</span>
                    </p>
                    <p v-else>Sin jefe: nivel raíz de la estructura.</p>
                    <p class="mt-1 text-xs text-[var(--mrl-texto-suave)]">
                        Cadena resultante: {{ editando?.nombre }}
                        <template v-if="elegido">
                            → {{ elegido.nombre
                            }}<template v-for="n in cadenaJefe" :key="n.id">
                                → {{ n.nombre }}</template
                            ></template
                        >
                    </p>
                    <Button
                        v-if="elegido"
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="mt-2"
                        @click="elegir(null)"
                        >Dejar sin jefe (nivel raíz)</Button
                    >
                </div>

                <div v-if="subordinados.length" class="text-xs">
                    <p class="mb-1 font-medium">
                        A su cargo directo ({{ subordinados.length }}):
                    </p>
                    <p class="text-[var(--mrl-texto-suave)]">
                        {{ subordinados.map((s) => s.nombre).join(', ') }}
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="motivo-jefe">Motivo del cambio</Label>
                    <Textarea
                        id="motivo-jefe"
                        v-model="form.motivo"
                        rows="2"
                        placeholder="Queda en la bitácora de auditoría"
                    />
                    <InputError :message="form.errors.motivo" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="editando = null"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing"
                        >Guardar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
