# Reporte procesado de asistencia — análisis y reglas

Documento de trabajo previo a la implementación. Define cómo se cruzan
**marcaciones + horarios + asignación de horarios + días excepcionales + licencias**
para producir el reporte procesado (estilo ZKTeco Attendance).

Fecha de análisis: **2026-07-29**.

---

## 1. Tablas involucradas

### 1.1 `horarios` (757 filas) — plantilla de horario

Una fila = **un día de la semana**, no una semana entera.

| Campo | Uso en el procesado |
|---|---|
| `dia` | 1=Dom … 7=Sáb (coincide con `DAYOFWEEK()` de MySQL) |
| `hEntrada` | hora nominal de entrada |
| `hTolerancia` | límite sin atraso (normal +10 min) |
| `eMinima` | antes de esto NO se acepta la marca como entrada |
| `eMaxima` | después de esto NO se acepta la marca como entrada |
| `hSalida` | hora nominal de salida |
| `sTolerancia` | antes de esto = salida anticipada |
| `sMinima` | antes de esto NO se acepta la marca como salida |
| `sMaxima` | después de esto NO se acepta la marca como salida |
| `hTrabajadas` | horas esperadas (decimal) |
| `siguienteDia` | 1 = la ventana de salida se busca en `fecha + 1` |

Las horas se guardan sobre la fecha base `1899-12-30` → comparar solo con `TIME()`.

### 1.2 `asignacion_horarios` (419.408 filas) — quién trabaja qué

`ci` + `horario_id` + `desde` … `hasta`. Un funcionario tiene **N filas para el
mismo rango**, una por día de la semana.

Ejemplo real (`ci = 1939161`): 5 filas (`dia` 2..6), todas `2026-01-05 → 2026-12-31`.

Resolución del horario del día:

```sql
asignacion_horarios a JOIN horarios t ON t.id = a.horario_id
WHERE a.ci = ? AND ? BETWEEN a.desde AND a.hasta
  AND t.dia = DAYOFWEEK(?)
```

Sin fila = día no laborable → **no es falta**.

Puede devolver **más de un horario** para el mismo día (horario partido, ver §5).

### 1.3 `asistencias` (4.401.523 filas) — marcaciones crudas

`ci` + `fecha` (día a las 00:00) + `hora` (base 1899-12-30) + `tipo`.

Tipos: `R` = 2.504.863 · `A` = 1.807.305 · `M` = 89.355.
`A` y `R` conviven en todos los años → **no es histórico, son dos fuentes
distintas**. Falta identificar qué es `A`.

### 1.4 `dias_excepcionales` (7.517 filas) — calendario, no solo feriados

Rango `2009-01-01 → 2029-07-31`, **una fila por fecha**. Solo **209** tienen
`motivoInasistencia`; las otras 7.308 son `NULL` = día normal.

Motivos vistos: `HORARIO CONTINUO`, `Mantenimiento Reloj`, `CARNAVAL`,
`PARO CIVICO`, `LLUVIA TORRENCIAL`, `VIERNES SANTO`…

### 1.5 `licencias` (1.107.748 filas) — permiso por `ci` + `fecha` + `horario_id`

Hay **dos clases de licencia**, y se distinguen por `tCompleto`:

| Campo | Significado |
|---|---|
| `tCompleto = 1` | **horario completo** (`lEntra`/`lSale` en `NULL`) |
| `tCompleto = 0` | **por horas**: el tramo va en `lEntra` / `lSale` |
| `goceHaberes` | con/sin sueldo |
| `motivo` | texto libre, sin normalizar |

**La licencia de horario completo cubre el día entero**: aunque la fila apunte a un
`horario_id`, si el funcionario tiene varios horarios asignados ese día, quedan todos
licenciados. Por eso se resuelve **a nivel día**, antes de repartir las marcas
entre horarios, y no se compara el `horario_id`.

La licencia **por horas** sí se evalúa horario por horario: se cruza con el horario por
`horario_id` (una fila sin `horario_id` aplica a todos los horarios del día).

**Una fila por día**, no por rango: un memo de 3 meses genera ~90 filas.

---

## 2. Parámetros y línea de tiempo

Horario de referencia usado en todos los ejemplos:

```
dia          = 2 (Lunes)
nombreHorario  = "LUN: 08:00 - 16:00"
hEntrada     = 08:00
hTolerancia  = 08:10
eMinima      = 07:00
eMaxima      = 09:00
hSalida      = 16:00
sTolerancia  = 16:00
sMinima      = 16:00
sMaxima      = 20:00
siguienteDia = 0
hTrabajadas  = 8,00
```

