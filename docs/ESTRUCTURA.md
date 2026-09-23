# SisMark — Cómo funciona y cómo está construido

Documento único de referencia del sistema: qué hace, cómo lo hace y dónde está
cada cosa en el código.

Para desplegarlo, ver el [README](../README.md). Para la historia día a día,
[sesiones/](sesiones/). Los temas que tienen su propio documento se enlazan
desde acá y no se repiten.

---

## 1. Qué es SisMark

Control de asistencia del **Órgano Ejecutivo del Gobierno Autónomo
Departamental del Beni**.

Lee las marcaciones de los relojes biométricos ZKTeco, las cruza contra el
contrato, el turno asignado, las licencias y los feriados, y responde una sola
pregunta por cada día de cada funcionario: **¿cumplió su jornada?**

Lo que **no** hace: no administra personal (eso es Mamoré), no liquida sueldos,
y no emite sanciones.

### Las cuatro fuentes de verdad

El sistema no es dueño de todo lo que muestra. Saber qué manda dónde evita la
mayoría de los errores:

| Dato | Dueño | SisMark |
|---|---|---|
| Identidad, cargo, foto, **contratos** | **Mamoré** (API externa) | consulta, no escribe |
| Historial anterior a la migración | **SIA** (SQL Server, solo lectura) | copió una vez, no vuelve |
| Marcaciones del reloj | **el equipo ZKTeco** | copia, nunca borra del reloj |
| Turnos, licencias, feriados, asistencia procesada | **SisMark** | es el dueño |

---

## 2. Panorama: las piezas y cómo se hablan

```
                     ┌──────────────────────────┐
   relojes ZKTeco    │  device-service (Python) │   TCP 4370
   ●─────────────────┤  FastAPI + pyzk          ├──────────●
                     └────────────┬─────────────┘
                        HTTP + X-Auth-Token
                                  │
   ┌──────────────────────────────▼──────────────────────────────┐
   │                     SisMark  (Laravel 13)                   │
   │                                                             │
   │   Pantallas web  ·  Reportes  ·  Planificador  ·  API v1    │
   └───────┬───────────────────────┬──────────────────┬──────────┘
           │                       │                  │
      MySQL local            SQL Server 2008     API Mamoré
      (base viva)            (SIA, lectura)      (personal)
                                                       ▲
                                                       │  API v1 de SisMark
                                                       └── Mamoré consume la
                                                           asistencia de vuelta
```

**Laravel nunca abre un socket a un reloj.** Todo el protocolo ZKTeco vive en
`device-service/main.py`. Si el microservicio está caído, el sistema sigue
funcionando: solo fallan las acciones de equipos.

---

## 3. Los datos

Base viva: **MySQL** (conexión `mysql`, por defecto). El SIA es una conexión
aparte (`sia`) y **solo de lectura**.

| Tabla | Qué guarda | Origen |
|---|---|---|
| `asistencias` | Marcaciones crudas: `ci`, `fecha`, `hora`, `tipo`. **4,4 millones de filas.** Sin baja lógica | reloj · CSV · alta manual · SIA |
| `personas` | Padrón local de funcionarios | SIA |
| `profesiones` | Catálogo | SIA |
| `turnos` | Horarios: entrada, salida, tolerancias y ventanas de marca | SIA |
| `asignacion_turnos` | Qué turno tiene cada funcionario y desde/hasta cuándo | SIA + altas propias |
| `licencias` | Permisos y licencias, **una fila por día y turno** | SIA · RRHH · solicitudes de Mamoré |
| `dias_excepcionales` | Feriados y días sin control | SIA + altas propias |
| `equipos` | Relojes: IP, puerto, clave y su configuración de sincronización | propio |
| `equipo_auditorias` | Bitácora de cada acción sobre un reloj | propio |
| `users`, `roles`, `permissions` | Acceso al sistema | propio |

### Tres convenciones de columnas que hay que conocer

1. **`fecha` guarda solo el día; `hora` guarda solo la hora.** Las horas se
   manejan en todo el sistema como **segundos desde medianoche**. El trait
   `App\Traits\ManejaHorasDelDia` se encarga de que escribir en una columna
   `time` no mande un `datetime` (MySQL estricto lo rechaza).
