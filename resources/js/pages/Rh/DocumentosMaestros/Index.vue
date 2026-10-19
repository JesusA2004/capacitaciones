<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    CheckCircle2,
    CircleAlert,
    CircleDashed,
    CircleX,
    FilePlus2,
    FileStack,
    Images,
    Info,
    Loader2,
    MoreHorizontal,
    PencilRuler,
    RefreshCw,
    Search,
    Stethoscope,
    Type,
    UserRoundSearch,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import DetalleMaestro from '@/components/documentos/maestros/DetalleMaestro.vue';
import SeccionesDocumentosMaestros from '@/components/documentos/maestros/SeccionesDocumentosMaestros.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import {
    solicitar,
    useDocumentosMaestros,
} from '@/composables/useDocumentosMaestros';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    cobertura,
    editor as editorRuta,
    nuevo as nuevoRuta,
    revisarFuentes as revisarFuentesRuta,
} from '@/routes/rh/documentos-maestros';
import { index as indexFondos } from '@/routes/rh/documentos-maestros/fondos';
import type {
    KpisMaestros,
    MasterAdminFila,
    MasterDetalle,
    SaludDocumental,
} from '@/types';

/**
 * Documentos maestros: los formatos oficiales que PEOPLE llena solo en
 * cada proceso. Una línea de estado («28 listos · 1 requiere atención»),
 * una acción primaria (Nuevo formato) y una tarjeta por formato con
 * [Editar] + «…». El diagnóstico del entorno y el detalle técnico viven en
 * menús/paneles, no a la vista.
 */
const props = defineProps<{
    masters: MasterAdminFila[];
    kpis: KpisMaestros;
    salud: SaludDocumental;
    grupos: Record<string, string>;
    procesos: Record<string, string>;
    claves: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Documentos maestros', href: '' },
        ],
    },
});

const api = useDocumentosMaestros();

// ───────── Filtros ─────────
const filtro = ref<'todos' | 'listo' | 'revision' | 'bloqueado'>('todos');
const busqueda = ref('');

function normalizar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

function grupoEstado(
    f: MasterAdminFila,
): 'listo' | 'revision' | 'bloqueado' | 'referencia' {
    if (!f.operativo) {
        return 'referencia';
    }

    if (f.estado_ejecutivo === 'listo') {
        return 'listo';
    }

    return f.estado_ejecutivo === 'bloqueado' ||
        f.estado_ejecutivo === 'sin_formato'
        ? 'bloqueado'
        : 'revision';
}

const filtradas = computed(() =>
    props.masters.filter((f) => {
        if (filtro.value !== 'todos' && grupoEstado(f) !== filtro.value) {
            return false;
        }

        const termino = normalizar(busqueda.value.trim());

        return (
            termino === '' ||
            normalizar(
                `${f.nombre} ${f.aplica_a} ${f.proceso_etiqueta ?? ''}`,
            ).includes(termino)
        );
    }),
);

const requierenAtencion = computed(
    () => props.salud.formatos.revision + props.salud.formatos.bloqueados,
);
const diagnosticoAbierto = ref(false);

/** Qué le falta a un formato operativo, en palabras (sin códigos). */
function pendientesDe(f: MasterAdminFila): string[] {
    const faltan: string[] = [];

    if (!(f.diseno === 'validado' || f.diseno === 'excepcion')) {
        faltan.push('revisar el diseño');
    }

    if (f.pendientes > 0) {
        faltan.push(`${f.pendientes} dato(s) por asignar`);
    }

    if (!f.fuentes_ok) {
        faltan.push('revisar fuentes');
    }

    return faltan;
}

const iconoInfra = {
    ok: CheckCircle2,
    advertencia: CircleAlert,
    error: CircleX,
    no_instalado: CircleDashed,
} as const;
const colorInfra = {
    ok: 'text-success',
    advertencia: 'text-warning',
    error: 'text-danger',
    no_instalado: 'text-muted-foreground',
} as const;

// ───────── Más información (detalle técnico) ─────────
const detalle = ref<MasterDetalle | null>(null);
const cargandoDetalle = ref(false);
const panelAbierto = ref(false);

async function abrir(id: number | null) {
    if (id === null) {
        return;
    }

    panelAbierto.value = true;
    cargandoDetalle.value = true;

    try {
        detalle.value = await api.detalle(id);
    } catch (e) {
        toast.error(
            e instanceof Error ? e.message : 'No se pudo abrir el documento.',
        );
        panelAbierto.value = false;
    } finally {
        cargandoDetalle.value = false;
    }
}

