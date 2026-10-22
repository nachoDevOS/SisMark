# Turnos asignados y regularización

Cómo se le asigna un turno a un funcionario, cómo se regulariza el de un contrato que ya
terminó y qué hace SisMark cuando Mamoré cambia las fechas de un contrato.

Escrito el 21/10/2026. Sesión: `docs/sesiones/10-2026/2026-10-21.md`.

---

## 1. Modelo

| Tabla | Qué guarda |
|---|---|
| `asignacion_turnos` | La **cabecera**: un turno, una cédula, un rango `desde`–`hasta`, el `contrato_id` de Mamoré y la observación |
| `asignacion_horarios` | El **detalle**: una fila por horario del turno (de lunes a viernes = 5 filas), con las mismas fechas y `asignacion_turno_id` |
| `asignacion_horarios` sin `asignacion_turno_id` | **Horarios heredados del SIA**. Son historia: 420.826 filas de 5.218 personas, **todas con `contrato_id` vacío** (no están atadas a ningún contrato de Mamoré) |

- **Todo lo que escribe turnos pasa por `App\Services\AsignadorTurnos`**: la pantalla y la
  API. Cabecera y detalle se mueven siempre juntos, en transacción.
- El procesador, las licencias y los reportes leen `asignacion_horarios`. Sin una fila
  vigente ese día, el procesador resuelve el día como **«no laborable»**: no hay falta,
  atraso ni horas computadas.
- El **contrato** es la primera puerta del procesador: un día que ningún contrato cubre no
  se procesa, aunque tenga turno.
- El procesado **no se guarda**: se calcula al pedir el reporte. Asignar o regularizar un
  turno cambia solos los reportes de esas fechas.
- `Horario::dia`: 1 = Domingo … 7 = Sábado (igual que el SIA). En Carbon es
  `dayOfWeek + 1`.

---

## 2. Qué contratos se pueden elegir

`ContratosFuncionario::asignables()` trae de Mamoré los contratos **firmados y
concluidos** (los borradores `elaborado`/`enviado` no). Cada uno lleva `regularizar`:

| Contrato | `regularizar` | En el modal |
|---|---|---|
| Firmado y vigente, o que todavía no empezó | `false` | Arriba, tarjeta verde «Vigente» / «Empieza el …» |
| Concluido, o firmado con el fin vencido | `true` | Plegado en «Contratos anteriores», una línea por contrato. **Siempre plegado**; se despliega solo si el contrato elegido es uno de ellos (p. ej. la persona no tiene vigente) |

En Mamoré, en la práctica, no existen firmados vencidos: el listado de contratos los pasa
a `concluido` al vencer (`ContractsController`, línea 52). Todo lo terminado es
«concluido».

Orden: primero los vigentes por fecha de inicio; después los terminados, del más reciente
al más viejo.

---

## 3. Contrato vigente: asignar turno

- **Contrato vigente sin turno:** se asigna como siempre (inicio y fin abajo).
- **Contrato vigente con turno — huecos sin turno:** se miran los días **desde su primer
  turno hasta el fin del contrato** con la misma cuenta de tramos que la regularización
  (`AsignadorTurnos::coberturaDelVigente()`) y se ofrece **el hueco más antiguo**, justo
  de punta a punta (inicio y fin exactos, sin encimarse ni dejar días):
  - **Cambio de jornada:** se concluyó el turno antes del fin → del día siguiente al
    fin del contrato. Ejemplo real: 7633685, turno 05/01–30/10 → 31/10–31/12.
  - **Hueco en el medio:** se concluyó un turno antes de que empiece el siguiente → el
    hueco entre los dos. Ejemplo real (22/10): turnos 05/01–22/10 y 31/10–31/12 →
    23/10–30/10, con el fin bloqueado en el 30/10.
  - No mira antes del primer turno: esos días no se ofrecen.
  - Sin huecos, no admite otro («Ese contrato ya tiene el turno …»).
  - El aviso dice «Sin turno del X al Y: el turno nuevo cubre ese tramo.»
