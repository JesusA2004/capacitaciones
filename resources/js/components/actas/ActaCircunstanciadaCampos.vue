<script setup lang="ts">
import {
    CalendarClock,
    Check,
    ClipboardList,
    Info,
    MessageSquareOff,
    MessageSquareText,
    Plus,
    Trash2,
    UserRoundCheck,
    UserRoundX,
} from '@lucide/vue';
import { computed } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import TimePicker from '@/components/Common/TimePicker.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { ahoraLocal, conflictoPersona } from '@/lib/actas';
import { testigos as buscarTestigos } from '@/routes/rh/actas';
import type {
    CatalogosActa,
    ColaboradorBusqueda,
    FormularioCircunstanciada,
    PersonaActa,
    TestigoCaptura,
} from '@/types';

/**
 * Captura del acta administrativa circunstanciada (docs/acta/…docx), pasos
 * 1 a 4. Las personas salen SIEMPRE de PEOPLE (buscador): nombre, puesto y
 * área los resuelve el backend. Las «instrucciones de llenado» del formato
 * solo viven aquí como ayudas; nunca llegan al PDF.
 */
const form = defineModel<FormularioCircunstanciada>({ required: true });

const props = defineProps<{
    paso: 1 | 2 | 3 | 4;
    errores: Partial<Record<string, string>>;
    catalogos: CatalogosActa;
    trabajador: { id: number; nombre: string };
    /** Quien levanta el acta, tal como lo guardó PEOPLE (paso 3). */
    responsable?: PersonaActa | null;
}>();

const c = computed(() => form.value.circunstanciada);
const hoy = ahoraLocal().fecha;

function error(campo: string): string | undefined {
    return props.errores[campo];
}

// ───── Personas ─────
function chip(p: {
    colaborador_id: number | null;
    nombre: string;
    puesto: string;
}): ColaboradorBusqueda | null {
    return p.colaborador_id
        ? {
              id: p.colaborador_id,
              nombre: p.nombre,
              numero_empleado: null,
              puesto: p.puesto || null,
              sucursal: null,
          }
        : null;
}

function urlTestigos(texto: string): string {
    return buscarTestigos.url({
        query: { q: texto, excluir: props.trabajador.id },
    });
}

function elegir(p: TestigoCaptura, elegido: ColaboradorBusqueda | null) {
    p.colaborador_id = elegido?.id ?? null;
    p.nombre = elegido?.nombre ?? '';
    p.puesto = elegido?.puesto ?? '';
}

function elegirJefe(elegido: ColaboradorBusqueda | null) {
    const j = c.value.jefe;
    j.colaborador_id = elegido?.id ?? null;
    j.nombre = elegido?.nombre ?? '';
    j.puesto = elegido?.puesto ?? '';
}

function agregarTestigoHechos() {
    c.value.testigos_hechos.push({
        colaborador_id: null,
        nombre: '',
        puesto: '',
        manifestacion: '',
        relacion: '',
        relacion_otro: '',
    });
}

function agregarTestigoAsistencia() {
    c.value.testigos_asistencia.push({
        colaborador_id: null,
        nombre: '',
        puesto: '',
        manifestacion: '',
    });
}

/**
 * Responsable y jefe son la misma persona (mismo colaborador, nunca por
 * nombre: dos homónimos son personas distintas): una sola manifestación (y firma).
 */
const jefeEsResponsable = computed(
    () =>
        c.value.jefe.participa &&
        props.responsable?.colaborador_id != null &&
        props.responsable.colaborador_id === c.value.jefe.colaborador_id,
);

function cerrarAhora() {
    const { fecha, hora } = ahoraLocal();
    c.value.cierre.fecha = fecha;
    c.value.cierre.hora = hora;
}
</script>

