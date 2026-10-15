<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Contact, Mail, Phone, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';

type ColaboradorDirectorio = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    puesto: string | null;
    puesto_id: number | null;
    nivel: number;
    sucursal: string | null;
    sucursal_id: number | null;
    es_corporativo: boolean;
    area: string;
    departamento: string | null;
    correo_corporativo: string | null;
    telefono_corporativo: string | null;
    /** El backend solo manda el contacto a quien puede verlo. */
    contacto_visible?: boolean;
    es_mi_jefe?: boolean;
};

type Vista = 'puesto' | 'area' | 'sucursal';

const props = defineProps<{
    colaboradores: ColaboradorDirectorio[];
    filtros: { busqueda?: string; vista?: string };
    veTodosLosContactos?: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Directorio', href: '' },
        ],
    },
});

const VISTAS: { valor: Vista; etiqueta: string }[] = [
    { valor: 'puesto', etiqueta: 'Por puesto' },
    { valor: 'area', etiqueta: 'Por área' },
    { valor: 'sucursal', etiqueta: 'Por sucursal' },
];

function vistaGuardada(): Vista {
    const deUrl = props.filtros.vista;

    if (deUrl === 'puesto' || deUrl === 'area' || deUrl === 'sucursal') {
        return deUrl;
    }

    try {
        const guardada = localStorage.getItem('directorio.vista');

        if (
            guardada === 'puesto' ||
            guardada === 'area' ||
            guardada === 'sucursal'
        ) {
            return guardada;
        }
    } catch {
        // Sin almacenamiento local (modo privado): vista por defecto.
    }

    return 'puesto';
}

const vista = ref<Vista>(vistaGuardada());
const busqueda = ref(props.filtros.busqueda ?? '');

watch(vista, (valor) => {
    try {
        localStorage.setItem('directorio.vista', valor);
    } catch {
        // Preferencia opcional: si no se puede guardar, no pasa nada.
    }
});

function plegar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const filtrados = computed(() => {
    const palabras = plegar(busqueda.value.trim()).split(/\s+/).filter(Boolean);

    if (palabras.length === 0) {
        return props.colaboradores;
    }

    return props.colaboradores.filter((c) => {
        const texto = plegar(
            [c.nombre, c.puesto, c.sucursal, c.area, c.numero_empleado]
                .filter(Boolean)
                .join(' '),
        );

        return palabras.every((p) => texto.includes(p));
    });
});

type Grupo = {
    clave: string;
    titulo: string;
    subtitulo?: string;
    orden: [number, number, string];
    personas: ColaboradorDirectorio[];
};

function porJerarquia(
    a: ColaboradorDirectorio,
    b: ColaboradorDirectorio,
): number {
    return a.nivel - b.nivel || a.nombre.localeCompare(b.nombre, 'es');
}

const grupos = computed<Grupo[]>(() => {
    const mapa = new Map<string, Grupo>();

    for (const c of filtrados.value) {
        let clave: string;
        let titulo: string;
        let orden: [number, number, string];

        if (vista.value === 'puesto') {
            clave = `p${c.puesto_id ?? 0}`;
            titulo = c.puesto ?? 'Sin puesto';
            orden = [c.nivel, 0, titulo];
        } else if (vista.value === 'area') {
            // Corporativo primero (Sistemas, Contraloría, RH…), luego los
            // puestos de sucursal (Gerentes, Subgerentes, Coordinadoras…).
            clave = `${c.es_corporativo ? 'c' : 's'}:${c.area}`;
            titulo = c.area;
            orden = [c.es_corporativo ? 0 : 1, c.nivel, titulo];
        } else {
            clave = `s${c.sucursal_id ?? 0}`;
            titulo = c.sucursal ?? 'Sin sucursal';
            orden = [c.es_corporativo ? 0 : c.sucursal_id ? 1 : 2, 0, titulo];
        }

        const grupo = mapa.get(clave) ?? { clave, titulo, orden, personas: [] };

        // El grupo se ordena por su integrante de mayor jerarquía.
        if (
            orden[0] < grupo.orden[0] ||
            (orden[0] === grupo.orden[0] && orden[1] < grupo.orden[1])
        ) {
            grupo.orden = orden;
        }

        grupo.personas.push(c);
        mapa.set(clave, grupo);
    }

    return [...mapa.values()]
        .map((g) => ({
            ...g,
            subtitulo:
                vista.value === 'area'
                    ? g.clave.startsWith('c')
                        ? 'Corporativo'
                        : 'Sucursales'
                    : undefined,
            personas: [...g.personas].sort(porJerarquia),
        }))
        .sort(
            (a, b) =>
                a.orden[0] - b.orden[0] ||
                a.orden[1] - b.orden[1] ||
                a.orden[2].localeCompare(b.orden[2], 'es'),
        );
});