- **Inicio propuesto:** hoy si el contrato ya empezó, o su inicio si es futuro. Arrancar
  en el inicio de un contrato que empezó hace meses controlaría hacia atrás días que nadie
  marcó con ese turno.
- **Fin: el del contrato, bloqueado.** Se llena solo y no se edita. El servidor rechaza
  cualquier otro: «La fecha de fin es la del contrato (dd/mm/aaaa).». Solo un contrato
  **abierto** (sin fecha de fin) deja elegirlo.
  - Más allá chocaría con el turno de la renovación; antes dejaría los últimos días sin
    control.
- Si el funcionario cambia de jornada a mitad de contrato: «Concluir» el turno en la
  solapa y asignar el nuevo, que sigue desde el día siguiente.

---

## 4. Contrato terminado: regularizar

Es para quien pasó un contrato **sin turno**, o con parte de él sin control: se le carga
el turno después, para que esos días se controlen.

- **Mismo permiso** que asignar (`Create:AsignacionTurno`). **Sin límite de
  antigüedad.**
- El modal muestra el aviso naranja «Regularización: … los días sin marcación saldrán
  como falta en los reportes» y el botón dice **«Regularizar turno»**.
- La observación se guarda con el prefijo **«Regularización.»** y el mensaje final es
  «Turno regularizado correctamente.».

### 4.1 La cobertura y los tramos

`AsignadorTurnos::periodosAsignados($ci)` trae **todo** lo que la persona tuvo asignado
—horarios del SIA **y** detalle de turnos—, vencido o no, agrupado por período y con los
días de la semana que trabajaba. Se pide **una sola vez** para todos los contratos.

`AsignadorTurnos::cobertura($periodos, $desde, $hasta)` busca los **tramos sin
control** dentro del contrato y devuelve:

| `estado` | Significa | En el modal |
|---|---|---|
| `libre` | No tenía nada asignado en el contrato | «Todo sin control»: se regulariza el contrato entero |
| `parcial` | Tenía algo; quedó al menos un tramo sin control | «Sin control del X al Y» (+N si hay más tramos) |
| `completa` | No queda ningún día sin cubrir | **No se muestra**. El título dice «· N ya cubiertos» |

Reglas de la cuenta:

1. **Se ofrece el tramo más antiguo.** El turno va de `libreDesde` a `libreHasta`, justo
   el hueco: **no pisa** ni corta nada que ya estuviera asignado.
2. **Los demás tramos quedan en `tramosPendientes`.** Al regularizar uno, ese turno cuenta
   como cubierto y al reabrir el modal aparece el siguiente. Cuando no queda ninguno, el
   contrato deja de mostrarse.
3. **Un tramo cuenta solo si tiene un día que la persona trabajaba.** El SIA cortaba un
   período el viernes y arrancaba el siguiente el lunes: ese fin de semana no es un hueco
   para quien trabaja de lunes a viernes. Para quien trabajaba los sábados, sí.
4. **Inicio y fin del tramo bloqueados**: el inicio no puede ser anterior a `libreDesde`
   y el fin tiene que ser `libreHasta` (ayuda «Fin del tramo sin control.»).
5. **Un contrato terminado puede tener varios turnos**, uno por tramo. Por eso al
   regularizar no se aplica «un contrato admite uno solo».

### 4.2 Un solo cálculo para la ficha y la validación

- La ficha (`AsignacionTurnoController::ficha()`) manda en cada contrato terminado
  `cobertura`: `estado`, `motivo`, `libreDesde`, `libreHasta` (y sus textos) y
  `tramosPendientes`.
- La validación (`StoreAsignacionTurnoRequest::validarRegularizacion()`) usa las mismas
  dos funciones.
- La frase la arma `AsignadorTurnos::motivoCobertura()`: el aviso verde del modal y el
  error del servidor dicen **exactamente lo mismo**.

Ejemplos reales (21/10/2026):

