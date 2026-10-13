<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    BellRing,
    Building2,
    ChevronRight,
    FileStack,
    Settings2,
    ToggleRight,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmpresaFormDialog from '@/components/Administracion/EmpresaFormDialog.vue';
import { Button } from '@/components/ui/button';
import { ayuda, dashboard } from '@/routes';
import { index as releasesIndex } from '@/routes/administracion/app-releases';
import {
    apariencia,
    notificaciones as notificacionesRuta,
    parametrosRh,
} from '@/routes/administracion/configuracion';
import { index as empresasIndex } from '@/routes/administracion/empresas';
import { index as rolesIndex } from '@/routes/administracion/roles';
import { index as usuariosIndex } from '@/routes/administracion/usuarios';

import { index as maestrosIndex } from '@/routes/rh/documentos-maestros';
import { index as fondosIndex } from '@/routes/rh/documentos-maestros/fondos';
import type { EmpresaItem } from '@/types';

/**
 * Configuración: cinco tarjetas para CONFIGURAR (no un manual). Cada una
 * muestra solo lo que este usuario puede tocar; el detalle de reglas y
 * permisos vive en cada pantalla o en la Guía.
 */
const props = defineProps<{
    empresa: EmpresaItem | null;
    variasEmpresas: boolean;
    puede: {
        empresas: boolean;
        documentos: boolean;
        notificaciones: boolean;
        rh: boolean;
        apariencia: boolean;
        usuarios: boolean;
        roles: boolean;
    };
    notificaciones: number;
    funciones: { clave: string; etiqueta: string; activa: boolean }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Configuración', href: '' },
        ],
    },
});

const empresaAbierta = ref(false);

const datosEmpresa = computed(() => {
    const e = props.empresa;

    if (!e) {
        return [];
    }

    return [
        ['Razón social', e.razon_social],
        ['RFC', e.rfc],
        ['Registro patronal', e.registro_patronal],
        ['Representante legal', e.representante_legal_nombre],
    ].map(([etiqueta, valor]) => ({ etiqueta: etiqueta as string, valor: (valor as string | null | undefined) || null }));
});

const faltanDatosEmpresa = computed(() => datosEmpresa.value.some((d) => d.valor === null));

const sistema = computed(() =>
    [
        props.puede.rh ? { titulo: 'Parámetros de RH', detalle: 'Periodo de prueba, motivos de rechazo, vigencias', href: parametrosRh.url() } : null,
        props.puede.usuarios ? { titulo: 'Usuarios', detalle: 'Cuentas y accesos', href: usuariosIndex.url() } : null,
        props.puede.roles ? { titulo: 'Roles y permisos', detalle: 'Qué puede hacer cada rol', href: rolesIndex.url() } : null,
        props.puede.apariencia ? { titulo: 'Apariencia', detalle: 'Colores institucionales', href: apariencia.url() } : null,
        props.puede.usuarios ? { titulo: 'Versiones de la app', detalle: 'APK publicada y versión mínima', href: releasesIndex.url() } : null,
    ].filter((x) => x !== null),
);
</script>