function detalle(c: ColaboradorDirectorio): string {
    if (vista.value === 'puesto') {
        return c.sucursal ?? 'Sin sucursal';
    }

    if (vista.value === 'area') {
        return c.es_corporativo
            ? (c.puesto ?? 'Sin puesto')
            : (c.sucursal ?? 'Sin sucursal');
    }

    return c.puesto ?? 'Sin puesto';
}
</script>

<template>
    <Head title="Directorio" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Directorio"
            :descripcion="
                veTodosLosContactos === false
                    ? 'Nombre, puesto, área y sucursal de la empresa. Ves el correo y teléfono corporativos de tu jefe directo.'
                    : 'Correo y teléfono corporativo del personal de corporativo y de las sucursales.'
            "
            :icono="Contact"
        />

        <section
            class="flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <div
                class="inline-flex rounded-xl bg-[var(--mrl-fondo)] p-1"
                role="tablist"
                aria-label="Organizar directorio"
            >
                <button
                    v-for="v in VISTAS"
                    :key="v.valor"
                    type="button"
                    role="tab"
                    :aria-selected="vista === v.valor"
                    class="min-h-9 rounded-lg px-4 text-sm font-medium transition-colors"
                    :class="
                        vista === v.valor
                            ? 'bg-[var(--mrl-superficie)] text-[var(--mrl-texto)] shadow-sm'
                            : 'text-muted-foreground hover:text-[var(--mrl-texto)]'
                    "
                    @click="vista = v.valor"
                >
                    {{ v.etiqueta }}
                </button>
            </div>
            <div class="relative min-w-[220px] flex-1">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="busqueda"
                    placeholder="Buscar por nombre, puesto o sucursal…"
                    class="pl-9"
                />
            </div>
            <p class="text-xs text-muted-foreground">
                {{ filtrados.length }} persona(s)
            </p>
        </section>

        <section
            v-for="grupo in grupos"
            :key="grupo.clave"
            class="flex flex-col gap-3"
            :aria-label="grupo.titulo"
        >
            <header
                class="flex items-baseline gap-2 border-b border-[var(--mrl-borde)] pb-1"
            >
                <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                    {{ grupo.titulo }}
                </h2>
                <span class="text-xs text-muted-foreground">
                    {{ grupo.personas.length }}
                    <template v-if="grupo.subtitulo">
                        · {{ grupo.subtitulo }}</template
                    >
                </span>
            </header>

            <div
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
            >
                <article
                    v-for="c in grupo.personas"
                    :key="c.id"
                    class="flex flex-col gap-1.5 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                >
                    <p class="text-sm font-semibold text-[var(--mrl-texto)]">
                        {{ c.nombre }}
                        <span
                            v-if="c.es_mi_jefe"
                            class="ml-1 rounded-full bg-salvia px-2 py-0.5 text-[10px] font-medium text-[var(--mrl-petroleo)]"
                            >Tu jefe directo</span
                        >
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ detalle(c) }}
                    </p>

                    <div class="mt-1 flex flex-col gap-1 text-sm">
                        <a
                            v-if="c.correo_corporativo"
                            :href="`mailto:${c.correo_corporativo}`"
                            class="flex items-center gap-1.5 break-all text-[var(--mrl-primary)] hover:underline"
                        >
                            <Mail class="size-3.5 shrink-0" />
                            {{ c.correo_corporativo }}
                        </a>
                        <p
                            v-else-if="c.contacto_visible !== false"
                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <Mail class="size-3.5" /> Sin correo corporativo
                        </p>

                        <a
                            v-if="c.telefono_corporativo"
                            :href="`tel:${c.telefono_corporativo}`"
                            class="flex items-center gap-1.5 text-[var(--mrl-texto)] hover:underline"
                        >
                            <Phone class="size-3.5" />
                            {{ c.telefono_corporativo }}
                        </a>
                    </div>
                </article>
            </div>
        </section>

        <p
            v-if="grupos.length === 0"
            class="py-10 text-center text-sm text-muted-foreground"
        >
            No se encontró a nadie con esa búsqueda.
        </p>
    </div>
</template>
