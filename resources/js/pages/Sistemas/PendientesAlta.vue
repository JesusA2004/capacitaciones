<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Briefcase,
    CalendarDays,
    ChevronDown,
    CircleCheckBig,
    ClipboardCopy,
    ClockAlert,
    Copy,
    CopyCheck,
    FolderOpen,
    Hash,
    Hourglass,
    Info,
    MailCheck,
    MailWarning,
    MailX,
    MapPin,
    MonitorCheck,
    Search,
    Send,
    Undo2,
    UserRoundCheck,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
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
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import SelectorVista from '@/components/ui/SelectorVista.vue';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ICONO_SECCION_ALTA, iconoDatoAlta } from '@/lib/altaSistemas';
import { dashboard } from '@/routes';
import {
    atender,
    index,
    reabrir,
    reenviar,
} from '@/routes/sistemas/pendientes-alta';
import type {
    EstadoCorreoAltaSistemas,
    FiltroPendientesAlta,
    PendienteAltaSistemas,
    TotalesPendientesAlta,
} from '@/types';

/**
 * Bandeja «Pendientes de alta en sistemas»: quién entró a contratación y
 * todavía no tiene sus usuarios en las demás plataformas. Sistemas copia
 * sus datos, le da de alta y lo marca como atendido (quién y cuándo queda
 * registrado). PendientesAltaSistemasService.
 */
const props = defineProps<{
    pendientes: {
        data: PendienteAltaSistemas[];
        current_page: number;
        last_page: number;
        per_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    totales: TotalesPendientesAlta;
    filtros: { filtro: FiltroPendientesAlta; buscar: string };
    correoActivo: boolean;
    puedeAtender: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Pendientes de alta en sistemas', href: index.url() },
        ],
    },
});

const filtro = ref<FiltroPendientesAlta>(props.filtros.filtro);
const buscar = ref(props.filtros.buscar ?? '');

const opciones = computed(() => [
    {
        valor: 'pendientes' as const,
        etiqueta: 'Pendientes',
        contador: props.totales.pendientes,
    },
    { valor: 'atendidos' as const, etiqueta: 'Atendidos' },
    { valor: 'todos' as const, etiqueta: 'Todos' },
]);

function visitar(): void {
    router.get(
        index.url({
            query: {
                filtro: filtro.value,
                ...(buscar.value.trim() !== ''
                    ? { buscar: buscar.value.trim() }
                    : {}),
            },
        }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const buscarConPausa = useDebounceFn(visitar, 350);

watch(filtro, visitar);
watch(buscar, () => buscarConPausa());

// ─── Indicadores ───
const indicadores = computed(() => [
    {
        clave: 'pendientes' as const,
        icono: Hourglass,
        valor: props.totales.pendientes,
        texto: 'Por dar de alta',
        clases: 'bg-info-soft text-info',
    },
    {
        clave: 'pendientes' as const,
        icono: ClockAlert,
        valor: props.totales.atrasados,
        texto: 'Con 3 días o más',
        clases:
            props.totales.atrasados > 0
                ? 'bg-warning-soft text-warning'
                : 'bg-crema text-bronce',
    },
    {
        clave: 'atendidos' as const,
        icono: CircleCheckBig,
        valor: props.totales.atendidos,
        texto: 'Ya dados de alta',
        clases: 'bg-success-soft text-success',
    },
]);

// ─── Estado del correo ───
const ESTADO_CORREO: Record<
    EstadoCorreoAltaSistemas,
    { icono: Component; clases: string }
> = {
    enviado: { icono: MailCheck, clases: 'bg-success-soft text-success' },
    pendiente: { icono: Hourglass, clases: 'bg-warning-soft text-warning' },
    fallido: { icono: MailWarning, clases: 'bg-danger-soft text-destructive' },
    sin_enviar: { icono: MailX, clases: 'bg-crema text-bronce' },
};

function clasesDias(fila: PendienteAltaSistemas): string {
    if (fila.atendido) {
        return 'bg-success-soft text-success';
    }

    if (fila.dias >= 7) {
        return 'bg-danger-soft text-destructive';
    }

    return fila.dias >= 3
        ? 'bg-warning-soft text-warning'
        : 'bg-info-soft text-info';
}

function textoDias(fila: PendienteAltaSistemas): string {
    const dias =
        fila.dias === 0
            ? 'hoy'
            : fila.dias === 1
              ? '1 día'
              : `${fila.dias} días`;

    if (fila.atendido) {
        return fila.dias === 0
            ? 'Atendido el mismo día'
            : `Atendido en ${dias}`;
    }

    return fila.dias === 0 ? 'Llegó hoy' : `Pendiente hace ${dias}`;
}

// ─── Ver datos / copiar ───
const abiertos = ref<Set<number>>(new Set());
const copiado = ref<number | null>(null);

function alternar(id: number): void {
    const siguiente = new Set(abiertos.value);

    if (siguiente.has(id)) {
        siguiente.delete(id);
    } else {
        siguiente.add(id);
    }

    abiertos.value = siguiente;
}

async function copiar(fila: PendienteAltaSistemas): Promise<void> {
    try {
        await navigator.clipboard.writeText(fila.texto_copiar);
        copiado.value = fila.id;
        window.setTimeout(() => {
            if (copiado.value === fila.id) {
                copiado.value = null;
            }
        }, 1800);
    } catch {
        copiado.value = null;
    }
}

// ─── Acciones ───
const procesando = ref<number | null>(null);
const marcando = ref<PendienteAltaSistemas | null>(null);
const notas = ref('');

function abrirMarcar(fila: PendienteAltaSistemas): void {
    marcando.value = fila;
    notas.value = '';
}

function confirmarMarcar(): void {
    const fila = marcando.value;

    if (!fila) {
        return;
    }

    procesando.value = fila.id;
    router.post(
        atender.url(fila.id),
        { notas: notas.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                marcando.value = null;
            },
            onFinish: () => {
                procesando.value = null;
            },
        },
    );
}

function accion(url: string, fila: PendienteAltaSistemas): void {
    procesando.value = fila.id;
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                procesando.value = null;
            },
        },
    );
}