```
00:00      07:00 ═══ 08:00 ─ 08:10 ═══ 09:00      16:00 ═══════ 20:00      23:59
  ✗          │ eMin  hEnt   hTol      eMax │        │ sMin       sMax │       ✗
  fuera      └────── VENTANA ENTRADA ───────┘        └─ VENTANA SALIDA ┘     fuera
                                              ↑
                                    09:00–16:00 = ZONA MUERTA
                                    (marcas acá no son ni entrada ni salida)
```

**Selección:** dentro de la ventana de entrada se toma la **primera** marca
(`min`); dentro de la ventana de salida se toma la **última** (`max`). El resto
se descarta.

---

## 3. Reglas confirmadas

### 3.1 Orden de prioridad (corta en el primer match)

```
1. ¿dias_excepcionales.motivoInasistencia IS NOT NULL?  → SÍ → CORTA. Fin.
2. ¿tiene horario asignado ese día?                       → NO → NO LABORABLE
3. ¿licencia de horario completo (tCompleto = 1)?         → SÍ → LICENCIA (todo el día)
4. procesar marcaciones horario por horario
```

**El día excepcional manda sobre todo**: no se procesan marcaciones aunque el
funcionario tenga uno o varios horarios asignados ese día.

**La licencia de horario completo también corta el día entero**, sin importar a qué
`horario_id` apunte: si el funcionario tiene dos horarios, quedan los dos licenciados.
Las licencias **por horas** no cortan — se aplican dentro de cada horario (§3.5).

### 3.2 Atraso

```
¿Hay atraso?  →  marca > hTolerancia      (≤ tolerancia = sin atraso, borde inclusive)
¿Cuánto?      →  marca − hEntrada         (se mide contra la hora NOMINAL)
```

| Marca | ¿Atraso? | Minutos |
|---|---|---|
| 08:08 | ❌ no | 0 |
| 08:10:00 | ❌ no | 0 |
| 08:10:01 | ✅ sí | 10 min 1 seg |
| 08:15 | ✅ sí | 15 min |
| 08:25 | ✅ sí | 25 min |

La tolerancia **solo decide si hay atraso**, no cuánto vale.

### 3.3 Horas computables — criterio estricto, con la tolerancia acreditada

```
inicio = (entrada ≤ hTolerancia) ? hEntrada : entrada
fin    = (salida  ≥ sTolerancia) ? hSalida  : salida

computable = fin − inicio          (acotado al horario)
```

Dos ideas:

1. **Se acota al horario.** El que llega 2h antes y se va 2h después no acumula
   12h. La **permanencia real** se muestra al lado como columna informativa, para
   que RRHH pueda autorizar compensación a mano.
2. **Dentro de la tolerancia cuenta como llegar a la hora.** Marcar 08:05 con
   tolerancia 08:10 no descuenta 5 min: si no hay atraso, tampoco hay déficit.
   Igual del lado de la salida con `sTolerancia`.

| Entrada | Salida | inicio | fin | Computable |
|---|---|---|---|---|
| 07:30 | 16:59 | 08:00 | 16:00 | 8h 00m |
| 08:05 | 16:05 | 08:00 (tolerancia) | 16:00 | 8h 00m |
| 08:25 | 16:50 | 08:25 | 16:00 | 7h 35m |
| 08:59 | 16:00 | 08:59 | 16:00 | 7h 01m |

Sin entrada válida o sin salida válida → **computable = 0h**.

### 3.4 Marcas fuera de rango

| Caso | Ejemplo | Estado |
|---|---|---|
| Salida `< sMinima` | 15:40 con `sMinima 16:00` | 🔴 **ABANDONO** (se fue antes) |
| Salida `> sMaxima` | 20:08 con `sMaxima 20:00` | 🟠 MARCA FUERA DE RANGO |
| Sin marca de salida | — | 🟠 SIN SALIDA |
| Entrada `< eMinima` | 06:59 con `eMinima 07:00` | 🟠 MARCA FUERA DE RANGO |
| Entrada `> eMaxima` | 09:15 con `eMaxima 09:00` | 🔴 ATRASO GRAVE / NO HABILITADO |
| Sin marca de entrada | — | 🟠 SIN ENTRADA |

**Abandono = únicamente irse antes de `sMinima`.** El que marca después de
`sMaxima` trabajó de más; su falta es no haber marcado dentro de la ventana.

### 3.5 Licencias por horas — corte duro

```
tCompleto = 1              →  horario completo: no se exige ninguna marca (todo el día)
licencia cubre la entrada  ⟺  lEntra ≤ hEntrada
licencia cubre la salida   ⟺  lSale  ≥ hSalida
```

