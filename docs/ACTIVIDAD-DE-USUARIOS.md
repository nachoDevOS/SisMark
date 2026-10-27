# Actividad de usuarios

> **Estado: FASE 1 IMPLEMENTADA (26/10/2026) — Fase 2 pendiente.**
> Para que funcione hay que correr la migración y el seeder de permisos (sección 13).
> Las preguntas de la sección 12 se tomaron con los valores propuestos; se pueden ajustar.

Registro de **quién entró al sistema, qué miró, qué buscó, qué reportes sacó y qué cambió**,
para tener el sistema auditado de punta a punta.

**Se registra TODO** (decisión del usuario, 26/10/2026): **cada pedido** que llega a Laravel
—con o sin sesión, pantallas, AJAX, API— queda en la tabla, **menos contraseñas y tokens**.
Los pedidos que son un **evento importante** (entró, miró una ficha, buscó, bajó un Excel,
aprobó…) llevan además una **frase legible** y quedan marcados como importantes, para que la
pantalla se lea como una historia y no como una lista técnica.

**No es la «Bitácora» de Biométricos.** Esa (`equipo_auditorias`, botón «Bitácora» en
Biométricos) registra solo lo que se hace con los relojes, y se queda como está. Para no
confundirlas, esta se llama **«Actividad de usuarios»** y no usa las palabras «auditoría»
ni «bitácora».

---

## 1. Qué problema resuelve

Hoy SisMark guarda **los cambios**, pero no **los accesos ni las consultas**:

| Ya existe | Qué registra | Qué no |
|---|---|---|
| `registerUser_id` / `deleteUser_id` / `deleteObservacion` (trait `RegistersUserEvents`) | quién creó o dio de baja cada fila, y por qué la borró | ediciones, consultas |
| `user_historials` (Usuarios → Historial) | alta, edición, baja, inhabilitación y cambio de contraseña de usuarios | todo lo demás |
| `equipo_auditorias` (Biométricos → Bitácora) | acciones sobre los relojes | todo lo demás |
| `sistema_externo_auditorias` | emisión y revocación de tokens de la API | todo lo demás |
| `sessions` (`SESSION_DRIVER=database`) | las sesiones **abiertas ahora** | la historia: se borran al vencer |

Preguntas que hoy **no** se pueden responder y con esto sí:

- ¿Quién entró ayer y desde qué computadora?
- ¿Alguien intentó entrar con una contraseña equivocada? ¿Con qué correo?
- ¿Quién miró la ficha de la CI 7633685 este mes?
- ¿Quién bajó en Excel el reporte de la dirección SDAF?
- ¿Qué buscó tal persona en Funcionarios?
- ¿Quién aprobó la licencia del 27/10 y desde dónde?

---

## 2. Decisiones

| Tema | Decisión | Por qué |
|---|---|---|
| Dónde se guarda | **Base de datos**, tabla propia `actividades` | Se filtra, se cruza con usuarios y funcionarios, tiene pantalla y entra en los respaldos. Un archivo de log no se puede consultar así y rota. |
| Qué se guarda | **Todo pedido** a Laravel, menos contraseñas y tokens; los importantes con frase legible y marca `importante` | Pedido del usuario: nada sin registrar. La marca separa la historia del detalle técnico. |
| Archivo de log | Sigue solo para **errores** (`storage/logs/laravel.log`), como hoy | No se mezcla con la actividad. |
| Nombre | **«Actividad de usuarios»** | No choca con la Bitácora de biométricos. |
| Paquete externo | **No** (nada de `spatie/laravel-activitylog` ni similares) | Esos solo ven cambios en modelos, no lo que se miró o buscó; y sumarían una dependencia. Se sigue el estilo de `UserHistorial`. |
| Cuánto se guarda | **Lo importante: 5 años. El resto: 1 año.** Borrado automático | Lo importante cubre una gestión completa y las revisiones de control; el detalle técnico es ~10 veces más filas y pasado un año ya no se consulta. *Confirmar plazos con Jurídica.* |
| Quién la ve | **Administrador** + cualquier rol con `ViewAny:Actividad` (p. ej. Jefatura de RRHH). Cada usuario ve **sus propios ingresos** | Control para quien corresponde; y seguridad: quien ve un ingreso que no hizo, avisa. |
| Se puede editar o borrar | **No**, por nadie, ni el Administrador | Un registro que se puede tocar no prueba nada. |

