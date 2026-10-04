<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FileStack } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatearFecha } from '@/lib/fechas';
import { leerCookie } from '@/lib/http';
import { dashboard } from '@/routes';
import type { MasterAdminFila, MasterDetalle } from '@/types';

/**
 * Administración → Documentos maestros. RH carga UNA VEZ el documento
 * jurídico original (DOCX/PDF); el sistema prepara el master técnico y su
 * mapa de campos. Aquí solo se versiona, activa y prueba. Los documentos
 * de cada persona se generan en su proceso (ficha, cierre, solicitud…).
 */
const props = defineProps<{
    masters: MasterAdminFila[];
    grupos: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Documentos maestros', href: '' },
        ],
    },
});

const filas = ref<MasterAdminFila[]>(props.masters);
const detalle = ref<MasterDetalle | null>(null);
const familiaCarga = ref<MasterAdminFila | null>(null);
const archivo = ref<File | null>(null);
const colaboradorPrueba = ref('');
const ocupado = ref(false);
const faltantesPrueba = ref<string[] | null>(null);

async function pedir<T>(metodo: 'GET' | 'POST', url: string, cuerpo?: FormData | Record<string, unknown>): Promise<T> {
    const esForm = cuerpo instanceof FormData;
    const r = await fetch(url, {
        method: metodo,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
            ...(cuerpo && !esForm ? { 'Content-Type': 'application/json' } : {}),
        },
        body: cuerpo === undefined ? undefined : esForm ? cuerpo : JSON.stringify(cuerpo),
    });
    const datos = (await r.json().catch(() => null)) as (T & { message?: string; errors?: Record<string, string[]> }) | null;

    if (!r.ok) {
        throw new Error((datos?.errors ? Object.values(datos.errors)[0]?.[0] : undefined) ?? datos?.message ?? `Error ${r.status}`);
    }

    return datos as T;
}

async function verDetalle(id: number | null) {
    if (id === null) {
        return;
    }

    faltantesPrueba.value = null;
    detalle.value = (await pedir<{ data: MasterDetalle }>('GET', `/rh/documentos-maestros/${id}`)).data;
}

async function cargarVersion() {
    if (!familiaCarga.value || !archivo.value) {
        return;
    }

    const datos = new FormData();
    datos.append('archivo', archivo.value);
    ocupado.value = true;

    try {
        const r = await pedir<{ message: string; data: MasterDetalle }>('POST', `/rh/documentos-maestros/familia/${familiaCarga.value.familia}/versiones`, datos);
        toast.success(r.message);
        familiaCarga.value = null;
        detalle.value = r.data;
        window.location.reload();
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo cargar.');
    } finally {
        ocupado.value = false;
    }
}

async function cambiarActivo(activar: boolean) {
    if (!detalle.value) {
        return;
    }

    ocupado.value = true;

    try {
        const r = await pedir<{ message: string; data: MasterDetalle }>('POST', `/rh/documentos-maestros/${detalle.value.id}/${activar ? 'activar' : 'desactivar'}`);
        toast.success(r.message);
        detalle.value = r.data;
        filas.value = filas.value.map((f) => (f.familia === r.data.familia ? { ...f, activo: activar && r.data.activo, version_activa: activar ? r.data.version : null } : f));
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo cambiar.');
    } finally {
        ocupado.value = false;
    }
}

async function probar() {
    if (!detalle.value || !colaboradorPrueba.value) {
        return;
    }

    ocupado.value = true;

    try {
        const r = await fetch(`/rh/documentos-maestros/${detalle.value.id}/probar`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
            },
            body: JSON.stringify({ colaborador_id: Number(colaboradorPrueba.value) }),
        });

        if (!r.ok) {
            const datos = (await r.json().catch(() => null)) as { message?: string } | null;

            throw new Error(datos?.message ?? `Error ${r.status}`);
        }

        faltantesPrueba.value = JSON.parse(r.headers.get('X-Faltantes') ?? '[]') as string[];
        window.open(URL.createObjectURL(await r.blob()), '_blank', 'noopener');
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo generar la prueba.');
    } finally {
        ocupado.value = false;
    }
}

function varianteEstado(estado: string): 'success' | 'warning' | 'destructive' | 'outline' {
    return estado === 'listo' ? 'success' : estado === 'referencia' ? 'outline' : estado === 'con_pendientes' ? 'warning' : 'destructive';
}