2. **`ci` no tiene clave foránea.** Una marcación es un hecho: alguien puso el
   dedo. Si el padrón está desactualizado, la marca igual se guarda y se cuenta
   aparte como huérfana.
3. **Casi todas las tablas tienen baja lógica** (`deleted_at`) y auditoría de
   autor (`registerUser_id`, `updateUser_id`, `deleteUser_id`), vía el trait
   `RegistersUserEvents`. La excepción es `asistencias`: el
   `deleted_at IS NULL` costaba demasiado sobre 4,4 millones de filas.

---

## 4. Módulos y pantallas

| Módulo | Ruta | Qué hace |
|---|---|---|
| **Escritorio** | `/` | Tarjetas de equipos y asistencia, gráfico de 14 días y panel de calidad de datos (se carga aparte por AJAX, porque cuenta la tabla entera) |
| **Funcionarios** | `/funcionarios` | Listado con dos fuentes conmutables: **Mamoré** (por defecto) o **SIAT** (base local). Ficha con cuatro solapas |
| **Marcaciones** | `/marcaciones` | Listado crudo con filtros. Dos formas de cargar: importar el CSV del reloj, o alta manual de a una (tipo `M`) para lo que el reloj no registró |
| **Horarios** | `/horarios` | CRUD de turnos |
| **Turnos asignados** | `/turnos-asignados` | Qué turno tiene cada funcionario. **Concluir** ≠ **Eliminar**: concluir pone fecha de fin y conserva la historia; eliminar es para lo cargado por error |
| **Licencias** | `/licencias` | Listado, alta en lote, ficha de la solicitud y resolución de lo que llega de Mamoré |
| **Días excepcionales** | `/dias-excepcionales` | Feriados y días sin control, con su decreto adjunto |
| **Equipos** | `/equipos` | CRUD de relojes + probar conexión, exportar, sincronizar y vaciar |
| **Bitácora** | `/equipos/auditoria` | Qué se le hizo a cada reloj, cuándo y con qué resultado |
| **Reportes** | `/reportes/marcaciones/*` | Sin procesar y procesado |
| **Usuarios / Roles** | `/usuarios`, `/roles` | Acceso y matriz de permisos |

### La ficha del funcionario

Cuatro solapas, cada una con su tabla propia cargada por AJAX la primera vez
que se la abre (entrar a la ficha pide una sola tabla, no cuatro):

| Solapa | Qué muestra | Permiso |
|---|---|---|
| Marcaciones | lo crudo del reloj | `ViewAny:Persona` |
| Licencias | los permisos, agrupados **por solicitud** y no día por día | `ViewAny:Licencia` |
| Turnos | los horarios asignados, agrupados por período de vigencia | `ViewAny:AsignacionTurno` |
| Asistencia procesada | las marcas ya cruzadas | `ViewAny:Reporte` |

---

## 5. El motor: `ProcesadorAsistencia`

**La pieza central del sistema.** Convierte marcas crudas en una ficha por día.
Sus reglas están detalladas en
[REPORTE-PROCESADO-ASISTENCIA.md](REPORTE-PROCESADO-ASISTENCIA.md); acá va lo
que hay que saber para orientarse.

### Orden de prioridad

Cada día se resuelve con este orden, y el primero que aplica gana:

```
0. ¿Lo cubre un contrato?   NO → «Sin contrato» (con turno) · «No laborable» (sin turno)
1. ¿Es día excepcional?     SÍ → «Excepcional»
2. ¿Tiene turno asignado?   NO → «No laborable»
3. ¿Licencia de día entero? SÍ → «Licencia»
4. Recién acá se miran las marcaciones
```

**El contrato es la primera puerta, antes que el turno.** Un día que ningún
contrato de Mamoré cubre no se procesa, aunque la persona tenga turno y haya
marcado. Sin contrato no hay jornada que cumplir. Sus marcas no se pierden:
siguen enteras en la solapa Marcaciones, que es lo crudo del reloj.

Los contratos **nunca se cachean**. La ficha de identidad sí se guarda un día
—nombre, cargo y foto no cambian—, pero procesar sobre una copia vieja de los
contratos imputaría faltas en días ya cubiertos por una renovación recién
cargada. Si Mamoré no responde, el reporte no se emite.

### Los estados de un día

