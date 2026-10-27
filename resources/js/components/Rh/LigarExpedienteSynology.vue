<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    FileText,
    FolderOpen,
    FolderSearch,
    Link2,
    RefreshCw,
    Search,
    Sparkles,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { carpetas, ligar, vistaPrevia } from '@/routes/rh/expedientes/synology';

/**
 * «Ligar expediente de Synology»: para quien la migración no ligó solo
 * porque su carpeta del NAS tiene el nombre mal escrito («Juan Pérez» ↔
 * «Juam Peres»). Lista completa al enfocar, filtra mientras se escribe
 * (sin acentos, por nombre o apellido, con parecido), sugeridas primero y
 * vista previa del contenido antes de confirmar. La regla real (permiso,
 * alcance, carpeta libre) la aplica App\Services\Expedientes\VinculacionSynologyService.
 */
const props = defineProps<{
    colaboradorId: number;
    nombre: string;
    // Nuevo ingreso que ya tiene su carpeta del sistema: su expediente nuevo
    // ya está en formación, así que ligar un histórico es opcional (no una
    // deficiencia). Cambia el texto de la tarjeta para no alarmar.
    tieneExpedienteNuevo?: boolean;
}>();

type CarpetaSynology = {
    clave: string;
    carpeta: string;
    sucursal: string;
    archivo_suelto: boolean;
    pdfs: number;
    similitud: number;
    sugerida: boolean;
    misma_sucursal: boolean;
    coincidencia: number | null;
};

type ResultadoBusqueda = {
    disponible: boolean;
    error: string | null;
    total: number;
    carpetas: CarpetaSynology[];
    sugerencias: CarpetaSynology[];
    tiene_expediente: boolean;
};

type VistaPrevia = {
    clave: string;
    carpeta: string;
    sucursal: string;
    archivo_suelto: boolean;
    total_archivos: number;
    pdfs_a_registrar: number;
    archivos: {
        nombre: string;
        extension: string;
        size: number;
        se_registra: boolean;
    }[];
    subcarpetas: string[];
    conserva_carpeta_sistema: boolean;
};

const abierto = ref(false);
const consulta = ref('');
const cargando = ref(false);
const error = ref('');
const resultado = ref<ResultadoBusqueda | null>(null);
const seleccion = ref<CarpetaSynology | null>(null);
const vista = ref<VistaPrevia | null>(null);
const cargandoVista = ref(false);
const errorVista = ref('');
const ligando = ref(false);
const errorLigar = ref('');

let temporizador: ReturnType<typeof setTimeout> | null = null;
let ultimaPeticion = 0;

function porcentaje(valor: number): string {
    return `${Math.round(valor * 100)} %`;
}

