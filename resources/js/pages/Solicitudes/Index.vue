<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Banknote,
    Baby,
    Cake,
    Calendar,
    Clock,
    ClipboardList,
    Eye,
    FileEdit,
    FileWarning,
    Heart,
    LogOut,
    MessageSquare,
    Plus,
    Timer,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DatosPersonalesFormulario from '@/components/Solicitudes/DatosPersonalesFormulario.vue';
import PermisoFormulario from '@/components/Solicitudes/PermisoFormulario.vue';
import SelectorDiasVacaciones from '@/components/Solicitudes/SelectorDiasVacaciones.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import VacacionesAntiguedad from '@/components/Vacaciones/VacacionesAntiguedad.vue';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index, show, store } from '@/routes/solicitudes';
import type {
    DatoPersonalCaptura,
    RespuestaPaginada,
    SaldoVacaciones,
    SolicitudInternaItem,
    TipoSolicitudInternaFormulario,
} from '@/types';

const props = defineProps<{
    solicitudes: RespuestaPaginada<SolicitudInternaItem>;
    tipos: TipoSolicitudInternaFormulario[];
    saldoVacaciones: SaldoVacaciones;
    datosPersonales: DatoPersonalCaptura[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mis solicitudes', href: index.url() },
        ],
    },
});

const dialogoAbierto = ref(false);
const pasoFormulario = ref<'tipo' | 'detalle'>('tipo');

const ICONO_TIPO: Record<string, typeof ClipboardList> = {
    permiso: Clock,
    vacaciones: Calendar,
    permiso_con_goce: FileEdit,
    permiso_sin_goce: FileEdit,
    permiso_tiempo: Timer,
    salida_temprano: LogOut,
    llegada_tarde: Clock,
    incapacidad: FileWarning,
    constancia_laboral: ClipboardList,
    actualizacion_datos: FileEdit,
    actualizacion_bancaria: Banknote,
    reposicion_documental: FileWarning,
    prestamo: Banknote,
    permiso_especial_cumpleanos: Cake,
    permiso_especial_paternidad: Baby,
    permiso_especial_fallecimiento: Heart,
    solicitud_general: MessageSquare,
};

const DESCRIPCION_TIPO: Record<string, string> = {
    vacaciones: 'Solicita días de tu saldo disponible.',
    permiso_con_goce: 'Permiso pagado por un rango de fechas.',
    permiso_sin_goce: 'Permiso sin pago por un rango de fechas.',
    permiso_tiempo: 'Salida por algunas horas en el día.',
    salida_temprano: 'Terminar tu jornada antes de la hora habitual.',
    llegada_tarde: 'Llegar después de tu horario habitual.',
    incapacidad: 'Registra una incapacidad médica y su comprobante.',
    constancia_laboral: 'Pide una constancia de que trabajas aquí.',
    actualizacion_datos: 'Corrige o actualiza tus datos personales.',
    actualizacion_bancaria: 'Actualiza tu cuenta para depósito de nómina.',
    reposicion_documental: 'Repone un documento de tu expediente.',
    prestamo: 'Solicita un préstamo con descuento a nómina.',
    permiso_especial_cumpleanos: 'Día libre por tu cumpleaños.',
    permiso_especial_paternidad: 'Permiso especial por paternidad.',
    permiso_especial_fallecimiento:
        'Permiso especial por fallecimiento familiar.',
    solicitud_general: 'Cualquier otro trámite que no encaje arriba.',
};