| Estado | Significa |
|---|---|
| `cumple` | entró y salió en hora |
| `atraso` | entró pasada la tolerancia |
| `abandono` | se retiró antes de la hora de salida |
| `sin_entrada` · `sin_salida` | falta una marca que se exigía |
| `falta` | ni entrada ni salida |
| `licencia` · `excepcional` · `no_laborable` | no había jornada que controlar |
| `sin_contrato` | la persona no era funcionaria ese día |
| `turno_invalido` | el turno está mal cargado y no puede fundar nada |

### Tres detalles que explican casi todas las dudas

- **El atraso se cuenta en minutos completos desde `hEntrada`, y `hTolerancia`
  se mide en la misma unidad.** Con entrada 08:00 y tolerancia 08:10 hay diez
  minutos de gracia, así que el minuto 08:10 entero está adentro: a las 08:10:59
  no hay atraso; a las 08:11:00 hay 11 min. Los segundos se descartan —el reloj
  los registra, pero la tolerancia se concede en minutos—.
- **Las horas computadas se acotan al turno.** Llegar dentro de la tolerancia
  cuenta como llegar en hora; quedarse de más no suma. La permanencia real
  viaja aparte, como dato.
- **Jornada partida:** si el día tiene dos turnos, cada bloque vale media
  jornada. El peso es `1 ÷ cantidad de bloques`.

La regla vive **dentro** del procesador y no en cada pantalla, así que la
heredan de una el reporte, su imprimible, el Excel y la API.

---

## 6. Reportes

| Reporte | Qué muestra | Salidas |
|---|---|---|
| **Sin procesar** | todas las marcaciones crudas del rango | pantalla · imprimible · CSV |
| **Procesado** | las mismas marcas cruzadas contra contrato, turno, licencias y feriados, con entradas, salidas, atrasos y horas | pantalla · imprimible · **Excel nativo** |

El imprimible lleva encabezado institucional, totales, resumen, referencias,
firmas y un **QR** que permite verificar el documento.

El Excel (`ExcelMarcacionesProcesadas`) repite la misma maqueta con las 13
columnas. Todas las celdas se escriben como **texto explícito**: si fueran
números, Excel convertiría «08:25:00» en una hora y «1/7/2026» en una fecha, y
el archivo dejaría de leerse igual que el imprimible. El QR no viaja: generarlo
en PNG requiere imagick, que no está instalada.

La tabla del reporte procesado es un parcial sin layout, pensado para
inyectarse por AJAX. Por eso la misma pantalla sirve al reporte general y a la
solapa de la ficha; la solapa pide `encabezado=0` para no repetir el nombre del
funcionario, que ya está unos centímetros más arriba.

---

## 7. API v1 — la que SisMark expone

Solo lectura, salvo un endpoint. La consumen los sistemas externos que necesitan
mostrarle a un funcionario **su propia** asistencia sin darle acceso a SisMark.
Hoy el único consumidor es Mamoré.

**Autenticación: token de Sanctum** en `Authorization: Bearer`, emitido sobre un
`App\Models\SistemaExterno` —no sobre un `User`—. Sin ningún sistema cargado no
hay token posible y la API rechaza todo con **401**: un servidor recién
desplegado no queda abierto.

Antes era una sola clave en `SISMARK_API_KEY`. Con una clave compartida no se le
puede cortar el acceso a un consumidor sin cortárselo a todos, ni rotarla sin
coordinar el mismo día con cada equipo, ni saber cuál pidió qué.

| Pieza | Para qué |
|---|---|
| `sistemas_externos` | una fila por consumidor, con su interruptor `activo` |
| `SistemaExterno::ALCANCES` | los cinco alcances, fuente única |
| `AppServiceProvider::configurarTokensDeSistemas()` | apagar o dar de baja un sistema le corta el acceso en el próximo pedido, sin borrarle el token |
| `config/sanctum.php` | `guard => []` (para que el tokenable no sea `User`) y `expiration => null` (credencial de máquina) |
| `sistema_externo_auditorias` | quién emitió o revocó cada token, cuándo y desde dónde |

**Alcances:** `asistencia:read`, `licencias:read`, `licencias:write`,
`turnos:read`, `turnos:write`. Van por área y no por endpoint: partirlos más
fino obligaría a reemitir el token cada vez que se agrega una ruta.

