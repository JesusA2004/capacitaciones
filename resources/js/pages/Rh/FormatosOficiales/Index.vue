<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Archive, FileStack, Plus, Search, Settings2, Sparkles } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import FormatoOficialGenerarDialog from '@/components/Rh/FormatoOficialGenerarDialog.vue';
import FormatosTabsNav from '@/components/Rh/FormatosTabsNav.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { dashboard } from '@/routes';
import { create, index as indexOficiales, show as showEditor } from '@/routes/rh/formatos-oficiales';
import type { FormatoOficialItem, OpcionCatalogo, PersonaDisponible } from '@/types';

const props = defineProps<{
    formatos: FormatoOficialItem[];
    filtros: { tipo: string | null; busqueda: string | null; archivados: boolean };
    categorias: OpcionCatalogo[];
    colaboradoresDisponibles: PersonaDisponible[];
    candidatosDisponibles: PersonaDisponible[];
    permisos: {
        generar: boolean;
        descargar: boolean;
        crear: boolean;
        configurar: boolean;
        versionar: boolean;
        archivar: boolean;
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

const busqueda = ref(props.filtros.busqueda ?? '');
const tipo = ref(props.filtros.tipo ?? '');
const archivados = ref(props.filtros.archivados);
let temporizador: ReturnType<typeof setTimeout> | undefined;

watch([busqueda, tipo, archivados], () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            indexOficiales.url(),
            { busqueda: busqueda.value || undefined, tipo: tipo.value || undefined, archivados: archivados.value ? 1 : undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

// Agrupado por categoría (Contratos, Permisos, Constancias…).
const grupos = computed(() => {
    const mapa = new Map<string, { etiqueta: string; formatos: FormatoOficialItem[] }>();

    for (const formato of props.formatos) {
        const grupo = mapa.get(formato.tipo) ?? { etiqueta: formato.tipo_etiqueta, formatos: [] };
        grupo.formatos.push(formato);
        mapa.set(formato.tipo, grupo);
    }

    return [...mapa.values()].sort((a, b) => a.etiqueta.localeCompare(b.etiqueta, 'es'));
});

const formatoSeleccionado = ref<FormatoOficialItem | null>(null);
const dialogoAbierto = ref(false);

function abrirGenerar(formato: FormatoOficialItem) {
    formatoSeleccionado.value = formato;
    dialogoAbierto.value = true;
}

function fecha(valor: string | null): string {
    return valor
        ? new Date(valor).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' })
        : 'Nunca generado';
}

const etiquetaArchivo: Record<string, string> = { pdf: 'PDF', docx: 'Word', imagen: 'Imagen' };
</script>

<template>
    <Head title="Formatos" />

    <div class="flex w-full min-w-0 flex-col gap-4 p-4 sm:p-6">
        <CrudPageHeader titulo="Formatos" :icono="FileStack">
            <Button v-if="permisos.crear" as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    Nueva plantilla
                </Link>
            </Button>
        </CrudPageHeader>

        <FormatosTabsNav activa="oficiales" />

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative w-full sm:max-w-xs">
                <Search class="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                <Input v-model="busqueda" placeholder="Buscar formato…" class="pl-8" />
            </div>
            <NativeSelect v-model="tipo" class="w-full sm:w-56">
                <option value="">Todas las categorías</option>
                <option v-for="c in categorias" :key="c.value" :value="c.value">{{ c.etiqueta }}</option>
            </NativeSelect>
            <Button v-if="permisos.archivar" variant="ghost" size="sm" :class="archivados && 'bg-muted'" @click="archivados = !archivados">
                <Archive class="size-4" />
                {{ archivados ? 'Viendo archivados' : 'Archivados' }}
            </Button>
        </div>

        <CrudEmptyState
            v-if="formatos.length === 0"
            :icono="FileStack"
            :titulo="archivados ? 'No hay formatos archivados' : 'Aún no hay plantillas oficiales'"
            :descripcion="permisos.crear && !archivados ? 'Sube el primer formato con «Nueva plantilla»: PDF, Word o imagen.' : undefined"
        />

        <section v-for="grupo in grupos" :key="grupo.etiqueta" data-tour="formatos-catalogo" class="flex flex-col gap-2">
            <h2 class="text-sm font-semibold text-muted-foreground">{{ grupo.etiqueta }} · {{ grupo.formatos.length }}</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                <article
                    v-for="formato in grupo.formatos"
                    :key="formato.id"
                    class="flex flex-col justify-between gap-3 rounded-2xl border bg-card p-4 transition-shadow hover:shadow-md"
                >
                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="leading-snug font-medium">{{ formato.nombre }}</h3>
                            <Badge v-if="formato.lista" class="shrink-0">Lista</Badge>
                            <Badge v-else variant="outline" class="shrink-0 border-amber-500/40 text-amber-700 dark:text-amber-400">Por configurar</Badge>
                        </div>
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                            <span>{{ etiquetaArchivo[formato.file_type] ?? formato.file_type }}</span>
                            <span v-if="formato.version_vigente">· v{{ formato.version_vigente.numero }} vigente ({{ formato.version_vigente.campos }} campos)</span>
                            <span v-if="formato.borrador" class="text-amber-700 dark:text-amber-400">· borrador v{{ formato.borrador }}</span>
                            <span v-if="formato.empresa">· {{ formato.empresa }}</span>
                            <span v-if="formato.aplica_a === 'candidato'">· Candidatos</span>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ fecha(formato.ultimo_generado_at) }}<template v-if="formato.veces_generado > 0"> · {{ formato.veces_generado }} generado(s)</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="permisos.generar && formato.lista && !formato.archivado" size="sm" @click="abrirGenerar(formato)">
                            <Sparkles class="size-4" />
                            Generar
                        </Button>
                        <Button v-if="permisos.configurar" as-child size="sm" variant="outline">
                            <Link :href="showEditor.url(formato.id)">
                                <Settings2 class="size-4" />
                                {{ formato.lista ? 'Plantilla' : 'Configurar' }}
                            </Link>
                        </Button>
                    </div>
                </article>
            </div>
        </section>
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
