# Sistema de Control de Asistencia (SisMark)

**Guía del sistema: qué hace, cómo funciona y con qué reglas**

Gobierno Autónomo Departamental del Beni — Órgano Ejecutivo
Versión del 26/10/2026

---

## 1. Qué es y para qué sirve

El Sistema de Control de Asistencia responde, para cada funcionario y cada día, una
sola pregunta: **¿cumplió su jornada?**

Para eso junta cuatro cosas:

1. **Las marcaciones** que cada persona hace con su huella en los relojes biométricos.
2. **Su contrato**: si ese día era funcionario.
3. **Su turno**: qué días y en qué horario tenía que trabajar.
4. **Las excepciones**: sus licencias y permisos, y los feriados o días sin control.

Con eso calcula, día por día, si la persona **cumplió**, llegó con **atraso**, se fue
antes (**abandono**), le faltó una marca o **faltó**. Recursos Humanos lo consulta en
pantalla, lo imprime con firma o lo baja en Excel.

### Lo que el sistema **no** hace

- **No administra el personal.** Las altas, bajas, cargos y contratos se hacen en
  **Mamoré**. El Sistema de Control de Asistencia los consulta, pero no los cambia.
- **No liquida sueldos** ni aplica descuentos.
- **No emite sanciones.** Muestra lo que pasó; la decisión es de quien corresponda.

---

## 2. Con qué otros sistemas trabaja

| Sistema | Qué aporta | Cómo se relaciona |
|---|---|---|
| **Mamoré** (Datos Personales) | Quién es cada funcionario, su foto, su cargo, su dirección y **sus contratos** | El sistema le pregunta cada vez que necesita esos datos. A su vez, Mamoré le pide al sistema la asistencia de cada funcionario para mostrársela en su perfil y le envía las licencias que el funcionario solicita desde allí |
| **Relojes biométricos** | Las marcaciones de huella | El sistema copia las marcaciones de cada reloj en los días y horas que se configuren. **Nunca las borra del reloj** al copiarlas |
| **SIA** (sistema anterior) | La historia anterior: marcaciones, horarios, licencias y feriados de años pasados | Se trajo una sola vez. Ya no se usa para trabajar |

**Quién manda en cada dato:**

| Dato | Lo decide |
|---|---|
| Identidad, cargo, foto y contratos | Mamoré |
| Marcaciones | El reloj (el sistema guarda una copia) |
| Turnos, licencias, feriados y la asistencia calculada | El Sistema de Control de Asistencia |

---

## 3. El recorrido: de la huella al reporte

```
  1. El funcionario pone la huella en el reloj
                     │
  2. El sistema copia las marcaciones del reloj (automático, en los horarios fijados)
                     │
  3. Para cada día se pregunta, en este orden:
        ¿tenía contrato ese día?        → si no: «Sin contrato», no se controla
        ¿es feriado o día sin control?  → si sí: «Día excepcional», no se controla
        ¿tenía turno ese día?           → si no: «No laborable» (fin de semana, etc.)
        ¿tenía licencia de día entero?  → si sí: «Licencia», no se controla
        recién ahí se miran sus marcaciones contra su horario
                     │
  4. El resultado del día: cumplió · atraso · abandono · falta · le faltó una marca
                     │
  5. Recursos Humanos lo ve en pantalla, lo imprime o lo baja en Excel
```

**El cálculo no se guarda: se hace cada vez que se pide el reporte.** Por eso, si
después se aprueba una licencia o se corrige un turno, el reporte de esos días cambia
solo, sin tener que «reprocesar» nada.

---

## 4. Los módulos

Lo que cada persona ve en el menú depende de los **permisos de su rol** (ver
sección 6). El Administrador ve todo.

### 4.1 Escritorio

La pantalla de inicio. Muestra avisos que piden atención (por ejemplo, un reloj fuera
de línea), los números del día y accesos rápidos a lo más usado.

### 4.2 Funcionarios

