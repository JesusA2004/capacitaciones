<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgeCheck,
    Building2,
    ClipboardCheck,
    FileSignature,
    Gavel,
    Hexagon,
    Hourglass,
    LogOut,
    MapPinned,
    Network,
    Package,
    ScanText,
    Sparkles,
    UserCheck,
    UserRound,
    UserSearch,
} from '@lucide/vue';
import type { Component } from 'vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import { formatearFecha } from '@/lib/fechas';
import { TONO_SUAVE, TONO_TEXTO } from '@/lib/tonos';
import { cn } from '@/lib/utils';
import type { CentroExpediente, TarjetaCentroExpediente } from '@/types';

/**
 * «Expediente como centro»: organización (jefe organizacional ≠ responsable
 * de aprobación), origen en reclutamiento, contratación y una tarjeta por
 * área (onboarding, periodo de prueba, evaluaciones, activos, actas, bajas,
 * OCR/correcciones) con contador, estado y una sola acción clara.
 */
const props = defineProps<{ centro: CentroExpediente }>();

const emit = defineEmits<{ pestana: [pestana: string] }>();

const ICONOS: Record<string, Component> = {
    hourglass: Hourglass,
    sparkles: Sparkles,
    'clipboard-check': ClipboardCheck,
    package: Package,
    gavel: Gavel,
    'log-out': LogOut,
    'scan-text': ScanText,
};

function accion(t: TarjetaCentroExpediente) {
    if (t.pestana) {
        emit('pestana', t.pestana);
    }
}

const contratacion = props.centro.contratacion;
</script>

