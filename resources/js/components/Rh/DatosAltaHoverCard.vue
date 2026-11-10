<script lang="ts">
export type CampoFichaAlta = {
    clave: string;
    etiqueta: string;
    valor: string;
    /** Lo que copia el botón del campo (fechas: solo dd/mm/aaaa). */
    copiar: string;
    capturado: boolean;
};

export type FichaAltaRapida = {
    tipo: 'colaborador' | 'candidato';
    titulo: string;
    subtitulo: string;
    origen: string | null;
    campos: CampoFichaAlta[];
};

// Compartido entre todas las tarjetas: una ficha se pide una sola vez por
// visita (y una sola petición aunque dos tarjetas la pidan a la vez).
const cache = new Map<string, FichaAltaRapida>();
const pendientes = new Map<string, Promise<FichaAltaRapida>>();

async function pedirFicha(url: string): Promise<FichaAltaRapida> {
    const respuesta = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!respuesta.ok) {
        throw new Error(
            respuesta.status === 403
                ? 'No tienes acceso a los datos de esta persona.'
                : 'No se pudieron cargar los datos. Intenta de nuevo.',
        );
    }

    return (await respuesta.json()) as FichaAltaRapida;
}
</script>

<script setup lang="ts">
import {
    BadgeCheck,
    Briefcase,
    Building2,
    Cake,
    CalendarCheck,
    Check,
    CircleAlert,
    ClipboardCopy,
    Copy,
    Fingerprint,
    Hash,
    Hexagon,
    Home,
    IdCard,
    Landmark,
    Mail,
    MapPinned,
    Network,
    Phone,
    ReceiptText,
    RefreshCw,
    ShieldPlus,
    User,
    UserRound,
    Users,
    VenusAndMars,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import fichasAlta from '@/routes/rh/fichas-alta';

/**
 * «Datos para dar de alta»: al pasar el mouse por la tarjeta (con una
 * pequeña espera, para no abrirse al cruzar la lista) o al tocar el botón
 * del gafete, muestra la ficha corta que RH copia a otros sistemas. Se pide
 * al backend la primera vez y queda en memoria por id (FichaAltaRapidaService).
 *
 * El contenido vive en un portal: sus clics no llegan a la tarjeta (no
 * navega ni dispara el arrastre del tablero).
 */

const props = withDefaults(
    defineProps<{
        tipo: 'colaborador' | 'candidato';
        id: number;
        nombre?: string;
        /** Posición del botón del gafete dentro de la tarjeta. */
        claseBoton?: string;
    }>(),
    { nombre: '', claseBoton: 'absolute top-2 right-2' },
);

const RETARDO_ABRIR = 450;
const RETARDO_CERRAR = 180;

const ICONOS: Record<string, Component> = {
    nombre_completo: UserRound,
    nombre: User,
    apellidos: Users,
    fecha_nacimiento: Cake,
    genero: VenusAndMars,
    curp: Fingerprint,
    rfc: ReceiptText,
    nss: ShieldPlus,
    telefono: Phone,
    correo: Mail,
    domicilio: Home,
    empresa: Landmark,
    sucursal: MapPinned,
    puesto: Briefcase,
    departamento: Hexagon,
    jefe: Network,
    fecha_ingreso: CalendarCheck,
    numero_empleado: Hash,
    estatus: BadgeCheck,
};

/** Campos que ocupan todo el ancho (valores largos). */
const ANCHOS = new Set(['nombre_completo', 'domicilio']);

const abierto = ref(false);
// Abierta por hover se cierra sola al salir; abierta con el botón se queda
// fija hasta un clic fuera o Escape.
const porHover = ref(false);
const ficha = ref<FichaAltaRapida | null>(cache.get(clave()) ?? null);
const cargando = ref(false);
const error = ref<string | null>(null);
const copiado = ref<string | null>(null);
const boton = ref<HTMLButtonElement | null>(null);

let timerAbrir: number | undefined;
let timerCerrar: number | undefined;
let timerCopiado: number | undefined;

function clave(): string {
    return `${props.tipo}:${props.id}`;
}

function url(): string {
    return props.tipo === 'colaborador'
        ? fichasAlta.colaborador.url(props.id)
        : fichasAlta.candidato.url(props.id);
}

async function cargar(): Promise<void> {
    const llave = clave();
    const enCache = cache.get(llave);

    if (enCache) {
        ficha.value = enCache;

        return;
    }

    cargando.value = true;
    error.value = null;

    try {
        let promesa = pendientes.get(llave);

        if (!promesa) {
            promesa = pedirFicha(url());
            pendientes.set(llave, promesa);
        }

        const datos = await promesa;
        cache.set(llave, datos);
        ficha.value = datos;
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'No se pudieron cargar los datos.';
    } finally {
        pendientes.delete(llave);
        cargando.value = false;
    }
}

function limpiarTimers(): void {
    window.clearTimeout(timerAbrir);
    window.clearTimeout(timerCerrar);
}

function abrir(desdeHover: boolean): void {
    limpiarTimers();
    porHover.value = desdeHover;
    abierto.value = true;
    void cargar();
}

function cerrar(): void {
    limpiarTimers();
    abierto.value = false;
    porHover.value = false;
}

function alEntrar(evento: PointerEvent): void {
    if (evento.pointerType === 'touch' || evento.buttons !== 0) {
        return;
    }

    window.clearTimeout(timerCerrar);

    if (!abierto.value) {
        timerAbrir = window.setTimeout(() => abrir(true), RETARDO_ABRIR);
    }
}

function alSalir(): void {
    window.clearTimeout(timerAbrir);

    if (abierto.value && porHover.value) {
        timerCerrar = window.setTimeout(cerrar, RETARDO_CERRAR);
    }
}

/** Clic o inicio de arrastre: la intención no era leer la ficha. */
function cancelarHover(): void {
    window.clearTimeout(timerAbrir);

    if (porHover.value) {
        cerrar();
    }
}

function alternarFijo(evento: Event): void {
    evento.preventDefault();
    evento.stopPropagation();

    if (abierto.value && !porHover.value) {
        cerrar();

        return;
    }

    abrir(false);
}

function alCambiarAbierto(valor: boolean): void {
    if (valor) {
        abierto.value = true;

        return;
    }

    cerrar();
}

function alClicFuera(evento: Event): void {
    // El botón del gafete alterna por su cuenta; sin esto el clic fuera lo
    // cerraría y el mismo clic lo volvería a abrir.
    const destino = evento.target;

    if (destino instanceof Node && boton.value?.contains(destino)) {
        evento.preventDefault();
    }
}

function alAbrirFoco(evento: Event): void {
    // Por hover no se roba el foco de la página.
    if (porHover.value) {
        evento.preventDefault();
    }
}

function alCerrarFoco(evento: Event): void {
    if (porHover.value || !abierto.value) {
        evento.preventDefault();
    }
}

function marcarCopiado(llave: string): void {
    copiado.value = llave;
    window.clearTimeout(timerCopiado);
    timerCopiado = window.setTimeout(() => (copiado.value = null), 1600);
}

async function copiarTexto(texto: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(texto);

        return true;
    } catch {
        toast.error('No se pudo copiar. Revisa los permisos del navegador.');

        return false;
    }
}