---

## 3. Qué se registra

Cada fila es **un evento**, escrito para que lo entienda cualquiera:

```
26/10/2026 15:42  Ignacio Molina   Miró la ficha de MEGAL AGUILERA ROSSENDY (CI 10799999)    192.168.1.20
26/10/2026 15:40  Ignacio Molina   Buscó «10799999» en Funcionarios                          192.168.1.20
26/10/2026 15:31  Ignacio Molina   Bajó en Excel el reporte por dirección SDAF (01/10–26/10)  192.168.1.20
26/10/2026 08:02  ana.rojas@…      Intento de ingreso fallido: contraseña incorrecta          10.0.4.7
```

### 3.1 Catálogo de acciones

| Tipo | `accion` | Cuándo | Ejemplo de descripción |
|---|---|---|---|
| 🔐 Acceso | `ingreso` | inició sesión | «Entró al sistema» |
| | `ingreso_fallido` | credenciales incorrectas | «Intento de ingreso fallido con ana.rojas@beni.gob.bo» |
| | `ingreso_rechazado` | contraseña bien, cuenta inhabilitada | «Intentó entrar con la cuenta inhabilitada» |
| | `bloqueo` | superó el límite de intentos (ver 8.4) | «Bloqueado 15 min por 5 intentos fallidos» |
| | `salida` | cerró sesión | «Cerró sesión» |
| | `cambio_password` | cambió su contraseña | «Cambió su contraseña» |
| 👁 Consulta | `ver` | abrió una ficha o un detalle | «Miró la ficha de … (CI …)», «Abrió la licencia del 27/10 de CI …» |
| 🔎 Búsqueda | `buscar` | escribió en un buscador | «Buscó "7633685" en Funcionarios» |
| 📊 Reporte | `reporte` | generó un reporte en pantalla | «Generó el reporte procesado de CI 7633685, 01/10 al 26/10» |
| | `imprimir` | abrió la versión para imprimir | «Imprimió el reporte por dirección SDAF» |
| | `exportar` | bajó un Excel o CSV | «Bajó en Excel los funcionarios sin turno» |
| ✏️ Cambio | `crear` / `editar` / `eliminar` | alta, edición o baja | «Asignó el turno "Horario Continuo 08:00 - 16:00" a CI …» |
| | `aprobar` / `rechazar` | resolvió una licencia | «Aprobó la licencia del 27/10 de CI 10799999» |
| | `concluir` | concluyó un turno u horario | «Concluyó el turno … de CI … el 31/10» |
| | `sin_cambios` | un aviso de cambio que no cambió nada | «Avisó un cambio del contrato 16837 de CI …: el turno ya tenía esas fechas, sin cambios» |
| 🎬 Ayuda | `ver_tutorial` | abrió un video de la Ayuda | «Miró el tutorial "Cambiar de turno"» |

### 3.2 Todo lo demás: el detalle (`importante = false`)

Cualquier otro pedido —cargas de tablas por AJAX, cambios de página, filtros, combos que
buscan en Mamoré, pedidos de la API v1 de otros sistemas, videos de la Ayuda por rangos—
también se guarda, sin frase: con su ruta, método, parámetros, resultado y tiempo. Se ve con
«Ver todo» en la pantalla.

Las **consultas de la API v1** son detalle pero **con frase** («Consultó la asistencia de CI …
del 01/10 al 27/10»): un sistema externo consulta mucho y no es una persona mirando, pero
así se lee qué pidió sin descifrar la URL.

### 3.3 Lo que escribe la API

Lo que un sistema externo **escribe** lo anota el controlador de la API, que es el que sabe
qué hizo SisMark con el pedido. Es importante, va con el sistema externo en «Quién» y con
módulo `API`. Solo se anota lo que salió bien; un 422 queda como el pedido, con su estado.