<template>
    <Head title="Configuración" />

    <div class="pagina-ancha flex flex-col gap-5">
        <header class="flex items-center gap-3">
            <span class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"><Settings2 class="size-5" /></span>
            <div>
                <h1 class="text-xl font-semibold">Configuración</h1>
                <p class="text-sm text-muted-foreground">
                    ¿Dudas? Todo se explica en la
                    <Link :href="ayuda.url()" class="text-primary hover:underline">Guía</Link>.
                </p>
            </div>
        </header>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Empresa -->
            <section v-if="puede.empresas" class="flex flex-col gap-4 rounded-2xl border bg-card p-5">
                <div class="flex items-start gap-3">
                    <Building2 class="mt-0.5 size-5 text-primary" />
                    <div class="mr-auto">
                        <h2 class="font-semibold">Empresa</h2>
                        <p class="text-sm text-muted-foreground">Datos que salen en contratos y formatos oficiales.</p>
                    </div>
                    <Button v-if="empresa" size="sm" @click="empresaAbierta = true">Editar</Button>
                    <Button v-else size="sm" variant="outline" as-child>
                        <Link :href="empresasIndex.url()">{{ variasEmpresas ? 'Ver empresas' : 'Abrir' }}</Link>
                    </Button>
                </div>
                <dl v-if="empresa" class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div v-for="d in datosEmpresa" :key="d.etiqueta" class="min-w-0">
                        <dt class="text-xs text-muted-foreground">{{ d.etiqueta }}</dt>
                        <dd class="truncate" :class="!d.valor && 'text-warning'">{{ d.valor ?? 'Falta capturar' }}</dd>
                    </div>
                </dl>
                <p v-if="empresa && faltanDatosEmpresa" class="text-xs text-warning">Completa los datos marcados para que los documentos salgan correctos.</p>
            </section>

            <!-- Documentos -->
            <section v-if="puede.documentos" class="flex flex-col gap-4 rounded-2xl border bg-card p-5">
                <div class="flex items-start gap-3">
                    <FileStack class="mt-0.5 size-5 text-primary" />
                    <div class="mr-auto">
                        <h2 class="font-semibold">Documentos</h2>
                        <p class="text-sm text-muted-foreground">Formatos oficiales que PEOPLE llena solo.</p>
                    </div>
                    <Button size="sm" as-child><Link :href="maestrosIndex.url()">Abrir</Link></Button>
                </div>
                <ul class="flex flex-col divide-y rounded-xl border text-sm">
                    <li>
                        <Link :href="maestrosIndex.url()" class="flex items-center justify-between px-3 py-2.5 hover:bg-muted/40">
                            Documentos maestros <ChevronRight class="size-4 text-muted-foreground" />
                        </Link>
                    </li>
                    <li>
                        <Link :href="fondosIndex.url()" class="flex items-center justify-between px-3 py-2.5 hover:bg-muted/40">
                            Fondos y recursos <ChevronRight class="size-4 text-muted-foreground" />
                        </Link>
                    </li>
                </ul>
            </section>

            <!-- Notificaciones -->
            <section v-if="puede.notificaciones" class="flex flex-col gap-4 rounded-2xl border bg-card p-5">
                <div class="flex items-start gap-3">
                    <BellRing class="mt-0.5 size-5 text-primary" />
                    <div class="mr-auto">
                        <h2 class="font-semibold">Notificaciones</h2>
                        <p class="text-sm text-muted-foreground">Quién recibe cada aviso del proceso ({{ notificaciones }} eventos).</p>
                    </div>
                    <Button size="sm" as-child><Link :href="notificacionesRuta.url()">Configurar</Link></Button>
                </div>
            </section>

            <!-- Funciones -->
            <section class="flex flex-col gap-4 rounded-2xl border bg-card p-5">
                <div class="flex items-start gap-3">
                    <ToggleRight class="mt-0.5 size-5 text-primary" />
                    <div>
                        <h2 class="font-semibold">Funciones</h2>
                        <p class="text-sm text-muted-foreground">Qué está encendido. Se cambia en el servidor.</p>
                    </div>
                </div>
                <ul class="flex flex-col gap-2 text-sm">
                    <li v-for="f in funciones" :key="f.clave" class="flex items-center justify-between gap-3">
                        <span>{{ f.etiqueta }}</span>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="f.activa ? 'bg-success-soft text-success' : 'bg-muted text-muted-foreground'"
                            >{{ f.activa ? 'Encendida' : 'Apagada' }}</span
                        >
                    </li>
                </ul>
            </section>

            <!-- Sistema -->
            <section v-if="sistema.length" class="flex flex-col gap-4 rounded-2xl border bg-card p-5 lg:col-span-2">
                <div class="flex items-start gap-3">
                    <Settings2 class="mt-0.5 size-5 text-primary" />
                    <div>
                        <h2 class="font-semibold">Sistema</h2>
                        <p class="text-sm text-muted-foreground">Reglas de RH, cuentas y accesos.</p>
                    </div>
                </div>
                <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    <li v-for="s in sistema" :key="s.titulo">
                        <Link :href="s.href" class="tarjeta-interactiva flex items-center gap-3 rounded-xl border p-3">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{ s.titulo }}</span>
                                <span class="block truncate text-xs text-muted-foreground">{{ s.detalle }}</span>
                            </span>
                            <ChevronRight class="size-4 shrink-0 text-muted-foreground" />
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </div>

    <EmpresaFormDialog v-if="empresa" v-model:open="empresaAbierta" :empresa="empresa" />
</template>