El listado de todo el personal, con los datos que llegan de Mamoré: foto, nombre,
cédula, cargo, dirección y tipo de contrato. Se busca por **cédula o nombre** y se
puede filtrar por:

- **con contrato o sin contrato**, y por tipo de contrato (permanente, eventual…);
- **con turnos o sin turnos**: la lista de a quién todavía le falta asignar un turno.

**La ficha del funcionario** reúne todo lo de una persona:

- **Datos personales** (foto, cédula, fecha de nacimiento, profesión, contacto).
- **Contrato vigente** (cargo, dirección, unidad y hasta cuándo vale).
- Cuatro pestañas:

| Pestaña | Qué muestra |
|---|---|
| **Marcaciones** | Todas sus marcas del reloj, tal como llegaron, por rango de fechas |
| **Licencias** | Sus permisos y licencias del mes, con su estado, y cuánto le queda de su tope de permisos |
| **Turnos/Horarios** | Sus turnos agrupados por contrato, con el horario de cada día y su tolerancia |
| **Asistencia procesada** | Su mes día por día: entradas, salidas, atrasos, faltas y licencias |

Desde la ficha también se puede registrar una licencia, asignar o concluir un turno y
cargar una marcación manual.

### 4.3 Licencias

Todas las licencias y permisos del personal. Desde aquí se anotan nuevas (para una
persona, varias, una dirección entera o todo el personal) y se **aprueban o
rechazan** las pendientes, de a una o varias juntas. Arriba, en la barra, un aviso
dice cuántas solicitudes esperan decisión.

### 4.4 Marcaciones

Todas las marcaciones, con filtros. Además de las que llegan solas de los relojes, se
pueden cargar:

- **Por archivo**: el que se baja de un reloj por USB, cuando un reloj no tiene red.
- **De a una, a mano**: para lo que el reloj no registró. Queda identificada como
  marcación manual, con quién la cargó.

### 4.5 Parámetros

| Opción | Qué es |
|---|---|
| **Días excepcionales** | Feriados y días en que no se controla la asistencia (paro, lluvia, mantenimiento del reloj…), con el decreto o la resolución adjunta |
| **Turnos** | Las jornadas de la semana: qué días se trabaja y en qué horario. Un turno puede ofrecerse en Mamoré para elegirlo al dar de alta un contrato |
| **Turnos asignados** | Qué turno tiene cada funcionario y desde cuándo hasta cuándo |

### 4.6 Reporte de marcaciones

| Reporte | Para qué sirve |
|---|---|
| **Sin procesar** | Las marcas tal cual del reloj de un funcionario en un rango, como el sistema anterior |
| **Procesado** | La asistencia de un funcionario día por día: horario, entrada, salida, atraso, abandono, falta y licencias, con los totales |
| **Por dirección** | Un renglón por funcionario de una dirección o unidad, con sus totales del rango. Se puede dejar solo a quien tuvo atrasos, faltas o licencias |
| **Sin turno** | Quién tiene contrato firmado y hoy no tiene turno: **a esas personas no se les está controlando la asistencia** |

Todos se pueden ver en pantalla, **imprimir** (con encabezado institucional, firmas y
un código QR para verificar el documento) o **bajar en Excel**.

### 4.7 Biométricos

Los relojes: su dirección en la red y cuándo se copian sus marcaciones (qué días y a
qué horas). Desde aquí se prueba la conexión, se copian las marcaciones a mano y, con
un permiso especial, se vacía la memoria de un reloj.

La **Bitácora** de biométricos registra todo lo que se hizo con cada reloj: quién lo
sincronizó, cuántas marcaciones tenía, cuántas llegaron, si la copia salió completa,
y quién importó un archivo o vació un reloj y por qué.

### 4.8 Usuarios, Roles y Actividad de usuarios

| Opción | Qué es |
|---|---|
| **Usuarios** | Quién puede entrar al sistema. Cada usuario tiene su historial de cambios. Se puede inhabilitar a alguien sin borrarlo |
| **Roles** | Grupos de permisos: qué puede ver y hacer cada tipo de usuario (sección 6) |
| **Actividad de usuarios** | Quién entró, cuándo y desde dónde, quién intentó entrar sin éxito, qué fichas miró, qué buscó y qué reportes sacó (sección 7) |