| Pedido | Acción | Frase |
|---|---|---|
| `POST /funcionarios/{ci}/turnos` | `crear` / `sin_cambios` | «Asignó el turno «X» a CI … del 20/10/2026 al 31/12/2026 (contrato 55)»; si ya lo tenía, «… ya lo tenía, sin cambios» |
| `PUT /funcionarios/{ci}/turnos` | `editar` / `sin_cambios` | «Cambió la vigencia del turno «X» de CI …: 20/10–31/12 → 20/10–31/03 (contrato 55)»; al acortar, qué turno terminó antes y cuál se dio de baja; con las mismas fechas, «… el turno ya tenía esas fechas, sin cambios» |
| `DELETE /funcionarios/{ci}/turnos` | `eliminar` / `sin_cambios` | «Dio de baja el turno «X» del … al … de CI …: contrato 55 anulado» |
| `POST /funcionarios/{ci}/licencias` | `crear` | «Pidió licencia para CI … el 21/10/2026, horario completo: «Cita médica». Queda pendiente (1 día)» |
| `DELETE /funcionarios/{ci}/licencias/{id}` | `eliminar` | «Canceló la solicitud de licencia #… de CI … del 21/10/2026 (1 día)» |

Desde SisMark no se sabe **por qué** cambió el contrato (edición, cambio de estado o adenda)
ni **quién** lo hizo en Mamoré: el pedido es el mismo. Para eso Mamoré tendría que mandarlo
(campos opcionales `motivo` y `usuario`); queda como mejora posible.

Si un pedido no tiene nombre de ruta (un 404 a una URL inventada), se guarda igual con la URL.

### 3.4 Lo único que **no** se registra

- **Contraseñas** (ni la equivocada de un intento fallido), **tokens**, el `_token` de CSRF y
  el encabezado `Authorization`.
- El **contenido** de los archivos subidos: solo su nombre, tipo y tamaño.
- Las imágenes, estilos y scripts de `public/`: los entrega el servidor web directo y no
  pasan por Laravel.

---

## 4. Modelo de datos

Tabla nueva **`actividades`**, en una **migración nueva** (el sistema ya está en
producción: no se re-migra ni se tocan migraciones anteriores).

| Columna | Tipo | Nulo | Para qué |
|---|---|---|---|
| `id` | bigint | no | |
| `user_id` | FK `users`, `nullOnDelete` | sí | quién. Nulo sin sesión (login, intento fallido) o en la API |
| `sistema_externo_id` | FK `sistema_externos`, `nullOnDelete` | sí | el sistema que llamó a la API v1 con su token (Mamoré, otros) |
| `importante` | boolean | no | `true` si es un evento con frase (sección 3.1); `false` si es detalle (3.2) |
| `email` | string(150) | sí | el correo escrito, en los intentos fallidos o rechazados |
| `accion` | string(30) | no | una del catálogo 3.1; en el detalle, `pedido` |
| `modulo` | string(40) | no | Acceso, Funcionarios, Licencias, Turnos, Reportes, Usuarios, Roles, Configuración, Biométricos, Ayuda, API |
| `descripcion` | string(500) | sí | la frase legible, ya armada (solo los importantes) |
| `ci` | string(20) | sí | el funcionario involucrado, si hay uno (para «quién consultó esta ficha») |
| `datos` | json | sí | los **parámetros del pedido** ya limpios (`{"q":"7633685","desde":"2026-10-01"}`), o antes/después de un cambio |
| `ruta` | string(120) | sí | nombre de la ruta (`funcionarios.mamore`) |
| `url` | string(500) | no | la ruta pedida con su query, sin dominio (`/funcionarios/ajax/list?q=…`) |
| `metodo` | string(10) | no | GET, POST, PATCH, DELETE |
| `estado` | smallint | no | código de respuesta: 200, 302, 403, 404, 419, 422, 500… |
| `duracion_ms` | int | no | cuánto tardó el pedido |
| `ip` | string(45) | sí | IPv4 o IPv6 (detrás del proxy: la del cliente, ver `trustProxies`) |
| `navegador` | string(255) | sí | `User-Agent` recortado |
| `created_at` | timestamp | no | cuándo. **Sin `updated_at`**: la fila no cambia nunca |

**Índices:** `(importante, created_at)`, `(user_id, created_at)`, `(ci, created_at)`,
`(accion, created_at)`, `(sistema_externo_id, created_at)`, `(created_at)`.

