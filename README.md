# Newton Conducta

Plugin de WordPress para **Newton Centro de Estudios** que centraliza la gestión académica: evaluación de conducta, control de asistencia y administración de exámenes.

## Características

- **Conducta** — Registro y seguimiento de evaluaciones de conducta por alumno, con facultades, carreras, cursos, grupos y subgrupos.
- **Asistencia** — Gestión de materias, sesiones de clase, docentes asignados y marcado de presentes/ausentes.
- **Exámenes** — Carga e importación de exámenes (incluye soporte para archivos PDF y hojas de cálculo).
- **Roles y permisos** — Acceso restringido por rol de WordPress (administradores, docentes, funcionarios de oficina).
- **API REST** — Backend bajo el namespace `conducta/v1` con autenticación vía nonce de WordPress.
- **Interfaz SPA** — Frontend en JavaScript vanilla con persistencia de estado en `sessionStorage`.

## Requisitos

- WordPress 5.8+
- PHP 7.4 o superior
- [Composer](https://getcomposer.org/) (para instalar dependencias PHP)
- MySQL / MariaDB (tablas creadas automáticamente al activar el plugin)

## Instalación

1. Clona el repositorio en la carpeta de plugins de WordPress:

   ```bash
   git clone https://github.com/DavidVillarM/newton-conducta.git wp-content/plugins/newton-conducta
   ```

2. Instala las dependencias PHP:

   ```bash
   cd wp-content/plugins/newton-conducta
   composer install --no-dev
   ```

3. Activa el plugin desde **Plugins → Plugins instalados** en el panel de WordPress.

4. Crea una página con el slug `conducta` u `opm` e inserta el shortcode:

   ```
   [newton_conducta_app]
   ```

5. Asigna los roles de WordPress necesarios a los usuarios que deban acceder al sistema.

## Estructura del proyecto

```
newton-conducta/
├── newton-conducta.php      # Punto de entrada del plugin
├── includes/                # Clases PHP (REST, base de datos, roles, importación)
├── assets/dist/             # Frontend compilado (JS y CSS)
├── composer.json            # Dependencias PHP
└── vendor/                  # Dependencias instaladas por Composer (no versionadas)
```

## Dependencias principales

| Paquete | Uso |
|---------|-----|
| `phpoffice/phpspreadsheet` | Importación y exportación de hojas de cálculo |
| `tecnickcom/tcpdf` | Generación de documentos PDF |
| `phpoffice/phpword` | Procesamiento de documentos Word |
| `smalot/pdfparser` | Lectura y análisis de archivos PDF |

## API REST

La API está disponible en:

```
/wp-json/conducta/v1/
```

Algunos endpoints principales:

| Módulo | Ejemplos de rutas |
|--------|-------------------|
| Conducta | `/facultades`, `/carreras`, `/alumnos`, `/evaluaciones` |
| Asistencia | `/asistencia/materias`, `/asistencia/sesiones` |
| Exámenes | `/examenes/...` |

Todas las rutas requieren usuario autenticado con permisos válidos.

## Desarrollo

El frontend vive en `assets/dist/`. Si modificas los archivos fuente, recompílalos y actualiza los bundles en esa carpeta antes de desplegar.

Las migraciones de base de datos se ejecutan automáticamente al cargar el plugin cuando cambia la versión del esquema.

## Autor

**David Villar** — [GitHub](https://github.com/DavidVillarM)

Desarrollado para Newton Centro de Estudios.

## Licencia

Software propietario. Todos los derechos reservados.