| Persona | Contrato | Asignado antes | Resultado |
|---|---|---|---|
| Gabriela Claure, 10811148 | DEGO-037/2026 · 18/06–17/10/2026 | SIA 18/06–17/08 | Sin control del 18/08 al 17/10/2026 |
| Ignacio Molina, 7633685 | 410/2022 · 17/01–30/06/2022 | SIA 16/05–30/06 | Sin control del 17/01 al 15/05/2022 |
| Ignacio Molina, 7633685 | SDAF-35/2023 · 03/01–31/08/2023 | SIA 03/01–23/06 y 26/06–31/12 | Cubierto (el 24–25/06 fue sábado y domingo): no se muestra |

---

## 5. Cuando Mamoré cambia las fechas del contrato

Mamoré llama a `PUT /api/v1/funcionarios/{ci}/turnos` (`AsignacionTurnoApiController::update()`)
desde la edición del contrato, el cambio de estado y las dos rutas de adenda
(`sincronizar_turno_sismark()`).

**Mamoré guarda primero y avisa después.** Si SisMark contesta error, el contrato o la
adenda quedan guardados igual: Mamoré solo agrega el aviso «(la vigencia del turno no se
actualizó en SisMark: …)» y lo anota en el log.

Firmar una adenda **revive** un contrato concluido: vuelve a `firmado` con el fin nuevo.
Una renovación firmada tarde convierte un contrato terminado (y quizá regularizado) en
vigente.

### 5.1 Contrato con turnos: solo se mueve el fin (`moverFin()`)

| Cambio | Qué hace SisMark |
|---|---|
| **Se extiende** | Estira **solo el último turno** (el que termina más tarde) hasta el fin nuevo. Los demás no se tocan |
| **Se acorta** | Los turnos que pasan del fin nuevo terminan ahí; el que empezaba después del fin nuevo se **da de baja** con «El contrato se acortó en Mamoré y este turno quedó fuera de él.» |
| **Cambia el inicio** | Nada. Un día fuera del contrato no se procesa igual; lo que quede sin turno al principio se regulariza a mano |
| La extensión choca con algo posterior (p. ej. horarios del SIA que empiezan después) | 422 con el motivo; no se toca nada |

Antes del 21/10 se copiaban las fechas del contrato a **cada** turno: con dos turnos
chocaban entre sí y nada se movía, y un turno que empezó tarde se corría hacia atrás
(faltas falsas en los reportes).

Ejemplo: contrato 05/01–30/11 con turno 1 (05/01–31/08) y turno 2 (01/09–30/11); adenda
hasta el 31/12 → turno 2 queda 01/09–31/12 y el turno 1 no cambia.

### 5.2 Otros casos

| El contrato tiene… | Con la adenda |
|---|---|
| Horarios sueltos con ese `contrato_id` (contratos de antes de los turnos) | Se mueven `desde` y `hasta` de esas filas (`moverHeredados()`) |
| Solo horarios del SIA (sin `contrato_id`) | **No se extienden**: SisMark no los encuentra |
| Nada atado al contrato | Intenta asignar el turno **sugerido**. Con más de uno sugerido falla: «SisMark tiene varios turnos sugeridos. Indicá cuál asignar.» |

En los dos últimos casos se arregla **a mano una sola vez**: el contrato queda vigente sin
turno y el modal deja asignar (inicio el día siguiente al último horario del SIA, fin el
de la adenda). Desde ahí las adendas lo estiran solas.

Anular el contrato (`DELETE`) da de baja todos sus turnos y sus horarios sueltos.

---

## 6. CI mal cargada en Mamoré

En SisMark la cédula es **solo el número** (`char(12)`). Algunas personas tienen en
Mamoré el complemento pegado en el campo `ci` («10811148 - 1D»). Con eso la ficha no
encuentra sus marcaciones, licencias ni horarios, dice falsamente «no tiene turno», y al
asignar falla con «El campo funcionario no debe tener más de 12 caracteres».

