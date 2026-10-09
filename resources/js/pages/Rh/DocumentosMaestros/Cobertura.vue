<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    CircleDashed,
    Loader2,
    Minus,
    Pencil,
    ShieldQuestion,
    Users,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { dashboard } from '@/routes';
import {
    cobertura as rutaCobertura,
    index as rutaMaestros,
} from '@/routes/rh/documentos-maestros';
import { puesto as rutaPuesto } from '@/routes/rh/documentos-maestros/cobertura';
import type {
    CeldaCobertura,
    PuestoCobertura,
    ReporteCobertura,
} from '@/types';

/**
 * COBERTURA DOCUMENTAL POR PUESTO: qué documento oficial le tocaría a cada
 * puesto activo y si ya existe su formato validado. Para enterarse ANTES
 * de que alguien espere su contrato. Todo puesto debe tener decisión:
 * grupo documental, o "no requiere documentos laborales" con motivo.
 */
const props = defineProps<{
    cobertura: ReporteCobertura;
    gruposDocumentales: { value: string; etiqueta: string }[];
    puedeEditar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Documentos maestros', href: rutaMaestros() },
            { title: 'Cobertura por puesto', href: rutaCobertura() },
        ],
    },
});

const soloProblemas = ref(false);
const conProblema = computed(
    () =>
        props.cobertura.resumen.incompletos +
        props.cobertura.resumen.sin_decision,
);
const puestos = computed(() =>
    soloProblemas.value
        ? props.cobertura.puestos.filter(
              (p) => p.estado === 'incompleto' || p.estado === 'sin_decision',
          )
        : props.cobertura.puestos,
);

const celda: Record<
    CeldaCobertura['estado'],
    { etiqueta: string; clase: string }
> = {
    ok: {
        etiqueta: 'Formato propio validado',
        clase: 'text-[var(--mrl-verde)]',
    },
    general: {
        etiqueta: 'Formato general validado',
        clase: 'text-[var(--mrl-verde)]',
    },
    sin_validar: {
        etiqueta: 'Formato cargado sin diseño validado',
        clase: 'text-warning',
    },
    falta: {
        etiqueta: 'Falta el formato de Jurídico',
        clase: 'text-destructive',
    },
    no_aplica: {
        etiqueta: 'No aplica a este puesto',
        clase: 'text-[var(--mrl-gris-verdoso)]',
    },
};

const varianteEstado: Record<
    PuestoCobertura['estado'],
    'success' | 'warning' | 'destructive' | 'outline'
> = {
    completo: 'success',
    incompleto: 'warning',
    sin_decision: 'destructive',
    excluido: 'outline',
};

// ───────── Decisión por puesto ─────────
const editando = ref<PuestoCobertura | null>(null);
const form = ref({
    grupo_documental: '',
    no_requiere_documentos_laborales: false,
    motivo_sin_documentos: '',
});
const guardando = ref(false);
const errores = ref<Record<string, string>>({});

function editar(p: PuestoCobertura) {
    editando.value = p;
    errores.value = {};
    form.value = {
        grupo_documental: p.grupo ?? '',
        no_requiere_documentos_laborales: p.no_requiere,
        motivo_sin_documentos: p.motivo_sin_documentos ?? '',
    };
}

const formularioValido = computed(() =>
    form.value.no_requiere_documentos_laborales
        ? form.value.motivo_sin_documentos.trim().length >= 10
        : form.value.grupo_documental !== '',
);

