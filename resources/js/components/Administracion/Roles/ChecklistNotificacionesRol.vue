<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { History, Info, Lock, RotateCcw, Save, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useAlertas } from '@/composables/useAlertas';
import { coincide, normalizar } from '@/lib/rolesPermisos';
import {
    restablecer,
    update,
} from '@/routes/administracion/roles/notificaciones';
import type {
    CambioNotificacionRol,
    ChecklistNotificaciones,
    EventoNotificacion,
    RolItem,
} from '@/types/roles';

/**
 * Qué acción le llega a este rol, en web y app. La autorización real vive en
 * PoliticaNotificacionRolService: las casillas bloqueadas también se validan allá.
 */
const props = defineProps<{
    rol: RolItem;
    checklist: ChecklistNotificaciones;
    historial: CambioNotificacionRol[];
}>();

const emit = defineEmits<{ cambios: [total: number] }>();

const { confirmarAccion } = useAlertas();

const marcadosActuales = computed(
    () => new Set(props.checklist.marcados[props.rol.id] ?? []),
);
const obligatorios = computed(
    () => new Set(props.checklist.obligatorios[props.rol.id] ?? []),
);

const borrador = ref(new Set<string>(marcadosActuales.value));
const busqueda = ref('');
const guardando = ref(false);
const verHistorial = ref(false);

watch(marcadosActuales, (actuales) => {
    borrador.value = new Set(actuales);
});

const totalCambios = computed(() => {
    const agregados = [...borrador.value].filter(
        (clave) => !marcadosActuales.value.has(clave),
    );
    const quitados = [...marcadosActuales.value].filter(
        (clave) => !borrador.value.has(clave),
    );

    return agregados.length + quitados.length;
});

watch(totalCambios, (total) => emit('cambios', total), { immediate: true });

const modulosFiltrados = computed(() => {
    const filtro = normalizar(busqueda.value);

    return props.checklist.modulos
        .map((modulo) => ({
            ...modulo,
            eventos: modulo.eventos.filter((evento) =>
                coincide(filtro, evento.etiqueta, modulo.etiqueta),
            ),
        }))
        .filter((modulo) => modulo.eventos.length > 0);
});

function esEditable(evento: EventoNotificacion): boolean {
    return evento.audiencia === 'operativa' && !obligatorios.value.has(evento.clave);
}

function estaMarcado(evento: EventoNotificacion): boolean {
    return evento.audiencia !== 'operativa' || borrador.value.has(evento.clave);
}

function motivoBloqueo(evento: EventoNotificacion): string | null {
    if (evento.audiencia === 'personal') {
        return 'Siempre le llega a la persona: es sobre su propio trámite.';
    }

    if (evento.audiencia === 'asignada') {
        return 'Siempre le llega a la persona asignada, sea cual sea su rol.';
    }

    return obligatorios.value.has(evento.clave)
        ? props.checklist.mensaje_obligatoria
        : null;
}

function alternar(evento: EventoNotificacion): void {
    if (!esEditable(evento)) {
        return;
    }

    const siguiente = new Set(borrador.value);

    if (siguiente.has(evento.clave)) {
        siguiente.delete(evento.clave);
    } else {
        siguiente.add(evento.clave);
    }

    borrador.value = siguiente;
}

function marcarModulo(eventos: EventoNotificacion[], marcar: boolean): void {
    const siguiente = new Set(borrador.value);

    eventos.filter(esEditable).forEach((evento) => {
        if (marcar) {
            siguiente.add(evento.clave);
        } else {
            siguiente.delete(evento.clave);
        }
    });

    borrador.value = siguiente;
}

function descartar(): void {
    borrador.value = new Set(marcadosActuales.value);
}

function guardar(): void {
    router.put(
        update.url(props.rol.id),
        { eventos: [...borrador.value] },
        {
            preserveScroll: true,
            onStart: () => (guardando.value = true),
            onFinish: () => (guardando.value = false),
        },
    );
}

async function restablecerValores(): Promise<void> {
    const confirmado = await confirmarAccion(
        'Restablecer valores por defecto',
        `Se pierden los cambios hechos a los avisos de «${props.rol.nombre}». Queda registrado en el historial.`,
        'Restablecer',
    );

    if (!confirmado) {
        return;
    }

    router.post(
        restablecer.url(props.rol.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (guardando.value = true),
            onFinish: () => (guardando.value = false),
        },
    );
}