**Volumen estimado:** con decenas de usuarios de RRHH,
- importantes: ~10.000 por día ≈ 3 a 4 millones por año → a 5 años, menos de 20 millones;
- todo: ~10 veces más, unos 30 a 40 millones por año → con 1 año de retención del detalle,
  la tabla se mantiene en ~40–60 millones de filas.

Es bastante más que `asistencias` (4,4 millones): por eso la retención del detalle es de 1
año, la pantalla siempre filtra por fecha (índices de arriba) y, si crece más de lo previsto,
se puede **particionar por mes** (`PARTITION BY RANGE`) sin tocar el código.

**Modelo `App\Models\Actividad`:**
- `const UPDATED_AT = null`.
- Sin `SoftDeletes`.
- Los eventos `updating` y `deleting` **lanzan una excepción**: la fila no se edita ni se borra
  desde la aplicación. La única baja es la de retención (sección 8.3), con una consulta directa.
- `casts`: `datos => array`.
- Constantes de acción y `ETIQUETAS` para la pantalla, como `UserHistorial`.

---

## 5. Cómo se captura

Un único servicio **`App\Services\RegistroActividad`** con un método
`registrar(string $accion, string $modulo, string $descripcion, ?string $ci = null, array $datos = [])`
que completa solo usuario, IP, navegador, ruta y método. Nadie escribe en la tabla por su cuenta.

### 5.1 Accesos — escuchando los eventos de Laravel

| Evento (`Illuminate\Auth\Events`) | Acción |
|---|---|
| `Login` | `ingreso` |
| `Failed` | `ingreso_fallido` (con `email`; sin la contraseña) |
| `Logout` | `salida` |
| `Lockout` | `bloqueo` (lo dispara el límite de intentos, 8.4) |

`ingreso_rechazado` y `cambio_password` se anotan a mano en `LoginController::store()` y
`CambioPasswordController::update()`, donde ya se sabe el motivo.

### 5.2 Todo pedido — un middleware global

Middleware **`RegistrarActividad`** **global** (en `bootstrap/app.php`, para `web` y `api`):
registra **cada pedido** con su ruta, URL, método, parámetros limpios, estado y duración, el
usuario o el sistema externo, la IP y el navegador.

Además mira el **nombre de la ruta**: si está en el mapa de abajo, le suma la frase, la CI y
la marca `importante`. Lo que no está en el mapa queda igual, como detalle.

| Ruta | Acción | Descripción |
|---|---|---|
| `funcionarios.show`, `funcionarios.mamore` | `ver` | «Miró la ficha de {nombre} (CI {ci})» |
| `funcionarios.list` con `q` | `buscar` | «Buscó "{q}" en Funcionarios» |
| `licencias.show` | `ver` | «Abrió la licencia del {fecha} de CI {ci}» |
| `licencias.list` con `q` | `buscar` | «Buscó "{q}" en Licencias» |
| `turnos-asignados.list` con `q` | `buscar` | «Buscó "{q}" en Turnos asignados» |
| `reportes.marcaciones.procesado.generar` | `reporte` / `imprimir` / `exportar` según `print` (0/1/2) | «… el reporte procesado de CI {ci}, {desde} al {hasta}» |
| `reportes.marcaciones.direccion.generar` | ídem | «… el reporte por dirección {dirección}{/unidad}, {desde} al {hasta}» |
| `reportes.marcaciones.sin-procesar.generar` | ídem | «… las marcaciones sin procesar de CI {ci}, …» |
| `reportes.funcionarios.sin-turno.generar` | ídem | «… los funcionarios sin turno{ de {dirección}}» |
| `funcionarios.reporte` | `imprimir` | «Imprimió el reporte de marcaciones de CI {ci}» |
| `equipos.marcaciones.exportar` | `exportar` | «Bajó las marcaciones del equipo {nombre}» |
| `usuarios.historial` | `ver` | «Miró el historial de {usuario}» |
| `manual.archivo` (solo `mp4` sin `Range` o con `bytes=0-`) | `ver_tutorial` | «Miró el tutorial "{título}"» |

- Se marca **importante solo si la respuesta salió bien** (2xx/3xx): una ficha que dio 404
  no se «miró». El pedido fallido queda igual, como detalle, con su estado.
- Se escribe **después de enviar la respuesta** (`terminate()` del middleware): el usuario no
  espera nada.