### 4.9 Tokens de API

Las llaves con las que otros sistemas (hoy, Mamoré) se conectan para consultar la
asistencia. Cada llave se puede apagar en el acto si hiciera falta.

### 4.10 Configuración

Los parámetros del sistema; hoy, el **tope mensual de permisos personales** y cómo se
cuenta. Cada cambio rige **desde un mes** en adelante, lleva **motivo obligatorio** y
queda en un historial: nunca se pisa lo anterior.

### 4.11 Ayuda

El botón verde **«Ayuda»**, arriba a la derecha, abre videos cortos que muestran paso
a paso cómo hacer cada trámite: localizar un funcionario, crear y asignar turnos,
cambiar de turno, registrar licencias y sacar los reportes. Cada usuario ve solo los
videos de lo que su rol le permite hacer.

---

## 5. Las reglas del negocio

### 5.1 Cómo se decide cada día

Para cada funcionario y cada día, el sistema se pregunta en este orden, y **la
primera respuesta que aplica es la que vale**:

| Orden | Pregunta | Si se cumple, el día queda como |
|---|---|---|
| 1 | ¿Ese día **no** tenía contrato? | **Sin contrato** — no se controla. Sus marcas no se pierden: quedan en la pestaña Marcaciones |
| 2 | ¿Es feriado o día sin control? | **Día excepcional** — no se controla |
| 3 | ¿No tenía turno ese día? | **No laborable** — no es falta (fines de semana, por ejemplo) |
| 4 | ¿Tenía licencia de día entero? | **Licencia** — no se controla |
| 5 | Si no, se miran sus marcaciones | Cumplió, atraso, abandono, falta o le faltó una marca |

**El contrato es lo primero.** Si una persona no tenía contrato ese día, no se le
exige nada aunque tenga turno y haya marcado.

### 5.2 Los resultados posibles de un día

| Resultado | Qué significa |
|---|---|
| **Cumplió** | Entró y salió en hora |
| **Atraso** | Entró después de la tolerancia |
| **Abandono** | Se retiró antes de su hora de salida |
| **Sin entrada / Sin salida** | Le faltó una de las dos marcas |
| **Falta** | No marcó ni la entrada ni la salida |
| **Licencia** | Tenía licencia o permiso aprobado |
| **Día excepcional** | Feriado o día sin control |
| **No laborable** | No tenía turno ese día |
| **Sin contrato** | Ese día no era funcionario |

### 5.3 Atraso y tolerancia

Cada horario tiene una **hora de entrada** y una **tolerancia** (normalmente 10 minutos).

- Dentro de la tolerancia **no hay atraso**. Con entrada 08:00 y tolerancia de 10
  minutos, quien marca a las 08:10 (incluso 08:10:59) llegó a tiempo.
- Pasada la tolerancia, el atraso se cuenta **desde la hora de entrada**, en minutos
  completos: quien marca a las 08:11 tiene **11 minutos** de atraso, no 1.
- Llegar dentro de la tolerancia cuenta como llegar en hora. Quedarse de más **no
  suma** tiempo a favor.

### 5.4 Qué marca vale como entrada y cuál como salida

El reloj no sabe si una marca es de entrada o de salida: lo decide el sistema por la
hora. Cada horario tiene una **ventana de entrada** y una **ventana de salida**. Por
ejemplo, para un turno de 07:30 a 15:30:

- Vale como **entrada** una marca entre las 06:30 y las 09:30. Se toma la **primera**.
- Vale como **salida** una marca entre las 15:30 y las 17:30. Se toma la **última**.
- Una marca a media mañana, fuera de las dos ventanas, no cuenta para ninguna.

Las ventanas se calculan solas al crear el turno y solo se cambian si hace falta.

### 5.5 Jornada partida