<template>
    <div class="flex flex-col gap-4" data-centro-expediente>
        <!-- Organización -->
        <section
            class="grid grid-cols-1 gap-3 rounded-2xl border border-border/60 bg-card p-4 sm:grid-cols-2 xl:grid-cols-3"
            data-bloque="organizacion"
        >
            <div class="flex items-start gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary">
                    <BadgeCheck class="size-4" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">Puesto</p>
                    <p class="truncate text-sm font-semibold">
                        {{ centro.organizacion.puesto ?? 'Sin puesto' }}
                    </p>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary">
                    <Hexagon class="size-4" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">Departamento</p>
                    <p class="truncate text-sm font-semibold">
                        {{ centro.organizacion.departamento ?? '—' }}
                    </p>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary">
                    <MapPinned class="size-4" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">Sucursal</p>
                    <p class="truncate text-sm font-semibold">
                        {{ centro.organizacion.sucursal ?? '—' }}
                    </p>
                    <p
                        v-if="centro.organizacion.empresa"
                        class="flex items-center gap-1 truncate text-xs text-muted-foreground"
                    >
                        <Building2 class="size-3" />
                        {{ centro.organizacion.empresa }}
                    </p>
                </div>
            </div>
            <div class="flex items-start gap-3" data-bloque="jefe-organizacional">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-info-soft text-info">
                    <Network class="size-4" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">Jefe organizacional</p>
                    <p class="truncate text-sm font-semibold">
                        {{ centro.organizacion.jefe_organizacional?.nombre ?? 'Sin asignar' }}
                    </p>
                    <p
                        v-if="centro.organizacion.jefe_organizacional?.puesto"
                        class="truncate text-xs text-muted-foreground"
                    >
                        {{ centro.organizacion.jefe_organizacional.puesto }}
                    </p>
                </div>
            </div>
            <div class="flex items-start gap-3 xl:col-span-2" data-bloque="responsable-aprobacion">
                <span
                    :class="
                        cn(
                            'flex size-9 shrink-0 items-center justify-center rounded-xl',
                            centro.organizacion.responsable_aprobacion.bloqueado
                                ? TONO_SUAVE.alerta
                                : TONO_SUAVE.exito,
                        )
                    "
                >
                    <UserCheck class="size-4" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">Responsable de aprobaciones</p>
                    <p class="text-sm font-semibold">
                        {{ centro.organizacion.responsable_aprobacion.nombre ?? 'Pendiente de asignar' }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="centro.organizacion.responsable_aprobacion.puesto">
                            {{ centro.organizacion.responsable_aprobacion.puesto }} ·
                        </template>
                        {{
                            centro.organizacion.responsable_aprobacion.mensaje ??
                            centro.organizacion.responsable_aprobacion.origen
                        }}
                    </p>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <!-- Origen en reclutamiento -->
            <section
                class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4"
                data-bloque="origen"
            >
                <div class="flex items-center justify-between gap-2">
                    <h3 class="flex items-center gap-2 text-sm font-semibold">
                        <UserSearch class="size-4 text-primary" /> Origen en
                        reclutamiento
                    </h3>
                    <ChipEstado
                        v-if="centro.origen"
                        :etiqueta="centro.origen.estado"
                        tono="primario"
                    />
                </div>
                <template v-if="centro.origen">
                    <p class="text-sm">
                        Candidato
                        <span class="font-medium">{{ centro.origen.nombre }}</span>
                        <template v-if="centro.origen.puesto">
                            · {{ centro.origen.puesto }}</template
                        >
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Registrado
                        {{ formatearFecha(centro.origen.registrado_en) }}
                        <template v-if="centro.origen.contratado_en">
                            · contratado
                            {{ formatearFecha(centro.origen.contratado_en) }}
                        </template>
                    </p>
                    <div v-if="centro.origen.url">
                        <Button size="sm" variant="outline" as-child>
                            <Link :href="centro.origen.url" data-accion="ver-origen">
                                <UserRound class="size-3.5" /> Ver origen en
                                reclutamiento
                            </Link>
                        </Button>
                    </div>
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    Sin candidato de origen (alta directa o migración).
                </p>
            </section>

            <!-- Contratación (resumen; el detalle está en su pestaña) -->
            <section
                class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4"
                data-bloque="contratacion"
            >
                <div class="flex items-center justify-between gap-2">
                    <h3 class="flex items-center gap-2 text-sm font-semibold">
                        <FileSignature class="size-4 text-primary" /> Contratación
                    </h3>
                    <ChipEstado
                        v-if="contratacion"
                        :etiqueta="contratacion.estado_etiqueta"
                        :tono="
                            contratacion.estado === 'completo'
                                ? 'exito'
                                : contratacion.estado === 'faltan_datos'
                                  ? 'alerta'
                                  : 'info'
                        "
                    />
                </div>
                <template v-if="contratacion && contratacion.contratos.length === 0">
                    <p class="text-sm text-warning">
                        {{ contratacion.motivo ?? 'Aún no hay contratos que calcular.' }}
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="b in contratacion.bloqueos"
                            :key="b.etiqueta"
                            class="rounded-full bg-warning-soft px-2.5 py-0.5 text-xs font-medium text-warning"
                            >{{ b.etiqueta }}</span
                        >
                    </div>
                </template>
                <template v-else-if="contratacion">
                    <p class="text-sm">
                        <span class="font-medium">
                            {{
                                contratacion.contratos.reduce(
                                    (n, e) =>
                                        n +
                                        e.documentos.filter((d) => d.estado === 'generado').length,
                                    0,
                                )
                            }}/{{
                                contratacion.contratos.reduce(
                                    (n, e) => n + e.documentos.length,
                                    0,
                                )
                            }}
                        </span>
                        documentos generados
                        <template v-if="contratacion.faltantes.length > 0">
                            ·
                            <span class="text-warning"
                                >{{ contratacion.faltantes.length }} dato{{
                                    contratacion.faltantes.length === 1 ? '' : 's'
                                }}
                                pendiente{{
                                    contratacion.faltantes.length === 1 ? '' : 's'
                                }}</span
                            >
                        </template>
                    </p>
                    <div>
                        <Button size="sm" variant="outline" @click="emit('pestana', 'contratacion')">
                            Ver contratación <ArrowRight class="size-3.5" />
                        </Button>
                    </div>
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    Sin acceso al módulo de contratos.
                </p>
            </section>
        </div>

        <!-- Tarjetas por área -->
        <div class="grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4 2xl:grid-cols-7">
            <component
                :is="t.url ? Link : t.pestana ? 'button' : 'div'"
                v-for="t in centro.tarjetas"
                :key="t.clave"
                :href="t.url ?? undefined"
                :type="!t.url && t.pestana ? 'button' : undefined"
                :class="
                    cn(
                        'flex min-w-0 flex-col gap-2 rounded-2xl border border-border/60 bg-card p-3 text-left',
                        (t.url || t.pestana) && 'tarjeta-interactiva cursor-pointer',
                    )
                "
                :data-tarjeta="t.clave"
                @click="!t.url && accion(t)"
            >
                <div class="flex items-start justify-between gap-2">
                    <span class="text-xs leading-tight font-medium text-muted-foreground">{{
                        t.titulo
                    }}</span>
                    <span
                        :class="cn('flex size-8 shrink-0 items-center justify-center rounded-xl', TONO_SUAVE[t.tono])"
                        aria-hidden="true"
                    >
                        <component :is="ICONOS[t.icono] ?? Sparkles" class="size-4" />
                    </span>
                </div>
                <span :class="cn('text-lg leading-tight font-bold', TONO_TEXTO[t.tono])">{{
                    t.valor
                }}</span>
                <span v-if="t.detalle" class="text-xs text-muted-foreground">{{ t.detalle }}</span>
                <span
                    v-if="t.accion && (t.url || t.pestana)"
                    class="mt-auto flex items-center gap-1 text-xs font-medium text-primary"
                >
                    {{ t.accion }} <ArrowRight class="size-3" />
                </span>
            </component>
        </div>
    </div>
</template>