const AVISO_FORMATO_AUTOMATICO: Record<string, string> = {
    permiso:
        'Tu gerente la revisa y Recursos Humanos la autoriza; al autorizarse se genera el formato oficial de permiso para imprimir y firmar.',
    vacaciones:
        'Al aprobarse se generará tu formato de vacaciones automáticamente.',
    permiso_con_goce:
        'Al aprobarse se generará el formato de permiso automáticamente.',
    permiso_sin_goce:
        'Al aprobarse se generará el formato de permiso automáticamente.',
    permiso_tiempo:
        'Al aprobarse se generará el formato de permiso automáticamente.',
    salida_temprano:
        'Al aprobarse se generará el formato de permiso automáticamente.',
    llegada_tarde:
        'Al aprobarse se generará el formato de permiso automáticamente.',
    prestamo:
        'Al aprobarse se generará el contrato de crédito correspondiente.',
};

function seleccionarTipo(clave: string) {
    form.tipo = clave;
    pasoFormulario.value = 'detalle';
}

function abrirNuevaSolicitud() {
    pasoFormulario.value = 'tipo';
    dialogoAbierto.value = true;
}

// ?nueva=<tipo> (p. ej. "Solicitar corrección" desde Mi expediente) abre
// el formulario de ese tipo directamente.
onMounted(() => {
    const nueva = new URLSearchParams(window.location.search).get('nueva');

    if (nueva && props.tipos.some((t) => t.clave === nueva)) {
        form.tipo = nueva;
        pasoFormulario.value = 'detalle';
        dialogoAbierto.value = true;
    }
});

const form = useForm({
    tipo: '',
    motivo: '',
    observaciones: '',
    fecha_inicio: '',
    duracion_dias: '',
    dias: [] as string[],
    monto_solicitado: '',
    permiso_tipo: '',
    permiso_goce: '',
    permiso_causal: '',
    hora_salida: '',
    hora_entrada: '',
    datos: {} as Record<string, string>,
});

// Taxonomía definitiva: permisos, vacaciones y préstamos. La baja NO es
// una solicitud (el jefe directo la inicia desde el colaborador → Cierre
// laboral). Actualización de datos se abre desde «Completar información» /
// «Solicitar cambio», no aparece como tarjeta.
const tiposCatalogo = computed(() => props.tipos.filter((t) => t.en_catalogo));

const esPermiso = computed(() => form.tipo === 'permiso');
const opcionesPermiso = (campo: string) =>
    tipoActual.value?.campos.find((c) => c.name === campo)?.opciones ?? [];

// Solo los campos del tipo elegido: nunca se manda un campo que la
// pantalla no muestra (antes `dias: []` en un préstamo daba un error
// invisible y el botón «no hacía nada»).
function camposDelTipo(datos: ReturnType<typeof form.data>) {
    const base = {
        tipo: datos.tipo,
        motivo: datos.motivo,
        observaciones: datos.observaciones,
    };

    // Completar / cambiar datos: solo los campos que la persona capturó.
    if (datos.tipo === 'actualizacion_datos') {
        const capturados = Object.fromEntries(
            Object.entries(datos.datos).filter(([, v]) => v.trim() !== ''),
        );

        return {
            ...base,
            ...(Object.keys(capturados).length ? { datos: capturados } : {}),
        };
    }

    if (datos.tipo === 'permiso') {
        return {
            ...base,
            permiso_tipo: datos.permiso_tipo,
            permiso_goce: datos.permiso_goce,
            permiso_causal: datos.permiso_causal,
            hora_salida: datos.hora_salida,
            hora_entrada: datos.hora_entrada,
            fecha_inicio: datos.fecha_inicio,
            duracion_dias: datos.duracion_dias,
        };
    }

    if (tipoActual.value?.requiere_monto) {
        return { ...base, monto_solicitado: datos.monto_solicitado };
    }

    return {
        ...base,
        ...(modoFechas.value === 'dias_especificos'
            ? { dias: datos.dias }
            : {}),
        ...(modoFechas.value !== 'ninguna' &&
        modoFechas.value !== 'dias_especificos'
            ? {
                  fecha_inicio: datos.fecha_inicio,
                  duracion_dias: datos.duracion_dias,
              }
            : {}),
    };
}

