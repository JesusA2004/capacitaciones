# Material de apoyo del centro de Ayuda

Videos e infografías oficiales (manual de operaciones, videos, flujograma de despliegue, infografía de gerentes, infografía de gestor) en **/ayuda/material**, con acceso desde la portada de la Guía (`/ayuda`) y desde el botón flotante de ayuda.

- Originales: `docs/ayuda/*` (no se mueven ni se renombran).
- Catálogo (título, descripción, tipo, tema, audiencia, archivo, orden): `config/ayuda_material.php`.
- Lógica: `App\Services\Ayuda\MaterialAyudaService` (la usan el comando y `AyudaController`).
- Publicados en el disco privado `local`: `storage/app/private/ayuda/` — solo se sirven con sesión iniciada (`/ayuda/material/archivo/{slug}`, con rangos para el video; PDF en línea o `?descargar=1`).

## Publicar en el servidor (después de `git pull`)

```bash
php artisan ayuda:publicar-material          # copia lo nuevo o cambiado; lo igual queda «Sin cambios»
php artisan ayuda:publicar-material --forzar # reemplaza todo
```

Muestra una tabla con cada material y su estado. Si falta un archivo en `docs/ayuda`, lo dice y termina con error. Mientras no se publique, la pantalla muestra un aviso para correr el comando.

Para agregar material: deja el archivo en `docs/ayuda`, agrégalo a `config/ayuda_material.php` y vuelve a correr el comando.