function guardar() {
    if (!editando.value) {
        return;
    }

    guardando.value = true;
    router.put(
        rutaPuesto.url(editando.value.id),
        {
            grupo_documental: form.value.no_requiere_documentos_laborales
                ? null
                : form.value.grupo_documental,
            no_requiere_documentos_laborales:
                form.value.no_requiere_documentos_laborales,
            motivo_sin_documentos: form.value.no_requiere_documentos_laborales
                ? form.value.motivo_sin_documentos.trim()
                : null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                editando.value = null;
            },
            onError: (e) => {
                errores.value = e;
            },
            onFinish: () => {
                guardando.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Cobertura documental por puesto" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Cobertura documental por puesto"
            :icono="Users"
        />

        <header class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">
                Cobertura documental por puesto
            </h1>
            <p class="text-sm text-[var(--mrl-texto-suave)]">
                Qué formato oficial usará PEOPLE para cada puesto activo.
                Resuelve aquí lo que falte antes de que alguien espere su
                contrato.
            </p>
        </header>

        <button
            v-if="conProblema > 0"
            type="button"
            class="flex items-center gap-3 rounded-2xl border border-warning/30 bg-warning-soft/50 p-4 text-left text-warning transition hover:shadow-sm"
            @click="soloProblemas = !soloProblemas"
        >
            <AlertTriangle class="size-5 shrink-0" />
            <span class="flex-1 text-sm font-medium">
                {{ conProblema }}
                {{ conProblema === 1 ? 'puesto tiene' : 'puestos tienen' }}
                configuración documental incompleta
            </span>
            <span class="text-xs underline">{{
                soloProblemas ? 'Ver todos' : 'Ver solo esos'
            }}</span>
        </button>
        <p
            v-else
            class="flex items-center gap-2 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm"
        >
            <CheckCircle2 class="size-5 text-[var(--mrl-verde)]" /> Todos los
            puestos activos tienen su documentación decidida y cubierta.
        </p>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3"
            >
                <p class="text-xs text-[var(--mrl-texto-suave)]">Completos</p>
                <p class="text-xl font-semibold tabular-nums">
                    {{ cobertura.resumen.completos }}
                </p>
            </div>
            <div
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3"
            >
                <p class="text-xs text-[var(--mrl-texto-suave)]">Incompletos</p>
                <p
                    class="text-xl font-semibold tabular-nums"
                    :class="
                        cobertura.resumen.incompletos ? 'text-warning' : ''
                    "
                >
                    {{ cobertura.resumen.incompletos }}
                </p>
            </div>
            <div
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3"
            >
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    Sin decisión
                </p>
                <p
                    class="text-xl font-semibold tabular-nums"
                    :class="
                        cobertura.resumen.sin_decision ? 'text-destructive' : ''
                    "
                >
                    {{ cobertura.resumen.sin_decision }}
                </p>
            </div>
            <div
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3"
            >
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    No requieren documentos
                </p>
                <p class="text-xl font-semibold tabular-nums">
                    {{ cobertura.resumen.excluidos }}
                </p>
            </div>
        </div>

        <TooltipProvider :delay-duration="150">
            <div
                class="overflow-x-auto rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
            >
                <table class="w-full min-w-[900px] text-sm">
                    <thead
                        class="bg-[var(--mrl-fondo)] text-left text-xs text-[var(--mrl-texto-suave)]"
                    >
                        <tr>
                            <th class="p-3 font-medium">Puesto</th>
                            <th class="p-3 font-medium">Grupo documental</th>
                            <th
                                v-for="c in cobertura.columnas"
                                :key="c.clave"
                                class="p-3 text-center font-medium"
                            >
                                {{ c.etiqueta }}
                            </th>
                            <th class="p-3 font-medium">Estado</th>
                            <th v-if="puedeEditar" class="p-3">
                                <span class="sr-only">Acciones</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in puestos"
                            :key="p.id"
                            class="border-t border-[var(--mrl-borde)]"
                        >
                            <td class="p-3 font-medium">{{ p.nombre }}</td>
                            <td class="p-3">
                                <span
                                    v-if="p.no_requiere"
                                    class="text-[var(--mrl-texto-suave)]"
                                    >No requiere</span
                                >
                                <span v-else-if="p.grupo_etiqueta">{{
                                    p.grupo_etiqueta
                                }}</span>
                                <span
                                    v-else
                                    class="flex items-center gap-1 text-destructive"
                                    ><ShieldQuestion class="size-3.5" /> Sin
                                    definir</span
                                >
                            </td>
                            <td
                                v-for="c in cobertura.columnas"
                                :key="c.clave"
                                class="p-3 text-center"
                            >
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span
                                            class="inline-flex items-center justify-center"
                                            :class="
                                                celda[
                                                    p.celdas[c.clave]?.estado ??
                                                        'no_aplica'
                                                ].clase
                                            "
                                            :aria-label="
                                                celda[
                                                    p.celdas[c.clave]?.estado ??
                                                        'no_aplica'
                                                ].etiqueta
                                            "
                                        >
                                            <CheckCircle2
                                                v-if="
                                                    p.celdas[c.clave]
                                                        ?.estado === 'ok' ||
                                                    p.celdas[c.clave]
                                                        ?.estado === 'general'
                                                "
                                                class="size-4"
                                            />
                                            <AlertTriangle
                                                v-else-if="
                                                    p.celdas[c.clave]
                                                        ?.estado ===
                                                    'sin_validar'
                                                "
                                                class="size-4"
                                            />
                                            <XCircle
                                                v-else-if="
                                                    p.celdas[c.clave]
                                                        ?.estado === 'falta' &&
                                                    c.requerida
                                                "
                                                class="size-4"
                                            />
                                            <CircleDashed
                                                v-else-if="
                                                    p.celdas[c.clave]
                                                        ?.estado === 'falta'
                                                "
                                                class="size-4 text-[var(--mrl-gris-verdoso)]"
                                            />
                                            <Minus v-else class="size-4" />
                                            <span
                                                v-if="
                                                    p.celdas[c.clave]
                                                        ?.estado === 'general'
                                                "
                                                class="ml-0.5 text-[10px]"
                                                >general</span
                                            >
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p>
                                            {{
                                                celda[
                                                    p.celdas[c.clave]?.estado ??
                                                        'no_aplica'
                                                ].etiqueta
                                            }}
                                        </p>
                                        <p
                                            v-if="p.celdas[c.clave]?.master"
                                            class="text-xs opacity-80"
                                        >
                                            {{
                                                p.celdas[c.clave]?.master
                                                    ?.nombre
                                            }}
                                            v{{
                                                p.celdas[c.clave]?.master
                                                    ?.version
                                            }}
                                        </p>
                                        <p
                                            v-if="
                                                p.celdas[c.clave]?.estado ===
                                                    'falta' && !c.requerida
                                            "
                                            class="text-xs opacity-80"
                                        >
                                            Jurídico no ha entregado este
                                            contrato; no se usa hoy.
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                            </td>
                            <td class="p-3">
                                <Badge :variant="varianteEstado[p.estado]">{{
                                    p.estado_etiqueta
                                }}</Badge>
                                <p
                                    v-if="p.faltantes.length"
                                    class="mt-1 text-xs text-[var(--mrl-texto-suave)]"
                                >
                                    {{ p.faltantes.join(' · ') }}
                                </p>
                                <p
                                    v-if="
                                        p.no_requiere && p.motivo_sin_documentos
                                    "
                                    class="mt-1 text-xs text-[var(--mrl-texto-suave)]"
                                >
                                    {{ p.motivo_sin_documentos }}
                                </p>
                            </td>
                            <td v-if="puedeEditar" class="p-3 text-right">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="editar(p)"
                                    ><Pencil class="size-4" /> Decidir</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </TooltipProvider>
        <p class="text-xs text-[var(--mrl-texto-suave)]">
            ✓ formato validado (propio o general) · ⚠ formato cargado sin diseño
            validado · ✗ falta el formato de Jurídico · — no aplica. Periodo de
            prueba y tiempo determinado se muestran pero no cuentan como
            faltante: Jurídico no ha entregado esos contratos.
        </p>
    </div>

    <Dialog
        :open="editando !== null"
        @update:open="(v: boolean) => !v && !guardando && (editando = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle
                    >Documentación de {{ editando?.nombre }}</DialogTitle
                >
                <DialogDescription>
                    Define qué variante de documentos le corresponde, o márcalo
                    como puesto que no requiere documentos laborales (con
                    motivo).
                </DialogDescription>
            </DialogHeader>
            <div class="flex flex-col gap-4">
                <label class="flex items-center gap-2 text-sm">
                    <Casilla v-model="form.no_requiere_documentos_laborales" />
                    Este puesto no requiere documentos laborales
                </label>
                <div
                    v-if="!form.no_requiere_documentos_laborales"
                    class="flex flex-col gap-1"
                >
                    <Label>Grupo documental</Label>
                    <Select v-model="form.grupo_documental">
                        <SelectTrigger
                            ><SelectValue placeholder="Selecciona el grupo"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="g in gruposDocumentales"
                                :key="g.value"
                                :value="g.value"
                                >{{ g.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <p
                        v-if="errores.grupo_documental"
                        class="text-xs text-destructive"
                    >
                        {{ errores.grupo_documental }}
                    </p>
                </div>
                <div v-else class="flex flex-col gap-1">
                    <Label for="motivo-sin-documentos"
                        >Motivo (obligatorio)</Label
                    >
                    <Textarea
                        id="motivo-sin-documentos"
                        v-model="form.motivo_sin_documentos"
                        rows="3"
                        maxlength="500"
                        placeholder="Ej. Puesto externo por honorarios: no tiene contrato laboral."
                    />
                    <p
                        v-if="errores.motivo_sin_documentos"
                        class="text-xs text-destructive"
                    >
                        {{ errores.motivo_sin_documentos }}
                    </p>
                </div>
            </div>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="guardando"
                    @click="editando = null"
                    >Cancelar</Button
                >
                <Button
                    :disabled="guardando || !formularioValido"
                    @click="guardar"
                >
                    <Loader2 v-if="guardando" class="size-4 animate-spin" />
                    Guardar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