function enviar() {
    if (form.processing) {
        return;
    }

    form.transform(camposDelTipo).post(store.url(), {
        preserveScroll: true,
        // Con error el modal sigue abierto: cada error se pinta junto a su
        // campo y la red global (lib/erroresGlobales) avisa y enfoca.
        onSuccess: () => {
            dialogoAbierto.value = false;
            pasoFormulario.value = 'tipo';
            form.reset();
        },
    });
}

const tipoActual = computed(() =>
    props.tipos.find((t) => t.clave === form.tipo),
);

// El permiso pinta sus propias fechas (PermisoFormulario).
const modoFechas = computed(() =>
    esPermiso.value ? 'ninguna' : (tipoActual.value?.modo_fechas ?? 'ninguna'),
);

/** Vista previa de la fecha de término: inicio + (días − 1), naturales. */
const fechaTermino = computed(() => {
    const dias = Number(form.duracion_dias);

    if (!form.fecha_inicio || !Number.isInteger(dias) || dias < 1) {
        return null;
    }

    const [a, m, d] = form.fecha_inicio.split('-').map(Number);
    const fin = new Date(a, m - 1, d + dias - 1);

    return fin.toLocaleDateString('es-MX', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
});

const saldoRestante = computed(
    () => props.saldoVacaciones.dias_disponibles - form.dias.length,
);
function nombreTipo(clave: string): string {
    return props.tipos.find((t) => t.clave === clave)?.nombre ?? clave;
}
</script>

<template>
    <Head title="Mis solicitudes" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Mis solicitudes"
            descripcion="Permisos, vacaciones y préstamos en un solo lugar."
            :icono="ClipboardList"
        >
            <Button
                data-tour="mis-solicitudes-nueva"
                @click="abrirNuevaSolicitud"
            >
                <Plus class="size-4" />
                Nueva solicitud
            </Button>
        </CrudPageHeader>

        <CrudEmptyState
            v-if="!solicitudes.data.length"
            :icono="ClipboardList"
            titulo="Todavía no tienes solicitudes"
            descripcion="Crea tu primera solicitud interna con el botón de arriba."
        >
            <Button @click="abrirNuevaSolicitud">
                <Plus class="size-4" />
                Crear la primera
            </Button>
        </CrudEmptyState>

        <div
            v-else
            data-tour="mis-solicitudes-lista"
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
            <Link
                v-for="solicitud in solicitudes.data"
                :key="solicitud.id"
                :href="show.url(solicitud.id)"
                class="group flex flex-col gap-2.5 rounded-2xl border border-border/60 bg-card p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ solicitud.folio }}
                        </p>
                        <p class="text-base font-semibold">
                            {{ nombreTipo(solicitud.tipo) }}
                        </p>
                    </div>
                    <EstadoBadge :estado="solicitud.estado" />
                </div>
                <p class="line-clamp-2 text-sm text-muted-foreground">
                    {{ solicitud.motivo }}
                </p>
                <div
                    class="mt-1 flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>{{ formatearFecha(solicitud.created_at) }}</span>
                    <Eye
                        class="size-4 opacity-60 transition-opacity md:opacity-0 md:group-hover:opacity-100"
                    />
                </div>
            </Link>
        </div>
    </div>

    <Dialog v-model:open="dialogoAbierto">
        <DialogContent
            class="max-h-[90vh] w-[calc(100vw-2rem)] max-w-none overflow-y-auto sm:w-[min(94vw,1100px)]"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        pasoFormulario === 'tipo'
                            ? '¿Qué necesitas solicitar?'
                            : form.tipo === 'actualizacion_datos'
                              ? 'Completar o cambiar mis datos'
                              : `Nueva solicitud — ${tipoActual?.nombre ?? ''}`
                    }}
                </DialogTitle>
            </DialogHeader>

            <div
                v-if="pasoFormulario === 'tipo'"
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <button
                    v-for="tipo in tiposCatalogo"
                    :key="tipo.clave"
                    type="button"
                    class="flex flex-col items-start gap-2 rounded-2xl border border-border/60 bg-card p-4 text-left transition-colors hover:border-primary/40 hover:bg-accent/40"
                    @click="seleccionarTipo(tipo.clave)"
                >
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-primary/10 text-primary"
                    >
                        <component
                            :is="ICONO_TIPO[tipo.clave] ?? ClipboardList"
                            class="size-4.5"
                        />
                    </span>
                    <span class="text-sm font-semibold">{{ tipo.nombre }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        tipo.descripcion ||
                        DESCRIPCION_TIPO[tipo.clave] ||
                        'Solicítalo desde aquí.'
                    }}</span>
                </button>
            </div>

            <form v-else class="grid gap-4" @submit.prevent="enviar">
                <button
                    type="button"
                    class="flex w-fit items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"
                    @click="pasoFormulario = 'tipo'"
                >
                    <ArrowLeft class="size-3.5" />
                    Cambiar tipo de solicitud
                </button>

                <div
                    v-if="AVISO_FORMATO_AUTOMATICO[form.tipo]"
                    class="rounded-lg border border-[var(--brand-primary)]/30 bg-[var(--brand-primary)]/5 p-3 text-xs text-foreground"
                >
                    {{ AVISO_FORMATO_AUTOMATICO[form.tipo] }}
                </div>

                <p
                    v-if="form.errors.tipo"
                    class="text-sm text-destructive"
                    data-input-error
                >
                    {{ form.errors.tipo }}
                </p>

                <DatosPersonalesFormulario
                    v-if="
                        form.tipo === 'actualizacion_datos' &&
                        datosPersonales.length
                    "
                    v-model="form.datos"
                    :campos="datosPersonales"
                    :errores="form.errors"
                />

                <!-- Permiso: solo lo que pide el formato oficial -->
                <PermisoFormulario
                    v-if="esPermiso"
                    v-model:permiso-tipo="form.permiso_tipo"
                    v-model:permiso-goce="form.permiso_goce"
                    v-model:permiso-causal="form.permiso_causal"
                    v-model:fecha-inicio="form.fecha_inicio"
                    v-model:duracion-dias="form.duracion_dias"
                    v-model:hora-salida="form.hora_salida"
                    v-model:hora-entrada="form.hora_entrada"
                    :opciones-tipo="opcionesPermiso('permiso_tipo')"
                    :opciones-goce="opcionesPermiso('permiso_goce')"
                    :opciones-causal="opcionesPermiso('permiso_causal')"
                    :errores="form.errors"
                />

                <VacacionesAntiguedad
                    v-if="tipoActual?.requiere_dias"
                    :saldo="saldoVacaciones"
                    class="rounded-xl border border-border/60 p-3"
                />

                <!-- Vacaciones: saldo antes → días elegidos → saldo después -->
                <div
                    v-if="tipoActual?.requiere_dias"
                    class="grid grid-cols-3 gap-2 text-center"
                >
                    <div
                        class="rounded-xl border border-border/60 bg-muted/40 p-3"
                    >
                        <p class="text-2xl font-bold tabular-nums">
                            {{ saldoVacaciones.dias_disponibles }}
                        </p>
                        <p class="text-xs text-muted-foreground">Saldo antes</p>
                    </div>
                    <div
                        class="rounded-xl border border-primary/30 bg-primary/5 p-3"
                    >
                        <p class="text-2xl font-bold text-primary tabular-nums">
                            {{ form.dias.length }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Días seleccionados
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-border/60 bg-muted/40 p-3"
                    >
                        <p
                            class="text-2xl font-bold tabular-nums"
                            :class="saldoRestante < 0 ? 'text-destructive' : ''"
                        >
                            {{ saldoRestante }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Saldo después
                        </p>
                    </div>
                </div>

                <!-- Fechas según el tipo: el backend calcula la fecha fin
                     (FechasSolicitudService), nunca se captura a mano. -->
                <div
                    v-if="
                        modoFechas === 'duracion' ||
                        modoFechas === 'horario' ||
                        modoFechas === 'fecha_unica'
                    "
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <div class="grid gap-2">
                        <Label for="fecha_inicio">{{
                            modoFechas === 'duracion'
                                ? 'Fecha de inicio'
                                : 'Fecha'
                        }}</Label>
                        <DatePicker
                            id="fecha_inicio"
                            v-model="form.fecha_inicio"
                        />
                        <p
                            v-if="form.errors.fecha_inicio"
                            class="text-sm text-destructive"
                            data-input-error
                        >
                            {{ form.errors.fecha_inicio }}
                        </p>
                    </div>
                    <div v-if="modoFechas === 'duracion'" class="grid gap-2">
                        <Label for="duracion_dias">Número de días</Label>
                        <Input
                            id="duracion_dias"
                            v-model="form.duracion_dias"
                            type="number"
                            min="1"
                            max="365"
                        />
                        <p
                            v-if="form.errors.duracion_dias"
                            class="text-sm text-destructive"
                            data-input-error
                        >
                            {{ form.errors.duracion_dias }}
                        </p>
                    </div>
                    <p
                        v-if="modoFechas === 'duracion' && fechaTermino"
                        class="text-sm text-muted-foreground sm:col-span-2"
                    >
                        Termina el
                        <span class="font-semibold text-foreground">{{
                            fechaTermino
                        }}</span>
                        (días naturales: incluye sábado y domingo).
                    </p>
                </div>

                <div
                    v-if="modoFechas === 'dias_especificos'"
                    class="grid gap-2"
                >
                    <Label>Días de vacaciones</Label>
                    <SelectorDiasVacaciones
                        v-model="form.dias"
                        :dias-no-seleccionables="
                            tipoActual?.dias_no_seleccionables ?? [0]
                        "
                    />
                    <p class="text-sm text-muted-foreground">
                        Te quedarían
                        <span
                            class="font-semibold"
                            :class="
                                saldoRestante < 0
                                    ? 'text-destructive'
                                    : 'text-foreground'
                            "
                            >{{ saldoRestante }}</span
                        >
                        días disponibles. El domingo no cuenta como vacaciones.
                    </p>
                    <p
                        v-if="form.errors.dias"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ form.errors.dias }}
                    </p>
                </div>

                <!-- Préstamo interno: solo monto y motivo; plazo, periodicidad,
                     entrega y primer descuento los define RH al autorizar. -->
                <div v-if="tipoActual?.requiere_monto" class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="monto_solicitado">Monto solicitado</Label>
                        <Input
                            id="monto_solicitado"
                            v-model="form.monto_solicitado"
                            type="number"
                            min="1"
                            step="0.01"
                        />
                        <p
                            v-if="form.errors.monto_solicitado"
                            class="text-sm text-destructive"
                            data-input-error
                        >
                            {{ form.errors.monto_solicitado }}
                        </p>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        RH definirá contigo el plazo, la periodicidad y la fecha
                        del primer descuento al autorizar el préstamo.
                    </p>
                </div>

                <div v-if="!esPermiso" class="grid gap-2">
                    <Label for="motivo">{{
                        form.tipo === 'actualizacion_datos'
                            ? 'Comentario para RH (opcional)'
                            : 'Motivo'
                    }}</Label>
                    <Textarea id="motivo" v-model="form.motivo" rows="3" />
                    <p
                        v-if="form.errors.motivo"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ form.errors.motivo }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="observaciones">Observaciones (opcional)</Label>
                    <Textarea
                        id="observaciones"
                        v-model="form.observaciones"
                        rows="2"
                    />
                    <p
                        v-if="form.errors.observaciones"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ form.errors.observaciones }}
                    </p>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="dialogoAbierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Enviar solicitud
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