- **Si el guardado falla, el pedido no falla**: el error va al log y la pantalla sigue.
- Los videos de la Ayuda se piden por rangos (muchos pedidos `206` del mismo archivo): se
  guardan todos como detalle y solo el primero es importante («Miró el tutorial…»).

### 5.3 Cambios — una línea donde ya se guarda

Cada servicio o controlador que ya escribe agrega su llamada a `RegistroActividad`:
`AsignadorTurnos` (asignar, concluir, eliminar), `RegistroLicencia` y `LicenciaController`
(anotar, aprobar, rechazar, en lote), `TurnoController`, `UserController`, `RoleController`,
`ConfiguracionController`, `DiaExcepcionalController`, `MarcacionController`, `EquipoController`.

`user_historials`, `equipo_auditorias` y `sistema_externo_auditorias` **siguen como están**:
tienen su detalle propio (antes/después del usuario, la respuesta del reloj). La actividad
lleva la frase y, en `datos`, el enlace a ese registro.

---

## 6. Pantallas

### 6.1 «Actividad de usuarios» (menú Usuarios)

- Línea de tiempo, la más nueva arriba, paginada.
- **Interruptor «Solo lo importante» / «Ver todo»**: arranca en «Solo lo importante» (se lee
  como una historia); «Ver todo» muestra cada pedido con su URL, estado y duración.
- **Filtros:** rango de fechas (obligatorio, arranca en hoy); **Quién**: «Personas de SisMark»
  (todo lo que no vino con token, también los intentos de ingreso), «Sistemas externos (API)»
  o un usuario puntual; CI del funcionario; método (GET, POST, PUT, PATCH,
  DELETE); estado (errores 4xx/5xx) y «solo intentos fallidos». El módulo y la IP se muestran
  en cada fila pero no se filtran.
- Cada fila se **despliega** con flecha (como el listado de licencias): `datos` en lenguaje
  claro, navegador, ruta y, en un cambio, antes → después.
- Íconos y colores por tipo; los intentos fallidos y bloqueos en rojo.
- Solo se consulta en pantalla: no se descarga.

### 6.2 En la ficha de cada usuario

Botón **«Actividad»** al lado de «Historial»: la misma pantalla filtrada por ese usuario.

### 6.3 En la ficha de cada funcionario — «Quién consultó esta ficha»

Solo con `ViewAny:Actividad`. Un recuadro o pestaña con quién miró la ficha, quién sacó
reportes de esa CI y quién anotó o resolvió sus licencias y turnos, con fecha y hora.

### 6.4 «Mis ingresos» (menú de la cuenta, para todos)

Los últimos 10 ingresos propios con fecha, IP y navegador, y los intentos fallidos con su
correo. Texto: «¿No reconocés alguno? Cambiá tu contraseña y avisá al Administrador».

### 6.5 Aviso en el Escritorio (solo con `ViewAny:Actividad`)

«⚠ 4 intentos de ingreso fallidos hoy», con enlace a la pantalla ya filtrada. No aparece
si no hubo ninguno (como el aviso de licencias pendientes).

---

## 7. Permisos

| Permiso | Para qué | Quién |
|---|---|---|
| `ViewAny:Actividad` | ver la pantalla, «Actividad» del usuario, «Quién consultó esta ficha», el aviso | Administrador; asignable a Jefatura de RRHH |
| — | «Mis ingresos» | todos, cada uno lo suyo |

No existe `Update:Actividad` ni `Delete:Actividad`. Se suman a la matriz de Roles como los
demás (`Habilidad:Modulo`).

---

## 8. Protección

### 8.1 Que no se pueda tocar
- Sin rutas ni botones de edición o baja.
- El modelo lanza una excepción en `updating`/`deleting` (sección 4).
- Prueba que lo verifica.

### 8.2 Privacidad
- Nunca contraseñas, tokens, `_token`, `Authorization` ni el contenido de archivos. Lista
  negra en `RegistroActividad`: **cualquier** campo cuyo nombre contenga `password`, `clave`,
  `token`, `secret` o `_token` se reemplaza por `••••` antes de guardar, venga de la pantalla,
  del AJAX o de la API, también dentro de arreglos anidados.
