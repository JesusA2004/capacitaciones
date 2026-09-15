<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileStack, Settings2, Sparkles } from '@lucide/vue';
import { ref } from 'vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import FormatoOficialGenerarDialog from '@/components/Rh/FormatoOficialGenerarDialog.vue';
import FormatosTabsNav from '@/components/Rh/FormatosTabsNav.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { dashboard } from '@/routes';
import { show as showConfigurador } from '@/routes/rh/formatos-oficiales';
import type { FormatoOficialItem, PersonaDisponible } from '@/types';

defineProps<{
    formatos: FormatoOficialItem[];
    colaboradoresDisponibles: PersonaDisponible[];
    candidatosDisponibles: PersonaDisponible[];
    permisos: {
        generar: boolean;
        descargar: boolean;
        configurar: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Formatos', href: '' },
        ],
    },
});

const formatoSeleccionado = ref<FormatoOficialItem | null>(null);
const dialogoAbierto = ref(false);

function abrirGenerar(formato: FormatoOficialItem) {
    formatoSeleccionado.value = formato;
    dialogoAbierto.value = true;
}

function formatearFecha(fecha: string | null): string {
    if (!fecha) {
        return 'Nunca generado';
    }

    return new Date(fecha).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <Head title="Formatos" />

    <div class="flex w-full min-w-0 flex-col gap-6 p-4 sm:p-6">
        <CrudPageHeader
            titulo="Formatos"
            descripcion="Genera documentos de MR. LANA precargados con los datos del colaborador."
            :icono="FileStack"
        />

        <FormatosTabsNav activa="oficiales" />

        <CrudEmptyState
            v-if="formatos.length === 0"
            titulo="No hay formatos oficiales importados"
            descripcion="Sube los PDFs oficiales y ejecuta «php artisan formatos:importar-originales»."
            :icono="FileStack"
        />

        <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Card
                v-for="formato in formatos"
                :key="formato.id"
                class="flex flex-col justify-between transition-shadow hover:shadow-md"
            >
                <CardHeader class="gap-2">
                    <div class="flex items-start justify-between gap-2">
                        <CardTitle class="text-base leading-snug">
                            {{ formato.nombre }}
                        </CardTitle>
                        <Badge
                            :variant="formato.lista ? 'default' : 'outline'"
                            :class="
                                !formato.lista &&
                                'shrink-0 border-amber-500/40 text-amber-600 dark:text-amber-400'
                            "
                        >
                            {{ formato.lista ? 'Listo' : 'Falta configurar' }}
                        </Badge>
                    </div>
                    <CardDescription class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                        <span>{{ formato.tipo_etiqueta }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ formatearFecha(formato.ultimo_generado_at) }}</span>
                        <template v-if="formato.veces_generado > 0">
                            <span aria-hidden="true">·</span>
                            <span>{{ formato.veces_generado }} generado(s)</span>
                        </template>
                    </CardDescription>
                </CardHeader>
                <CardFooter class="flex flex-wrap gap-2">
                    <Tooltip v-if="permisos.generar && !formato.lista">
                        <TooltipTrigger as-child>
                            <span class="inline-flex">
                                <Button size="sm" disabled>
                                    <Sparkles class="size-4" />
                                    Generar
                                </Button>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>
                            Este formato necesita configurar dónde se colocarán los datos.
                        </TooltipContent>
                    </Tooltip>
                    <Button
                        v-else-if="permisos.generar"
                        size="sm"
                        @click="abrirGenerar(formato)"
                    >
                        <Sparkles class="size-4" />
                        Generar
                    </Button>

                    <Button
                        v-if="permisos.configurar"
                        as-child
                        size="sm"
                        variant="outline"
                    >
                        <Link :href="showConfigurador.url(formato.id)">
                            <Settings2 class="size-4" />
                            Configurar campos
                        </Link>
                    </Button>
                </CardFooter>
            </Card>
        </div>
    </div>

    <FormatoOficialGenerarDialog
        v-if="formatoSeleccionado"
        v-model:open="dialogoAbierto"
        :formato="formatoSeleccionado"
        :colaboradores-disponibles="colaboradoresDisponibles"
        :candidatos-disponibles="candidatosDisponibles"
        :puede-descargar="permisos.descargar"
    />
</template>
