<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Briefcase,
    Building2,
    Check,
    Contact,
    Copy,
    Download,
    Layers,
    Mail,
    MailX,
    Pencil,
    Phone,
    Search,
    SearchX,
    Store,
    Users,
    X,
} from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import EdicionFilaDirectorio from '@/components/Directorio/EdicionFilaDirectorio.vue';
import type { OpcionesEdicionDirectorio } from '@/components/Directorio/EdicionFilaDirectorio.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { excel, pdf } from '@/routes/directorio/exportar';

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
    departamento_id: number | null;
    /** Los gestores no tienen correo corporativo (lleva_correo_corporativo = false). */
    correo_corporativo: string | null;
    correo_personal: string | null;
    /** Teléfono de la persona: la empresa no asigna líneas. */
    telefono: string | null;
    lleva_correo_corporativo?: boolean;
    /** El backend solo manda el contacto a quien puede verlo. */
    contacto_visible?: boolean;
    /** Le falta correo, teléfono, puesto o sucursal. Solo llega a RH (ve todos); si no, null. */
    datos_por_completar?: boolean | null;
    es_mi_jefe?: boolean;
    /** Foto oficial del expediente (URL protegida); null → iniciales. */
    foto_url?: string | null;
    /** Solo si quien mira alcanza ese expediente. */
    expediente_url?: string | null;
};

type Vista = 'puesto' | 'area' | 'sucursal';

const props = defineProps<{
    colaboradores: ColaboradorDirectorio[];
    filtros: { busqueda?: string; vista?: string };
    veTodosLosContactos?: boolean;
    puedeExportar?: boolean;
    /** Solo llega a quien puede editar (directorio.editar); si no, null. */
    edicion?: {
        opciones: OpcionesEdicionDirectorio;
        editables: number[];
    } | null;
}>();

// Copia local: la edición rápida reemplaza la fila guardada sin recargar.
const lista = ref<ColaboradorDirectorio[]>([...props.colaboradores]);

watch(
    () => props.colaboradores,
    (nuevos) => {
        lista.value = [...nuevos];
    },
);

const editables = computed(() => new Set(props.edicion?.editables ?? []));
const editandoId = ref<number | null>(null);

function alGuardar(id: number, fila: Record<string, unknown> | null) {
    editandoId.value = null;
    lista.value = fila
        ? lista.value.map((c) =>
              c.id === id ? (fila as unknown as ColaboradorDirectorio) : c,
          )
        : lista.value.filter((c) => c.id !== id);
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Directorio', href: '' },
        ],
    },
});

const VISTAS: { valor: Vista; etiqueta: string; icono: Component }[] = [
    { valor: 'puesto', etiqueta: 'Puesto', icono: Briefcase },
    { valor: 'area', etiqueta: 'Área', icono: Layers },
    { valor: 'sucursal', etiqueta: 'Sucursal', icono: Store },
];

const ICONO_GRUPO: Record<Vista, Component> = {
    puesto: Briefcase,
    area: Layers,
    sucursal: Store,
};

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
/** Filtro RH: solo quienes tienen datos por completar. */
const soloPorCompletar = ref(false);

/** Cada grupo muestra pocas personas hasta pedir «Ver los N» (pág. 5). */
const PERSONAS_POR_GRUPO = 4;
const gruposAbiertos = ref(new Set<string>());

/** Al buscar o filtrar se ve todo: lo que coincide no debe quedar plegado. */
function personasVisibles(grupo: Grupo): ColaboradorDirectorio[] {
    return busqueda.value.trim() !== '' ||
        soloPorCompletar.value ||
        gruposAbiertos.value.has(grupo.clave)
        ? grupo.personas
        : grupo.personas.slice(0, PERSONAS_POR_GRUPO);
}

function abrirGrupo(clave: string) {
    gruposAbiertos.value = new Set(gruposAbiertos.value).add(clave);
}

const consultaExportacion = computed(() => ({
    query: {
        ...(busqueda.value.trim() !== '' ? { busqueda: busqueda.value } : {}),
        ...(soloPorCompletar.value ? { solo_por_completar: '1' } : {}),
    },
}));

