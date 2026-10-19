<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ExternalLink, Loader2, PackageCheck, PenLine, Printer, Undo2, XCircle } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Badge } from '@/components/ui/badge';
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
import { formatearFecha } from '@/lib/fechas';
import { enviarJson } from '@/lib/http';
import { dashboard } from '@/routes';
import { ciclo as cicloColaborador } from '@/routes/rh/colaboradores';
import {
    cancelar as cancelarResponsiva,
    devolucion as devolucionResponsiva,
    marcarFirmadaFisica as marcarFirmadaFisicaResponsiva,
    pdf as pdfResponsiva,
} from '@/routes/rh/responsivas';
import type { EstadoResponsiva, ResponsivaItem } from '@/types';

type Fila = ResponsivaItem & {
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        puesto: string | null;
        sucursal: string | null;
    };
};

type Paginador = {
    data: Fila[];
    current_page: number;
    last_page: number;
    per_page: number;
    from?: number | null;
    to?: number | null;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

const props = defineProps<{
    responsivas: Paginador;
    filtros: { tipo: string | null; estado: string | null; busqueda: string };
    tipos: { value: string; label: string }[];
    estados: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Activos y responsivas', href: '' },
        ],
    },
});

const variantes: Record<EstadoResponsiva, 'warning' | 'success' | 'info' | 'destructive'> = {
    generada: 'warning',
    firmada: 'success',
    devuelta: 'info',
    cancelada: 'destructive',
};

const termino = ref(props.filtros.busqueda);
const tipo = ref(props.filtros.tipo ?? '');
const estado = ref(props.filtros.estado ?? '');