**Un sistema tiene un solo token vivo.** Emitir uno nuevo revoca el anterior, y
el consumidor queda cortado hasta que lo cargue: no hay ventana de convivencia.

### Dónde se emite

La pantalla **«Tokens de API»** (`/tokens-api`): alta y baja de consumidores,
interruptor `activo`, emisión y revocación.

**La pantalla emite siempre con todos los alcances.** Elegirlos de a uno obliga a
saber de antemano qué endpoints va a usar el consumidor, que es justo lo que no
se sabe al darlo de alta, y equivocarse ahí se manifiesta como un 403 del otro
lado que manda a buscar el problema al lado equivocado. El corte fino sigue
existiendo, por consola: `sismark:token {slug} --alcance=…`.

Emitir pide **su propio permiso** (`Token:SistemaExterno`) y **la contraseña** de
quien lo hace. No sale de `Update:SistemaExterno` a propósito: administrar la
ficha de un consumidor es una cosa, y entregar la credencial que abre la
asistencia de los ~4.600 funcionarios es otra. El token se muestra **una sola
vez** —la base guarda solo su hash— y la emisión va limitada a 5 por hora y por
usuario.

Dar de alta un nombre corto que existe **dado de baja** reactiva esa ficha en vez
de rechazarla: el índice único no distingue `deleted_at`, y rechazarla dejaría ese
slug quemado sin pantalla desde donde recuperarlo. Al reactivar se le revoca el
token que tenía, porque quien da de alta puede ser otro equipo que eligió el
mismo nombre.

`php artisan sismark:token {slug}` queda como respaldo: es el camino cuando la
pantalla no sirve —un despliegue nuevo, sin usuarios ni roles todavía—. Anota en
la misma bitácora, sin usuario.

Fuera de producción, `IntegracionMamoreSeeder` —al final de `MigrarSiaSeeder`—
deja sembrado un token de **texto fijo** y marca el horario sugerido, para que un
`migrate:fresh` no obligue a reemitir la credencial ni a volver a tocar el `.env`
de Mamoré. En producción se planta y no siembra nada.

**Limitador:** `throttle:api`, por consumidor y no por IP — detrás de un proxy
todos los pedidos llegan con la misma IP, así que el exceso de uno castigaría al
otro.

| Método | Endpoint | Qué hace |
|---|---|---|
| `GET` | `/api/v1/funcionarios/{ci}/marcaciones` | marcaciones crudas del rango |
| `GET` | `/api/v1/funcionarios/{ci}/asistencia` | la asistencia ya procesada |
| `GET` | `/api/v1/funcionarios/{ci}/licencias` | listado de sus licencias |
| `GET` | `/api/v1/funcionarios/{ci}/licencias/{id}` | ficha con el desglose día por día |
| `GET` | `/api/v1/funcionarios/{ci}/licencias/{id}/respaldo` | enlace temporal al certificado |
| `POST` | `/api/v1/funcionarios/{ci}/licencias` | el funcionario solicita una licencia |
| `DELETE` | `/api/v1/funcionarios/{ci}/licencias/{id}` | baja de su propia solicitud, mientras siga «Pendiente» |
| `GET` | `/api/v1/turnos/sugeridos` | el horario que se propone al dar de alta un contrato |
| `POST` | `/api/v1/funcionarios/{ci}/turnos` | le asigna el horario por la vigencia del contrato |
| `PUT` | `/api/v1/funcionarios/{ci}/turnos` | mueve esa vigencia cuando el contrato cambia de fechas |
| `DELETE` | `/api/v1/funcionarios/{ci}/turnos` | baja lógica del horario cuando el contrato se anula |

### El horario y la vigencia del contrato

`POST` asigna, `PUT` mueve, `DELETE` da de baja. Los tres van atados al contrato
por `contrato_id`, que es lo que permite saber **cuáles** de las asignaciones de
un funcionario hay que tocar con él. Se guarda el id y no el código porque
Mamoré regenera el código cuando cambia el año de inicio o la dirección
administrativa. Los tres filtran además por cédula: sola, una cédula equivocada
del otro lado tocaría el horario de quien no corresponde.