async function copiarCampo(campo: CampoFichaAlta): Promise<void> {
    if (!campo.capturado) {
        return;
    }

    if (await copiarTexto(campo.copiar)) {
        marcarCopiado(campo.clave);
        toast.success(`${campo.etiqueta} copiado`, {
            description: campo.copiar,
        });
    }
}

const textoCompleto = computed(() => {
    if (!ficha.value) {
        return '';
    }

    const lineas = ficha.value.campos.map((c) => `${c.etiqueta}: ${c.valor}`);

    return [`DATOS PARA ALTA — ${ficha.value.titulo}`, '', ...lineas].join(
        '\n',
    );
});

const faltantes = computed(
    () => ficha.value?.campos.filter((c) => !c.capturado).length ?? 0,
);

async function copiarTodo(): Promise<void> {
    if (!ficha.value) {
        return;
    }

    if (await copiarTexto(textoCompleto.value)) {
        marcarCopiado('__todo');
        toast.success('Datos copiados', {
            description: `${ficha.value.campos.length} datos de ${ficha.value.titulo}, listos para pegar.`,
        });
    }
}

onBeforeUnmount(() => {
    limpiarTimers();
    window.clearTimeout(timerCopiado);
});
</script>

<template>
    <Popover :open="abierto" @update:open="alCambiarAbierto">
        <PopoverAnchor as-child>
            <div
                class="relative"
                data-datos-alta
                @pointerenter="alEntrar"
                @pointerleave="alSalir"
                @pointerdown="cancelarHover"
                @dragstart.capture="cancelarHover"
            >
                <slot />

                <button
                    ref="boton"
                    type="button"
                    draggable="false"
                    :class="
                        cn(
                            'z-10 flex size-7 items-center justify-center rounded-lg border border-border/60 bg-card/90 text-muted-foreground shadow-xs backdrop-blur-sm transicion-ui hover:border-primary/40 hover:bg-salvia hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            abierto && !porHover && 'bg-salvia text-primary',
                            claseBoton,
                        )
                    "
                    :aria-label="`Ver datos para dar de alta${nombre ? ` de ${nombre}` : ''}`"
                    :aria-expanded="abierto"
                    aria-haspopup="dialog"
                    title="Datos para dar de alta"
                    data-accion="datos-alta"
                    @click="alternarFijo"
                    @pointerdown.stop
                >
                    <IdCard class="size-4" />
                </button>
            </div>
        </PopoverAnchor>

        <PopoverContent
            side="right"
            align="start"
            :side-offset="8"
            :collision-padding="12"
            class="flex max-h-(--reka-popover-content-available-height) w-[min(36rem,calc(100vw-2rem))] flex-col gap-0 overflow-hidden rounded-2xl border-border/70 p-0 shadow-xl"
            data-ficha-alta
            @pointerenter="limpiarTimers"
            @pointerleave="alSalir"
            @open-auto-focus="alAbrirFoco"
            @close-auto-focus="alCerrarFoco"
            @pointer-down-outside="alClicFuera"
        >
            <!-- Encabezado -->
            <div
                class="flex items-start gap-3 border-b border-border/60 bg-crema/50 px-4 py-3"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                    aria-hidden="true"
                >
                    <IdCard class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <p
                        class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Datos para dar de alta
                    </p>
                    <p
                        v-if="ficha"
                        class="text-sm leading-tight font-semibold break-words"
                    >
                        {{ ficha.titulo }}
                    </p>
                    <Skeleton v-else class="mt-1 h-4 w-40" />
                    <p
                        v-if="ficha"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        {{ ficha.subtitulo }}
                        <template v-if="faltantes > 0">
                            · {{ faltantes }} sin capturar</template
                        >
                    </p>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    class="shrink-0 gap-1.5"
                    :disabled="!ficha"
                    data-accion="copiar-todo"
                    @click="copiarTodo"
                >
                    <Check
                        v-if="copiado === '__todo'"
                        class="size-4 text-success"
                    />
                    <ClipboardCopy v-else class="size-4" />
                    Copiar todo
                </Button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-3">
                <!-- Error -->
                <div
                    v-if="error && !ficha"
                    class="flex flex-col items-center gap-2 px-4 py-6 text-center"
                >
                    <CircleAlert class="size-6 text-destructive" />
                    <p class="text-sm text-muted-foreground">{{ error }}</p>
                    <Button size="sm" variant="ghost" @click="cargar">
                        <RefreshCw class="size-4" />
                        Reintentar
                    </Button>
                </div>

                <!-- Cargando -->
                <div
                    v-else-if="!ficha || cargando"
                    class="grid grid-cols-1 gap-2 sm:grid-cols-2"
                    aria-busy="true"
                    aria-label="Cargando datos"
                >
                    <div
                        v-for="n in 8"
                        :key="n"
                        class="flex items-center gap-2.5 rounded-xl px-2.5 py-2"
                    >
                        <Skeleton class="size-7 shrink-0 rounded-lg" />
                        <div class="flex flex-1 flex-col gap-1.5">
                            <Skeleton class="h-2.5 w-16" />
                            <Skeleton class="h-3.5 w-28" />
                        </div>
                    </div>
                </div>

                <!-- Ficha -->
                <template v-else>
                    <p
                        v-if="ficha.origen"
                        class="mb-2 rounded-lg bg-info-soft px-3 py-1.5 text-xs text-info"
                    >
                        {{ ficha.origen }}
                    </p>
                    <dl class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                        <div
                            v-for="campo in ficha.campos"
                            :key="campo.clave"
                            class="group/campo flex min-w-0 items-start gap-2.5 rounded-xl px-2.5 py-2 transicion-ui hover:bg-crema/50"
                            :class="
                                ANCHOS.has(campo.clave) ? 'sm:col-span-2' : ''
                            "
                            :data-campo="campo.clave"
                        >
                            <span
                                class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-salvia/70 text-primary"
                                aria-hidden="true"
                            >
                                <component
                                    :is="ICONOS[campo.clave] ?? Building2"
                                    class="size-3.5"
                                />
                            </span>
                            <div class="min-w-0 flex-1">
                                <dt
                                    class="text-[11px] leading-tight text-muted-foreground"
                                >
                                    {{ campo.etiqueta }}
                                </dt>
                                <dd
                                    class="text-sm leading-snug font-medium break-words"
                                    :class="
                                        campo.capturado
                                            ? ''
                                            : 'font-normal text-muted-foreground/80 italic'
                                    "
                                >
                                    {{ campo.valor }}
                                </dd>
                            </div>
                            <button
                                v-if="campo.capturado"
                                type="button"
                                class="flex size-7 shrink-0 items-center justify-center rounded-lg text-muted-foreground opacity-60 transicion-ui group-hover/campo:opacity-100 hover:bg-card hover:text-primary focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :aria-label="`Copiar ${campo.etiqueta.toLowerCase()}`"
                                :title="`Copiar ${campo.copiar}`"
                                @click="copiarCampo(campo)"
                            >
                                <Check
                                    v-if="copiado === campo.clave"
                                    class="size-3.5 text-success"
                                />
                                <Copy v-else class="size-3.5" />
                            </button>
                        </div>
                    </dl>
                </template>
            </div>
        </PopoverContent>
    </Popover>
</template>