- La ficha de Mamoré muestra la etiqueta naranja **«CI mal cargada en Mamoré»** cuando el
  `ci` no es solo dígitos, con la explicación al pasar el mouse.
- **No se recorta en SisMark**: Mamoré dejaría de encontrar a la persona por el número.
  Lo corrige RRHH en Mamoré.

---

## 7. La solapa «Turnos/Horarios» de la ficha

Decisión del usuario (22/10/2026), después de probar una vista agrupada por contrato y
ajustarla: agrupado por contrato, sin pliegue ni extras.

- **Agrupado por contrato, con flecha para plegar** (el vigente abierto, los concluidos plegados): el contrato se dice una vez
  (código de Mamoré, estado y fechas) y debajo van sus turnos.
- **Cada turno:** cabecera con el turno, las fechas, la situación y, si corresponde,
  «Regularizado», con concluir y eliminar.
- Los horarios del SIA van después, sueltos, con «Sistema anterior» en la cabecera.
- **Debajo, en la tabla, solo los días con su horario.**
- Orden: los que siguen en pie primero, después del más reciente al más viejo. Se pagina
  por período.
- Los códigos se le piden a Mamoré una vez por tabla (`PersonaController::contratosDeLosTurnos()`),
  solo si hay turnos con contrato; si no responde, «Contrato #id».
- El modal de licencias muestra esta misma tabla sin botones.

---

## 8. Dónde está cada cosa

| Archivo | Qué |
|---|---|
| `app/Services/AsignadorTurnos.php` | `conflicto()`, `asignar()`, `concluir()`, `moverVigencia()`, `eliminar()`, `restaurar()`, `periodosAsignados()`, `cobertura()`, `motivoCobertura()` |
| `app/Services/ContratosFuncionario.php` | `asignables()` con `regularizar` |
| `resources/views/funcionarios/horarios-list.blade.php` y `_periodo-horarios.blade.php` | La solapa y el bloque de un período |
| `app/Http/Requests/StoreAsignacionTurnoRequest.php` | `validarContrato()`, `validarRegularizacion()`, `esRegularizacion()` |
| `app/Http/Controllers/AsignacionTurnoController.php` | `ficha()` (JSON del modal), `store()` |
| `app/Http/Controllers/Api/AsignacionTurnoApiController.php` | `store()`, `update()` → `moverFin()`, `destroy()` |
| `resources/views/turnos-asignados/_ficha-asignacion-js.blade.php` | Estado compartido: `elegible()`, `cubierto()`, `contratosLibres()`, `contratosVisibles()`, `nadaQueAsignar()`, `inicioLibre()`, `finLibre()`, `finFijo()`, `regularizando()` |
| `resources/views/components/modal-turno-asignado.blade.php` | Modal de la ficha (vigentes, anteriores plegables, pasos) |
| `resources/views/turnos-asignados/create.blade.php` y `_ficha-asignacion.blade.php` | Pantalla «Turnos asignados → Asignar» |
| `tests/Feature/AsignacionTurnoContratoTest.php` | Contratos, regularización y cobertura |
| `tests/Feature/TurnosApiTest.php` | API de Mamoré (alta, adenda, anulación) |

---

## 9. Pendientes conocidos

1. ~~Cambiar de turno a mitad de un contrato vigente~~ y ~~el vigente sin cuenta de
   tramos~~: resueltos el 21/10 (sección 3).
2. **Días sin control antes del último turno de un contrato vigente** no se ofrecen: solo
   se mira hacia adelante, para no controlar hacia atrás. Raro; se resuelve a mano.
3. **«Concluir» no tiene tope:** acepta una fecha posterior al fin actual y estira el
   turno sin revisar choques ni el contrato.
4. **Eliminar y volver a cargar el mismo turno con el mismo inicio** choca con la única
   `(ci, horario_id, desde)`, que incluye los borrados; la pantalla no tiene «restaurar».
5. **Dos turnos sugeridos:** Mamoré no puede asignar solo el turno de un contrato sin
   nada atado. Con uno solo sugerido lo haría (decisión de RRHH).
