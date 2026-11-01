<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import FotoPerfilCaptura from '@/components/Common/FotoPerfilCaptura.vue';
import CartaCompromisoTarjeta from '@/components/Rh/Expediente/CartaCompromisoTarjeta.vue';
import ExpedienteDocumentos from '@/components/Rh/ExpedienteDocumentos.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { foto as subirFotoPropia } from '@/routes/portal';
import { show as divisionHistorico } from '@/routes/rh/expedientes/division-historico';
import { store as subirFotoColaborador } from '@/routes/rh/expedientes/foto';
import type { DocumentoExpedienteItem, ExpedienteColaborador } from '@/types';

const props = defineProps<{
    colaborador: ExpedienteColaborador;
    documentosRequeridos: DocumentoExpedienteItem[];
    esPropio: boolean;
    /** PDF(s) históricos únicos (migración inicial): fuera del checklist. */
    expedienteHistorico?: {
        id: number;
        nombre: string;
        original: string;
        size: number | null;
        migrado_en: string | null;
        url: string;
        descargar_url: string;
    }[];
    puedeAplicarExtraccion: boolean;
    puedeEditar: boolean;
    puedeIgnorarExtraccion: boolean;
    puedeReprocesarExtraccion: boolean;
    puedeRevisarDocumentos: boolean;
    puedeVerExtraccion: boolean;
}>();

const urlSubidaFoto = computed(() =>
    props.esPropio
        ? subirFotoPropia.url()
        : subirFotoColaborador.url(props.colaborador.id),
);
</script>

<template>
    <!-- Expediente histórico: el PDF único anterior al sistema.
                         NO es parte del checklist ni cuenta en el avance. -->
    <Card
        v-if="expedienteHistorico && expedienteHistorico.length"
        class="rounded-2xl border-border/60"
    >
        <CardContent class="flex flex-col gap-3 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold">
                        Expediente histórico
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        Documentación anterior al sistema en un solo archivo. Se
                        conserva íntegra; al dividirlo, sus copias por documento
                        entran al checklist en revisión.
                    </p>
                </div>
                <Button
                    v-if="puedeVerExtraccion"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="divisionHistorico(colaborador.id)"
                        >Dividir por checklist y revisar datos</Link
                    >
                </Button>
            </div>
            <div
                v-for="h in expedienteHistorico"
                :key="h.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border/60 p-3"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">
                        {{ h.nombre }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Original: {{ h.original
                        }}{{
                            h.size
                                ? ` · ${(h.size / 1048576).toFixed(1)} MB`
                                : ''
                        }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" as-child>
                        <a :href="h.url" target="_blank" rel="noopener">Ver</a>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <a :href="h.descargar_url">Descargar</a>
                    </Button>
                </div>
            </div>
        </CardContent>
    </Card>
    <!-- Sin histórico: la página puede ofrecer «Ligar expediente de Synology». -->
    <slot v-else name="sin-expediente-historico" />
    <!-- La foto se registra junto con los documentos: es lo
                         que identifica al colaborador en todos los módulos. -->
    <FotoPerfilCaptura
        :nombre="`${colaborador.name} ${colaborador.apellidos ?? ''}`"
        :foto-url="colaborador.foto_url"
        :url-subida="urlSubidaFoto"
        :puede-editar="esPropio || puedeEditar"
        :estado="esPropio ? colaborador.foto_estado : null"
    />
    <!-- Carta compromiso de documentos pendientes (si la tiene). -->
    <CartaCompromisoTarjeta />
    <ExpedienteDocumentos
        :colaborador-id="colaborador.id"
        :documentos="documentosRequeridos"
        :puede-subir="esPropio || puedeEditar"
        :puede-revisar="puedeRevisarDocumentos"
        :puede-ver-extraccion="puedeVerExtraccion"
        :puede-aplicar-extraccion="puedeAplicarExtraccion"
        :puede-reprocesar-extraccion="puedeReprocesarExtraccion"
        :puede-ignorar-extraccion="puedeIgnorarExtraccion"
    />
    <!-- Documentos generados desde plantillas oficiales (solo vista RH). -->
    <slot name="documentos-oficiales" />
</template>
