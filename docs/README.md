# Documentación de SisMark

Control de asistencia del Órgano Ejecutivo del Gobierno Autónomo Departamental
del Beni, sobre equipos biométricos ZKTeco.

## Por dónde empezar

**Si es tu primera vez acá, leé [ESTRUCTURA.md](ESTRUCTURA.md).** Es el
documento único de referencia: qué hace el sistema, cómo lo hace y dónde está
cada cosa en el código.

| Documento | Cuándo se lee |
|---|---|
| **[ESTRUCTURA.md](ESTRUCTURA.md)** | Panorama completo: módulos, motor de asistencia, reportes, API, sincronización, permisos y árbol del código |
| [REPORTE-PROCESADO-ASISTENCIA.md](REPORTE-PROCESADO-ASISTENCIA.md) | Las reglas del motor en detalle: por qué un día salió «Falta», «Atraso» o «Abandono» |
| [COMUNICACION-BIOMETRICOS.md](COMUNICACION-BIOMETRICOS.md) | Protocolo ZKTeco y el microservicio Python que lo habla |
| [MIGRACION-SIA-MYSQL.md](MIGRACION-SIA-MYSQL.md) | Traer el histórico del SIA (SQL Server) a MySQL, tabla por tabla |
| [CONEXION-BD.md](CONEXION-BD.md) | Conexiones y credenciales: MySQL local y SQL Server del SIA |
| [../README.md](../README.md) | Requisitos, instalación y despliegue |

---

## Bitácora de sesiones de trabajo

Cada día de trabajo se registra en un archivo dentro de la carpeta del mes:
`docs/sesiones/MM-AAAA/AAAA-MM-DD.md` (por ejemplo `docs/sesiones/07-2026/2026-07-10.md`).

### Cómo registrar una sesión

1. Copiar `docs/sesiones/_plantilla.md` a la carpeta del mes (`docs/sesiones/MM-AAAA/`).
2. Renombrarlo a la fecha del día (`AAAA-MM-DD.md`).
3. Completar cada bloque `Trabajo N` (commit, problema, archivos, solución) y el
   informe de presentación. Borrar los bloques que no apliquen.

### Convención

- Un archivo por día, agrupado por mes en `MM-AAAA/`.
- Un bloque `## Trabajo N` por tarea, en orden cronológico.
- Enlazar el commit relevante en cada bloque cuando exista.
