<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Copy } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { getJson } from '@/lib/http';
import { variables } from '@/routes/rh/plantillas';
import { update as actualizarVariables } from '@/routes/rh/plantillas/variables';
import type { PlantillaItem } from '@/types';

const props = defineProps<{
    open: boolean;
    plantilla: PlantillaItem;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

type TipoVariableManual =
    'text' | 'textarea' | 'date' | 'number' | 'currency' | 'select';

type VariableManualDef = {
    clave: string;
    etiqueta: string;
    descripcion: string | null;
    tipo: TipoVariableManual;
    requerido: boolean;
    valor_por_defecto: string | null;
    opciones: string[] | null;
};

type CatalogoGrupo = {
    grupo: string;
    variables: { clave: string; etiqueta: string }[];
};

type VariableAutomatica = {
    clave: string;
    etiqueta: string;
    requerido: boolean;
};

type Fila = Omit<VariableManualDef, 'valor_por_defecto'> & {
    /** true = RH decidió mapear esta variable; si queda en false, el marcador se guarda sin tocar (sigue "sin mapear"). */
    configurar: boolean;
    opcionesTexto: string;
    /** `null` solo existe en el contrato del backend; en el formulario siempre es string (vacío = sin valor por defecto). */
    valorPorDefecto: string;
};

const { mostrarExito, mostrarError } = useAlertas();

const cargando = ref(false);
const detectadas = ref<string[]>([]);
const catalogo = ref<CatalogoGrupo[]>([]);
const filas = ref<Fila[]>([]);
const automaticas = ref<VariableAutomatica[]>([]);

const tiposDisponibles: { value: TipoVariableManual; etiqueta: string }[] = [
    { value: 'text', etiqueta: 'Texto' },
    { value: 'textarea', etiqueta: 'Texto largo' },
    { value: 'date', etiqueta: 'Fecha' },
    { value: 'number', etiqueta: 'Número' },
    { value: 'currency', etiqueta: 'Moneda' },
    { value: 'select', etiqueta: 'Lista de opciones' },
];

function filaVacia(clave: string): Fila {
    return {
        clave,
        etiqueta: '',
        descripcion: null,
        tipo: 'text',
        requerido: false,
        opciones: null,
        configurar: false,
        opcionesTexto: '',
        valorPorDefecto: '',
    };
}

async function cargar() {
    cargando.value = true;

    try {
        const respuesta = await getJson<{
            detectadas: string[];
            sin_mapear: string[];
            manuales: VariableManualDef[];
            automaticas: VariableAutomatica[];
            catalogo: CatalogoGrupo[];
        }>(variables.url({ plantilla: props.plantilla.id }));

        detectadas.value = respuesta.detectadas;
        catalogo.value = respuesta.catalogo;
        automaticas.value = respuesta.automaticas.map((a) => ({ ...a }));

        const clavesManuales = new Set(respuesta.manuales.map((m) => m.clave));
        filas.value = [
            ...respuesta.manuales.map((m) => ({
                clave: m.clave,
                etiqueta: m.etiqueta,
                descripcion: m.descripcion,
                tipo: m.tipo,
                requerido: m.requerido,
                opciones: m.opciones,
                configurar: true,
                opcionesTexto: (m.opciones ?? []).join(', '),
                valorPorDefecto: m.valor_por_defecto ?? '',
            })),
            ...respuesta.sin_mapear
                .filter((clave) => !clavesManuales.has(clave))
                .map(filaVacia),
        ];
    } catch {
        mostrarError('No se pudieron cargar las variables de esta plantilla.');
    } finally {
        cargando.value = false;
    }
}

watch(
    () => props.open,
    (abierto) => {
        if (abierto) {
            void cargar();
        }
    },
    { immediate: true },
);

const sinConfigurar = computed(() => filas.value.filter((f) => !f.configurar));

const form = useForm<{ variables: VariableManualDef[] }>({ variables: [] });

/** Vue interpreta `{{` como apertura de interpolación incluso dentro de un literal de texto en el template — por eso este helper arma el marcador en script, nunca escrito literal en el <template>. */
function marcador(clave: string): string {
    return `{{${clave}}}`;
}

const EJEMPLO_MARCADOR = marcador('...');

function copiarMarcador(clave: string) {
    void navigator.clipboard.writeText(marcador(clave));
    mostrarExito(`Marcador ${marcador(clave)} copiado.`);
}

function guardar() {
    const manualesConfiguradas = filas.value
        .filter((f) => f.configurar && f.etiqueta.trim() !== '')
        .map((f) => ({
            clave: f.clave,
            etiqueta: f.etiqueta,
            descripcion: f.descripcion,
            tipo: f.tipo,
            requerido: f.requerido,
            valor_por_defecto:
                f.valorPorDefecto.trim() === '' ? null : f.valorPorDefecto,
            opciones:
                f.tipo === 'select'
                    ? f.opcionesTexto
                          .split(',')
                          .map((o) => o.trim())
                          .filter(Boolean)
                    : null,
        }));

    // Automática: solo se guarda si RH la marcó requerida — su
    // etiqueta/tipo/valor no los administra este formulario, los sigue
    // resolviendo el dato real del colaborador/candidato.
    const automaticasRequeridas = automaticas.value
        .filter((a) => a.requerido)
        .map((a) => ({
            clave: a.clave,
            etiqueta: a.etiqueta,
            descripcion: null,
            tipo: 'text' as TipoVariableManual,
            requerido: true,
            valor_por_defecto: null,
            opciones: null,
        }));

    form.variables = [...manualesConfiguradas, ...automaticasRequeridas];

    form.put(actualizarVariables.url({ plantilla: props.plantilla.id }), {
        preserveScroll: true,
        onSuccess: () => {
            mostrarExito('Variables de la plantilla actualizadas.');
            void cargar();
        },
        onError: () => mostrarError('Revisa los datos capturados.'),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Variables de «{{ plantilla.nombre }}»</DialogTitle>
                <DialogDescription>
                    Marcadores {{ EJEMPLO_MARCADOR }} detectados en el archivo.
                    Los que ya corresponden a un dato del colaborador se llenan
                    solos; el resto necesita que definas de dónde sale su valor.
                </DialogDescription>
            </DialogHeader>

            <div v-if="cargando" class="flex justify-center p-8">
                <Spinner />
            </div>

            <template v-else>
                <p
                    v-if="detectadas.length === 0"
                    class="rounded-lg border border-border/60 bg-muted/40 p-3 text-sm text-muted-foreground"
                >
                    No se detectó ningún marcador {{ EJEMPLO_MARCADOR }} en este
                    archivo. Revisa que el DOCX use ese formato exacto (ver la
                    guía «Formatos DOCX»).
                </p>

                <div
                    v-if="sinConfigurar.length > 0"
                    class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-xs text-amber-700 dark:text-amber-400"
                >
                    {{ sinConfigurar.length }} marcador(es) sin mapear todavía:
                    <code
                        v-for="clave in sinConfigurar.map((f) => f.clave)"
                        :key="clave"
                        class="mx-0.5 rounded bg-black/5 px-1 dark:bg-white/10"
                        >{{ marcador(clave) }}</code
                    >
                    — quedarán literales en el documento hasta que los
                    configures abajo.
                </div>

                <div v-if="automaticas.length > 0" class="flex flex-col gap-2">
                    <p class="text-xs font-semibold text-muted-foreground">
                        Datos automáticos detectados — se llenan solos; marca
                        cuáles no pueden quedar vacíos.
                    </p>
                    <div
                        v-for="auto in automaticas"
                        :key="auto.clave"
                        class="flex items-center justify-between gap-3 rounded-xl border border-border/60 p-3"
                    >
                        <div class="flex items-center gap-2">
                            <code class="text-xs font-medium">{{
                                marcador(auto.clave)
                            }}</code>
                            <span class="text-xs text-muted-foreground">{{
                                auto.etiqueta
                            }}</span>
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <Checkbox
                                :model-value="auto.requerido"
                                @update:model-value="
                                    (v) => (auto.requerido = !!v)
                                "
                            />
                            Obligatorio para generar
                        </label>
                    </div>
                </div>

                <div class="flex flex-col gap-3">
                    <div
                        v-for="fila in filas"
                        :key="fila.clave"
                        class="rounded-xl border border-border/60 p-3"
                        :class="fila.configurar ? 'bg-card' : 'bg-muted/30'"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <Checkbox
                                    :model-value="fila.configurar"
                                    @update:model-value="
                                        (v) => (fila.configurar = !!v)
                                    "
                                />
                                <code class="text-xs font-medium">{{
                                    marcador(fila.clave)
                                }}</code>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="copiarMarcador(fila.clave)"
                            >
                                <Copy class="size-3.5" />
                                Copiar
                            </Button>
                        </div>

                        <div
                            v-if="fila.configurar"
                            class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2"
                        >
                            <div class="grid gap-1">
                                <Label class="text-xs">Etiqueta</Label>
                                <Input
                                    v-model="fila.etiqueta"
                                    placeholder="Nombre visible para RH"
                                />
                            </div>
                            <div class="grid gap-1">
                                <Label class="text-xs">Tipo de dato</Label>
                                <Select v-model="fila.tipo">
                                    <SelectTrigger class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="t in tiposDisponibles"
                                            :key="t.value"
                                            :value="t.value"
                                            >{{ t.etiqueta }}</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </div>
                            <div
                                v-if="fila.tipo === 'select'"
                                class="grid gap-1 sm:col-span-2"
                            >
                                <Label class="text-xs"
                                    >Opciones (separadas por coma)</Label
                                >
                                <Input
                                    v-model="fila.opcionesTexto"
                                    placeholder="Opción A, Opción B, Opción C"
                                />
                            </div>
                            <div class="grid gap-1">
                                <Label class="text-xs"
                                    >Valor por defecto (opcional)</Label
                                >
                                <Input v-model="fila.valorPorDefecto" />
                            </div>
                            <label class="mt-5 flex items-center gap-2 text-sm">
                                <Checkbox
                                    :model-value="fila.requerido"
                                    @update:model-value="
                                        (v) => (fila.requerido = !!v)
                                    "
                                />
                                Obligatoria para generar el documento
                            </label>
                        </div>
                    </div>
                </div>
                <InputError :message="form.errors.variables" />

                <details class="rounded-xl border border-border/60 p-3">
                    <summary class="cursor-pointer text-sm font-medium">
                        Catálogo de variables disponibles
                    </summary>
                    <div class="mt-3 flex flex-col gap-3">
                        <div v-for="grupo in catalogo" :key="grupo.grupo">
                            <p
                                class="text-xs font-semibold text-muted-foreground"
                            >
                                {{ grupo.grupo }}
                            </p>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                <button
                                    v-for="v in grupo.variables"
                                    :key="v.clave"
                                    type="button"
                                    class="rounded-md border border-border/60 px-2 py-1 text-xs hover:bg-muted"
                                    :title="`Copiar {{${v.clave}}}`"
                                    @click="copiarMarcador(v.clave)"
                                >
                                    {{ v.etiqueta }}
                                </button>
                            </div>
                        </div>
                    </div>
                </details>
            </template>

            <DialogFooter>
                <Button
                    type="button"
                    variant="secondary"
                    @click="emit('update:open', false)"
                    >Cerrar</Button
                >
                <Button
                    type="button"
                    :disabled="form.processing || cargando"
                    @click="guardar"
                >
                    <Spinner v-if="form.processing" />
                    Guardar variables
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
