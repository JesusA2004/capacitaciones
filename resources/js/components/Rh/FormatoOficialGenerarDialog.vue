<script setup lang="ts">
import { AlertTriangle, CheckCircle2, Download, ExternalLink, Eye, ListChecks, Sparkles } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
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
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { enviarJson } from '@/lib/http';
import { generar, preparar, vistaPrevia } from '@/routes/rh/formatos-oficiales';
import type { PersonaDisponible, PreparacionFormato } from '@/types';

/**
 * Generar un documento desde una plantilla oficial: elige persona (o viene
 * fija desde el expediente/solicitud), el backend dice qué falta, qué
 * datos manuales pide la plantilla y de qué solicitud/préstamo/contrato
 * sale; vista previa real; genera el PDF final (docs/FORMATOS_OFICIALES.md).
 * Nunca pide datos que People ya conoce.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        formato: { id: number; nombre: string; aplica_a?: string };
        colaboradoresDisponibles?: PersonaDisponible[];
        candidatosDisponibles?: PersonaDisponible[];
        puedeDescargar: boolean;
        sujetoFijo?: { tipo: 'colaborador' | 'candidato'; id: number; nombre: string } | null;
        solicitudId?: number | null;
    }>(),
    { colaboradoresDisponibles: () => [], candidatosDisponibles: () => [], sujetoFijo: null, solicitudId: null },
);

const emit = defineEmits<{
    'update:open': [valor: boolean];
    generado: [];
}>();

const { mostrarError } = useAlertas();

const tipoSujeto = ref<'colaborador' | 'candidato'>(props.sujetoFijo?.tipo ?? (props.formato.aplica_a === 'candidato' ? 'candidato' : 'colaborador'));
const sujetoId = ref(props.sujetoFijo ? String(props.sujetoFijo.id) : '');
const contexto = ref<Record<string, string>>(props.solicitudId ? { solicitud: String(props.solicitudId) } : {});
const manuales = ref<Record<string, string>>({});
const guardarEnExpediente = ref(true);

const preparacion = ref<PreparacionFormato | null>(null);
const cargando = ref(false);
const previsualizando = ref(false);
const generando = ref(false);
const pdfPrevia = ref<string | null>(null);
const resultado = ref<{ nombre: string; version: number | null; en_expediente: boolean; descargar_url: string; ver_url: string } | null>(null);

const opcionesPersona = computed(() =>
    (tipoSujeto.value === 'colaborador' ? props.colaboradoresDisponibles : props.candidatosDisponibles).map((p) => ({
        value: String(p.id),
        label: `${p.name ?? p.nombre ?? ''} ${p.apellidos ?? ''}`.trim(),
    })),
);

const ETIQUETA_CONTEXTO: Record<string, string> = {
    solicitud: 'Solicitud',
    prestamo: 'Préstamo',
    contrato: 'Contrato',
    finiquito: 'Finiquito',
};

function cuerpo() {
    const numero = (clave: string) => (contexto.value[clave] ? Number(contexto.value[clave]) : null);

    return {
        tipo_sujeto: tipoSujeto.value,
        sujeto_id: Number(sujetoId.value),
        solicitud_id: numero('solicitud'),
        prestamo_id: numero('prestamo'),
        contrato_id: numero('contrato'),
        manuales: manuales.value,
        guardar_en_expediente: guardarEnExpediente.value,
    };
}

let secuencia = 0;

async function cargarPreparacion() {
    if (!sujetoId.value) {
        preparacion.value = null;

        return;
    }

    const actual = ++secuencia;
    cargando.value = true;

    try {
        const datos = await enviarJson<PreparacionFormato>('POST', preparar.url(props.formato.id), cuerpo());

        if (actual === secuencia) {
            preparacion.value = datos;
            datos.manuales.forEach((m) => (manuales.value[m.clave] ??= m.valor));
        }
    } catch (e) {
        if (actual === secuencia) {
            preparacion.value = null;
            await mostrarError(e instanceof Error ? e.message : 'No se pudo revisar el formato.');
        }
    } finally {
        if (actual === secuencia) {
            cargando.value = false;
        }
    }
}

watch(tipoSujeto, () => {
    if (!props.sujetoFijo) {
        sujetoId.value = '';
    }
});
watch([sujetoId, contexto], () => {
    resultado.value = null;
    pdfPrevia.value = null;
    void cargarPreparacion();
}, { deep: true, immediate: true });

let temporizadorManuales: ReturnType<typeof setTimeout> | undefined;
watch(manuales, () => {
    clearTimeout(temporizadorManuales);
    temporizadorManuales = setTimeout(() => void cargarPreparacion(), 500);
}, { deep: true });

async function verVistaPrevia() {
    previsualizando.value = true;

    try {
        const datos = await enviarJson<PreparacionFormato>('POST', vistaPrevia.url(props.formato.id), cuerpo());
        preparacion.value = { ...datos, contextos: preparacion.value?.contextos, puede_guardar_en_expediente: preparacion.value?.puede_guardar_en_expediente };
        pdfPrevia.value = datos.pdf_base64 ? `data:application/pdf;base64,${datos.pdf_base64}` : null;
    } catch (e) {
        await mostrarError(e instanceof Error ? e.message : 'No se pudo generar la vista previa.');
    } finally {
        previsualizando.value = false;
    }
}

async function generarDocumento() {
    generando.value = true;

    try {
        const datos = await enviarJson<{ generacion: { nombre: string; version: number | null; en_expediente: boolean; descargar_url: string; ver_url: string } }>(
            'POST',
            generar.url(props.formato.id),
            cuerpo(),
        );
        resultado.value = datos.generacion;
        emit('generado');
    } catch (e) {
        await mostrarError(e instanceof Error ? e.message : 'No fue posible generar el documento.');
    } finally {
        generando.value = false;
    }
}

const nombreSujeto = computed(() => props.sujetoFijo?.nombre ?? opcionesPersona.value.find((o) => o.value === sujetoId.value)?.label ?? '');
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="flex max-h-[92vh] w-[calc(100vw-2rem)] flex-col overflow-hidden sm:max-w-2xl lg:max-w-5xl">
            <DialogHeader>
                <DialogTitle>Generar «{{ formato.nombre }}»</DialogTitle>
                <DialogDescription>
                    {{ nombreSujeto ? `Para ${nombreSujeto}. ` : '' }}Los datos que People ya tiene se llenan solos; revisa y genera.
                </DialogDescription>
            </DialogHeader>

            <div class="grid min-h-0 flex-1 grid-cols-1 gap-4 overflow-y-auto pr-1 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                <div class="flex min-w-0 flex-col gap-4">
                    <template v-if="!sujetoFijo">
                        <div class="grid gap-1.5">
                            <Label>Para</Label>
                            <NativeSelect v-model="tipoSujeto">
                                <option value="colaborador">Colaborador</option>
                                <option value="candidato">Candidato</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1.5">
                            <Label>{{ tipoSujeto === 'colaborador' ? 'Colaborador' : 'Candidato' }}</Label>
                            <Combobox v-model="sujetoId" :items="opcionesPersona" placeholder="Buscar por nombre…" />
                        </div>
                    </template>

                    <div v-if="cargando" class="flex items-center gap-2 text-sm text-muted-foreground"><Spinner />Revisando datos…</div>

                    <template v-if="preparacion">
                        <!-- De qué solicitud/préstamo/contrato sale -->
                        <div v-for="clave in preparacion.contextos?.usados ?? []" :key="clave" class="grid gap-1.5">
                            <Label>{{ ETIQUETA_CONTEXTO[clave] ?? clave }}</Label>
                            <NativeSelect v-model="contexto[clave]">
                                <option value="">Selecciona…</option>
                                <option v-for="o in preparacion.contextos?.opciones[clave] ?? []" :key="o.id" :value="String(o.id)">{{ o.label }}</option>
                            </NativeSelect>
                            <p v-if="(preparacion.contextos?.opciones[clave] ?? []).length === 0" class="text-xs text-muted-foreground">
                                Esta persona no tiene {{ (ETIQUETA_CONTEXTO[clave] ?? clave).toLowerCase() }}s registrados.
                            </p>
                        </div>

                        <!-- Datos faltantes: se completan en el expediente -->
                        <div v-if="preparacion.faltantes.length > 0" class="flex flex-col gap-2 rounded-xl border border-amber-500/40 bg-amber-500/5 p-3">
                            <p class="flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                                <AlertTriangle class="size-4" />
                                Faltan {{ preparacion.faltantes.length }} dato(s) para generar este documento
                            </p>
                            <ul class="flex flex-col gap-1 text-sm">
                                <li v-for="f in preparacion.faltantes" :key="f.variable" class="flex items-center justify-between gap-2">
                                    <span>{{ f.etiqueta }}</span>
                                    <a v-if="f.completar_url" :href="f.completar_url" target="_blank" class="inline-flex items-center gap-1 text-xs font-medium text-primary underline">
                                        Completar <ExternalLink class="size-3" />
                                    </a>
                                </li>
                            </ul>
                        </div>

                        <!-- Campos manuales declarados en la plantilla -->
                        <div v-if="preparacion.manuales.length > 0" class="flex flex-col gap-2 rounded-xl border p-3">
                            <p class="text-sm font-medium">Datos de este documento</p>
                            <div v-for="m in preparacion.manuales" :key="m.clave" class="grid gap-1">
                                <Label class="text-xs">{{ m.etiqueta }}<span v-if="m.requerido" class="text-destructive"> *</span></Label>
                                <Input v-model="manuales[m.clave]" :placeholder="m.etiqueta" />
                            </div>
                        </div>

                        <p v-if="preparacion.motivo && preparacion.faltantes.length === 0" class="rounded-xl border border-dashed p-3 text-sm text-muted-foreground">
                            {{ preparacion.motivo }}
                        </p>

                        <div v-if="preparacion.datos.length > 0" class="rounded-xl border p-3">
                            <p class="mb-2 flex items-center gap-2 text-sm font-medium"><ListChecks class="size-4 text-muted-foreground" />Datos que se usarán</p>
                            <dl class="grid gap-1 text-sm">
                                <div v-for="d in preparacion.datos" :key="d.etiqueta" class="flex justify-between gap-3 border-b border-dashed py-0.5 last:border-0">
                                    <dt class="shrink-0 text-muted-foreground">{{ d.etiqueta }}</dt>
                                    <dd class="truncate text-right font-medium">{{ d.valor }}</dd>
                                </div>
                            </dl>
                        </div>

                        <label v-if="preparacion.puede_guardar_en_expediente" class="flex items-center gap-2 text-sm">
                            <Checkbox :model-value="guardarEnExpediente" @update:model-value="(v) => (guardarEnExpediente = !!v)" />
                            Guardar en el expediente del colaborador
                        </label>
                    </template>
                </div>

                <div class="flex min-h-80 min-w-0 flex-col overflow-hidden rounded-xl border bg-muted/20">
                    <iframe v-if="pdfPrevia" :src="pdfPrevia" title="Vista previa del documento" class="h-[60vh] w-full" />
                    <div v-else class="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center text-sm text-muted-foreground">
                        <Eye class="size-6" />
                        Usa «Vista previa» para ver exactamente cómo saldrá el documento.
                    </div>
                </div>
            </div>

            <div v-if="resultado" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[var(--success)]/40 bg-[var(--success)]/5 p-3">
                <p class="flex items-center gap-2 text-sm">
                    <CheckCircle2 class="size-4 text-[var(--success)]" />
                    {{ resultado.nombre }} (v{{ resultado.version }}){{ resultado.en_expediente ? ' · guardado en el expediente' : '' }}
                </p>
                <div class="flex gap-2">
                    <Button as-child size="sm" variant="outline"><a :href="resultado.ver_url" target="_blank"><Eye class="size-4" />Ver</a></Button>
                    <Button v-if="puedeDescargar" as-child size="sm"><a :href="resultado.descargar_url"><Download class="size-4" />Descargar PDF</a></Button>
                </div>
            </div>

            <DialogFooter class="gap-2">
                <Button type="button" variant="secondary" @click="emit('update:open', false)">Cerrar</Button>
                <Button variant="outline" :disabled="!sujetoId || previsualizando" @click="verVistaPrevia">
                    <Spinner v-if="previsualizando" />
                    <Eye v-else class="size-4" />
                    Vista previa
                </Button>
                <Button :disabled="!preparacion?.puede_generar || generando || resultado !== null" @click="generarDocumento">
                    <Spinner v-if="generando" />
                    <Sparkles v-else class="size-4" />
                    Generar documento
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