Si un día tiene dos horarios (mañana y tarde), **cada uno se controla por separado**,
con su propia entrada y salida. El resultado del día es **el más grave de los dos**, y
los minutos de atraso de ambos se suman. Por ejemplo: si cumplió a la mañana pero no
volvió a la tarde, ese día figura como **falta**.

### 5.6 Turnos

- **Un turno es la jornada de la semana**: por ejemplo «lunes a viernes de 07:30 a
  15:30». Se crea una vez y se asigna a muchas personas.
- **Un turno no se edita.** Para otra jornada se crea otro turno. Así, lo ya
  controlado con el turno anterior nunca cambia.
- **Todo turno asignado va atado a un contrato.** Empieza hoy (o cuando empieza el
  contrato, si es futuro) y **termina cuando termina el contrato**. La fecha de fin no
  se elige: es la del contrato.
- **Concluir no es eliminar.**
  - **Concluir** es ponerle fecha de fin: la persona dejó ese turno. La historia queda.
  - **Eliminar** es solo para lo que se cargó por error.
- **Cambio de jornada:** se concluye el turno actual y se asigna el nuevo. El sistema
  ofrece solo el tramo que queda libre, **desde el día siguiente** hasta el fin del
  contrato, para que no queden días sin control ni días controlados dos veces.
- **Contratos que ya terminaron sin turno** se pueden **regularizar**: se les asigna
  el turno por los días que pasaron sin control, de a tramos. Esos días se empiezan a
  controlar, y los que no tienen marcación salen como falta.
- **Cuando Mamoré extiende un contrato** con una adenda, el turno del contrato se
  extiende solo. Cuando lo anula, el turno se da de baja solo.
- **Mientras una persona no tenga turno, no se le controla la asistencia.** Para eso
  está el reporte «Sin turno».

### 5.7 Licencias y permisos

**Dos tipos:**

| Tipo | Quién lo dispone | Alcance | ¿Cuenta para el tope mensual? |
|---|---|---|---|
| **Permiso personal** | Lo pide el funcionario para su uso: un trámite, el médico, un asunto familiar | **Siempre por horas** | **Sí** |
| **Licencia institucional** | La dispone la institución: capacitación, comisión, actividad | Día completo o por horas | No |

- Cada licencia indica si es **con o sin goce de haberes** y lleva su **motivo**. Puede
  llevar adjunto un **respaldo** (certificado médico, memorándum…).
- **Toda licencia nace «pendiente» y no justifica nada hasta que se aprueba.** Da
  igual si la anotó Recursos Humanos o la pidió el funcionario desde Mamoré.
- **Aprobar** justifica la ausencia en el cálculo. **Rechazar** exige un motivo, que el
  funcionario lee en su perfil de Mamoré, y **no se revierte**. Una licencia aprobada
  todavía se puede rechazar, con motivo.
- Solo se anota en los días en que la persona **tiene turno**. Si se pide para un
  sábado sin turno, no se anota nada y el sistema lo avisa.
- Se puede licenciar a **una persona, a varias, a una dirección o unidad entera, o a
  todo el personal** (por ejemplo, una actividad institucional). El día completo de
  **todo** el personal se carga como **día excepcional**, no como licencia.

**El tope mensual de permisos personales:**

- Limita cuántas horas de **permiso personal** puede sumar un funcionario en el mes.
  Se fija en Configuración; hoy es de **1 hora 30 minutos por contrato** al mes.
- Se controla **al pedir** (lo aprobado + lo pendiente + lo nuevo) y **al aprobar**. Si
  se pasa, el sistema no lo deja anotar.
- Cada mes arranca de cero. Rechazar o dar de baja un permiso libera ese tiempo.
- El saldo («le queda») se ve en la ficha del funcionario, al anotar y en el perfil de
  Mamoré.

### 5.8 Días excepcionales

Feriados y días en que no se controla la asistencia, con su decreto o resolución.
**Mandan sobre todo lo demás**: ese día no se mira ninguna marcación de nadie.

### 5.9 Marcaciones