- La URL guardada pasa por la misma limpieza (un `?token=` en la query también se tapa).
- De un archivo subido se guarda solo `{nombre, tipo, tamaño}`.
- El `User-Agent` se recorta a 255 y `datos` a 10 KB (un formulario enorme no infla la tabla).

### 8.3 Retención
- Comando `actividad:depurar` programado una vez por día: borra el **detalle de más de 1
  año** y lo **importante de más de 5 años** (los dos plazos configurables en Configuración).
  Borra por tandas de 10.000 para no trabar la base. Es la única baja, y anota cuántas filas
  borró.

### 8.4 Límite de intentos de ingreso — **hoy no existe**
Al revisar el login (26/10/2026) se vio que `LoginController::store()` **no limita los
intentos**: se pueden probar contraseñas sin freno. Va en la Fase 1:
- `RateLimiter` por correo + IP: **5 intentos fallidos → 15 minutos bloqueado**, con el aviso
  «Demasiados intentos. Probá de nuevo en N minutos».
- Al bloquear se dispara `Lockout` (y queda la actividad `bloqueo`).
- El limitador va por correo + IP y no solo por IP: en la Gobernación muchos comparten la
  misma salida a internet (mismo criterio que la emisión de tokens).

---

## 9. Pruebas (Pest, SQLite en memoria)

- Ingreso, ingreso fallido (sin la contraseña en `datos`), ingreso rechazado por cuenta
  inhabilitada, salida, cambio de contraseña.
- El sexto intento fallido en 15 min queda bloqueado y anota `bloqueo`; pasado el tiempo, entra.
- **Todo pedido queda registrado**: pantalla, AJAX, API (con su sistema externo), con y sin
  sesión, con su estado y duración.
- Mirar una ficha anota `ver` importante con la CI; una ficha que da 404 queda solo como detalle.
- Buscar anota `buscar` importante con el texto; listar sin búsqueda queda como detalle.
- Un campo `password`, `token` o `clave` (también anidado o en la query) nunca llega a la tabla.
- Cada reporte con `print` 0/1/2 anota `reporte` / `imprimir` / `exportar` con sus filtros.
- Aprobar una licencia, asignar y concluir un turno anotan su cambio.
- Ningún evento guarda campos de la lista negra.
- `Actividad` no se puede editar ni borrar (excepción).
- La pantalla pide `ViewAny:Actividad`; «Mis ingresos» muestra solo lo propio.
- `actividad:depurar` borra el detalle de más de 1 año y lo importante de más de 5, y deja lo demás.
- Si falla el guardado, el pedido responde igual.

---

## 10. Plan de trabajo

### Fase 1 — El núcleo (responde «quién entró, qué miró, qué buscó») ✅ 26/10/2026
- [x] Migración nueva `create_actividades_table`, modelo `Actividad` inmutable, factory.
- [x] Servicio `RegistroActividad` con la lista negra de campos.
- [x] Listener `RegistrarAcceso` (`Login`, `Failed`, `Logout`, `Lockout`); `ingreso_rechazado` y `cambio_password`.
- [x] Límite de intentos de ingreso (8.4).
- [x] Middleware **global** `RegistrarActividad`: todo pedido, con el mapa de rutas importantes de `CatalogoActividad` (5.2), en `terminate()`. No registra `/up` ni la propia pantalla `/actividades`.
- [x] Permiso `ViewAny:Actividad` en la matriz de Roles (`RolePolicy::MODULOS`).
- [x] Pantalla «Actividad de usuarios» (menú lateral, grupo «Seguridad») con filtros, «Solo lo importante / Ver todo» y detalle desplegable.
- [x] Pruebas: `tests/Feature/ActividadUsuariosTest.php` (17) y `PermisosTest`.
- [x] Documentación y sesión del día.

La frase se guarda con la CI («Miró la ficha del funcionario CI …»), sin el nombre: buscarlo
al guardar sería una consulta más a Mamoré por cada pedido. El **nombre se resuelve al
mostrar** la pantalla, solo para las CI de la página y con la caché de `ResolutorNombres`
(un día; la base local si Mamoré no responde).

La columna `ci` se llena también en los pedidos de detalle cuando la CI viene en la ruta
(`/api/v1/funcionarios/{ci}/…`, la ficha de Mamoré) o en la query (las pestañas de la
ficha): así lo que pide Mamoré por la API se busca por CI y sale con nombre. En lo
registrado antes de eso, la pantalla toma la CI de la URL.