El `POST` es idempotente sobre `(ci, idTurno, desde)`: reintentarlo no duplica.
El `PUT` mueve las filas de ese contrato y **crea las que falten**, para el
contrato anterior a esta integración o aquel cuyo alta falló. El `DELETE` marca
`deleted_at` y contesta `eliminados: 0` —no un error— si no había nada.

**La baja es lógica y la fila se queda.** El turno es el respaldo de por qué a
esa persona se le exigió marcar en esas fechas: borrarlo dejaría sin explicación
los atrasos y las faltas ya imputadas mientras el contrato estuvo vigente. El
reporte igual deja de contarlos —el contrato es la primera puerta del
procesador—; la baja es para que el horario no siga en pantalla como si esa
persona tuviera que ir a trabajar.

**Volver a cargar un contrato anulado revive sus filas.** La única de
`asignacion_turnos` no distingue `deleted_at`, así que sin eso la fila muerta
bloquearía el alta y el funcionario quedaría sin horario —o sea sin control de
asistencia— sin que nadie lo note. Solo se revive lo del **mismo** contrato: una
baja de otro contrato en la misma terna se informa como `omitidos`.

> **Por qué el `PUT` importa más de lo que parece.** Una adenda que renueva deja
> al contrato cubriendo días que el turno no cubre, y `ProcesadorAsistencia` los
> resuelve como «no laborable»: esa persona queda **sin control de asistencia** y
> nadie se entera hasta el reporte del mes. Concluir un contrato antes de tiempo
> es inofensivo —el contrato es la primera puerta del procesador, así que un día
> sin contrato no se procesa aunque sobre el turno— pero deja el horario
> mintiendo en pantalla.

> **La clave autentica al sistema, no a la persona.** Quién es el funcionario lo
> decide el consumidor desde su propia sesión. Por eso, en los endpoints donde
> el identificador es el id de una fila y no la cédula, el controlador comprueba
> que esa licencia sea de esa cédula: sin eso, un id corrido dejaría bajarse los
> certificados médicos de todo el personal.

### El circuito de una solicitud de licencia

```
Funcionario en Mamoré  →  POST /api/v1/.../licencias  →  estado «Pendiente»
                                                              │
RRHH en SisMark  →  ficha de la solicitud  →  Aprobar ──────► surte efecto
                                           └─ Rechazar (exige motivo, que el
                                              funcionario lee en su perfil)
```

Aprobar y rechazar son **rutas distintas**, no un campo del formulario: así un
envío manipulado no puede convertir un rechazo en una aprobación. Cada decisión
queda con su autor y su fecha. Lo rechazado y lo dado de baja se conserva como
constancia y nunca se reescribe.

---

## 8. Integración con Mamoré — la dirección opuesta

Mismo mecanismo, al revés: SisMark consulta la API de Datos Personales de
Mamoré (`/api/externo/personal`) con un **token de Sanctum que emite Mamoré**
desde su pantalla `/admin/tokens-api`, con el alcance `personal:read`.

| Variable | Para qué |
|---|---|
| `MAMORE_URL` | la URL con el prefijo completo |
| `MAMORE_TOKEN` | el token que emitió Mamoré; acá solo se pega |
| `MAMORE_ORIGIN` | el dominio con el que Mamoré tiene registrado a SisMark |

`MAMORE_ORIGIN` viaja en la cabecera `Origin`: del otro lado la comparan contra
el dominio de la ficha del sistema y contestan **403** si falta o no coincide.
No es la puerta —el `Origin` se forja— sino una baranda: atrapa el token pegado
en el sistema equivocado, que si no funcionaría perfecto y dejaría los logs
mintiendo. Vacío, se cae en `APP_URL`.

**Cada sistema emite el token de su propia API.** Mamoré emite el que usa
SisMark para entrar acá; SisMark emite el que usa Mamoré para entrar a la API v1
(§7). Un token de Sanctum no va firmado: su hash vive en la base que lo creó, así
que el de un lado no lo puede validar el otro.

| Servicio | Qué resuelve |
|---|---|
| `MamoreClient` | cliente HTTP crudo |
| `DirectorioMamore` | listado y ficha ya normalizados para las vistas |
| `ResolutorNombres` | el CI suelto de un listado → nombre y cargo. **Cachea un día** |
| `ContratosFuncionario` | entre qué fechas la persona fue funcionaria. **Nunca cachea** |