/** Etiqueta de lo que falta, como en la propuesta («Sin correo», etc.). */
function faltantes(c: ColaboradorDirectorio): string {
    const lista = [
        !c.telefono && 'teléfono',
        !c.correo_personal && 'correo personal',
        c.lleva_correo_corporativo !== false &&
            !c.correo_corporativo &&
            'correo corporativo',
        !c.puesto_id && 'puesto',
        !c.sucursal_id && 'sucursal',
    ].filter(Boolean);

    return `Sin ${lista.join(' ni ')}`;
}

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
    const base =
        soloPorCompletar.value && props.veTodosLosContactos
            ? lista.value.filter((c) => c.datos_por_completar === true)
            : lista.value;
    const palabras = plegar(busqueda.value.trim()).split(/\s+/).filter(Boolean);

    if (palabras.length === 0) {
        return base;
    }

    return base.filter((c) => {
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

/** En móvil las tres tarjetas van sin ícono para que la etiqueta quepa. */
const conIconos = useMediaQuery('(min-width: 640px)');

/** Persona que se está editando (diálogo; pantalla completa en móvil). */
const editando = computed(
    () => lista.value.find((c) => c.id === editandoId.value) ?? null,
);

// KPIs sobre toda la plantilla activa visible (no sobre la búsqueda), para que
// coincidan con el Tablero. «Sucursales» cuenta PERSONAS en sucursales, no el
// número de sucursales (propuesta Etapa 1): Corporativo + Sucursales = activas.
const totales = computed(() => {
    const todos = lista.value;
    const corporativo = todos.filter((c) => c.es_corporativo).length;

    return {
        personas: todos.length,
        corporativo,
        sucursales: todos.length - corporativo,
        porCompletar: todos.filter((c) => c.datos_por_completar === true)
            .length,
    };
});

type DatoContacto = {
    clave: 'telefono' | 'correo-corporativo' | 'correo-personal';
    etiqueta: string;
    /** Nombre completo del dato, para lectores de pantalla. */
    nombre: string;
    valor: string;
};

/** Contacto con su etiqueta literal; solo lo que la persona tiene capturado. */
function contactos(c: ColaboradorDirectorio): DatoContacto[] {
    const datos: DatoContacto[] = [];

    if (c.telefono) {
        datos.push({
            clave: 'telefono',
            etiqueta: 'Teléfono',
            nombre: 'el teléfono',
            valor: c.telefono,
        });
    }

    if (c.correo_corporativo) {
        datos.push({
            clave: 'correo-corporativo',
            etiqueta: 'Correo corp.',
            nombre: 'el correo corporativo',
            valor: c.correo_corporativo,
        });
    }

    if (c.correo_personal) {
        datos.push({
            clave: 'correo-personal',
            etiqueta: 'Correo pers.',
            nombre: 'el correo personal',
            valor: c.correo_personal,
        });
    }

    return datos;
}

/** `${id}:${clave}` del dato recién copiado (muestra la palomita un momento). */
const copiado = ref<string | null>(null);

async function copiar(id: number, dato: DatoContacto): Promise<void> {
    const marca = `${id}:${dato.clave}`;

    try {
        await navigator.clipboard.writeText(dato.valor);
        copiado.value = marca;
        window.setTimeout(() => {
            if (copiado.value === marca) {
                copiado.value = null;
            }
        }, 1600);
    } catch {
        // Sin permiso de portapapeles: el dato sigue a la vista para copiarlo a mano.
        copiado.value = null;
    }
}

function puedeEditar(c: ColaboradorDirectorio): boolean {
    return Boolean(props.edicion) && editables.value.has(c.id);
}

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
        <div class="flex flex-wrap items-center justify-between gap-3">
            <CrudPageHeader titulo="Directorio" :icono="Contact" />
            <div v-if="puedeExportar" class="ml-auto flex gap-2">
                <Button variant="outline" size="sm" as-child>
                    <a :href="excel(consultaExportacion).url">
                        <Download class="size-4" /> Excel
                    </a>
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <a :href="pdf(consultaExportacion).url">
                        <Download class="size-4" /> PDF
                    </a>
                </Button>
            </div>
        </div>

        <!-- Conteos -->
        <section
            class="grid gap-2.5 sm:gap-3"
            :class="
                veTodosLosContactos
                    ? 'grid-cols-2 sm:grid-cols-4'
                    : 'grid-cols-3'
            "
            aria-label="Resumen del directorio"
        >
            <TarjetaMetrica
                etiqueta="Personas activas"
                :valor="totales.personas"
                :icono="conIconos ? Users : null"
                tono="primario"
                detalle="Total de la plantilla activa"
            />
            <TarjetaMetrica
                etiqueta="Corporativo"
                :valor="totales.corporativo"
                :icono="conIconos ? Building2 : null"
                tono="oro"
                detalle="Personas en Corporativo"
            />
            <TarjetaMetrica
                etiqueta="Sucursales"
                :valor="totales.sucursales"
                :icono="conIconos ? Store : null"
                tono="info"
                detalle="Personas en sucursales"
            />
            <TarjetaMetrica
                v-if="veTodosLosContactos"
                etiqueta="Datos por completar"
                :valor="totales.porCompletar"
                :icono="conIconos ? MailX : null"
                tono="alerta"
                detalle="Aparecen con aviso, no se ocultan"
                clicable
                :activa="soloPorCompletar"
                @click="soloPorCompletar = !soloPorCompletar"
            />
        </section>

        <!-- Organizar + buscar -->
        <section
            class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3 shadow-sm sm:p-4 lg:flex-row lg:items-center"
        >
            <div class="flex items-center gap-2">
                <div
                    class="grid flex-1 grid-cols-3 rounded-xl bg-[var(--mrl-fondo)] p-1 lg:flex-none"
                    role="tablist"
                    aria-label="Organizar directorio"
                >
                    <button
                        v-for="v in VISTAS"
                        :key="v.valor"
                        type="button"
                        role="tab"
                        :aria-selected="vista === v.valor"
                        class="flex min-h-10 items-center justify-center gap-1.5 rounded-lg px-3 text-sm font-medium transition-colors lg:px-4"
                        :class="
                            vista === v.valor
                                ? 'bg-[var(--mrl-superficie)] text-primary shadow-sm'
                                : 'text-muted-foreground hover:text-[var(--mrl-texto)]'
                        "
                        @click="vista = v.valor"
                    >
                        <component :is="v.icono" class="size-4 shrink-0" />
                        {{ v.etiqueta }}
                    </button>
                </div>
                <AyudaBoton
                    titulo="Directorio"
                    :texto="
                        veTodosLosContactos === false
                            ? 'Tu círculo de contacto: la gerencia y la coordinación de tu sucursal y la gerencia regional, con su teléfono y sus correos (personal y corporativo).'
                            : 'Teléfono, correo personal y correo corporativo del personal de corporativo y de las sucursales (los gestores no tienen correo corporativo). Copia cualquier dato con el botón de copiar, o toca el ícono de correo o teléfono para escribir o llamar.'
                    "
                />
            </div>
            <div class="relative min-w-0 flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="busqueda"
                    type="search"
                    placeholder="Buscar nombre, puesto o sucursal…"
                    class="h-11 pr-10 pl-10 text-base"
                    aria-label="Buscar en el directorio"
                />
                <button
                    v-if="busqueda"
                    type="button"
                    class="absolute top-1/2 right-2 flex size-7 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground hover:bg-accent"
                    aria-label="Limpiar búsqueda"
                    @click="busqueda = ''"
                >
                    <X class="size-4" />
                </button>
            </div>
            <Button
                v-if="veTodosLosContactos"
                type="button"
                :variant="soloPorCompletar ? 'default' : 'outline'"
                :aria-pressed="soloPorCompletar"
                class="h-11"
                @click="soloPorCompletar = !soloPorCompletar"
            >
                Solo datos por completar
            </Button>
        </section>

        <EmptyState
            v-if="grupos.length === 0"
            :icono="SearchX"
            titulo="Sin resultados"
            descripcion="No se encontró a nadie con esa búsqueda."
        />

        <!-- Grupos en columnas (tipo mosaico): aprovecha todo el ancho -->
        <div
            v-else
            class="columns-1 gap-4 min-[1800px]:columns-4 md:columns-2 xl:columns-3"
        >
            <section
                v-for="grupo in grupos"
                :key="grupo.clave"
                class="mb-4 break-inside-avoid overflow-hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] shadow-sm"
                :aria-label="grupo.titulo"
            >
                <header
                    class="flex items-center gap-3 border-b border-[var(--mrl-borde)] bg-crema/40 px-4 py-3"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                        aria-hidden="true"
                    >
                        <component :is="ICONO_GRUPO[vista]" class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2
                            class="truncate text-sm font-semibold text-[var(--mrl-texto)]"
                            :title="grupo.titulo"
                        >
                            {{ grupo.titulo }}
                        </h2>
                        <p
                            v-if="grupo.subtitulo"
                            class="text-xs text-muted-foreground"
                        >
                            {{ grupo.subtitulo }}
                        </p>
                    </div>
                    <span
                        class="rounded-full bg-card px-2.5 py-0.5 text-sm font-bold text-primary tabular-nums"
                        >{{ grupo.personas.length }}</span
                    >
                </header>

                <ul class="divide-y divide-[var(--mrl-borde)]">
                    <li
                        v-for="c in personasVisibles(grupo)"
                        :key="c.id"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <ColaboradorAvatar
                            :nombre="c.nombre"
                            :foto-url="c.foto_url"
                            tamano="lg"
                        />
                        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <p
                                class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm font-semibold text-[var(--mrl-texto)]"
                            >
                                <span class="min-w-0 truncate">{{
                                    c.nombre
                                }}</span>
                                <span
                                    v-if="c.es_mi_jefe"
                                    class="rounded-full bg-salvia px-2 py-0.5 text-[11px] font-medium text-primary"
                                    >Tu jefe</span
                                >
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ detalle(c) }}
                            </p>
                            <span
                                v-if="c.datos_por_completar === true"
                                class="mt-0.5 inline-flex w-fit items-center gap-1 rounded-full bg-warning-soft px-2 py-0.5 text-[11px] font-medium text-warning"
                            >
                                <MailX class="size-3" />
                                Datos por completar · {{ faltantes(c) }}
                            </span>
                            <Link
                                v-if="c.expediente_url"
                                :href="c.expediente_url"
                                class="mt-0.5 w-fit text-xs font-medium text-primary hover:underline"
                                >Abrir expediente</Link
                            >
                            <dl
                                v-if="contactos(c).length > 0"
                                class="mt-1 flex flex-col gap-0.5"
                            >
                                <div
                                    v-for="dato in contactos(c)"
                                    :key="dato.clave"
                                    class="flex min-w-0 items-center gap-1.5 text-xs"
                                    :data-contacto="dato.clave"
                                >
                                    <dt
                                        class="w-[5.25rem] shrink-0 text-muted-foreground"
                                    >
                                        {{ dato.etiqueta }}:
                                    </dt>
                                    <dd
                                        class="min-w-0 flex-1 truncate font-medium text-[var(--mrl-texto)]"
                                        :class="
                                            dato.clave === 'telefono'
                                                ? 'tabular-nums'
                                                : ''
                                        "
                                        :title="dato.valor"
                                    >
                                        {{ dato.valor }}
                                    </dd>
                                    <button
                                        type="button"
                                        class="flex size-7 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-[var(--mrl-fondo)] hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        :aria-label="
                                            copiado === `${c.id}:${dato.clave}`
                                                ? 'Copiado'
                                                : `Copiar ${dato.nombre} de ${c.nombre}`
                                        "
                                        :title="
                                            copiado === `${c.id}:${dato.clave}`
                                                ? 'Copiado'
                                                : 'Copiar'
                                        "
                                        @click="copiar(c.id, dato)"
                                    >
                                        <Check
                                            v-if="
                                                copiado ===
                                                `${c.id}:${dato.clave}`
                                            "
                                            class="size-3.5 text-success"
                                        />
                                        <Copy v-else class="size-3.5" />
                                    </button>
                                </div>
                            </dl>
                        </div>

                        <div class="flex shrink-0 items-center gap-1.5">
                            <a
                                v-if="c.correo_corporativo || c.correo_personal"
                                :href="`mailto:${c.correo_corporativo || c.correo_personal}`"
                                class="flex size-10 items-center justify-center rounded-xl bg-info-soft text-info transition-colors hover:bg-info-soft/70"
                                :aria-label="`Escribir a ${c.nombre}`"
                                :title="
                                    c.correo_corporativo ||
                                    c.correo_personal ||
                                    undefined
                                "
                            >
                                <Mail class="size-5" />
                            </a>
                            <a
                                v-if="c.telefono"
                                :href="`tel:${c.telefono}`"
                                class="flex size-10 items-center justify-center rounded-xl bg-success-soft text-success transition-colors hover:bg-success-soft/70"
                                :aria-label="`Llamar a ${c.nombre}`"
                                :title="c.telefono"
                            >
                                <Phone class="size-5" />
                            </a>
                            <span
                                v-if="
                                    !c.correo_corporativo &&
                                    !c.correo_personal &&
                                    !c.telefono &&
                                    c.contacto_visible !== false &&
                                    c.datos_por_completar !== true
                                "
                                class="flex size-10 items-center justify-center rounded-xl bg-muted/70 text-muted-foreground/70"
                                title="Sin datos de contacto"
                                aria-label="Sin datos de contacto"
                                role="img"
                                ><MailX class="size-4"
                            /></span>
                            <button
                                v-if="puedeEditar(c)"
                                type="button"
                                class="flex size-10 items-center justify-center rounded-xl text-muted-foreground transition-colors hover:bg-[var(--mrl-fondo)] hover:text-[var(--mrl-texto)]"
                                :aria-label="`Editar a ${c.nombre}`"
                                title="Editar"
                                @click="editandoId = c.id"
                            >
                                <Pencil class="size-4" />
                            </button>
                        </div>
                    </li>
                </ul>
                <button
                    v-if="
                        personasVisibles(grupo).length < grupo.personas.length
                    "
                    type="button"
                    class="w-full border-t border-[var(--mrl-borde)] px-4 py-2.5 text-center text-xs font-medium text-primary hover:bg-[var(--mrl-fondo)]"
                    @click="abrirGrupo(grupo.clave)"
                >
                    Ver los {{ grupo.personas.length }} →
                </button>
            </section>
        </div>
    </div>

    <!-- Edición rápida: diálogo (pantalla completa en móvil) -->
    <Dialog
        :open="editando !== null && Boolean(edicion)"
        @update:open="(v: boolean) => !v && (editandoId = null)"
    >
        <DialogContent
            class="max-h-[100svh] content-start overflow-y-auto max-sm:h-[100svh] max-sm:max-w-none max-sm:rounded-none sm:max-h-[90vh] sm:max-w-xl"
        >
            <DialogHeader>
                <DialogTitle class="flex items-center gap-3">
                    <ColaboradorAvatar
                        v-if="editando"
                        :nombre="editando.nombre"
                        :foto-url="editando.foto_url"
                        tamano="md"
                    />
                    <span class="min-w-0 truncate">{{ editando?.nombre }}</span>
                </DialogTitle>
                <DialogDescription>
                    Teléfono, correos, puesto, sucursal y departamento.
                </DialogDescription>
            </DialogHeader>
            <EdicionFilaDirectorio
                v-if="editando && edicion"
                :key="`edicion-${editando.id}`"
                :fila="editando"
                :opciones="edicion.opciones"
                @cancelar="editandoId = null"
                @guardado="(fila) => editando && alGuardar(editando.id, fila)"
            />
        </DialogContent>
    </Dialog>
</template>