### Fase 2 — Completo
- [ ] Cambios anotados en servicios y controladores (5.3).
- [ ] «Actividad» en la ficha de cada usuario (6.2).
- [ ] «Quién consultó esta ficha» en la ficha del funcionario (6.3).
- [ ] «Mis ingresos» en el menú de la cuenta (6.4).
- [ ] Aviso de intentos fallidos en el Escritorio (6.5).
- [ ] Comando `actividad:depurar` programado y los dos plazos en Configuración (8.3).
- [ ] Pruebas de la Fase 2 y documentación.

---

## 11. Archivos que se crean o se tocan

| Archivo | Qué |
|---|---|
| `database/migrations/AAAA_MM_DD_HHMMSS_create_actividades_table.php` | nuevo |
| `app/Models/Actividad.php`, `database/factories/ActividadFactory.php` | nuevos |
| `app/Services/RegistroActividad.php` | nuevo |
| `app/Listeners/RegistrarAcceso.php` (o uno por evento) | nuevo |
| `app/Http/Middleware/RegistrarActividad.php` | nuevo; se registra en `bootstrap/app.php` / grupo `auth` de `routes/web.php` |
| `app/Http/Controllers/ActividadController.php` | nuevo: pantalla, lista AJAX |
| `app/Policies/ActividadPolicy.php` | nuevo |
| `app/Console/Commands/DepurarActividad.php` | nuevo (Fase 2) |
| `resources/views/actividades/index.blade.php`, `list.blade.php` | nuevos |
| `app/Http/Controllers/Auth/LoginController.php` | límite de intentos, `ingreso_rechazado` |
| `app/Http/Controllers/Auth/CambioPasswordController.php` | `cambio_password` |
| `resources/views/layouts/app.blade.php` | entrada de menú; «Mis ingresos» en la cuenta (Fase 2) |
| Servicios y controladores de 5.3 | una línea cada uno (Fase 2) |
| `tests/Feature/ActividadUsuariosTest.php` y afines | nuevos |

---

## 12. Preguntas abiertas (cerrar antes de empezar)

1. **Plazos de retención:** ¿5 años lo importante y 1 año el detalle? Confirmar con Jurídica / Contraloría interna.
2. **Quién más la ve:** además del Administrador, ¿qué rol? (propuesta: Jefatura de RRHH).
3. **Límite de intentos:** ¿5 intentos y 15 minutos, o más estricto?
4. **«Quién consultó esta ficha»:** ¿solo con `ViewAny:Actividad`, o también para el propio
   funcionario cuando exista un portal?

---

## 13. Poner en marcha (desarrollo y producción)

```sh
php artisan migrate                                   # crea la tabla `actividades`
php artisan db:seed --class=RolesAndPermissionsSeeder # crea ViewAny:Actividad
```

- Hasta correr la migración, el sistema **funciona igual**, pero cada pedido deja en
  `storage/logs/laravel.log` el error de que la tabla no existe (el registro nunca corta un
  pedido).
- El Administrador ve la pantalla sin hacer nada más (`Gate::before`). A otro rol se le da
  «Actividad de usuarios → Ver listado» (y «Exportar» si corresponde) en Roles.

---

## 14. Riesgos y cómo se cubren

| Riesgo | Cubierto por |
|---|---|
| Que registrar haga lento el sistema | Se guarda después de responder (`terminate()`); un solo `INSERT` por pedido; índices |
| Que un error al guardar corte el trabajo | `try/catch` en `RegistroActividad`: el error va al log, el pedido sigue |
| Que la tabla crezca sin control | Retención de 1 año para el detalle y 5 para lo importante; borrado por tandas; partición por mes si hiciera falta (sección 4) |
| Que «todo» vuelva ilegible la pantalla | Arranca en «Solo lo importante»; «Ver todo» es para investigar |
| Que se filtre una contraseña | Lista negra de campos + prueba dedicada |
| Que alguien borre sus rastros | Modelo inmutable, sin rutas de baja, prueba dedicada |
| Confundirla con la Bitácora de biométricos | Nombre distinto; aquella no cambia |
