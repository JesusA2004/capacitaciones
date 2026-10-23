<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type Props = {
    breadcrumbs: BreadcrumbItemType[];
};

defineProps<Props>();
</script>

<template>
    <!-- Siempre UN renglón: nunca se parte en dos ni desborda la barra.
         Es una rejilla de columnas minmax(0, max-content): cuando falta
         espacio, el que hay se reparte por partes iguales, así un nivel
         corto («Diseño», «Inicio») se ve completo y solo los largos se
         recortan con «…» (texto completo en el tooltip). -->
    <Breadcrumb class="min-w-0">
        <BreadcrumbList
            class="-m-1 grid min-w-0 auto-cols-[minmax(0,max-content)] grid-flow-col items-center justify-start overflow-hidden p-1"
        >
            <template v-for="(item, index) in breadcrumbs" :key="index">
                <!-- En móvil solo el nivel anterior y el actual: tres o más
                     niveles partían la barra en dos renglones. -->
                <BreadcrumbItem
                    :class="[
                        'min-w-0 whitespace-nowrap',
                        index < breadcrumbs.length - 2 ? 'max-sm:hidden' : '',
                    ]"
                >
                    <template v-if="index === breadcrumbs.length - 1">
                        <BreadcrumbPage
                            data-recortable
                            class="block truncate"
                            :title="item.title"
                            >{{ item.title }}</BreadcrumbPage
                        >
                    </template>
                    <template v-else>
                        <BreadcrumbLink as-child>
                            <Link
                                :href="item.href"
                                data-recortable
                                class="block truncate"
                                :title="item.title"
                                >{{ item.title }}</Link
                            >
                        </BreadcrumbLink>
                    </template>
                </BreadcrumbItem>
                <BreadcrumbSeparator
                    v-if="index !== breadcrumbs.length - 1"
                    :class="
                        index < breadcrumbs.length - 2 ? 'max-sm:hidden' : ''
                    "
                />
            </template>
        </BreadcrumbList>
    </Breadcrumb>
</template>