Si Mamoré no responde o no está configurado, las pantallas se muestran igual y
el dato queda en «—». La ficha no depende de la API para existir.

---

## 9. Sincronización de biométricos

Detalle del protocolo en
[COMUNICACION-BIOMETRICOS.md](COMUNICACION-BIOMETRICOS.md).

### El microservicio

`device-service/main.py` — FastAPI + pyzk, todo en un archivo. Cuatro endpoints,
todos con `X-Auth-Token`:

| Endpoint | Qué hace |
|---|---|
| `GET /health` | está vivo |
| `GET /device/info` | datos del reloj (probar conexión) |
| `GET /device/users` | usuarios cargados en el reloj |
| `GET /device/attendance` | marcaciones del buffer |
| `POST /device/attendance/clear` | vacía el buffer — **irreversible** |

### Cómo se copia

**La sincronización no pide rango: baja el buffer completo.** Y no cuesta más
que pedir un día, porque el protocolo ZK no sabe filtrar: `get_attendance()`
vuelca todo el historial igual. A cambio, nada queda afuera. Lo que ya está en
la base se descarta por la terna `(ci, fecha, hora)`, así que **repetir la
corrida no cambia nada**.

Copiar **nunca borra del reloj**. Vaciar el buffer es una acción aparte, con su
propio permiso (`Clear:Equipo`), y es irreversible.

### Automática

Cada equipo se configura desde su ficha: en qué días y a qué horas se
sincroniza (`sync_dias`, `sync_horarios`).

```
Planificador cada minuto  →  sismark:sincronizar-equipos
                                   │
                                   └─ para cada equipo: ¿activo? ¿automática
                                      encendida? ¿hoy es día? ¿es la hora exacta?
```

El planificador corre **cada minuto** y el comando decide qué equipos trabajan:
los horarios se configuran desde el sistema y cambian sin tocar el código.
`withoutOverlapping` evita que un reloj lento encime dos corridas.

> Requiere que el servidor dispare `php artisan schedule:run` cada minuto. En el
> contenedor lo levanta la propia imagen; en Windows se configura como tarea del
> Programador. Sin eso, la sincronización automática no ocurre por más que esté
> marcada en la ficha.

**Un equipo caído recupera todo lo que se perdió** al volver: el rango arranca
en la última corrida **que trajo datos** (`sync_ultimo_exito`) y no en la última
que se intentó (`sync_ultimo_automatico`). La ficha muestra las dos fechas por
separado, justamente para delatar al reloj que responde pero no entrega.

Cada corrida deja su entrada en `equipo_auditorias` con las cifras que permiten
comprobar que la transferencia se completó: cuántas dice el reloj que tiene,
cuántas llegaron, cuántas eran nuevas, repetidas, huérfanas o fuera de rango.

---

## 10. Migración del SIA

Guía completa en [MIGRACION-SIA-MYSQL.md](MIGRACION-SIA-MYSQL.md); conexión y
credenciales en [CONEXION-BD.md](CONEXION-BD.md).

Siete comandos, uno por tabla. Todos **idempotentes** (upsert por clave
natural), con `--chunk` y sin tocar el origen:

```bash
php artisan sia:migrar-profesiones
php artisan sia:migrar-personas
php artisan sia:migrar-horarios
php artisan sia:migrar-asignacion-turnos   # resuelve la FK turno_id
php artisan sia:migrar-licencias
php artisan sia:migrar-dias-excepcionales
php artisan sia:migrar-marcaciones --chunk=1000
```

El orden importa: las asignaciones cruzan `idTurno` contra `turnos`, así que los
horarios van antes.

> **SQL Server 2008 R2 no tiene `OFFSET/FETCH`.** Por eso existen
> `app/Database/SqlServer2008Connection.php` y `SqlServer2008Grammar.php`, que
> traducen la paginación a `ROW_NUMBER()`. Sin ellos, cualquier `paginate()`
> contra el SIA falla.

La meta es que SisMark viva 100% en MySQL y deje de depender del SQL Server.

---

## 11. Permisos

Convención del nombre: **`Habilidad:Modulo`** (`ViewAny:Persona`,
`Approve:Licencia`, `Clear:Equipo`).