const etiquetaAccion: Record<string, string> = {
    documento_maestro_importado: 'Versión cargada',
    documento_maestro_activado: 'Activada',
    documento_maestro_desactivado: 'Desactivada',
    documento_maestro_probado: 'Probada con colaborador',
};

const etiquetaEstado: Record<string, string> = {
    listo: 'Listo',
    con_pendientes: 'Con pendientes',
    bloqueado: 'Bloqueado',
    referencia: 'Referencia (no se genera)',
    sin_original: 'Sin original cargado',
};
</script>

<template>
    <Head title="Documentos maestros" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Documentos maestros"
            descripcion="Formatos jurídicos originales de RH/Jurídico. Se cargan una vez; PEOPLE los llena en cada proceso (alta, baja, permiso, préstamo…)."
            :icono="FileStack"
        />

        <div
            data-tour="documentos-maestros-lista"
            class="overflow-x-auto rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
        >
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-[var(--mrl-texto-suave)] uppercase">
                    <tr>
                        <th class="p-3">Documento</th>
                        <th class="p-3">Proceso</th>
                        <th class="p-3">Aplica a</th>
                        <th class="p-3">Empresa</th>
                        <th class="p-3">Versión</th>
                        <th class="p-3">Estado</th>
                        <th class="p-3">Campos</th>
                        <th class="p-3">Última prueba</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in filas" :key="f.familia" class="border-t border-[var(--mrl-borde)]">
                        <td class="p-3 font-medium">{{ f.nombre }}</td>
                        <td class="p-3">{{ f.proceso_etiqueta ?? '—' }}</td>
                        <td class="p-3">{{ f.aplica_a }}</td>
                        <td class="p-3">{{ f.empresa }}</td>
                        <td class="p-3">{{ f.version_activa ? `v${f.version_activa}` : '—' }}</td>
                        <td class="p-3">
                            <Badge :variant="varianteEstado(f.estado)">{{ etiquetaEstado[f.estado] ?? f.estado }}</Badge>
                            <span v-if="f.operativo && !f.activo && f.estado === 'listo'" class="ml-1 text-xs text-[var(--mrl-texto-suave)]">(inactivo)</span>
                        </td>
                        <td class="p-3 text-xs">
                            {{ f.detectados }} detectados · {{ f.mapeados }} mapeados ·
                            <span :class="f.pendientes ? 'font-semibold text-red-700' : ''">{{ f.pendientes }} pendientes</span>
                        </td>
                        <td class="p-3 text-xs">{{ f.ultima_prueba_en ? formatearFecha(f.ultima_prueba_en) : '—' }}</td>
                        <td class="p-3">
                            <div class="flex gap-2">
                                <Button size="sm" variant="outline" :disabled="f.master_id === null" @click="verDetalle(f.master_id)">Ver</Button>
                                <Button v-if="f.operativo" size="sm" variant="outline" @click="familiaCarga = f; archivo = null">Nueva versión</Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <Dialog :open="familiaCarga !== null" @update:open="(v: boolean) => !v && (familiaCarga = null)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Nueva versión: {{ familiaCarga?.nombre }}</DialogTitle>
                <DialogDescription>
                    Sube el archivo ORIGINAL que entregó Jurídico (sin editar
                    ni agregar marcadores). PEOPLE aplica el mismo mapa de
                    campos de esta familia y te muestra el reporte; la versión
                    queda inactiva hasta que la pruebes y la actives.
                </DialogDescription>
            </DialogHeader>
            <Input type="file" :accept="familiaCarga?.motor === 'pdf_overlay' ? '.pdf' : '.docx'" @change="(e: Event) => (archivo = (e.target as HTMLInputElement).files?.[0] ?? null)" />
            <Button :disabled="ocupado || !archivo" @click="cargarVersion">Cargar y preparar</Button>
        </DialogContent>
    </Dialog>

    <Dialog :open="detalle !== null" @update:open="(v: boolean) => !v && (detalle = null)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ detalle?.nombre }} · v{{ detalle?.version }}</DialogTitle>
                <DialogDescription>
                    {{ detalle?.proceso }} · {{ detalle?.grupos.length ? detalle.grupos.join(', ') : 'General' }} ·
                    motor {{ detalle?.motor === 'pdf_overlay' ? 'PDF original + overlay' : 'Word original preparado' }}
                </DialogDescription>
            </DialogHeader>
            <div v-if="detalle" class="flex flex-col gap-4 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :variant="varianteEstado(detalle.estado ?? '')">{{ etiquetaEstado[detalle.estado ?? ''] ?? detalle.estado }}</Badge>
                    <Badge :variant="detalle.activo ? 'success' : 'outline'">{{ detalle.activo ? 'Activa' : 'Inactiva' }}</Badge>
                    <a class="text-xs underline" :href="`/rh/documentos-maestros/${detalle.id}/original`">Descargar original de Jurídico</a>
                </div>
                <p>
                    <strong>{{ detalle.reporte.detectados }}</strong> campos detectados ·
                    <strong>{{ detalle.reporte.mapeados }}</strong> mapeados ·
                    {{ detalle.reporte.firmas }} líneas de firma (se dejan en blanco) ·
                    <strong :class="detalle.reporte.pendientes.length + detalle.reporte.reglas_pendientes.length ? 'text-red-700' : ''">
                        {{ detalle.reporte.pendientes.length + detalle.reporte.reglas_pendientes.length }} pendientes
                    </strong>
                </p>
                <ul v-if="detalle.reporte.pendientes.length || detalle.reporte.reglas_pendientes.length" class="list-disc pl-5 text-xs text-red-800">
                    <li v-for="(p, i) in detalle.reporte.pendientes" :key="`p${i}`">{{ p.tipo }}: {{ p.contexto }}</li>
                    <li v-for="(p, i) in detalle.reporte.reglas_pendientes" :key="`r${i}`">Regla {{ p.regla }} ({{ p.campo }}) sin contexto {{ p.contexto }}</li>
                </ul>
                <p class="text-xs text-[var(--mrl-texto-suave)]">Datos que llena PEOPLE: {{ detalle.reporte.campos.join(', ') }}</p>
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    Requiere: {{ detalle.banderas.impresion ? 'impresión' : '' }} {{ detalle.banderas.firma_fisica ? '· firma física' : '' }}
                    {{ detalle.banderas.huella ? '· huella' : '' }} {{ detalle.banderas.testigos ? `· ${detalle.banderas.cantidad_testigos} testigos` : '' }}
                    {{ detalle.banderas.envio_corporativo ? '· envío del original a corporativo' : '' }}
                </p>
                <div v-if="detalle.observaciones.length">
                    <p class="text-xs font-semibold">Observaciones para RH/Jurídico</p>
                    <ul class="list-disc pl-5 text-xs">
                        <li v-for="(o, i) in detalle.observaciones" :key="i">{{ o }}</li>
                    </ul>
                </div>
                <div v-if="detalle.historial.length">
                    <p class="text-xs font-semibold">Historial (quién cargó, activó o probó)</p>
                    <ul class="text-xs">
                        <li v-for="(h, i) in detalle.historial" :key="i">
                            {{ h.en ? formatearFecha(h.en) : '' }} ·
                            {{ etiquetaAccion[h.accion] ?? h.accion }} ·
                            {{ h.por ?? 'Sistema (importador)' }}
                        </li>
                    </ul>
                </div>
                <div class="rounded-xl bg-[var(--mrl-fondo)] p-3">
                    <Label for="prueba">Probar con colaborador (ID)</Label>
                    <div class="mt-1 flex gap-2">
                        <Input id="prueba" v-model="colaboradorPrueba" type="number" min="1" class="w-32" />
                        <Button size="sm" :disabled="ocupado || !colaboradorPrueba" @click="probar">Generar vista previa</Button>
                    </div>
                    <p v-if="faltantesPrueba" class="mt-2 text-xs">
                        {{ faltantesPrueba.length ? `Faltan: ${faltantesPrueba.join(', ')} (marcados [FALTA] en el PDF).` : 'Sin datos faltantes.' }}
                    </p>
                    <p class="mt-1 text-xs text-[var(--mrl-texto-suave)]">QA administrativo: la vista previa no se guarda en ningún expediente.</p>
                </div>
                <div class="flex gap-2">
                    <Button v-if="!detalle.activo" :disabled="ocupado || detalle.estado !== 'listo'" @click="cambiarActivo(true)">Activar esta versión</Button>
                    <Button v-else variant="outline" :disabled="ocupado" @click="cambiarActivo(false)">Desactivar</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