<template>
    <!-- 1. Incidencia -->
    <div v-if="paso === 1" class="flex flex-col gap-5">
        <fieldset class="flex flex-col gap-2">
            <legend class="mb-1 text-sm font-medium">Tipo de incidencia</legend>
            <p class="text-xs text-muted-foreground">
                Clasifica el hecho, sin calificarlo: el acta registra lo
                ocurrido, no determina responsabilidad.
            </p>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="op in catalogos.incidencias"
                    :key="op.value"
                    type="button"
                    class="group flex items-center gap-2 rounded-xl border px-3 py-2.5 text-left text-sm transition-all duration-150 hover:-translate-y-0.5 hover:border-oro/60 hover:shadow-sm focus-visible:outline-2 focus-visible:outline-oro motion-reduce:hover:translate-y-0"
                    :class="
                        c.incidencia.tipo === op.value
                            ? 'border-[var(--mrl-petroleo)] bg-salvia font-medium text-[var(--mrl-petroleo)]'
                            : 'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]'
                    "
                    :aria-pressed="c.incidencia.tipo === op.value"
                    @click="c.incidencia.tipo = op.value"
                >
                    <span
                        class="flex size-5 shrink-0 items-center justify-center rounded-full border transition-colors"
                        :class="
                            c.incidencia.tipo === op.value
                                ? 'border-[var(--mrl-petroleo)] bg-[var(--mrl-petroleo)] text-white'
                                : 'border-[var(--mrl-borde)] group-hover:border-oro'
                        "
                    >
                        <Check
                            v-if="c.incidencia.tipo === op.value"
                            class="size-3"
                        />
                    </span>
                    {{ op.label }}
                </button>
            </div>
            <InputError :message="error('circunstanciada.incidencia.tipo')" />
            <div
                v-if="c.incidencia.tipo === 'otra'"
                class="flex flex-col gap-1.5"
            >
                <Label for="acta-otro">¿Qué tipo de incidencia?</Label>
                <Input
                    id="acta-otro"
                    v-model="c.incidencia.otro"
                    maxlength="160"
                    placeholder="Descríbela en pocas palabras, sin calificarla"
                />
                <InputError
                    :message="error('circunstanciada.incidencia.otro')"
                />
            </div>
        </fieldset>

        <fieldset
            class="flex flex-col gap-3 rounded-xl border border-[var(--mrl-borde)] bg-crema/30 p-4"
        >
            <legend class="px-1 text-sm font-medium">
                Cuándo y dónde ocurrieron los hechos
            </legend>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <Label for="acta-hechos-desde"
                        >Fecha (o inicio del período)</Label
                    >
                    <DatePicker
                        id="acta-hechos-desde"
                        v-model="c.incidencia.fecha_desde"
                        :max-value="hoy"
                    />
                    <InputError
                        :message="
                            error('circunstanciada.incidencia.fecha_desde')
                        "
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="acta-hechos-hasta"
                        >Fin del período (opcional)</Label
                    >
                    <DatePicker
                        id="acta-hechos-hasta"
                        v-model="c.incidencia.fecha_hasta"
                        :min-value="c.incidencia.fecha_desde || undefined"
                        :max-value="hoy"
                        placeholder="Solo si fueron varios días"
                    />
                    <InputError
                        :message="
                            error('circunstanciada.incidencia.fecha_hasta')
                        "
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="acta-hora-desde">Hora (opcional)</Label>
                    <TimePicker
                        id="acta-hora-desde"
                        v-model="c.incidencia.hora_desde"
                        :desde="0"
                        :hasta="23"
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="acta-hora-hasta">Hasta (opcional)</Label>
                    <TimePicker
                        id="acta-hora-hasta"
                        v-model="c.incidencia.hora_hasta"
                        :desde="0"
                        :hasta="23"
                        placeholder="Solo si fue un lapso"
                    />
                </div>
            </div>
            <div class="flex flex-col gap-1.5">
                <Label for="acta-lugar-hechos"
                    >Lugar de los hechos (opcional)</Label
                >
                <Input
                    id="acta-lugar-hechos"
                    v-model="c.incidencia.lugar_hechos"
                    maxlength="255"
                    placeholder="Ej. Área de cajas de la sucursal"
                />
            </div>
        </fieldset>

        <fieldset class="flex flex-col gap-3">
            <legend class="mb-1 flex items-center gap-1.5 text-sm font-medium">
                <CalendarClock class="size-4 text-[var(--mrl-petroleo)]" />
                Levantamiento del acta
            </legend>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="flex flex-col gap-1.5">
                    <Label for="acta-fecha">Fecha</Label>
                    <DatePicker
                        id="acta-fecha"
                        v-model="form.fecha"
                        :max-value="hoy"
                    />
                    <InputError :message="error('fecha')" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="acta-hora">Hora</Label>
                    <TimePicker
                        id="acta-hora"
                        v-model="form.hora"
                        :desde="0"
                        :hasta="23"
                    />
                    <InputError :message="error('hora')" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="acta-lugar">Lugar</Label>
                    <Input
                        id="acta-lugar"
                        v-model="form.lugar"
                        maxlength="255"
                        placeholder="Ciudad, estado"
                    />
                    <InputError :message="error('lugar')" />
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                Empresa, centro de trabajo y domicilio salen de la sucursal del
                trabajador; el lugar se propone con su ciudad y puedes
                ajustarlo.
            </p>
        </fieldset>
    </div>

    <!-- 2. Hechos -->
    <div v-else-if="paso === 2" class="flex flex-col gap-5">
        <div class="flex flex-col gap-1.5">
            <Label for="acta-hechos">Hechos</Label>
            <div
                class="flex items-start gap-2 rounded-xl bg-info-soft/60 p-3 text-xs text-[var(--mrl-petroleo)]"
            >
                <Info class="mt-0.5 size-4 shrink-0" />
                <p>
                    Narra en orden qué ocurrió, cuándo, dónde, quién intervino y
                    cómo se conocieron los hechos. Numera cada hecho en su
                    propio párrafo (1., 2., 3.). Describe; no califiques ni
                    concluyas.
                </p>
            </div>
            <Textarea
                id="acta-hechos"
                v-model="form.hechos"
                rows="10"
                class="leading-relaxed"
                placeholder="1. El 13 de octubre de 2026, a las 09:10 horas, …&#10;&#10;2. …"
            />
            <p class="text-right text-xs text-muted-foreground">
                {{ form.hechos.length.toLocaleString('es-MX') }} / 20,000
            </p>
            <InputError :message="error('hechos')" />
        </div>

        <div class="flex flex-col gap-1.5">
            <Label for="acta-disposiciones"
                >Disposiciones relacionadas con los hechos (opcional)</Label
            >
            <Textarea
                id="acta-disposiciones"
                v-model="c.disposiciones"
                rows="3"
                placeholder="Ej. Reglamento interior de trabajo, artículo …"
            />
            <p class="text-xs text-muted-foreground">
                Incluye únicamente disposiciones previamente revisadas por
                RH/Jurídico.
            </p>
            <InputError :message="error('circunstanciada.disposiciones')" />
        </div>
    </div>

    <!-- 3. Participantes -->
    <div v-else-if="paso === 3" class="flex flex-col gap-5">
        <section
            class="flex flex-col gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <h3 class="text-sm font-semibold">Quien levanta el acta</h3>
            <dl
                v-if="responsable?.nombre"
                class="grid gap-2 text-sm sm:grid-cols-3"
            >
                <div>
                    <dt class="text-xs text-muted-foreground">Nombre</dt>
                    <dd class="font-medium">{{ responsable.nombre }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Puesto</dt>
                    <dd>{{ responsable.puesto || 'Sin puesto en PEOPLE' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Área</dt>
                    <dd>{{ responsable.area || 'Sin área en PEOPLE' }}</dd>
                </div>
            </dl>
            <div class="flex flex-col gap-1.5 sm:max-w-xs">
                <Label for="acta-caracter"
                    >Carácter con el que interviene</Label
                >
                <NativeSelect
                    id="acta-caracter"
                    v-model="c.responsable.caracter"
                >
                    <NativeSelectOption value="" disabled
                        >Elige…</NativeSelectOption
                    >
                    <NativeSelectOption
                        v-for="op in catalogos.caracteres"
                        :key="op.value"
                        :value="op.value"
                        >{{ op.label }}</NativeSelectOption
                    >
                </NativeSelect>
                <InputError
                    :message="error('circunstanciada.responsable.caracter')"
                />
            </div>
        </section>

        <section
            class="flex flex-col gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold">Jefe inmediato</h3>
                <div
                    class="inline-flex rounded-lg border border-[var(--mrl-borde)] p-0.5"
                    role="group"
                    aria-label="¿El jefe inmediato participa?"
                >
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs transition-colors"
                        :class="
                            c.jefe.participa
                                ? 'bg-[var(--mrl-petroleo)] text-white'
                                : 'hover:bg-crema/60'
                        "
                        :aria-pressed="c.jefe.participa"
                        @click="c.jefe.participa = true"
                    >
                        <UserRoundCheck class="size-3.5" /> Participa
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs transition-colors"
                        :class="
                            !c.jefe.participa
                                ? 'bg-[var(--mrl-petroleo)] text-white'
                                : 'hover:bg-crema/60'
                        "
                        :aria-pressed="!c.jefe.participa"
                        @click="c.jefe.participa = false"
                    >
                        <UserRoundX class="size-3.5" /> No participa
                    </button>
                </div>
            </div>
            <template v-if="c.jefe.participa">
                <BuscadorColaborador
                    :model-value="chip(c.jefe)"
                    :buscar-url="urlTestigos"
                    @update:model-value="elegirJefe"
                />
                <p
                    v-if="
                        c.jefe.colaborador_id &&
                        conflictoPersona(
                            form,
                            trabajador.id,
                            c.jefe.colaborador_id,
                            'jefe',
                        )
                    "
                    class="text-xs text-destructive"
                >
                    {{
                        conflictoPersona(
                            form,
                            trabajador.id,
                            c.jefe.colaborador_id,
                            'jefe',
                        )
                    }}
                </p>
                <InputError
                    :message="error('circunstanciada.jefe.colaborador_id')"
                />
            </template>
            <p v-else class="text-xs text-muted-foreground">
                No interviene: su bloque y su firma no se imprimen.
                <template v-if="c.jefe.nombre">
                    PEOPLE propone a {{ c.jefe.nombre }}.</template
                >
            </p>
        </section>

        <section
            class="flex flex-col gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <div>
                <h3 class="text-sm font-semibold">Testigos de hechos</h3>
                <p class="text-xs text-muted-foreground">
                    Personas que presenciaron o conocen los hechos. Registra
                    solo a quienes realmente correspondan: 0, 1 o 2.
                </p>
            </div>
            <TransitionGroup
                name="lista-acta"
                tag="div"
                class="flex flex-col gap-3"
            >
                <div
                    v-for="(t, i) in c.testigos_hechos"
                    :key="`h${i}`"
                    class="flex flex-col gap-2 rounded-lg border border-dashed border-[var(--mrl-borde)] p-3 transition-colors hover:border-oro/50"
                >
                    <div class="flex items-center justify-between gap-2">
                        <Label>Testigo de hechos {{ i + 1 }}</Label>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-7 hover:bg-danger-soft hover:text-destructive"
                            :aria-label="`Quitar testigo de hechos ${i + 1}`"
                            @click="c.testigos_hechos.splice(i, 1)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                    <BuscadorColaborador
                        :model-value="chip(t)"
                        :buscar-url="urlTestigos"
                        @update:model-value="(x) => elegir(t, x)"
                    />
                    <p
                        v-if="
                            conflictoPersona(
                                form,
                                trabajador.id,
                                t.colaborador_id,
                                t,
                            )
                        "
                        class="text-xs text-destructive"
                    >
                        {{
                            conflictoPersona(
                                form,
                                trabajador.id,
                                t.colaborador_id,
                                t,
                            )
                        }}
                    </p>
                    <InputError
                        :message="
                            error(
                                `circunstanciada.testigos_hechos.${i}.colaborador_id`,
                            )
                        "
                    />
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label :for="`acta-relacion-${i}`"
                                >Relación con los hechos</Label
                            >
                            <NativeSelect
                                :id="`acta-relacion-${i}`"
                                v-model="t.relacion"
                            >
                                <NativeSelectOption value="" disabled
                                    >Elige…</NativeSelectOption
                                >
                                <NativeSelectOption
                                    v-for="op in catalogos.relaciones"
                                    :key="op.value"
                                    :value="op.value"
                                    >{{ op.label }}</NativeSelectOption
                                >
                            </NativeSelect>
                            <InputError
                                :message="
                                    error(
                                        `circunstanciada.testigos_hechos.${i}.relacion`,
                                    )
                                "
                            />
                        </div>
                        <div
                            v-if="t.relacion === 'otra'"
                            class="flex flex-col gap-1.5"
                        >
                            <Label :for="`acta-relacion-otro-${i}`"
                                >¿Cuál?</Label
                            >
                            <Input
                                :id="`acta-relacion-otro-${i}`"
                                v-model="t.relacion_otro"
                                maxlength="160"
                            />
                        </div>
                    </div>
                </div>
            </TransitionGroup>
            <p
                v-if="!c.testigos_hechos.length"
                class="text-xs text-muted-foreground italic"
            >
                Se imprimirá: «No se registraron testigos de hechos.»
            </p>
            <Button
                v-if="c.testigos_hechos.length < 2"
                type="button"
                variant="outline"
                size="sm"
                class="self-start"
                @click="agregarTestigoHechos"
            >
                <Plus class="size-4" /> Agregar testigo de hechos
            </Button>
        </section>

        <section
            class="flex flex-col gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <div>
                <h3 class="text-sm font-semibold">Testigos de asistencia</h3>
                <p class="text-xs text-muted-foreground">
                    Dan fe de que la diligencia se llevó a cabo; no declaran
                    sobre los hechos. Hasta 2.
                </p>
            </div>
            <TransitionGroup
                name="lista-acta"
                tag="div"
                class="flex flex-col gap-3"
            >
                <div
                    v-for="(t, i) in c.testigos_asistencia"
                    :key="`a${i}`"
                    class="flex flex-col gap-2 rounded-lg border border-dashed border-[var(--mrl-borde)] p-3 transition-colors hover:border-oro/50"
                >
                    <div class="flex items-center justify-between gap-2">
                        <Label>Testigo de asistencia {{ i + 1 }}</Label>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-7 hover:bg-danger-soft hover:text-destructive"
                            :aria-label="`Quitar testigo de asistencia ${i + 1}`"
                            @click="c.testigos_asistencia.splice(i, 1)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                    <BuscadorColaborador
                        :model-value="chip(t)"
                        :buscar-url="urlTestigos"
                        @update:model-value="(x) => elegir(t, x)"
                    />
                    <p
                        v-if="
                            conflictoPersona(
                                form,
                                trabajador.id,
                                t.colaborador_id,
                                t,
                            )
                        "
                        class="text-xs text-destructive"
                    >
                        {{
                            conflictoPersona(
                                form,
                                trabajador.id,
                                t.colaborador_id,
                                t,
                            )
                        }}
                    </p>
                    <InputError
                        :message="
                            error(
                                `circunstanciada.testigos_asistencia.${i}.colaborador_id`,
                            )
                        "
                    />
                </div>
            </TransitionGroup>
            <Button
                v-if="c.testigos_asistencia.length < 2"
                type="button"
                variant="outline"
                size="sm"
                class="self-start"
                @click="agregarTestigoAsistencia"
            >
                <Plus class="size-4" /> Agregar testigo de asistencia
            </Button>
        </section>
    </div>

    <!-- 4. Manifestaciones -->
    <div v-else class="flex flex-col gap-5">
        <section
            class="flex flex-col gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <h3 class="text-sm font-semibold">
                Trabajador · {{ trabajador.nombre }}
            </h3>
            <div class="grid gap-2 sm:grid-cols-2">
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-xl border px-3 py-2.5 text-left text-sm transition-all duration-150 hover:-translate-y-0.5 hover:shadow-sm motion-reduce:hover:translate-y-0"
                    :class="
                        c.trabajador.manifesto === true
                            ? 'border-[var(--mrl-petroleo)] bg-salvia font-medium text-[var(--mrl-petroleo)]'
                            : 'border-[var(--mrl-borde)] hover:border-oro/60'
                    "
                    :aria-pressed="c.trabajador.manifesto === true"
                    @click="c.trabajador.manifesto = true"
                >
                    <MessageSquareText class="size-4" /> Capturar manifestación
                </button>
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-xl border px-3 py-2.5 text-left text-sm transition-all duration-150 hover:-translate-y-0.5 hover:shadow-sm motion-reduce:hover:translate-y-0"
                    :class="
                        c.trabajador.manifesto === false
                            ? 'border-[var(--mrl-petroleo)] bg-salvia font-medium text-[var(--mrl-petroleo)]'
                            : 'border-[var(--mrl-borde)] hover:border-oro/60'
                    "
                    :aria-pressed="c.trabajador.manifesto === false"
                    @click="c.trabajador.manifesto = false"
                >
                    <MessageSquareOff class="size-4" /> No quiso manifestar
                </button>
            </div>
            <Textarea
                v-if="c.trabajador.manifesto === true"
                v-model="c.trabajador.manifestacion"
                rows="5"
                aria-label="Manifestación del trabajador"
                placeholder="Lo que manifestó, con sus palabras"
            />
            <p
                v-else-if="c.trabajador.manifesto === false"
                class="text-xs text-muted-foreground"
            >
                Se imprimirá: «El trabajador manifestó que no deseaba formular
                manifestación.»
            </p>
            <InputError
                :message="
                    error('circunstanciada.trabajador.manifestacion') ??
                    error('circunstanciada.trabajador.manifesto')
                "
            />
            <div class="flex flex-col gap-1.5">
                <Label for="acta-elementos"
                    >Elementos que aporta el trabajador (opcional)</Label
                >
                <Textarea
                    id="acta-elementos"
                    v-model="c.trabajador.elementos"
                    rows="2"
                    placeholder="Documentos, nombres de personas o pruebas que ofrece"
                />
            </div>
        </section>

        <section
            class="flex flex-col gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <h3 class="text-sm font-semibold">
                Quien levanta el acta<template v-if="jefeEsResponsable">
                    y jefe inmediato</template
                >
            </h3>
            <Textarea
                v-model="c.responsable.manifestacion"
                rows="3"
                aria-label="Manifestación de quien levanta el acta"
                placeholder="Lo que constató o conoce de los hechos (opcional)"
            />
            <p v-if="jefeEsResponsable" class="text-xs text-muted-foreground">
                Es la misma persona que el jefe inmediato: se registra una sola
                manifestación.
            </p>
        </section>

        <section
            v-if="c.jefe.participa && !jefeEsResponsable"
            class="flex flex-col gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <h3 class="text-sm font-semibold">
                Jefe inmediato<template v-if="c.jefe.nombre">
                    · {{ c.jefe.nombre }}</template
                >
            </h3>
            <Textarea
                v-model="c.jefe.manifestacion"
                rows="3"
                aria-label="Manifestación del jefe inmediato"
                placeholder="Lo que manifestó (opcional)"
            />
        </section>

        <section
            v-for="(t, i) in c.testigos_hechos.filter((x) => x.colaborador_id)"
            :key="`mh${i}`"
            class="flex flex-col gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <h3 class="text-sm font-semibold">
                Testigo de hechos · {{ t.nombre }}
            </h3>
            <Textarea
                v-model="t.manifestacion"
                rows="3"
                :aria-label="`Manifestación de ${t.nombre}`"
                placeholder="Lo que manifestó este testigo, con sus palabras"
            />
            <p class="text-xs text-muted-foreground">
                Cada testigo da su propia manifestación; nunca se copia de otro.
            </p>
        </section>

        <section
            v-for="(t, i) in c.testigos_asistencia.filter(
                (x) => x.colaborador_id,
            )"
            :key="`ma${i}`"
            class="flex flex-col gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <h3 class="text-sm font-semibold">
                Testigo de asistencia · {{ t.nombre }}
            </h3>
            <Textarea
                v-model="t.manifestacion"
                rows="2"
                :aria-label="`Manifestación de ${t.nombre}`"
                placeholder="Solo sobre el desarrollo de la diligencia (opcional)"
            />
        </section>

        <section
            class="flex flex-col gap-3 rounded-xl border border-[var(--mrl-borde)] bg-crema/30 p-4"
        >
            <h3 class="flex items-center gap-1.5 text-sm font-semibold">
                <ClipboardList class="size-4 text-[var(--mrl-petroleo)]" />
                Incidencias y cierre
            </h3>
            <div class="flex flex-col gap-1.5">
                <Label for="acta-incidencias"
                    >Incidencias durante el levantamiento (opcional)</Label
                >
                <Textarea
                    id="acta-incidencias"
                    v-model="c.incidencias_levantamiento"
                    rows="2"
                    placeholder="Ej. el trabajador se retiró antes de concluir"
                />
                <p class="text-xs text-muted-foreground">
                    Si no hubo, se imprime: «No se registraron incidencias
                    adicionales durante el levantamiento.»
                </p>
            </div>
            <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <div class="flex flex-col gap-1.5">
                    <Label for="acta-cierre-fecha">Fecha de cierre</Label>
                    <DatePicker
                        id="acta-cierre-fecha"
                        v-model="c.cierre.fecha"
                        :max-value="hoy"
                        placeholder="Al generar el acta"
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="acta-cierre-hora">Hora de cierre</Label>
                    <TimePicker
                        id="acta-cierre-hora"
                        v-model="c.cierre.hora"
                        :desde="0"
                        :hasta="23"
                        placeholder="Al generar"
                    />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="cerrarAhora"
                    >Usar ahora</Button
                >
            </div>
            <p class="text-xs text-muted-foreground">
                Si lo dejas vacío, se usa la fecha y hora en que generas el acta
                definitiva.
            </p>
        </section>
    </div>
</template>

<style scoped>
.lista-acta-enter-active,
.lista-acta-leave-active {
    transition:
        opacity 180ms ease,
        transform 180ms ease;
}

.lista-acta-enter-from,
.lista-acta-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
    .lista-acta-enter-active,
    .lista-acta-leave-active {
        transition: none;
    }
}
</style>