Si la licencia **cubre** la hora de entrada, el funcionario **no necesita marcar**
al ingresar: el tramo se da por cumplido.

Si la licencia **no arranca exactamente en `hEntrada`** queda un hueco sin
justificar y la marca pasa a ser obligatoria.

| `lEntra` | ¿Cubre entrada? | Sin marcar → |
|---|---|---|
| 07:30 (antes de `hEntrada`) | ✅ sí | ✅ CUMPLE |
| 08:00 (= `hEntrada`) | ✅ sí | ✅ CUMPLE |
| 08:05 | ❌ no | 🔴 **ABANDONO** |

**Cómo se computan las horas.** El tramo licenciado se acredita siempre (recortado
al horario). Lo que queda del horario son uno o dos tramos de trabajo, y cada uno se
acredita solo si existe la marca que lo cierra:

```
licenciado = [lEntra, lSale] ∩ [hEntrada, hSalida]        ← siempre se acredita

tramo previo    = [inicio,  lEntra]   si lEntra > hEntrada   ← necesita marca de ENTRADA
tramo posterior = [lSale,   fin]      si lSale  < hSalida    ← necesita marca de SALIDA

computable = licenciado + tramos acreditados     (tope: hTrabajadas)
```

El **reingreso es implícito en `lSale`**: no se marca al volver del permiso. Lo
que se pierde cuando falta la marca de entrada es solo el tramo previo.

| Licencia | Marcas | licenciado | tramos | Computable | Estado |
|---|---|---|---|---|---|
| 08:00–11:00 | 16:56 | 3h 00m | posterior `11:00→16:00` = 5h | **8h 00m** | ✅ CUMPLE |
| 08:05–11:00 | 16:25 | 2h 55m | previo perdido · posterior 5h | **7h 55m** | 🔴 ABANDONO |
| 13:00–16:00 | 08:05 | 3h 00m | previo `08:00→13:00` = 5h | **8h 00m** | ✅ CUMPLE |
| 13:00–16:00 | — | 3h 00m | previo perdido | **3h 00m** | 🟠 SIN ENTRADA |
| 10:00–12:00 | 08:05 · 16:30 | 2h 00m | previo 2h · posterior 4h | **8h 00m** | ✅ CUMPLE |

### 3.6 Dedupe

Marcas del mismo `ci` separadas por **≤ 2 minutos** se toman como una sola. Los
datos reales traen rebotes a 4-5 segundos:

```
1052243  2026-07-01  07:44:12 A
1052243  2026-07-01  07:44:16 A   ← rebote
```

### 3.7 Horarios nocturnos (`siguienteDia = 1`)

La ventana de salida se busca en `fecha + 1`. Solo es válido si
`hSalida < hEntrada`.

Ejemplo bien configurado (horario `056` real de la BD):

```
LUN 18:00 → 07:00 · tol 18:10 · entrada [16:00–21:00]
                    sTol 07:10 · salida  [06:00–10:00] (día siguiente)
siguienteDia = 1 · hTrabajadas = 13,00
```

---

## 4. Batería de casos — horario `08:00–16:00`, salida `[16:00–20:00]`

| # | Entrada | Salida | Entrada → | Salida → | Estado | Computable |
|---|---|---|---|---|---|---|
| 1 | 07:30 | 16:59 | ✅ puntual | ✅ completa | CUMPLE | 8h 00m |
| 2 | 08:05 | 16:05 | ✅ puntual | ✅ completa | CUMPLE | 8h 00m |
| 3 | 08:10 | 16:00 | ✅ borde tol | ✅ borde | CUMPLE | 8h 00m |
| 4 | 08:25 | 16:50 | ⚠️ atraso 25 min | ✅ completa | ATRASO | 7h 35m |
| 5 | 08:59 | 16:00 | ⚠️ atraso 59 min | ✅ completa | ATRASO | 7h 01m |
| 6 | 09:15 | 16:10 | ❌ > `eMaxima` | ✅ completa | SIN ENTRADA VÁLIDA | 0h |
| 7 | 06:59 | 16:00 | ❌ < `eMinima` | ✅ completa | SIN ENTRADA VÁLIDA | 0h |
| 8 | 07:50 | 15:40 | ✅ puntual | ❌ < `sMinima` | 🔴 ABANDONO | 0h |
| 9 | 08:12 | 20:08 | ⚠️ atraso 12 min | ❌ > `sMaxima` | SIN SALIDA | 0h |
| 10 | 07:50 | 19:45 | ✅ puntual | ✅ dentro | CUMPLE (+3h45 fuera de horario) | 8h 00m |
| 11 | — | — | ❌ | ❌ | 🔴 FALTA | 0h |
| 12 | 07:44:12 + :16 | 16:10:51 + :56 | dedupe → 07:44 | dedupe → 16:10 | CUMPLE | 8h 00m |
| 13 | 07:59 / 08:29 | 17:56 | ✅ 07:59 puntual (08:29 ignorada) | ✅ completa | CUMPLE | 8h 00m |
| 14 | 07:58 / 08:05 / 13:00 | 18:45 | ✅ 07:58 (resto ignoradas) | ✅ completa | CUMPLE | 8h 00m |