const vacio = computed(() => {
    if (buscar.value.trim() !== '') {
        return {
            titulo: 'Sin resultados',
            descripcion: 'Nadie coincide con esa búsqueda.',
        };
    }

    return filtro.value === 'pendientes'
        ? {
              titulo: '¡Todo al día!',
              descripcion:
                  'No hay nadie pendiente de alta en sistemas. Cuando alguien entre a contratación aparecerá aquí y te llegará un aviso.',
          }
        : {
              titulo: 'Aún no hay altas registradas',
              descripcion:
                  'Aquí verás a quién ya se dio de alta, quién lo hizo y cuándo.',
          };
});
</script>

<template>
    <Head title="Pendientes de alta en sistemas" />

    <div class="pagina-ancha flex flex-col gap-4">
        <CrudPageHeader
            titulo="Pendientes de alta en sistemas"
            :icono="MonitorCheck"
        />

        <!-- Indicadores (clic = filtra) -->
        <div class="grid gap-3 sm:grid-cols-3">
            <button
                v-for="(ind, i) in indicadores"
                :key="i"
                type="button"
                class="flex tarjeta-interactiva items-center gap-3 rounded-2xl border bg-card p-4 text-left shadow-xs"
                @click="filtro = ind.clave"
            >
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl"
                    :class="ind.clases"
                    aria-hidden="true"
                >
                    <component :is="ind.icono" class="size-5" />
                </span>
                <span class="flex flex-col">
                    <span
                        class="text-2xl leading-none font-semibold tabular-nums"
                    >
                        {{ ind.valor }}
                    </span>
                    <span class="text-sm text-muted-foreground">
                        {{ ind.texto }}
                    </span>
                </span>
            </button>
        </div>

        <p
            v-if="!correoActivo"
            class="flex items-start gap-2 rounded-xl bg-warning-soft px-3 py-2 text-sm text-warning"
        >
            <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            El correo a Sistemas está apagado en este servidor. La bandeja y los
            avisos en PEOPLE funcionan igual.
        </p>

        <!-- Filtros -->
        <div class="flex flex-wrap items-center gap-3">
            <SelectorVista
                v-model="filtro"
                :opciones="opciones"
                etiqueta="Qué mostrar"
            />
            <div class="relative w-full sm:w-72">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="buscar"
                    type="search"
                    class="pl-9"
                    placeholder="Nombre o número de empleado"
                    aria-label="Buscar persona"
                />
            </div>
        </div>

        <CambioSuave>
            <CrudEmptyState
                v-if="pendientes.data.length === 0"
                key="vacio"
                :icono="filtro === 'pendientes' ? UserRoundCheck : MonitorCheck"
                :titulo="vacio.titulo"
                :descripcion="vacio.descripcion"
            />

            <ListaAnimada
                v-else
                key="lista"
                class="flex flex-col gap-3"
                :cantidad="pendientes.data.length"
                aria-label="Personas por dar de alta en sistemas"
            >
                <li
                    v-for="fila in pendientes.data"
                    :key="fila.id"
                    class="rounded-2xl border bg-card p-4 shadow-xs transicion-ui hover:border-oro/50 hover:shadow-md"
                >
                    <div class="flex flex-wrap items-start gap-3">
                        <ColaboradorAvatar
                            :nombre="fila.colaborador.nombre"
                            tamano="lg"
                        />

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-base font-semibold">
                                {{ fila.colaborador.nombre }}
                            </p>
                            <ul
                                class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground"
                            >
                                <li class="flex items-center gap-1.5">
                                    <Briefcase
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{
                                        fila.colaborador.puesto ?? 'Sin puesto'
                                    }}
                                </li>
                                <li class="flex items-center gap-1.5">
                                    <MapPin
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{
                                        fila.colaborador.sucursal ??
                                        'Sin sucursal'
                                    }}
                                </li>
                                <li class="flex items-center gap-1.5">
                                    <CalendarDays
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{
                                        fila.colaborador.fecha_ingreso ??
                                        'Ingreso sin capturar'
                                    }}
                                </li>
                                <li
                                    v-if="fila.colaborador.numero_empleado"
                                    class="flex items-center gap-1.5"
                                >
                                    <Hash class="size-3.5" aria-hidden="true" />
                                    {{ fila.colaborador.numero_empleado }}
                                </li>
                            </ul>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium"
                                :class="clasesDias(fila)"
                            >
                                <CircleCheckBig
                                    v-if="fila.atendido"
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                <Hourglass
                                    v-else
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ textoDias(fila) }}
                            </span>
                            <span
                                class="flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium"
                                :class="
                                    ESTADO_CORREO[fila.correo.estado].clases
                                "
                                :title="
                                    fila.correo.enviado_en
                                        ? `Enviado el ${fila.correo.enviado_en}`
                                        : (fila.correo.error ?? undefined)
                                "
                            >
                                <component
                                    :is="
                                        ESTADO_CORREO[fila.correo.estado].icono
                                    "
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ fila.correo.etiqueta }}
                            </span>
                        </div>
                    </div>

                    <p
                        v-if="fila.atendido"
                        class="mt-3 flex flex-wrap items-center gap-1.5 rounded-xl bg-success-soft/60 px-3 py-2 text-sm"
                    >
                        <UserRoundCheck
                            class="size-4 text-success"
                            aria-hidden="true"
                        />
                        <span>
                            Dado de alta por
                            <strong>{{ fila.atendido.por }}</strong>
                            · {{ fila.atendido.en }}
                        </span>
                        <span
                            v-if="fila.atendido.notas"
                            class="w-full text-muted-foreground italic"
                        >
                            «{{ fila.atendido.notas }}»
                        </span>
                    </p>

                    <!-- Acciones -->
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <Button
                            v-if="fila.puede_atender && !fila.atendido"
                            size="sm"
                            :disabled="procesando !== null"
                            @click="abrirMarcar(fila)"
                        >
                            <CircleCheckBig class="size-4" />
                            Marcar como dado de alta
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="copiar(fila)"
                        >
                            <CopyCheck
                                v-if="copiado === fila.id"
                                class="size-4 text-success"
                            />
                            <ClipboardCopy v-else class="size-4" />
                            {{
                                copiado === fila.id
                                    ? '¡Copiado!'
                                    : 'Copiar datos'
                            }}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            :aria-expanded="abiertos.has(fila.id)"
                            @click="alternar(fila.id)"
                        >
                            <ChevronDown
                                class="size-4 transicion-ui"
                                :class="
                                    abiertos.has(fila.id) ? 'rotate-180' : ''
                                "
                            />
                            {{
                                abiertos.has(fila.id)
                                    ? 'Ocultar datos'
                                    : 'Ver datos'
                            }}
                        </Button>
                        <Button as-child size="sm" variant="ghost">
                            <Link :href="fila.url_detalle">
                                <Copy class="size-4" />
                                Copiar dato por dato
                            </Link>
                        </Button>
                        <Button
                            v-if="fila.colaborador.url_expediente"
                            as-child
                            size="sm"
                            variant="ghost"
                        >
                            <Link :href="fila.colaborador.url_expediente">
                                <FolderOpen class="size-4" />
                                Expediente
                            </Link>
                        </Button>

                        <div class="ml-auto flex flex-wrap gap-2">
                            <Button
                                v-if="fila.puede_reenviar"
                                size="sm"
                                variant="ghost"
                                :disabled="procesando !== null"
                                @click="accion(reenviar.url(fila.id), fila)"
                            >
                                <Spinner v-if="procesando === fila.id" />
                                <Send v-else class="size-4" />
                                {{
                                    fila.correo.estado === 'sin_enviar'
                                        ? 'Enviar correo'
                                        : 'Reenviar correo'
                                }}
                            </Button>
                            <Button
                                v-if="fila.puede_atender && fila.atendido"
                                size="sm"
                                variant="ghost"
                                :disabled="procesando !== null"
                                @click="accion(reabrir.url(fila.id), fila)"
                            >
                                <Undo2 class="size-4" />
                                Deshacer
                            </Button>
                        </div>
                    </div>

                    <!-- Datos básicos -->
                    <Transition name="cambio-suave">
                        <div
                            v-if="abiertos.has(fila.id)"
                            class="mt-3 grid gap-4 lg:grid-cols-3"
                        >
                            <dl
                                v-for="seccion in fila.secciones"
                                :key="seccion.clave"
                                class="flex flex-col gap-1 rounded-xl bg-[var(--mrl-fondo)] p-3"
                            >
                                <p
                                    class="mb-1 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    <component
                                        :is="ICONO_SECCION_ALTA[seccion.clave]"
                                        class="size-3.5 text-bronce"
                                        aria-hidden="true"
                                    />
                                    {{ seccion.titulo }}
                                </p>
                                <div
                                    v-for="dato in seccion.datos"
                                    :key="dato.etiqueta"
                                    class="flex flex-wrap justify-between gap-x-3 rounded-lg px-2 py-1 text-sm transicion-ui hover:bg-crema/50"
                                >
                                    <dt
                                        class="flex items-center gap-1.5 text-muted-foreground"
                                    >
                                        <component
                                            :is="iconoDatoAlta(dato.etiqueta)"
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        {{ dato.etiqueta }}
                                    </dt>
                                    <dd
                                        class="text-right break-words"
                                        :class="
                                            dato.falta
                                                ? 'text-warning italic'
                                                : 'font-medium'
                                        "
                                    >
                                        {{ dato.valor }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </Transition>
                </li>
            </ListaAnimada>
        </CambioSuave>

        <PaginacionTabla v-if="pendientes.total > 0" :datos="pendientes" />

        <Dialog
            :open="marcando !== null"
            @update:open="(abierto) => !abierto && (marcando = null)"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <CircleCheckBig class="size-5 text-success" />
                        Marcar como dado de alta
                    </DialogTitle>
                    <DialogDescription>
                        {{ marcando?.colaborador.nombre }} ya tiene sus usuarios
                        en los demás sistemas. Quedará registrado quién y
                        cuándo.
                    </DialogDescription>
                </DialogHeader>
                <div class="flex flex-col gap-2">
                    <Label for="notas-alta">Notas (opcional)</Label>
                    <Textarea
                        id="notas-alta"
                        v-model="notas"
                        maxlength="1000"
                        placeholder="Ej. Correo, usuario del sistema de cobranza y VPN creados."
                    />
                </div>
                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="marcando = null">
                        Cancelar
                    </Button>
                    <Button
                        :disabled="procesando !== null"
                        @click="confirmarMarcar"
                    >
                        <Spinner v-if="procesando !== null" />
                        <CircleCheckBig v-else class="size-4" />
                        Confirmar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