- **Una marcación es un hecho**: alguien puso el dedo. Se guarda siempre, aunque la
  persona todavía no esté bien cargada; aparece en sus reportes en cuanto se la dé de
  alta.
- Copiar del reloj **nunca borra** el reloj. Vaciar la memoria de un reloj es una
  acción aparte, con permiso propio, y queda en la Bitácora.
- Copiar dos veces no duplica: lo que ya estaba se reconoce y se descarta.
- Si un reloj estuvo caído, al volver se recupera todo lo que no se había copiado.

### 5.10 Lo que ve el funcionario desde Mamoré

Desde su perfil de Mamoré, cada funcionario puede:

- ver su **asistencia** día por día y su **horario**;
- **pedir permisos y licencias**, que llegan al sistema como pendientes;
- ver el **estado** de sus pedidos y, si se rechazó, el motivo;
- ver cuánto le **queda de su tope** de permisos del mes.

---

## 6. Quién puede hacer qué: roles y permisos

- Cada usuario tiene uno o más **roles**. Cada rol tiene permisos por módulo: ver,
  crear, editar, eliminar, aprobar, exportar…
- Los permisos delicados van **separados**, para poder darlos a personas distintas.
  Por ejemplo:
  - ver reportes es una cosa y **bajarlos en Excel** es otra;
  - anotar licencias es una cosa y **aprobarlas** es otra;
  - administrar un reloj es una cosa y **vaciar su memoria** es otra.
- El **Administrador del sistema** puede todo.
- Un usuario se puede **inhabilitar**: no entra más, pero su historia queda.

---

## 7. Control y seguridad

| Qué | Cómo |
|---|---|
| **Nada se borra de verdad** | Lo que se elimina queda guardado como dado de baja, con quién lo hizo y **por qué** (el motivo es obligatorio) |
| **Bitácora de biométricos** | Todo lo que se hizo con cada reloj |
| **Historial de usuarios** | Cada cambio de cada usuario: antes y después |
| **Actividad de usuarios** | Cada ingreso, intento fallido, salida, ficha mirada, búsqueda y reporte sacado, con fecha, hora y computadora. Nadie la puede editar ni borrar. **Nunca** guarda contraseñas |
| **Límite de intentos** | 5 contraseñas equivocadas seguidas bloquean ese usuario por 15 minutos desde esa computadora |
| **Cambio de contraseña** | Quien recibe una contraseña puesta por otro tiene que cambiarla al entrar |
| **Documentos verificables** | Los reportes impresos llevan un código QR para comprobar que son auténticos |
| **Conexión con otros sistemas** | Cada sistema externo entra con su propia llave, que se puede apagar al instante |

---

## 8. Glosario

| Término | Significa |
|---|---|
| **Marcación** | Cada vez que alguien pone la huella en el reloj |
| **Turno** | La jornada de la semana: qué días y en qué horario |
| **Horario** | Un día del turno, con su entrada, salida y tolerancia |
| **Tolerancia** | Los minutos de gracia después de la hora de entrada |
| **Ventana de marcación** | El rango de horas en que una marca vale como entrada o como salida |
| **Atraso** | Entrar después de la tolerancia; se cuenta desde la hora de entrada |
| **Abandono** | Irse antes de la hora de salida |
| **Falta** | No marcar ni entrada ni salida en un día con turno |
| **Licencia institucional** | La dispone la institución; no cuenta para el tope |
| **Permiso personal** | Lo pide el funcionario, por horas; cuenta para el tope mensual |
| **Tope mensual** | El máximo de horas de permiso personal por mes |
| **Día excepcional** | Feriado o día sin control de asistencia |
| **Concluir un turno** | Ponerle fecha de fin, conservando la historia |
| **Regularizar** | Asignar turno a días de un contrato ya terminado que pasaron sin control |
| **Reporte procesado** | La asistencia ya calculada: las marcas cruzadas con el turno, las licencias y los feriados |
| **Mamoré** | El sistema de Datos Personales: personal, cargos y contratos |
| **Actividad de usuarios** | El registro de quién entró al sistema y qué hizo |
