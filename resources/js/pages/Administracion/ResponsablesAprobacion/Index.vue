<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Briefcase,
    GitBranch,
    Plus,
    Search,
    ShieldCheck,
    Trash2,
    UserCheck,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import BuscadorPersonaOrganigrama from '@/components/Organigrama/BuscadorPersonaOrganigrama.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { formatearFechaHora } from '@/lib/fechas';
import {
    destroy,
    index,
    store,
} from '@/routes/administracion/responsables-aprobacion';

/**
 * Organización → Responsables de aprobación. Separa «a quién reporta»
 * (jefe organizacional, sale del organigrama) de «quién aprueba sus
 * solicitudes» (responsable de aprobación). Toda la regla y la bitácora
 * viven en el backend (AprobacionOrganizacionalService).
 */
type PersonaCorta = { id: number; nombre: string; puesto: string | null };
type Opcion = { value: string; label: string };
type Regla = {
    id: number;
    ambito: 'persona' | 'puesto';
    colaborador: PersonaCorta | null;
    puesto: { id: number; nombre: string } | null;
    responsable: (PersonaCorta & { activo: boolean }) | null;
    motivo: string | null;
    asignado_por: string | null;
    actualizado_en: string | null;
};
type Persona = {
    id: number;
    nombre: string;
    /** Foto oficial (URL protegida, dentro del alcance); null → iniciales. */
    foto_url: string | null;
    puesto: string | null;
    sucursal: string | null;
    jefe_organizacional: PersonaCorta | null;
    responsable_aprobaciones: PersonaCorta | null;
    origen: string;
    origen_etiqueta: string;
    mensaje: string | null;
    bloqueado: boolean;
    distinto_del_jefe: boolean;
    regla_id: number | null;
};

const props = defineProps<{
    reglas: Regla[];
    personas: Persona[];
    alertas: {
        tipo: string;
        colaborador_id: number;
        nombre: string;
        mensaje: string;
    }[];
    puestos: Opcion[];
    candidatos: Opcion[];
    puedeEditar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Organización', href: '' },
            { title: 'Responsables de aprobación', href: index.url() },
        ],
    },
});

const { confirmarEliminacion } = useAlertas();

// ---- Filtro de personas (lista completa desde el inicio, sin mínimo) ----
const termino = ref('');
const soloDistintos = ref(false);

function normalizar(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

const personasFiltradas = computed(() => {
    const texto = normalizar(termino.value.trim());

    return props.personas.filter((p) => {
        if (soloDistintos.value && !p.distinto_del_jefe && !p.bloqueado) {
            return false;
        }

        if (texto === '') {
            return true;
        }

        return normalizar(
            [
                p.nombre,
                p.puesto,
                p.sucursal,
                p.jefe_organizacional?.nombre,
                p.responsable_aprobaciones?.nombre,
            ]
                .filter(Boolean)
                .join(' '),
        ).includes(texto);
    });
});

// Se pintan por bloques (la lista completa se filtra igual al escribir).
const limite = ref(48);
const personasVisibles = computed(() =>
    personasFiltradas.value.slice(0, limite.value),
);

// ---- Asignar ----
const dialogo = ref(false);
const form = useForm({
    ambito: 'persona' as 'persona' | 'puesto',
    colaborador_id: '',
    puesto_id: '',
    responsable_colaborador_id: '',
    motivo: '',
});

function abrir(persona?: Persona): void {
    form.reset();
    form.clearErrors();

    if (persona) {
        form.colaborador_id = String(persona.id);
    }

    dialogo.value = true;
}

const puedeGuardar = computed(
    () =>
        form.responsable_colaborador_id !== '' &&
        form.motivo.trim().length >= 5 &&
        (form.ambito === 'persona'
            ? form.colaborador_id !== ''
            : form.puesto_id !== ''),
);

function guardar(): void {
    form.transform((d) => ({
        colaborador_id:
            d.ambito === 'persona' ? Number(d.colaborador_id) || null : null,
        puesto_id: d.ambito === 'puesto' ? Number(d.puesto_id) || null : null,
        responsable_colaborador_id: Number(d.responsable_colaborador_id),
        motivo: d.motivo,
    })).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => (dialogo.value = false),
    });
}

