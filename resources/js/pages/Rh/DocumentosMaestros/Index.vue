<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Banknote,
    CalendarClock,
    FileCheck2,
    FileClock,
    FileSignature,
    FileStack,
    FileWarning,
    Handshake,
    Loader2,
    LogOut,
    Search,
    ShieldQuestion,
    UserRoundX,
    Images,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import { toast } from 'vue-sonner';
import KpiCard from '@/components/Dashboard/KpiCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DetalleMaestro from '@/components/documentos/maestros/DetalleMaestro.vue';
import EstadoDisenoBadge from '@/components/documentos/maestros/EstadoDisenoBadge.vue';
import EstadoMaestroBadge from '@/components/documentos/maestros/EstadoMaestroBadge.vue';
import SeccionesDocumentosMaestros from '@/components/documentos/maestros/SeccionesDocumentosMaestros.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useDocumentosMaestros } from '@/composables/useDocumentosMaestros';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { cobertura } from '@/routes/rh/documentos-maestros';
import { index as indexFondos } from '@/routes/rh/documentos-maestros/fondos';
import type {
    EstadoEjecutivoMaster,
    KpisMaestros,
    MasterAdminFila,
    MasterDetalle,
} from '@/types';

/**
 * Administración → Documentos maestros: los formatos oficiales de Jurídico
 * que PEOPLE usa automáticamente en cada proceso. Aquí solo se cargan
 * versiones, se valida su diseño, se prueban y se activan; los documentos
 * de cada persona se generan en su proceso (ficha, cierre, solicitud…).
 */
