import { defineAsyncComponent } from 'vue';

/**
 * ApexCharts (~1.4 MB sin comprimir entre apexcharts y vue3-apexcharts) se
 * descarga en su propio chunk y solo cuando una pantalla realmente pinta
 * una gráfica: el dashboard y Reportes muestran sus tarjetas y tablas de
 * inmediato y las gráficas aparecen al terminar de cargar la librería. Las
 * pantallas sin gráficas (Empresas, Usuarios…) nunca la descargan.
 */
export const VueApexCharts = defineAsyncComponent(
    () => import('vue3-apexcharts'),
);