async function quitar(regla: Regla): Promise<void> {
    const nombre =
        regla.colaborador?.nombre ?? regla.puesto?.nombre ?? 'esta regla';

    if (!(await confirmarEliminacion(`el responsable de ${nombre}`))) {
        return;
    }

    router.delete(destroy.url(regla.id), { preserveScroll: true });
}

const ORIGEN_CLASE: Record<string, string> = {
    explicito: 'bg-[var(--mrl-primary)]/10 text-[var(--mrl-primary)]',
    explicito_puesto: 'bg-[var(--mrl-primary)]/10 text-[var(--mrl-primary)]',
    cobertura: 'bg-menta text-[var(--mrl-success)]',
    pendiente_region: 'bg-[var(--mrl-gold)]/15 text-[var(--mrl-gold-dark)]',
    regional_vacante: 'bg-[var(--mrl-gold)]/15 text-[var(--mrl-gold-dark)]',
    sin_responsable: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head title="Responsables de aprobación" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Responsables de aprobación"
            descripcion="Quién aprueba las solicitudes de cada persona o puesto"
            :icono="ShieldCheck"
        >
            <Button v-if="puedeEditar" @click="abrir()">
                <Plus class="size-4" /> Asignar responsable
            </Button>
        </CrudPageHeader>

        <!-- Explicación corta -->
        <div
            class="grid gap-3 rounded-2xl border border-border/60 bg-crema p-4 text-sm sm:grid-cols-2"
        >
            <p class="flex items-start gap-2">
                <GitBranch class="mt-0.5 size-4 shrink-0 text-primary" />
                <span
                    ><strong>Jefe organizacional:</strong> a quién reporta en el
                    organigrama. Sale solo del puesto y la sucursal.</span
                >
            </p>
            <p class="flex items-start gap-2">
                <UserCheck class="mt-0.5 size-4 shrink-0 text-primary" />
                <span
                    ><strong>Responsable de aprobaciones:</strong> quién da el
                    visto bueno de sus solicitudes. Por omisión es la regla del
                    organigrama; aquí RH lo asigna cuando debe ser otra
                    persona.</span
                >
            </p>
        </div>

        <!-- Alertas: aprobaciones detenidas por la estructura -->
        <section
            v-if="alertas.length"
            class="flex flex-col gap-2 rounded-2xl border border-[var(--mrl-gold)]/40 bg-[var(--mrl-gold)]/10 p-4"
        >
            <h2
                class="flex items-center gap-2 text-sm font-semibold text-[var(--mrl-gold-dark)]"
            >
                <AlertTriangle class="size-4" />
                {{ alertas.length }}
                {{
                    alertas.length === 1
                        ? 'persona con aprobaciones detenidas'
                        : 'personas con aprobaciones detenidas'
                }}
            </h2>
            <ul class="grid max-h-72 gap-2 overflow-y-auto lg:grid-cols-2">
                <li
                    v-for="a in alertas"
                    :key="a.colaborador_id"
                    class="rounded-xl bg-card px-3 py-2 text-sm"
                >
                    <p class="font-medium">{{ a.nombre }}</p>
                    <p class="text-xs text-muted-foreground">{{ a.mensaje }}</p>
                </li>
            </ul>
        </section>

        <!-- Reglas asignadas por RH -->
        <section class="flex flex-col gap-3" data-tour="responsables-asignados">
            <h2 class="text-sm font-semibold">
                Asignados por RH
                <span class="font-normal text-muted-foreground"
                    >({{ reglas.length }})</span
                >
            </h2>
            <p
                v-if="!reglas.length"
                class="rounded-2xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
            >
                Todavía no hay responsables asignados a mano: todas las
                aprobaciones siguen la regla del organigrama.
            </p>
            <ul v-else class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                <li
                    v-for="regla in reglas"
                    :key="regla.id"
                    class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <UserRound
                                    v-if="regla.ambito === 'persona'"
                                    class="size-3.5"
                                />
                                <Briefcase v-else class="size-3.5" />
                                {{
                                    regla.ambito === 'persona'
                                        ? 'Persona'
                                        : 'Todo el puesto'
                                }}
                            </p>
                            <p class="truncate font-semibold">
                                {{
                                    regla.colaborador?.nombre ??
                                    regla.puesto?.nombre
                                }}
                            </p>
                            <p
                                v-if="regla.colaborador?.puesto"
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ regla.colaborador.puesto }}
                            </p>
                        </div>
                        <Button
                            v-if="puedeEditar"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Quitar responsable de ${regla.colaborador?.nombre ?? regla.puesto?.nombre}`"
                            @click="quitar(regla)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                    <p
                        class="flex items-center gap-2 rounded-xl bg-salvia/60 px-3 py-2 text-sm"
                    >
                        <UserCheck class="size-4 shrink-0 text-primary" />
                        <span class="min-w-0"
                            >Aprueba:
                            <strong>{{
                                regla.responsable?.nombre ?? '—'
                            }}</strong>
                            <span
                                v-if="
                                    regla.responsable &&
                                    !regla.responsable.activo
                                "
                                class="ml-1 text-xs text-destructive"
                                >(sin acceso: se usa la regla del
                                organigrama)</span
                            ></span
                        >
                    </p>
                    <p
                        v-if="regla.motivo"
                        class="text-xs text-muted-foreground"
                    >
                        «{{ regla.motivo }}»
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        {{ regla.asignado_por ?? 'RH' }} ·
                        {{ formatearFechaHora(regla.actualizado_en) }}
                    </p>
                </li>
            </ul>
        </section>

        <!-- Personas: jefe organizacional vs responsable de aprobaciones -->
        <section class="flex flex-col gap-3" data-tour="responsables-personas">
            <div
                class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between"
            >
                <h2 class="text-sm font-semibold whitespace-nowrap">
                    Personas
                    <span class="font-normal text-muted-foreground"
                        >({{ personasFiltradas.length }} de
                        {{ personas.length }})</span
                    >
                </h2>
                <div
                    class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between lg:justify-end"
                >
                    <label
                        class="flex items-center gap-2 text-xs text-muted-foreground sm:whitespace-nowrap"
                    >
                        <input
                            v-model="soloDistintos"
                            type="checkbox"
                            class="size-4 accent-[var(--mrl-primary)]"
                        />
                        Solo responsables distintos o detenidos
                    </label>
                    <label
                        class="flex h-10 items-center gap-2 rounded-xl border border-border bg-card px-3 sm:w-72 xl:w-80"
                    >
                        <Search class="size-4 text-muted-foreground" />
                        <input
                            v-model="termino"
                            type="search"
                            placeholder="Buscar persona, puesto o sucursal…"
                            class="w-full bg-transparent text-sm outline-none"
                            aria-label="Buscar persona"
                        />
                    </label>
                </div>
            </div>

            <ul
                class="grid gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
            >
                <li
                    v-for="p in personasVisibles"
                    :key="p.id"
                    class="flex flex-col gap-2 rounded-2xl border bg-card p-4"
                    :class="
                        p.bloqueado
                            ? 'border-[var(--mrl-gold)]/50'
                            : 'border-border/60'
                    "
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <ColaboradorAvatar
                            :nombre="p.nombre"
                            :foto-url="p.foto_url"
                            tamano="md"
                        />
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ p.nombre }}</p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ p.puesto ?? 'Sin puesto'
                                }}<template v-if="p.sucursal">
                                    · {{ p.sucursal }}</template
                                >
                            </p>
                        </div>
                    </div>
                    <dl class="grid gap-1.5 text-xs">
                        <div
                            class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-1.5"
                        >
                            <GitBranch
                                class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                            />
                            <dt class="text-muted-foreground">
                                Jefe organizacional:
                            </dt>
                            <dd
                                class="col-start-2 min-w-0 font-medium break-words"
                            >
                                {{ p.jefe_organizacional?.nombre ?? '—' }}
                            </dd>
                        </div>
                        <div
                            class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-1.5"
                        >
                            <UserCheck
                                class="mt-0.5 size-3.5 shrink-0 text-primary"
                            />
                            <dt class="text-muted-foreground">
                                Responsable de aprobaciones:
                            </dt>
                            <dd
                                class="col-start-2 min-w-0 font-medium break-words"
                            >
                                {{
                                    p.responsable_aprobaciones?.nombre ??
                                    (p.bloqueado ? 'Detenido' : 'RH directo')
                                }}
                            </dd>
                        </div>
                    </dl>
                    <span
                        class="w-fit rounded-full px-2 py-0.5 text-[11px] font-medium"
                        :class="
                            ORIGEN_CLASE[p.origen] ??
                            'bg-muted text-muted-foreground'
                        "
                    >
                        {{ p.origen_etiqueta }}
                    </span>
                    <p
                        v-if="p.bloqueado && p.mensaje"
                        class="text-xs text-[var(--mrl-gold-dark)]"
                    >
                        {{ p.mensaje }}
                    </p>
                    <Button
                        v-if="puedeEditar"
                        variant="outline"
                        size="sm"
                        class="mt-auto"
                        @click="abrir(p)"
                    >
                        <UserCheck class="size-4" />
                        {{
                            p.regla_id
                                ? 'Cambiar responsable'
                                : 'Asignar otro responsable'
                        }}
                    </Button>
                </li>
            </ul>
            <Button
                v-if="personasFiltradas.length > personasVisibles.length"
                variant="outline"
                class="self-center"
                @click="limite += 48"
            >
                Mostrar más ({{
                    personasFiltradas.length - personasVisibles.length
                }}
                restantes)
            </Button>
        </section>
    </div>

    <Dialog v-model:open="dialogo">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <span
                        class="flex size-8 items-center justify-center rounded-full bg-salvia text-primary"
                    >
                        <UserCheck class="size-4" />
                    </span>
                    Responsable de aprobación
                </DialogTitle>
                <DialogDescription>
                    No cambia el organigrama ni el jefe organizacional: solo
                    decide quién da el visto bueno de sus solicitudes. Queda en
                    la bitácora con tu motivo.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div
                    class="grid grid-cols-2 gap-1 rounded-xl bg-muted p-1 text-sm"
                    role="tablist"
                >
                    <button
                        v-for="op in [
                            { v: 'persona', t: 'Una persona' },
                            { v: 'puesto', t: 'Todo un puesto' },
                        ] as const"
                        :key="op.v"
                        type="button"
                        role="tab"
                        :aria-selected="form.ambito === op.v"
                        class="rounded-lg px-3 py-2 font-medium transition-colors"
                        :class="
                            form.ambito === op.v
                                ? 'bg-card shadow-sm'
                                : 'text-muted-foreground'
                        "
                        @click="form.ambito = op.v"
                    >
                        {{ op.t }}
                    </button>
                </div>

                <div class="grid gap-1.5">
                    <p class="text-sm font-medium">
                        {{
                            form.ambito === 'persona'
                                ? '¿De quién?'
                                : '¿De qué puesto?'
                        }}
                    </p>
                    <BuscadorPersonaOrganigrama
                        v-if="form.ambito === 'persona'"
                        v-model="form.colaborador_id"
                        :items="candidatos"
                    />
                    <BuscadorPersonaOrganigrama
                        v-else
                        v-model="form.puesto_id"
                        :items="puestos"
                    />
                    <p
                        v-if="
                            form.errors.colaborador_id || form.errors.puesto_id
                        "
                        class="text-sm text-destructive"
                    >
                        {{
                            form.errors.colaborador_id ?? form.errors.puesto_id
                        }}
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <p class="text-sm font-medium">¿Quién aprobará?</p>
                    <BuscadorPersonaOrganigrama
                        v-model="form.responsable_colaborador_id"
                        :items="candidatos"
                        :excluir="
                            form.ambito === 'persona' && form.colaborador_id
                                ? [form.colaborador_id]
                                : []
                        "
                    />
                    <p
                        v-if="form.errors.responsable_colaborador_id"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.responsable_colaborador_id }}
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <label for="motivo-responsable" class="text-sm font-medium"
                        >Motivo</label
                    >
                    <Textarea
                        id="motivo-responsable"
                        v-model="form.motivo"
                        rows="2"
                        placeholder="Ej.: Alberto es su jefe operativo; Dirección General no aprueba solicitudes."
                    />
                    <p
                        v-if="form.errors.motivo"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.motivo }}
                    </p>
                </div>
            </div>

            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="dialogo = false"
                    >Cancelar</Button
                >
                <Button
                    :disabled="form.processing || !puedeGuardar"
                    @click="guardar"
                >
                    <Spinner v-if="form.processing" />
                    Guardar responsable
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