function filtrar() {
    router.get(
        window.location.pathname,
        {
            busqueda: termino.value || undefined,
            tipo: tipo.value || undefined,
            estado: estado.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(termino, useDebounceFn(filtrar, 300));
watch([tipo, estado], filtrar);

// Operaciones: reutilizan los endpoints per-persona de ResponsivaController
// (misma regla de negocio en ResponsivaService), luego recargan la lista.
type Accion = 'firmada_fisica' | 'devolucion' | 'cancelar';
const accion = ref<Accion | null>(null);
const seleccionada = ref<Fila | null>(null);
const fechaDevolucion = ref('');
const texto = ref('');
const enviando = ref(false);
const hoy = new Date().toLocaleDateString('en-CA');

const titulos: Record<Accion, string> = {
    firmada_fisica: 'Marcar responsiva firmada físicamente',
    devolucion: 'Registrar devolución del equipo',
    cancelar: 'Cancelar responsiva',
};

function abrir(tipoAccion: Accion, fila: Fila) {
    seleccionada.value = fila;
    accion.value = tipoAccion;
    fechaDevolucion.value = hoy;
    texto.value = '';
}

async function confirmar() {
    const fila = seleccionada.value;

    if (fila === null || accion.value === null) {
        return;
    }

    enviando.value = true;

    try {
        let respuesta: { message: string };

        if (accion.value === 'firmada_fisica') {
            respuesta = await enviarJson('POST', marcarFirmadaFisicaResponsiva.url(fila.id), {});
        } else if (accion.value === 'devolucion') {
            respuesta = await enviarJson('POST', devolucionResponsiva.url(fila.id), {
                devuelta_en: fechaDevolucion.value,
                observaciones: texto.value,
            });
        } else {
            respuesta = await enviarJson('POST', cancelarResponsiva.url(fila.id), { motivo: texto.value });
        }

        toast.success(respuesta.message);
        accion.value = null;
        router.reload({ only: ['responsivas'] });
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo completar.');
    } finally {
        enviando.value = false;
    }
}
</script>

<template>
    <Head title="Activos y responsivas" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Activos y responsivas"
            descripcion="Panorama de las responsivas de equipo entregadas a los colaboradores de tu alcance (bien mueble, equipo electrónico y vehículo). Es documental, no inventario."
            :icono="PackageCheck"
        />

        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div class="flex-1">
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]">Buscar colaborador</Label>
                <Input v-model="termino" placeholder="Nombre o número de empleado" />
            </div>
            <div class="w-full sm:w-52">
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]">Familia</Label>
                <SelectSimple
                    v-model="tipo"
                    :opciones="tipos.map((t) => ({ value: t.value, label: t.label }))"
                    opcion-vacia="Todas"
                    placeholder="Todas"
                />
            </div>
            <div class="w-full sm:w-52">
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]">Estado</Label>
                <SelectSimple
                    v-model="estado"
                    :opciones="estados.map((e) => ({ value: e.value, label: e.label }))"
                    opcion-vacia="Todos"
                    placeholder="Todos"
                />
            </div>
        </div>

        <p
            v-if="!responsivas.data.length"
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-4 py-8 text-center text-sm text-[var(--mrl-texto-suave)]"
        >
            No hay responsivas registradas en tu alcance con estos filtros. Genéralas desde la ficha del colaborador.
        </p>

        <template v-else>
            <!-- Tabla (pantallas anchas) -->
            <div class="tabla-scroll hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] md:block">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--mrl-borde)] text-xs text-[var(--mrl-texto-suave)]">
                        <tr>
                            <th class="px-4 py-3 font-medium">Colaborador</th>
                            <th class="px-4 py-3 font-medium">Puesto / Sucursal</th>
                            <th class="px-4 py-3 font-medium">Familia / Activo</th>
                            <th class="px-4 py-3 font-medium">Estado</th>
                            <th class="px-4 py-3 font-medium">Entrega</th>
                            <th class="px-4 py-3 text-right font-medium">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in responsivas.data"
                            :key="r.id"
                            class="border-b border-[var(--mrl-borde)] last:border-0"
                            :class="{ 'opacity-70': r.estado === 'cancelada' }"
                        >
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ r.colaborador.nombre }}</p>
                                <p class="text-xs text-[var(--mrl-texto-suave)]">
                                    {{ r.colaborador.numero_empleado ?? '—' }} · {{ r.folio }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ r.colaborador.puesto ?? '—' }}<br />
                                <span class="text-[var(--mrl-texto-suave)]">{{ r.colaborador.sucursal ?? '—' }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span class="font-medium">{{ r.tipo_etiqueta }}</span>
                                <span v-if="r.resumen.length" class="block text-[var(--mrl-texto-suave)]">{{ r.resumen.join(' · ') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :variant="variantes[r.estado]">{{ r.estado_etiqueta }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ formatearFecha(r.fecha_entrega) }}<br />
                                <span class="text-[var(--mrl-texto-suave)]">{{ r.entregado_por }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center justify-end gap-1.5">
                                    <Button v-if="r.tiene_pdf" size="sm" variant="outline" as-child>
                                        <a :href="pdfResponsiva.url(r.id)" target="_blank" rel="noopener">
                                            <Printer class="size-3.5" /> PDF
                                        </a>
                                    </Button>
                                    <Button v-if="r.puede_operar && r.estado === 'generada'" size="sm" variant="secondary" @click="abrir('firmada_fisica', r)">
                                        <PenLine class="size-3.5" /> Firmada
                                    </Button>
                                    <Button v-if="r.puede_operar" size="sm" variant="ghost" @click="abrir('devolucion', r)">
                                        <Undo2 class="size-3.5" /> Devolución
                                    </Button>
                                    <Button v-if="r.puede_cancelar" size="sm" variant="ghost" class="text-destructive" @click="abrir('cancelar', r)">
                                        <XCircle class="size-3.5" /> Cancelar
                                    </Button>
                                    <Button size="sm" variant="ghost" as-child>
                                        <Link :href="cicloColaborador.url(r.colaborador.id)">
                                            <ExternalLink class="size-3.5" /> Ficha
                                        </Link>
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Tarjetas (pantallas estrechas): una columna -->
            <ul class="flex flex-col gap-3 md:hidden">
                <li
                    v-for="r in responsivas.data"
                    :key="r.id"
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm"
                    :class="{ 'opacity-70': r.estado === 'cancelada' }"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ r.colaborador.nombre }}</span>
                        <Badge :variant="variantes[r.estado]">{{ r.estado_etiqueta }}</Badge>
                    </div>
                    <p class="mt-0.5 text-xs text-[var(--mrl-texto-suave)]">
                        {{ r.colaborador.puesto ?? '—' }} · {{ r.colaborador.sucursal ?? '—' }}
                    </p>
                    <p class="mt-1 text-xs">
                        <span class="font-medium">{{ r.tipo_etiqueta }}</span>
                        <template v-if="r.resumen.length"> · {{ r.resumen.join(' · ') }}</template>
                    </p>
                    <p class="mt-1 text-xs text-[var(--mrl-texto-suave)]">
                        {{ r.folio }} · {{ formatearFecha(r.fecha_entrega) }} · Entregó {{ r.entregado_por }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <Button v-if="r.tiene_pdf" size="sm" variant="outline" as-child>
                            <a :href="pdfResponsiva.url(r.id)" target="_blank" rel="noopener"><Printer class="size-3.5" /> PDF</a>
                        </Button>
                        <Button v-if="r.puede_operar && r.estado === 'generada'" size="sm" variant="secondary" @click="abrir('firmada_fisica', r)">
                            <PenLine class="size-3.5" /> Firmada
                        </Button>
                        <Button v-if="r.puede_operar" size="sm" variant="ghost" @click="abrir('devolucion', r)">
                            <Undo2 class="size-3.5" /> Devolución
                        </Button>
                        <Button v-if="r.puede_cancelar" size="sm" variant="ghost" class="text-destructive" @click="abrir('cancelar', r)">
                            <XCircle class="size-3.5" /> Cancelar
                        </Button>
                        <Button size="sm" variant="ghost" as-child>
                            <Link :href="cicloColaborador.url(r.colaborador.id)"><ExternalLink class="size-3.5" /> Ficha</Link>
                        </Button>
                    </div>
                </li>
            </ul>

            <PaginacionTabla :datos="responsivas" />
        </template>

        <Dialog :open="accion !== null" @update:open="(v: boolean) => (accion = v ? accion : null)">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ accion ? titulos[accion] : '' }}</DialogTitle>
                    <DialogDescription>Responsiva {{ seleccionada?.folio }} · {{ seleccionada?.colaborador.nombre }}</DialogDescription>
                </DialogHeader>

                <p v-if="accion === 'firmada_fisica'" class="rounded-xl bg-crema/50 px-4 py-3 text-sm text-muted-foreground">
                    La responsiva se imprimió y se firmó en papel. Al confirmar, queda registrada como firmada (quién y cuándo); no se sube ningún archivo.
                </p>

                <div v-else-if="accion === 'devolucion'" class="grid gap-4">
                    <div class="grid gap-1.5">
                        <Label for="responsiva-devuelta">Fecha de devolución</Label>
                        <DatePicker id="responsiva-devuelta" v-model="fechaDevolucion" :max-value="hoy" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="responsiva-obs">Observaciones (opcional)</Label>
                        <Textarea id="responsiva-obs" v-model="texto" rows="3" maxlength="2000" placeholder="Estado en que se recibió el equipo…" />
                    </div>
                </div>

                <div v-else-if="accion === 'cancelar'" class="grid gap-1.5">
                    <Label for="responsiva-motivo">Motivo de la cancelación</Label>
                    <Textarea id="responsiva-motivo" v-model="texto" rows="3" maxlength="500" placeholder="Se capturó mal el número de serie…" />
                    <p class="text-xs text-muted-foreground">La responsiva no se borra: queda en el historial como cancelada.</p>
                </div>

                <DialogFooter class="gap-2">
                    <Button variant="ghost" @click="accion = null">Cerrar</Button>
                    <Button
                        :variant="accion === 'cancelar' ? 'destructive' : 'default'"
                        :disabled="
                            enviando ||
                            (accion === 'devolucion' && fechaDevolucion === '') ||
                            (accion === 'cancelar' && texto.trim().length < 5)
                        "
                        @click="confirmar"
                    >
                        <Loader2 v-if="enviando" class="size-4 animate-spin" />
                        {{ accion === 'cancelar' ? 'Cancelar responsiva' : accion === 'firmada_fisica' ? 'Marcar firmada físicamente' : 'Registrar devolución' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