function fechaLegible(fecha: string | null): string {
    return fecha === null
        ? ''
        : new Date(fecha).toLocaleString('es-MX', {
              dateStyle: 'medium',
              timeStyle: 'short',
          });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div
            class="flex gap-2 rounded-xl bg-info-soft p-3 text-sm"
            data-testid="ayuda-checklist"
        >
            <Info class="mt-0.5 size-4 shrink-0 text-info" />
            <span>
                Decide qué acciones le avisan a «{{ rol.nombre }}» en la campana,
                la app y el navegador. Marcar no amplía lo que el rol puede ver
                ni hacer: sólo recibe avisos de personas dentro de su alcance.
                Quitar un aviso no quita la tarea: sigue en «Mis pendientes».
            </span>
        </div>

        <div
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-3 shadow-sm md:flex-row md:items-center"
        >
            <div class="relative min-w-0 flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="busqueda"
                    class="pl-9"
                    placeholder="Buscar aviso: «baja», «candidato», «documento»…"
                    aria-label="Buscar aviso"
                />
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    @click="verHistorial = !verHistorial"
                >
                    <History class="size-4" />
                    Historial
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="guardando"
                    @click="restablecerValores"
                >
                    <RotateCcw class="size-4" />
                    Restablecer valores por defecto
                </Button>
            </div>
        </div>

        <section
            v-if="verHistorial"
            class="rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
            aria-label="Historial de cambios"
        >
            <h3 class="mb-2 text-sm font-semibold">Historial de cambios</h3>
            <p
                v-if="historial.length === 0"
                class="text-sm text-muted-foreground"
            >
                Este rol todavía no tiene cambios.
            </p>
            <ul v-else class="flex flex-col gap-2 text-sm">
                <li v-for="cambio in historial" :key="cambio.id">
                    <span class="font-medium">{{ cambio.etiqueta }}</span
                    >: {{ cambio.antes ? 'marcado' : 'sin marcar' }} →
                    {{ cambio.despues ? 'marcado' : 'sin marcar' }}
                    <span class="text-muted-foreground"
                        >· {{ cambio.actor ?? 'Sistema' }} ·
                        {{ fechaLegible(cambio.fecha) }}</span
                    >
                </li>
            </ul>
        </section>

        <p
            v-if="modulosFiltrados.length === 0"
            class="rounded-2xl border border-border/60 bg-card p-6 text-center text-sm text-muted-foreground"
        >
            Ningún aviso coincide con tu búsqueda.
        </p>

        <section
            v-for="modulo in modulosFiltrados"
            :key="modulo.clave"
            class="rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
            :aria-label="modulo.etiqueta"
        >
            <header class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold">{{ modulo.etiqueta }}</h3>
                <div
                    v-if="modulo.eventos.some(esEditable)"
                    class="flex gap-1"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="marcarModulo(modulo.eventos, true)"
                        >Marcar módulo</Button
                    >
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="marcarModulo(modulo.eventos, false)"
                        >Quitar módulo</Button
                    >
                </div>
            </header>

            <ul class="flex flex-col gap-1">
                <li v-for="evento in modulo.eventos" :key="evento.clave">
                    <label
                        class="flex items-start gap-3 rounded-lg px-2 py-2 text-sm"
                        :class="
                            esEditable(evento)
                                ? 'cursor-pointer hover:bg-muted/60'
                                : 'opacity-80'
                        "
                    >
                        <input
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 accent-primary"
                            :checked="estaMarcado(evento)"
                            :disabled="!esEditable(evento)"
                            @change="alternar(evento)"
                        />
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="flex flex-wrap items-center gap-2">
                                {{ evento.etiqueta }}
                                <Badge
                                    v-if="motivoBloqueo(evento)"
                                    variant="secondary"
                                    ><Lock /> Obligatoria</Badge
                                >
                            </span>
                            <span
                                v-if="motivoBloqueo(evento)"
                                class="text-xs text-muted-foreground"
                                >{{ motivoBloqueo(evento) }}</span
                            >
                        </span>
                        <span class="flex shrink-0 gap-1">
                            <Badge variant="outline">Web</Badge>
                            <Badge variant="outline">App</Badge>
                        </span>
                    </label>
                </li>
            </ul>
        </section>

        <div
            v-if="totalCambios > 0"
            class="sticky bottom-20 z-20 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-warning/40 bg-warning-soft p-3 shadow-lg md:bottom-4"
            role="status"
        >
            <span class="text-sm font-semibold"
                >{{ totalCambios }} cambio(s) sin guardar en «{{
                    rol.nombre
                }}»</span
            >
            <div class="flex gap-2">
                <Button variant="outline" size="sm" @click="descartar">
                    <RotateCcw class="size-4" />
                    Descartar
                </Button>
                <Button size="sm" :disabled="guardando" @click="guardar">
                    <Save class="size-4" />
                    {{ guardando ? 'Guardando…' : 'Guardar avisos' }}
                </Button>
            </div>
        </div>
    </div>
</template>