const props = defineProps<{
    masters: MasterAdminFila[];
    kpis: KpisMaestros;
    grupos: Record<string, string>;
    procesos: Record<string, string>;
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
const filtroProceso = ref('todos');
const filtroGrupo = ref('todos');
const filtroEstado = ref<'todos' | EstadoEjecutivoMaster | 'sin_validar'>(
    'todos',
);
const busqueda = ref('');

function normalizar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const filtradas = computed(() =>
    props.masters.filter((f) => {
        if (
            filtroProceso.value !== 'todos' &&
            f.proceso !== filtroProceso.value
        ) {
            return false;
        }

        if (filtroGrupo.value === 'general' && f.grupos.length > 0) {
            return false;
        }

        if (
            filtroGrupo.value !== 'todos' &&
            filtroGrupo.value !== 'general' &&
            !f.grupos.includes(filtroGrupo.value)
        ) {
            return false;
        }

        if (filtroEstado.value === 'sin_validar') {
            if (
                f.versiones_sin_validar === 0 &&
                f.diseno !== 'sin_validar' &&
                f.diseno !== 'fallido'
            ) {
                return false;
            }
        } else if (
            filtroEstado.value !== 'todos' &&
            f.estado_ejecutivo !== filtroEstado.value
        ) {
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

const iconoProceso: Record<string, Component> = {
    alta: FileSignature,
    renovacion: CalendarClock,
    evaluacion: FileCheck2,
    baja: LogOut,
    negativa_firma: UserRoundX,
    permiso: FileClock,
    prestamo: Banknote,
    activos: Handshake,
    referencia: ShieldQuestion,
};

// ───────── Detalle ─────────
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
    router.reload({ only: ['masters', 'kpis'] });
}

// ───────── Nueva versión ─────────
const familiaCarga = ref<MasterAdminFila | null>(null);
const archivos = ref<File[]>([]);
const subiendo = ref(false);

function nuevaVersion(fila: MasterAdminFila | null) {
    familiaCarga.value = fila;
    archivos.value = [];
}

function nuevaVersionDesdeDetalle() {
    const fila =
        props.masters.find((f) => f.familia === detalle.value?.familia) ?? null;
    nuevaVersion(fila);
}

async function cargarVersion() {
    const archivo = archivos.value[0];

    if (!familiaCarga.value || !archivo) {
        return;
    }

    subiendo.value = true;

    try {
        const r = await api.cargarVersion(familiaCarga.value.familia, archivo);
        toast.success(r.message);
        familiaCarga.value = null;
        detalle.value = r.data;
        panelAbierto.value = true;
        router.reload({ only: ['masters', 'kpis'] });
    } catch (e) {
        toast.error(
            e instanceof Error ? e.message : 'No se pudo cargar la versión.',
        );
    } finally {
        subiendo.value = false;
    }
}

function tamano(bytes: number): string {
    return bytes > 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
</script>

<template>
    <Head title="Documentos maestros" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Documentos maestros"
            descripcion="Formatos oficiales utilizados automáticamente por PEOPLE en cada proceso."
            :icono="FileStack"
        >
            <Button variant="outline" as="a" :href="cobertura.url()">
                <Users class="size-4" /> Cobertura por puesto
            </Button>
            <Button variant="outline" as="a" :href="indexFondos.url()">
                <Images class="size-4" /> Fondos
            </Button>
        </CrudPageHeader>

        <SeccionesDocumentosMaestros actual="juridicos" />

        <header class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">Documentos maestros</h1>
            <p class="text-sm text-[var(--mrl-texto-suave)]">
                Formatos oficiales utilizados automáticamente por PEOPLE en cada
                proceso. El original de Jurídico nunca se modifica: cada
                documento sale idéntico, solo con los datos de la persona.
            </p>
        </header>

        <div
            data-tour="documentos-maestros-kpis"
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <KpiCard
                titulo="Formatos activos"
                :valor="kpis.formatos_activos"
                :icono="FileCheck2"
                tono="success"
            />
            <KpiCard
                titulo="Requieren revisión"
                :valor="kpis.requieren_revision"
                :icono="FileWarning"
                :tono="kpis.requieren_revision ? 'warning' : 'default'"
            />
            <KpiCard
                titulo="Puestos sin cobertura"
                :valor="kpis.puestos_sin_cobertura"
                :icono="Users"
                :tono="kpis.puestos_sin_cobertura ? 'danger' : 'default'"
                :href="cobertura.url()"
            />
            <KpiCard
                titulo="Versiones sin validar"
                :valor="kpis.versiones_sin_validar"
                :icono="ShieldQuestion"
                :tono="kpis.versiones_sin_validar ? 'warning' : 'default'"
            />
        </div>

        <div
            class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center"
        >
            <Select v-model="filtroProceso">
                <SelectTrigger class="sm:w-52" aria-label="Proceso"
                    ><SelectValue placeholder="Proceso"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="todos">Todos los procesos</SelectItem>
                    <SelectItem
                        v-for="(etiqueta, clave) in procesos"
                        :key="clave"
                        :value="String(clave)"
                        >{{ etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select v-model="filtroGrupo">
                <SelectTrigger class="sm:w-52" aria-label="Grupo"
                    ><SelectValue placeholder="Grupo"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="todos">Todos los puestos</SelectItem>
                    <SelectItem value="general">General</SelectItem>
                    <SelectItem
                        v-for="(etiqueta, clave) in grupos"
                        :key="clave"
                        :value="String(clave)"
                        >{{ etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select v-model="filtroEstado">
                <SelectTrigger class="sm:w-52" aria-label="Estado"
                    ><SelectValue placeholder="Estado"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="todos">Todos los estados</SelectItem>
                    <SelectItem value="listo">Listo</SelectItem>
                    <SelectItem value="requiere_revision"
                        >Requiere revisión</SelectItem
                    >
                    <SelectItem value="bloqueado">Bloqueado</SelectItem>
                    <SelectItem value="sin_formato">Sin formato</SelectItem>
                    <SelectItem value="sin_validar"
                        >Falta validar diseño</SelectItem
                    >
                    <SelectItem value="referencia">Referencia</SelectItem>
                </SelectContent>
            </Select>
            <div class="relative sm:ml-auto sm:w-72">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[var(--mrl-texto-suave)]"
                />
                <Input
                    v-model="busqueda"
                    type="search"
                    placeholder="Buscar documento…"
                    class="pl-9"
                />
            </div>
        </div>

        <p
            v-if="filtradas.length === 0"
            class="rounded-2xl border border-dashed border-[var(--mrl-borde)] p-8 text-center text-sm text-[var(--mrl-texto-suave)]"
        >
            Ningún documento coincide con los filtros.
        </p>

        <ul
            data-tour="documentos-maestros-lista"
            class="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
        >
            <li v-for="f in filtradas" :key="f.familia">
                <button
                    type="button"
                    class="group flex h-full w-full flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-left shadow-sm transition-all hover:border-primary/40 hover:shadow-md disabled:cursor-default disabled:opacity-70"
                    :disabled="f.master_id === null"
                    @click="abrir(f.master_id)"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                        >
                            <component
                                :is="iconoProceso[f.proceso ?? ''] ?? FileStack"
                                class="size-5"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="leading-snug font-medium">
                                {{ f.nombre }}
                            </p>
                            <p class="text-xs text-[var(--mrl-texto-suave)]">
                                {{ f.proceso_etiqueta ?? '—' }} ·
                                {{ f.aplica_a }}
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <EstadoMaestroBadge :estado="f.estado_ejecutivo" />
                        <EstadoDisenoBadge :diseno="f.diseno" />
                    </div>
                    <div
                        class="mt-auto flex items-center justify-between gap-2 text-xs text-[var(--mrl-texto-suave)]"
                    >
                        <span>{{
                            f.version_activa
                                ? `Versión activa v${f.version_activa}`
                                : 'Sin versión activa'
                        }}</span>
                        <span v-if="f.ultima_prueba_en"
                            >Probado
                            {{ formatearFecha(f.ultima_prueba_en) }}</span
                        >
                        <span v-else-if="f.master_id === null"
                            >Falta el original de Jurídico</span
                        >
                    </div>
                    <p
                        v-if="f.versiones_sin_validar > 0 && f.operativo"
                        class="text-xs text-amber-700 dark:text-amber-300"
                    >
                        {{ f.versiones_sin_validar }} versión(es) sin validar
                    </p>
                </button>
            </li>
        </ul>
    </div>

    <!-- Detalle -->
    <Sheet v-model:open="panelAbierto">
        <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-3xl">
            <SheetHeader>
                <SheetTitle>{{
                    detalle?.nombre ?? 'Documento maestro'
                }}</SheetTitle>
                <SheetDescription>
                    {{
                        detalle
                            ? `${detalle.proceso ?? ''} · ${detalle.grupos.length ? detalle.grupos.join(', ') : 'General'}`
                            : ''
                    }}
                </SheetDescription>
            </SheetHeader>
            <div class="px-4 pb-6">
                <div v-if="cargandoDetalle" class="flex flex-col gap-3">
                    <Skeleton class="h-8 w-1/2" />
                    <Skeleton class="h-32 w-full" />
                    <Skeleton class="h-40 w-full" />
                </div>
                <DetalleMaestro
                    v-else-if="detalle"
                    :detalle="detalle"
                    @actualizado="alActualizar"
                    @nueva-version="nuevaVersionDesdeDetalle"
                    @ver-version="abrir"
                />
            </div>
        </SheetContent>
    </Sheet>

    <!-- Nueva versión -->
    <Dialog
        :open="familiaCarga !== null"
        @update:open="(v: boolean) => !v && !subiendo && (familiaCarga = null)"
    >
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle
                    >Nueva versión: {{ familiaCarga?.nombre }}</DialogTitle
                >
                <DialogDescription>
                    Sube el archivo ORIGINAL que entregó Jurídico, sin editarlo.
                    PEOPLE lo conserva intacto, prepara su copia técnica, valida
                    que el resultado se vea idéntico y deja la versión inactiva
                    hasta que la actives.
                </DialogDescription>
            </DialogHeader>
            <PeopleFileDropzone
                v-model="archivos"
                :accept="
                    familiaCarga?.motor === 'pdf_overlay' ? '.pdf' : '.docx'
                "
                :max-size-mb="20"
                :loading="subiendo"
                label="Arrastra aquí el documento oficial"
                :hint="`${familiaCarga?.motor === 'pdf_overlay' ? 'PDF' : 'DOCX'} · Máx. 20 MB`"
                @error="(m: string) => toast.error(m)"
            />
            <p v-if="archivos[0]" class="text-xs text-[var(--mrl-texto-suave)]">
                {{ archivos[0].name }} · {{ tamano(archivos[0].size) }} ·
                {{
                    archivos[0].name.toLowerCase().endsWith('.pdf')
                        ? 'PDF'
                        : 'Word (DOCX)'
                }}
            </p>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="subiendo"
                    @click="familiaCarga = null"
                    >Cancelar</Button
                >
                <Button
                    :disabled="subiendo || archivos.length === 0"
                    @click="cargarVersion"
                >
                    <Loader2 v-if="subiendo" class="size-4 animate-spin" />
                    {{
                        subiendo
                            ? 'Preparando y validando diseño…'
                            : 'Cargar y validar'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