function alActualizar(nuevo: MasterDetalle) {
    detalle.value = nuevo;
    router.reload({ only: ['masters', 'kpis', 'salud'] });
}

// ───────── Acciones del encabezado ─────────
const revalidando = ref(false);

async function revalidarPendientes() {
    revalidando.value = true;

    try {
        const r = await api.revalidarPendientes();
        toast[
            r.data.some((f) => f.estado === 'fallido') ? 'warning' : 'success'
        ](r.message);
        router.reload({ only: ['masters', 'kpis', 'salud'] });
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo revalidar.');
    } finally {
        revalidando.value = false;
    }
}

const revisandoFuentes = ref(false);

async function revisarFuentes() {
    revisandoFuentes.value = true;

    try {
        const r = await solicitar<{ message: string }>(
            'POST',
            revisarFuentesRuta.url(),
        );
        toast.success(r.message);
        router.reload({ only: ['salud', 'masters'] });
    } catch (e) {
        toast.error(
            e instanceof Error
                ? e.message
                : 'No se pudieron revisar las fuentes.',
        );
    } finally {
        revisandoFuentes.value = false;
    }
}

// ───────── Nuevo formato (no-code) ─────────
const nuevoAbierto = ref(false);
const archivos = ref<File[]>([]);
const subiendo = ref(false);
const formulario = ref({
    nombre: '',
    clave: '' as string,
    grupo: '' as string,
    proceso: 'expediente',
    requiere_impresion: true,
    requiere_firma_fisica: true,
    requiere_huella: false,
});

function abrirNuevo() {
    archivos.value = [];
    formulario.value = {
        nombre: '',
        clave: '',
        grupo: '',
        proceso: 'expediente',
        requiere_impresion: true,
        requiere_firma_fisica: true,
        requiere_huella: false,
    };
    nuevoAbierto.value = true;
}

async function crearFormato() {
    const archivo = archivos.value[0];

    if (!archivo || formulario.value.nombre.trim() === '') {
        return;
    }

    subiendo.value = true;
    const cuerpo = new FormData();
    cuerpo.append('archivo', archivo);

    for (const [clave, valor] of Object.entries(formulario.value)) {
        cuerpo.append(
            clave,
            typeof valor === 'boolean' ? (valor ? '1' : '0') : valor,
        );
    }

    try {
        const r = await solicitar<{ message: string; data: { id: number } }>(
            'POST',
            nuevoRuta.url(),
            cuerpo,
        );
        toast.success(r.message);
        router.visit(editorRuta.url(r.data.id));
    } catch (e) {
        toast.error(
            e instanceof Error ? e.message : 'No se pudo cargar el formato.',
        );
    } finally {
        subiendo.value = false;
    }
}
</script>