Casos con licencia parcial (`08:00–11:00`, única marca `16:56`):

| Tramo | Marca | Resultado |
|---|---|---|
| Entrada 08:00 | — | ✅ cubierta por licencia |
| 08:00–11:00 | — | ✅ 3h licencia |
| 11:00–16:00 | — | ✅ 5h trabajo |
| Salida 16:00 | 16:56 | ✅ correcta |
| **Total** | | ✅ **CUMPLE — 8h 00m** |

Misma licencia pero desde `08:05` (no cubre `hEntrada`), única marca `16:25`:

| | |
|---|---|
| Entrada | 🔴 **ABANDONO** — debía marcar entre 07:00 y 08:05 |
| Licencia 08:05–11:00 | 2h 55m |
| 11:00–16:00 | 5h 00m |
| Salida 16:25 | ✅ correcta |
| Computable | **7h 55m** — déficit −5 min |
| **ESTADO** | 🔴 **ABANDONO** |

---

## 5. Doble horario el mismo día

Un funcionario puede tener dos filas de `asignacion_horarios` para el mismo `dia`.

**Horario A** `LUN 08:00–12:00` · tol `08:10` · entrada `[07:00–10:00]` · salida `[12:00–13:45]` · 4h
**Horario B** `LUN 14:00–18:00` · tol `14:10` · entrada `[13:15–15:00]` · salida `[17:15–23:59]` · 4h

```
07:00 ═ 08:00 ─ 08:10 ═ 10:00 ▓▓▓ 12:00 ═ 13:45   13:15 ═ 14:00 ─ 14:10 ═ 15:00 ▓▓▓ 17:15 ═════ 23:59
└──── ENTRADA A ────────────┘      └─ SALIDA A ─┘   └──── ENTRADA B ───────────┘      └─ SALIDA B ──┘
```

### Ejemplo 1 — marcas `08:00 09:01 12:42 14:36 18:53`

| Marca | Cae en | Rol | Resultado |
|---|---|---|---|
| 08:00 | entrada A | 🅰 Entrada A | ✅ PUNTUAL |
| 09:01 | entrada A | ➖ ignorada | ya había entrada A |
| 12:42 | salida A | 🅰 Salida A | ✅ CORRECTA |
| 14:36 | entrada B | 🅱 Entrada B | ⚠️ ATRASO 36 min |
| 18:53 | salida B | 🅱 Salida B | ✅ CORRECTA |

| | Horario A `08:00–12:00` | Horario B `14:00–18:00` |
|---|---|---|
| Entrada | **08:00** ✅ puntual | **14:36** ⚠️ atraso |
| Salida | **12:42** ✅ | **18:53** ✅ |
| Atraso | 0 min | **36 min** (`14:36 − 14:00`) |
| Salida anticipada | 0 min | 0 min |
| Permanencia | 4h 42m | 4h 17m |
| Computable | `12:00 − 08:00` = **4h 00m** | `18:00 − 14:36` = **3h 24m** |
| Esperado | 4h 00m | 4h 00m |
| Déficit | 0 | **−36 min** |
| Estado | ✅ **CUMPLE** | ⚠️ **ATRASO** |

| Total del día | |
|---|---|
| Marcas útiles | 4 de 5 (09:01 descartada) |
| Atraso total | **36 min** |
| Computable | **7h 24m** |
| Esperado | 8h 00m |
| Déficit | **−36 min** |
| **ESTADO DÍA** | ⚠️ **ATRASO — 1 de 2 horarios con retraso** |

### Ejemplo 2 — marcas `08:12 11:08`

| | Horario A `08:00–12:00` | Horario B `14:00–18:00` |
|---|---|---|
| Entrada | **08:12** ⚠️ atraso | ❌ ninguna |
| Salida | ❌ ninguna válida — 11:08 cae antes de `sMinima 12:00` | ❌ ninguna |
| Atraso | **12 min** (`08:12 − 08:00`) | — |
| Salida anticipada | — | — |
| Permanencia | 2h 56m (no acreditable) | — |
| Computable | **0h 00m** | **0h 00m** |
| Esperado | 4h 00m | 4h 00m |
| Déficit | **−4h 00m** | **−4h 00m** |
| Estado | 🔴 **ABANDONO** | 🔴 **FALTA** |