```
Usuario inicia sesión
  → Gate::before en AppServiceProvider
      ├─ super_admin → acceso total, sin mirar permisos individuales
      └─ cualquier otro → el controlador llama $this->authorize(...)
            → Laravel resuelve la Policy por convención (Modelo → ModeloPolicy)
            → la Policy pregunta $user->can('Habilidad:Modulo')
      → sin el permiso, 403
```

`App\Policies\RolePolicy::MODULOS` es la **fuente única** de los permisos: la
usan el seeder, la matriz de checkboxes de `/roles` y la validación. Cada módulo
declara **solo las habilidades que le aplican**, para que la matriz no ofrezca
casillas que no significan nada.

> ⚠️ **Las reglas de negocio no van en la Policy.** El `Gate::before` de
> super_admin devuelve `true` sin llegar a ejecutarla, así que una condición
> escrita ahí —«solo lo Pendiente»— se saltearía justamente para el usuario que
> más usa la pantalla. Esas condiciones van en el **FormRequest**, que corre
> siempre. Ver `RevisarLicenciaRequest`.

---

## 12. Estructura del código

MVC clásico de Laravel. El patrón se repite en todos los recursos:

| Pieza | Qué define |
|---|---|
| `app/Http/Controllers/XxxController.php` | listar / crear / editar / eliminar |
| `app/Http/Requests/StoreXxx·UpdateXxx.php` | validación **y reglas de negocio** |
| `resources/views/xxx/*.blade.php` | `index`, `create`, `edit`, `_form` |
| `app/Policies/XxxPolicy.php` | quién puede hacer qué |

```
app/
├── Console/Commands/
│   ├── Migrar*Sia.php                 # 7 comandos de migración del SIA
│   ├── EmitirTokenSistema.php         # sismark:token {slug}
│   └── SincronizarEquipos.php         # sismark:sincronizar-equipos
├── Database/
│   ├── SqlServer2008Connection.php    # conexión sqlsrv con grammar propio
│   └── SqlServer2008Grammar.php       # paginación con ROW_NUMBER()
├── Exceptions/
│   ├── DeviceServiceException.php     # errores del microservicio, legibles
│   └── MamoreException.php            # errores de la API de personal
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   ├── AsignacionTurnoApiController.php      # asigna el turno del contrato
│   │   │   ├── AsistenciaFuncionarioController.php   # los GET de la API v1
│   │   │   ├── SolicitudLicenciaController.php       # POST y DELETE
│   │   │   └── TurnoSugeridoController.php           # el horario sugerido
│   │   ├── AsignacionTurnoController.php
│   │   ├── Auth/LoginController.php
│   │   ├── DashboardController.php
│   │   ├── DiaExcepcionalController.php
│   │   ├── DiaTurnoController.php     # «Horarios»
│   │   ├── EquipoController.php       # CRUD + probar/exportar/sincronizar/vaciar
│   │   ├── LicenciaController.php
│   │   ├── MarcacionController.php
│   │   ├── PersonaController.php      # funcionarios + solapas de la ficha
│   │   ├── ReporteMarcacionController.php
│   │   └── SistemaExternoController.php  # «Tokens de API»
│   └── Requests/                      # Store*/Update*/Revisar* por recurso
├── Models/
│   ├── Sia/                           # solo lectura, conexión `sia`
│   ├── SistemaExterno.php             # consumidor de la API v1, dueño del token
│   ├── SistemaExternoAuditoria.php    # bitácora de emisión y revocación
│   └── *.php                          # base local
├── Policies/                          # una por modelo, autodescubiertas
├── Providers/AppServiceProvider.php   # conexión sqlsrv 2008, Gate::before
├── Services/
│   ├── ProcesadorAsistencia.php       # ★ el motor: marcas → ficha por día
│   ├── ContratosFuncionario.php       # primera puerta: ¿había contrato?
│   ├── MamoreClient.php               # cliente HTTP de Mamoré
│   ├── DirectorioMamore.php           # listado/ficha normalizados
│   ├── ResolutorNombres.php           # CI → nombre (cachea un día)
│   ├── DeviceService.php              # cliente HTTP del microservicio
│   ├── SincronizadorEquipos.php       # reloj → tabla `asistencias`
│   ├── RegistroAsistencia.php         # alta de marcaciones desde cualquier fuente
│   ├── RegistroLicencia.php           # expande un rango a una fila por día/turno
│   ├── RespaldoDocumento.php          # adjuntos al bucket S3, enlaces firmados
│   ├── ResumenEscritorio.php          # paneles del escritorio
│   └── ExcelMarcacionesProcesadas.php # .xlsx nativo del reporte procesado
└── Traits/
    ├── ManejaHorasDelDia.php          # columnas `time` y `date` sin datetime
    └── RegistersUserEvents.php        # quién creó/editó/eliminó

device-service/main.py                 # microservicio ZKTeco, todo en un archivo

resources/views/
├── layouts/app.blade.php              # layout único: sidebar, topbar y TODO el CSS
├── components/                        # 8 componentes: botones, modales, avatar
└── <recurso>/                         # index · create · edit · _form · *-list

routes/
├── web.php                            # pantallas y AJAX (sesión)
├── api.php                            # API v1 (clave compartida)
└── console.php                        # planificador

docs/
├── ESTRUCTURA.md                      # ← este archivo
├── REPORTE-PROCESADO-ASISTENCIA.md    # las reglas del motor, en detalle
├── COMUNICACION-BIOMETRICOS.md        # protocolo ZKTeco y microservicio
├── MIGRACION-SIA-MYSQL.md             # guía tabla por tabla
├── CONEXION-BD.md                     # MySQL y SQL Server
└── sesiones/MM-AAAA/AAAA-MM-DD.md     # bitácora diaria
```