function tamano(bytes: number): string {
    return bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

async function mensajeDe(respuesta: Response, respaldo: string) {
    try {
        const cuerpo = (await respuesta.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        return (
            cuerpo.errors?.synology?.[0] ??
            cuerpo.errors?.clave?.[0] ??
            cuerpo.message ??
            respaldo
        );
    } catch {
        return respaldo;
    }
}

async function cargar(refrescar = false) {
    const peticion = ++ultimaPeticion;
    cargando.value = true;
    error.value = '';

    try {
        const respuesta = await fetch(
            carpetas.url(props.colaboradorId, {
                query: {
                    q: consulta.value.trim() || undefined,
                    refrescar: refrescar ? 1 : undefined,
                },
            }),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (!respuesta.ok) {
            throw new Error(
                await mensajeDe(
                    respuesta,
                    'No se pudo consultar Synology. Intenta de nuevo.',
                ),
            );
        }

        const datos = ((await respuesta.json()) as { data: ResultadoBusqueda })
            .data;

        if (peticion === ultimaPeticion) {
            resultado.value = datos;
            error.value = datos.disponible ? '' : (datos.error ?? '');
        }
    } catch (e) {
        if (peticion === ultimaPeticion) {
            error.value =
                e instanceof Error
                    ? e.message
                    : 'No se pudo consultar Synology.';
        }
    } finally {
        if (peticion === ultimaPeticion) {
            cargando.value = false;
        }
    }
}

async function seleccionar(carpeta: CarpetaSynology) {
    seleccion.value = carpeta;
    vista.value = null;
    errorVista.value = '';
    errorLigar.value = '';
    cargandoVista.value = true;

    try {
        const respuesta = await fetch(
            vistaPrevia.url(props.colaboradorId, {
                query: { clave: carpeta.clave },
            }),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (!respuesta.ok) {
            throw new Error(
                await mensajeDe(
                    respuesta,
                    'No se pudo leer el contenido de la carpeta.',
                ),
            );
        }

        const datos = ((await respuesta.json()) as { data: VistaPrevia }).data;

        if (seleccion.value?.clave === carpeta.clave) {
            vista.value = datos;
        }
    } catch (e) {
        errorVista.value =
            e instanceof Error
                ? e.message
                : 'No se pudo leer el contenido de la carpeta.';
    } finally {
        cargandoVista.value = false;
    }
}

function confirmar() {
    if (!vista.value || ligando.value) {
        return;
    }

    errorLigar.value = '';
    router.post(
        ligar.url(props.colaboradorId),
        { clave: vista.value.clave },
        {
            preserveScroll: true,
            onStart: () => (ligando.value = true),
            onFinish: () => (ligando.value = false),
            onSuccess: () => {
                abierto.value = false;
            },
            onError: (errores) => {
                errorLigar.value =
                    errores.synology ??
                    errores.clave ??
                    'No se pudo ligar el expediente.';
                void cargar();
            },
        },
    );
}

function abrir() {
    abierto.value = true;
    consulta.value = '';
    seleccion.value = null;
    vista.value = null;
    errorLigar.value = '';
    void cargar();
}

function alEnfocar() {
    if (!resultado.value && !cargando.value) {
        void cargar();
    }
}

watch(consulta, () => {
    if (temporizador) {
        clearTimeout(temporizador);
    }

    temporizador = setTimeout(() => void cargar(), 250);
});

onBeforeUnmount(() => {
    if (temporizador) {
        clearTimeout(temporizador);
    }
});

const buscando = computed(() => consulta.value.trim() !== '');
const sugerencias = computed(() =>
    buscando.value ? [] : (resultado.value?.sugerencias ?? []),
);
const lista = computed(() => {
    const todas = resultado.value?.carpetas ?? [];

    if (buscando.value) {
        return todas;
    }

    const sugeridas = new Set(sugerencias.value.map((c) => c.clave));

    return todas.filter((c) => !sugeridas.has(c.clave));
});
</script>

<template>
    <Card class="rounded-2xl border-dashed border-border/80 bg-crema/40">
        <CardContent
            class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-start gap-3">
                <FolderSearch class="mt-0.5 size-5 shrink-0 text-primary" />
                <div v-if="tieneExpedienteNuevo">
                    <h3 class="text-base font-semibold">
                        Expediente en formación
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        Sus documentos nuevos ya se guardan en su carpeta del
                        sistema. Si además existe un expediente histórico (de
                        antes del sistema) con su nombre en Synology, puedes
                        ligarlo aquí; si es de nuevo ingreso, no hace falta.
                    </p>
                </div>
                <div v-else>
                    <h3 class="text-base font-semibold">
                        Sin expediente de Synology ligado
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        Si su carpeta en Synology tiene el nombre escrito
                        distinto, búscala y lígala a mano. No se mueve ni se
                        borra nada del NAS.
                    </p>
                </div>
            </div>
            <Button
                size="sm"
                :variant="tieneExpedienteNuevo ? 'outline' : 'default'"
                class="shrink-0"
                @click="abrir"
            >
                <Link2 class="size-3.5" />
                {{
                    tieneExpedienteNuevo
                        ? 'Ligar expediente histórico'
                        : 'Ligar expediente de Synology'
                }}
            </Button>
        </CardContent>
    </Card>

    <Dialog v-model:open="abierto">
        <DialogContent
            class="flex max-h-[92vh] w-[calc(100vw-2rem)] flex-col gap-4 overflow-hidden sm:max-w-4xl"
        >
            <DialogHeader>
                <DialogTitle>Ligar expediente de Synology</DialogTitle>
                <DialogDescription>
                    Carpetas de Synology que aún no están ligadas a nadie. Elige
                    la de {{ nombre }}, revisa su contenido y confirma.
                </DialogDescription>
            </DialogHeader>

            <div class="flex items-center gap-2">
                <div class="relative min-w-0 flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="consulta"
                        class="pl-9"
                        placeholder="Busca por nombre, apellido o sucursal (p. ej. juan perez)"
                        aria-label="Buscar carpeta en Synology"
                        autocomplete="off"
                        @focus="alEnfocar"
                    />
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :disabled="cargando"
                    aria-label="Volver a leer Synology"
                    title="Volver a leer Synology"
                    @click="cargar(true)"
                >
                    <RefreshCw
                        class="size-4"
                        :class="cargando ? 'animate-spin' : ''"
                    />
                </Button>
            </div>

            <p
                v-if="error"
                class="rounded-xl bg-danger-soft px-3 py-2 text-sm text-danger"
                role="alert"
            >
                {{ error }}
            </p>

            <div
                class="grid min-h-0 flex-1 gap-4 overflow-hidden md:grid-cols-2"
            >
                <!-- Lista: sugeridas primero, luego todas (o resultados). -->
                <div
                    class="flex min-h-0 flex-col gap-2 overflow-y-auto pr-1"
                    aria-label="Carpetas sin ligar"
                >
                    <template v-if="sugerencias.length">
                        <p
                            class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            <Sparkles class="size-3.5" />
                            Sugeridas para {{ nombre }}
                        </p>
                        <button
                            v-for="c in sugerencias"
                            :key="`s-${c.clave}`"
                            type="button"
                            class="flex w-full tarjeta-interactiva items-center justify-between gap-2 rounded-xl border p-3 text-left"
                            :class="
                                seleccion?.clave === c.clave
                                    ? 'border-primary bg-salvia/40'
                                    : 'border-border/60 bg-success-soft/40'
                            "
                            @click="seleccionar(c)"
                        >
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-sm font-medium"
                                >
                                    {{ c.carpeta }}
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ c.sucursal }} · {{ c.pdfs }} PDF{{
                                        c.pdfs === 1 ? '' : 's'
                                    }}
                                    {{
                                        c.misma_sucursal ? '· su sucursal' : ''
                                    }}
                                </span>
                            </span>
                            <Badge variant="secondary" class="shrink-0">
                                {{ porcentaje(c.similitud) }}
                            </Badge>
                        </button>
                    </template>

                    <p
                        class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <template v-if="buscando">
                            Resultados ({{ resultado?.total ?? 0 }})
                        </template>
                        <template v-else>
                            Todas las carpetas sin ligar ({{
                                resultado?.total ?? 0
                            }})
                        </template>
                    </p>

                    <p
                        v-if="cargando && !resultado"
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        Leyendo Synology…
                    </p>
                    <p
                        v-else-if="
                            resultado?.disponible &&
                            !lista.length &&
                            !sugerencias.length
                        "
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        {{
                            buscando
                                ? 'Ninguna carpeta sin ligar se parece a lo que escribiste.'
                                : 'No hay carpetas sin ligar en Synology.'
                        }}
                    </p>

                    <button
                        v-for="c in lista"
                        :key="c.clave"
                        type="button"
                        class="flex w-full tarjeta-interactiva items-center justify-between gap-2 rounded-xl border p-3 text-left"
                        :class="
                            seleccion?.clave === c.clave
                                ? 'border-primary bg-salvia/40'
                                : 'border-border/60 bg-card'
                        "
                        @click="seleccionar(c)"
                    >
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium">
                                {{ c.carpeta }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ c.sucursal }} ·
                                {{
                                    c.archivo_suelto
                                        ? 'PDF suelto'
                                        : `${c.pdfs} PDF${c.pdfs === 1 ? '' : 's'}`
                                }}
                            </span>
                        </span>
                        <Badge
                            v-if="c.coincidencia !== null || c.sugerida"
                            variant="outline"
                            class="shrink-0"
                        >
                            {{ porcentaje(c.coincidencia ?? c.similitud) }}
                        </Badge>
                    </button>
                    <p
                        v-if="
                            resultado &&
                            resultado.total > resultado.carpetas.length
                        "
                        class="text-center text-xs text-muted-foreground"
                    >
                        Se muestran {{ resultado.carpetas.length }} de
                        {{ resultado.total }}: escribe para acotar.
                    </p>
                </div>

                <!-- Vista previa antes de confirmar. -->
                <div
                    class="flex min-h-0 flex-col gap-3 overflow-y-auto rounded-xl border border-border/60 bg-crema/40 p-4"
                    aria-live="polite"
                >
                    <div
                        v-if="!seleccion"
                        class="flex flex-1 flex-col items-center justify-center gap-2 py-8 text-center text-sm text-muted-foreground"
                    >
                        <FolderOpen class="size-6" />
                        Elige una carpeta para ver qué contiene.
                    </div>
                    <p
                        v-else-if="cargandoVista"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Leyendo la carpeta…
                    </p>
                    <p
                        v-else-if="errorVista"
                        class="rounded-xl bg-danger-soft px-3 py-2 text-sm text-danger"
                        role="alert"
                    >
                        {{ errorVista }}
                    </p>
                    <template v-else-if="vista">
                        <div>
                            <p class="text-sm font-semibold break-words">
                                {{ vista.carpeta }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ vista.sucursal }} ·
                                {{ vista.total_archivos }} archivo{{
                                    vista.total_archivos === 1 ? '' : 's'
                                }}
                                · se registrará{{
                                    vista.pdfs_a_registrar === 1 ? '' : 'n'
                                }}
                                {{ vista.pdfs_a_registrar }} PDF{{
                                    vista.pdfs_a_registrar === 1 ? '' : 's'
                                }}
                            </p>
                        </div>
                        <ul class="flex flex-col gap-1.5">
                            <li
                                v-for="a in vista.archivos"
                                :key="a.nombre"
                                class="flex items-center justify-between gap-2 rounded-lg bg-card px-2.5 py-1.5 text-sm"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <FileText
                                        class="size-3.5 shrink-0 text-muted-foreground"
                                    />
                                    <span class="truncate">{{ a.nombre }}</span>
                                </span>
                                <span
                                    class="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground"
                                >
                                    {{ tamano(a.size) }}
                                    <Badge
                                        v-if="a.se_registra"
                                        variant="secondary"
                                        >Se registra</Badge
                                    >
                                </span>
                            </li>
                        </ul>
                        <p
                            v-if="vista.subcarpetas.length"
                            class="text-xs text-muted-foreground"
                        >
                            Subcarpetas (no se registran):
                            {{ vista.subcarpetas.join(', ') }}
                        </p>
                        <p
                            v-if="vista.pdfs_a_registrar === 0"
                            class="rounded-xl bg-warning-soft px-3 py-2 text-xs"
                        >
                            Esta carpeta no tiene PDF que registrar como
                            expediente histórico.
                        </p>
                        <p
                            v-if="vista.conserva_carpeta_sistema"
                            class="rounded-xl bg-info-soft px-3 py-2 text-xs"
                        >
                            {{ nombre }} ya tiene su carpeta del sistema: sus
                            documentos nuevos seguirán guardándose ahí; esta
                            carpeta se liga como expediente histórico.
                        </p>
                    </template>
                </div>
            </div>

            <p
                v-if="errorLigar"
                class="rounded-xl bg-danger-soft px-3 py-2 text-sm text-danger"
                role="alert"
            >
                {{ errorLigar }}
            </p>

            <DialogFooter class="gap-2 sm:gap-0">
                <Button variant="outline" @click="abierto = false">
                    Cancelar
                </Button>
                <Button :disabled="!vista || ligando" @click="confirmar">
                    <Link2 class="size-3.5" />
                    {{
                        ligando
                            ? 'Ligando…'
                            : vista
                              ? `Ligar «${vista.carpeta}»`
                              : 'Ligar expediente'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