<template>
    <Head title="Documentos maestros" />

    <div class="pagina-ancha flex flex-col gap-5">
        <header class="flex flex-wrap items-center gap-3">
            <span
                class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"
                ><FileStack class="size-5"
            /></span>
            <div class="mr-auto min-w-0">
                <h1 class="text-xl font-semibold">Documentos maestros</h1>
                <p class="text-sm text-muted-foreground">
                    <button
                        type="button"
                        class="hover:text-foreground hover:underline"
                        @click="filtro = 'listo'"
                    >
                        <span class="font-medium text-success tabular-nums">{{
                            salud.formatos.listos
                        }}</span>
                        listos
                    </button>
                    <template v-if="requierenAtencion > 0">
                        ·
                        <button
                            type="button"
                            class="hover:text-foreground hover:underline"
                            @click="
                                filtro = salud.formatos.bloqueados
                                    ? 'bloqueado'
                                    : 'revision'
                            "
                        >
                            <span
                                class="font-medium text-warning tabular-nums"
                                >{{ requierenAtencion }}</span
                            >
                            {{
                                requierenAtencion === 1
                                    ? 'requiere atención'
                                    : 'requieren atención'
                            }}
                        </button>
                    </template>
                    <template v-if="filtro !== 'todos'">
                        ·
                        <button
                            type="button"
                            class="text-primary hover:underline"
                            @click="filtro = 'todos'"
                        >
                            ver todos
                        </button>
                    </template>
                </p>
            </div>
            <Button @click="abrirNuevo"
                ><FilePlus2 class="size-4" /> Nuevo formato</Button
            >
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Más acciones"
                        ><MoreHorizontal class="size-4"
                    /></Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-56">
                    <DropdownMenuItem as="a" :href="cobertura.url()"
                        ><Users class="size-4" /> Cobertura por
                        puesto</DropdownMenuItem
                    >
                    <DropdownMenuItem as="a" :href="indexFondos.url()"
                        ><Images class="size-4" /> Fondos</DropdownMenuItem
                    >
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        :disabled="revalidando"
                        @click="revalidarPendientes"
                        ><RefreshCw class="size-4" /> Revalidar
                        pendientes</DropdownMenuItem
                    >
                    <DropdownMenuItem
                        :disabled="revisandoFuentes"
                        @click="revisarFuentes"
                        ><Type class="size-4" /> Revisar
                        fuentes</DropdownMenuItem
                    >
                    <DropdownMenuItem @click="diagnosticoAbierto = true"
                        ><Stethoscope class="size-4" /> Diagnóstico del
                        entorno</DropdownMenuItem
                    >
                </DropdownMenuContent>
            </DropdownMenu>
        </header>

        <SeccionesDocumentosMaestros actual="juridicos" />

        <div class="relative sm:w-80">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="busqueda"
                type="search"
                placeholder="Buscar formato…"
                aria-label="Buscar formato"
                class="pl-9"
            />
        </div>

        <p
            v-if="filtradas.length === 0"
            class="rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            {{
                busqueda
                    ? 'Ningún formato coincide con tu búsqueda.'
                    : 'No hay formatos en este grupo.'
            }}
        </p>

        <TransitionGroup
            tag="ul"
            data-tour="documentos-maestros-lista"
            class="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
            enter-active-class="transition duration-200 ease-out motion-reduce:transition-none"
            enter-from-class="opacity-0 translate-y-1"
        >
            <li
                v-for="f in filtradas"
                :key="f.familia"
                class="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-xs transition-shadow duration-150 hover:shadow-sm"
            >
                <div class="min-w-0">
                    <p class="leading-snug font-medium">{{ f.nombre }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ f.aplica_a
                        }}<template v-if="f.version_activa">
                            · v{{ f.version_activa }}</template
                        >
                    </p>
                </div>
                <p v-if="!f.operativo" class="text-xs text-muted-foreground">
                    Referencia (no se genera)
                </p>
                <p
                    v-else-if="!f.master_id"
                    class="flex items-center gap-1.5 text-xs text-warning"
                >
                    <CircleAlert class="size-3.5" /> Falta el documento original
                </p>
                <p
                    v-else-if="grupoEstado(f) === 'listo'"
                    class="flex items-center gap-1.5 text-xs text-success"
                >
                    <CheckCircle2 class="size-3.5" /> Listo
                </p>
                <p v-else class="flex items-start gap-1.5 text-xs text-warning">
                    <CircleAlert class="mt-px size-3.5 shrink-0" />
                    <span
                        >{{
                            f.version_activa
                                ? 'Requiere atención'
                                : 'Sin versión activa'
                        }}<template v-if="pendientesDe(f).length"
                            >: {{ pendientesDe(f).join(', ') }}</template
                        ></span
                    >
                </p>
                <div v-if="f.master_id" class="mt-auto flex items-center gap-2">
                    <Button size="sm" as="a" :href="editorRuta.url(f.master_id)"
                        ><PencilRuler class="size-4" /> Editar</Button
                    >
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                size="icon"
                                variant="ghost"
                                class="size-8"
                                :aria-label="`Más acciones de ${f.nombre}`"
                                ><MoreHorizontal class="size-4"
                            /></Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuItem
                                v-if="f.operativo"
                                as="a"
                                :href="
                                    editorRuta.url(f.master_id, {
                                        query: { probar: 1 },
                                    })
                                "
                                ><UserRoundSearch class="size-4" /> Probar con
                                colaborador</DropdownMenuItem
                            >
                            <DropdownMenuItem @click="abrir(f.master_id)"
                                ><Info class="size-4" /> Más
                                información</DropdownMenuItem
                            >
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </li>
        </TransitionGroup>
    </div>

    <!-- Diagnóstico del entorno (motores de conversión, OCR, fuentes) -->
    <Sheet v-model:open="diagnosticoAbierto">
        <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-md">
            <SheetHeader>
                <SheetTitle>Diagnóstico del entorno</SheetTitle>
                <SheetDescription
                    >Herramientas del servidor que usan los
                    formatos.</SheetDescription
                >
            </SheetHeader>
            <ul class="flex flex-col gap-2 px-4 pb-6">
                <li
                    v-for="c in salud.infraestructura"
                    :key="c.clave"
                    class="flex items-start gap-2 rounded-lg border p-3 text-sm"
                >
                    <component
                        :is="iconoInfra[c.estado]"
                        :class="
                            cn('mt-0.5 size-4 shrink-0', colorInfra[c.estado])
                        "
                    />
                    <span class="min-w-0">
                        <span class="block font-medium"
                            >{{ c.etiqueta
                            }}<span
                                v-if="c.estado === 'no_instalado'"
                                class="font-normal text-muted-foreground"
                            >
                                · no instalado</span
                            ></span
                        >
                        <span
                            v-if="c.detalle"
                            class="block text-xs text-muted-foreground"
                            >{{ c.detalle }}</span
                        >
                    </span>
                </li>
            </ul>
        </SheetContent>
    </Sheet>

    <!-- Más información (detalle técnico) -->
    <Sheet v-model:open="panelAbierto">
        <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-3xl">
            <SheetHeader>
                <SheetTitle>{{
                    detalle?.nombre ?? 'Documento maestro'
                }}</SheetTitle>
                <SheetDescription>{{
                    detalle
                        ? `${detalle.proceso ?? ''} · ${detalle.grupos.length ? detalle.grupos.join(', ') : 'General'}`
                        : ''
                }}</SheetDescription>
            </SheetHeader>
            <div class="px-4 pb-6">
                <div v-if="cargandoDetalle" class="flex flex-col gap-3">
                    <Skeleton class="h-8 w-1/2" />
                    <Skeleton class="h-40 w-full" />
                </div>
                <DetalleMaestro
                    v-else-if="detalle"
                    :detalle="detalle"
                    @actualizado="alActualizar"
                    @ver-version="abrir"
                />
            </div>
        </SheetContent>
    </Sheet>

    <!-- Nuevo formato -->
    <Dialog
        :open="nuevoAbierto"
        @update:open="(v: boolean) => !subiendo && (nuevoAbierto = v)"
    >
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Nuevo formato</DialogTitle>
                <DialogDescription
                    >Sube el Word o PDF original. PEOPLE detecta los espacios y
                    abre el editor.</DialogDescription
                >
            </DialogHeader>
            <div class="flex flex-col gap-3">
                <div class="flex flex-col gap-1.5">
                    <Label for="nf-nombre">Nombre</Label>
                    <Input
                        id="nf-nombre"
                        v-model="formulario.nombre"
                        maxlength="150"
                        placeholder="Carta de recomendación"
                    />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label>Tipo de documento</Label>
                        <SelectSimple
                            v-model="formulario.clave"
                            :opciones="claves"
                            opcion-vacia="Nuevo tipo"
                            aria-label="Tipo de documento"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label>Aplica a</Label>
                        <SelectSimple
                            v-model="formulario.grupo"
                            :opciones="
                                Object.entries(grupos).map(
                                    ([value, label]) => ({ value, label }),
                                )
                            "
                            opcion-vacia="Todos los puestos"
                            aria-label="Aplica a"
                        />
                    </div>
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label>Se genera en</Label>
                    <SelectSimple
                        v-model="formulario.proceso"
                        :opciones="[
                            {
                                value: 'expediente',
                                label: 'Expediente del colaborador (a demanda)',
                            },
                            ...Object.entries(procesos)
                                .filter(([v]) => v !== 'referencia')
                                .map(([value, label]) => ({ value, label })),
                        ]"
                        aria-label="Se genera en"
                    />
                </div>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2"
                        ><Checkbox v-model="formulario.requiere_impresion" /> Se
                        imprime</label
                    >
                    <label class="flex items-center gap-2"
                        ><Checkbox v-model="formulario.requiere_firma_fisica" />
                        Firma física</label
                    >
                    <label class="flex items-center gap-2"
                        ><Checkbox v-model="formulario.requiere_huella" />
                        Huella</label
                    >
                </div>
                <PeopleFileDropzone
                    v-model="archivos"
                    accept=".docx,.pdf"
                    :max-size-mb="20"
                    :loading="subiendo"
                    label="Arrastra aquí el documento"
                    hint="Word (.docx) o PDF · Máx. 20 MB"
                    @error="(m: string) => toast.error(m)"
                />
            </div>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="subiendo"
                    @click="nuevoAbierto = false"
                    >Cancelar</Button
                >
                <Button
                    :disabled="
                        subiendo ||
                        !archivos.length ||
                        !formulario.nombre.trim()
                    "
                    @click="crearFormato"
                >
                    <Loader2 v-if="subiendo" class="size-4 animate-spin" />
                    {{ subiendo ? 'Analizando…' : 'Subir y detectar' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