### El frontend no tiene build

**El CSS vive embebido en `resources/views/layouts/app.blade.php`.** No hay
Vite, ni Tailwind, ni `npm`. Un cambio de estilos se ve al recargar. Alpine.js
se sirve desde `public/vendor/alpine/`.

Las tablas se cargan por AJAX y devuelven **parciales sin layout**. Un
envoltorio de `fetch` instalado en el `<head>` detecta el 401 (sesión vencida) y
el 419 (CSRF viejo) y recarga la pantalla, en vez de dibujar el formulario de
ingreso dentro de la tabla.

---

## 13. Dónde está qué

| Busco | Archivo |
|---|---|
| Por qué un día salió «Falta» | `app/Services/ProcesadorAsistencia.php` |
| Por qué un día no se procesó | `app/Services/ContratosFuncionario.php` |
| Cómo se lee un reloj | `device-service/main.py` + `app/Services/DeviceService.php` |
| Cuándo se sincroniza solo | `app/Models/Equipo.php::tocaSincronizar()` + `routes/console.php` |
| Qué devuelve la API a Mamoré | `app/Http/Controllers/Api/AsistenciaFuncionarioController.php` |
| Cómo se aprueba una licencia | `LicenciaController` + `RevisarLicenciaRequest` |
| La lista de permisos | `app/Policies/RolePolicy::MODULOS` |
| Colores, sidebar, paginación | `resources/views/layouts/app.blade.php` |
| Comportamientos globales | `app/Providers/AppServiceProvider.php` |
| Sesión vencida, proxies, JSON de errores | `bootstrap/app.php` |

---

## 14. Pruebas

**648 pruebas Pest**, todas de integración real contra SQLite en memoria: sin
mocks de la base. Mamoré y el microservicio se simulan con `Http::fake()`.

```bash
php artisan test --compact                      # todo
php artisan test --compact --filter=Procesad    # un área
vendor/bin/pint --dirty --format agent          # formato antes de cerrar
```

Las de más valor están en `ProcesadorAsistenciaTest` y
`ContratosEnAsistenciaTest`: fijan las reglas del motor contra dos años de datos
reales.

---

## 15. Despliegue

Imagen Docker de **una sola etapa** (`unit:1.34.2-php8.3`), sin Node.

- `ENV TZ=America/La_Paz` — los relojes guardan en hora local y el procesador
  compara marcas contra horarios sin zona horaria.
- El contenedor levanta el planificador que dispara la sincronización; no hace
  falta crontab del host.
- Los adjuntos van al disco `s3` (DigitalOcean Spaces) y no al disco local: el
  contenedor no tiene volumen persistente, así que un archivo escrito en disco
  se pierde en el próximo despliegue.

Variables, comandos y requisitos en el [README](../README.md).