| Total del día | |
|---|---|
| Marcas útiles | 1 de 2 |
| Atraso total | **12 min** |
| Computable | **0h 00m** |
| Esperado | 8h 00m |
| Déficit | **−8h 00m** |
| **ESTADO DÍA** | 🔴 **ABANDONO + FALTA — 0 de 2 horarios cumplidos** |

### Solape entre horarios del mismo día

```
Horario A salida  [12:00 ──────── 13:45]
Horario B entrada        [13:15 ──────── 15:00]
                        └──┬──┘
                     13:15–13:45 SOLAPADAS
```

Una marca a las 13:30 es ambigua. **Regla propuesta** (sin confirmar): dentro del
solape, la marca se asigna al horario que aún no tenga cubierto ese rol.

---

## 6. Validaciones que debería exigir el formulario de horarios

| # | Regla |
|---|---|
| 1 | `eMinima < hEntrada ≤ hTolerancia ≤ eMaxima` |
| 2 | `sMinima ≤ sTolerancia ≤ hSalida ≤ sMaxima` |
| 3 | ventana de entrada y ventana de salida **no se solapan** |
| 4 | `sMinima < sTolerancia` (si son iguales, la banda de salida anticipada mide cero) |
| 5 | `siguienteDia = 1` ⟹ `hSalida < hEntrada`; `siguienteDia = 0` ⟹ `hSalida > hEntrada` |
| 6 | `hTrabajadas ≈ hSalida − hEntrada` (±tolerancia) |

### Defectos ya detectados en los 757 horarios de la BD

| Horario | Problema |
|---|---|
| `0MH` | `hTrabajadas = 0.0000` con horario real 09:00–13:00 |
| `0ZX` | `hEntrada 06:00` pero `hTolerancia 10:10`; `sMinima 17:00 > hSalida 16:00` |
| varios | `sMinima == sTolerancia` → salir 1 min antes cae en zona muerta, no se reporta como anticipada |

El procesador debe **validar el horario antes de evaluar** y marcar la fila como
«horario mal configurado» en vez de emitir un resultado falso.

---

## 7. Basura de datos a filtrar

| Problema | Detalle |
|---|---|
| Fechas RTC futuras | 637 filas en 2103, 20 en 2064, 18 en 2031 |
| Fechas epoch | 1.764 filas en 1970, 1 en 1900 |
| Rebotes de marca | dobles marcas a 4-5 segundos |
| Motivos sin normalizar | `LLUVIA TORRENCIAL` / `LLUVIA TORRENCIAKL`, `Mantenimiento reloj` / `MANTEMIENTO RELOJ` |

`App\Services\RegistroAsistencia` ya descarta fechas futuras al insertar, pero
**lo migrado del SIA las trae igual** → hay que filtrar en el reporte.

---

## 8. Pendientes de decisión

| # | Tema | Opciones |
|---|---|---|
| 1 | Significado de `tipo = 'A'` (1.8M filas) | segundo equipo / marcación automática / investigar en el SIA |
| 2 | Alcance del reporte | un funcionario × rango · todos × rango · ambos |
| 3 | Persistencia | al vuelo (sin tabla) · tabla `asistencias_procesadas` |
| 4 | ¿El ABANDONO anula todo el día (0h) o se mantienen las horas con la marca encima? | — |
| 5 | Horas de un día excepcional | acreditar `hTrabajadas` del horario · 0h con solo la etiqueta |
| 6 | Marcas de un día excepcional | mostrarlas como referencia · ocultarlas |
| 7 | Marca ambigua en solape de horarios | regla automática · marcar para revisión manual |
| 8 | Solape de rangos en `asignacion_horarios` | desempate por `desde` más reciente (propuesto) |
| 9 | `siguienteDia` mal configurado | validación dura que no deja guardar · aviso |

---

## 9. Piezas existentes reutilizables

| Pieza | Para qué |
|---|---|
| `app/Http/Controllers/ReporteMarcacionController.php` | patrón selección + generación (pantalla / print / CSV) |
| `resources/views/reportes/marcaciones/sinProcesar/` | 3 vistas (`report`, `lista`, `print`) |
| `ReporteMarcacionController::buscarFuncionarios()` | combo select2 con fallback Mamoré → local |
| `AsignacionHorario::scopeVigenteEn()` | resuelve `desde ≤ fecha ≤ hasta` |
| `AsignacionHorario::scopeDelFuncionario()` | orden por día y hora de entrada, con join a `horarios` |