# Ayuda (videos tutoriales)

Botón **«Ayuda»** en la barra superior: abre una ventana con videos que
muestran, paso a paso, cómo hacer un trámite, sin salir de la pantalla en la
que se está trabajando. Copiado del de Jichi y pasado a Blade + Alpine.

- **Biblioteca:** una tarjeta por video con su **vista previa en bucle** (se
  apura al pasar el mouse), módulo, cantidad de pasos y «Ver el video» /
  «Continuar desde m:ss» / «Ver de nuevo». Con un solo video se abre directo.
- **Reproductor:** barra de pasos por tramos (se llena y salta), paso anterior y
  siguiente, velocidad 0.75× a 1.5×, lista de pasos (hechos con tilde, el actual
  latiendo), aviso «Seguimos donde lo dejó» y pantalla final.
- **Lo visto se guarda en el navegador** (`localStorage`, `sismark.ayuda.{clave}`).
- Con «menos movimiento» pedido en el sistema operativo no hay vista previa: la
  portada se pasea despacio y las animaciones se apagan.

| Pieza | Dónde |
| --- | --- |
| Catálogo (título, módulo, permisos, capítulos) | `app/Services/ManualUsuario.php` |
| Video y portada, con sesión y por rangos (206) | `app/Http/Controllers/ManualController.php`, ruta `manual.archivo` |
| Botón y ventana | `resources/views/components/manual-usuario.blade.php` (estilos en `layouts/app.blade.php`) |
| Videos, portadas y vistas previas | `resources/manual/{clave}.mp4`, `.jpg` y `.previa.mp4` |
| Pruebas | `tests/Feature/ManualUsuarioTest.php` |

- **El video no va en `public/`**: muestra el sistema por dentro. Cada tutorial
  pide los permisos del trámite que enseña; sin ellos no se lista ni se baja.
- Un tutorial sin su `.mp4` no se ofrece: el botón aparece recién con el primer video.

## Grabar un video

Dos tiempos: `grabar-*.cjs` maneja el sistema con Playwright y guarda cuadros a
4K con un registro de eventos; `componer.py` arma el MP4 1920×1080 a 30 fps y
dibuja encima la **cámara que se acerca al foco**, el recuadro que oscurece el
resto, el cursor, la onda del clic y los subtítulos. Lo común a todos los
guiones (entrar, capturar, foco, clic, subtítulos) está en `grabador.cjs`.

| Guion | Video | ¿Escribe en la base? |
| --- | --- | --- |
| `grabar-localizar-funcionario.cjs` | `localizar-funcionario` | No |
| `grabar-crear-y-asignar-turno.cjs` | `crear-y-asignar-turno` | Sí: crea `TURNO_DEMO` y se lo asigna a `CI_DEMO` |
| `grabar-cambiar-turno.cjs` | `cambiar-turno` | Sí: concluye ese turno el `HASTA_DEMO` y asigna `TURNO_NUEVO`. Va después del anterior |
| `grabar-licencia-desde-ficha.cjs` | `licencia-desde-ficha` | Sí: anota y aprueba un permiso personal por horas de `CI_DEMO` el `FECHA_DEMO` |
| `grabar-licencia-desde-licencias.cjs` | `licencia-desde-licencias` | Sí: anota y aprueba una licencia institucional de horario completo de `CI_DEMO` el `FECHA_DEMO` |
| `grabar-reporte-procesado.cjs` | `reporte-procesado` | No |
| `grabar-reporte-direccion.cjs` | `reporte-direccion` | No |
| `grabar-reporte-sin-turno.cjs` | `reporte-sin-turno` | No |

Las de licencias se grabaron el 26/10/2026 con 10799999 (27/10 de 08:00 a 09:00 y
29/10 completo). Su tope de permisos personales es de 1 h 30 min por mes: un permiso
más largo lo rechaza el servidor.

Los dos de turnos se grabaron el 26/10/2026 en la base de desarrollo con
`CI_DEMO=10799999`. Para regrabarlos hay que deshacer lo que dejaron (el turno
nuevo y las dos asignaciones de esa CI): el turno no se edita y una asignación
borrada choca con la nueva si tiene el mismo turno e inicio.

Con sisMark en `:8002` y Mamoré en `:8001` corriendo. SisMark no tiene
`package.json`: se usa el Playwright de Jichi con `NODE_PATH`. Hace falta
Python con Pillow y `ffmpeg`.

```powershell
$env:NODE_PATH = "D:\garoto\gadbeni\jichi\node_modules"
$env:SALIDA = "$env:TEMP\sismark-manual"
$env:USUARIO = "<correo>"; $env:CLAVE = "<contraseña>"
node docs\manual\grabar-localizar-funcionario.cjs        # ~8 min
python docs\manual\componer.py $env:SALIDA resources\manual\localizar-funcionario.mp4
ffmpeg -y -ss 42 -i resources\manual\localizar-funcionario.mp4 -frames:v 1 -q:v 3 -vf scale=1280:-1 resources\manual\localizar-funcionario.jpg
```

Los segundos de cada paso quedan en `$SALIDA/capitulos.json`: se copian a
`ManualUsuario::TUTORIALES`. La portada se saca de un cuadro que muestre el
trámite: con `-ss 1.5` todas salen iguales (el Escritorio).

### Vista previa de la tarjeta

`python docs\manual\vista-previa.py <clave>` arma `resources/manual/<clave>.previa.mp4`:
1,8 s de cada capítulo (menos la presentación), 640×360, 50–130 KB. Lee los capítulos
de `ManualUsuario.php`: regenerarla cada vez que se regraba el video o cambian sus pasos.

## Por qué así

- **4K de verdad necesita `--force-device-scale-factor=2`.** Con solo
  `deviceScaleFactor: 2` el screencast sale a 1920×1080.
- **Se graba en cámara lenta** (`LENTO=5`): a 4K el screencast da ~3 cuadros/s.
  Todo espera 5× más y `componer.py` divide los tiempos; lo que se mueve
  (cámara, cursor, foco) va a 30 cuadros/s porque se dibuja en la composición.
- **Con la pantalla quieta el screencast no manda cuadros**: `componer.py` hace
  durar el video hasta el último evento del guion, no hasta el último cuadro
  (si no, se corta el subtítulo final).
- **Antes de un guion que escribe, una pasada en seco**: recorrer la pantalla con los
  mismos selectores sin enviar nada. Si el guion se corta a la mitad, deja datos a medias.
- **Mamoré tiene que responder** (`:8001`). Si el listado de Funcionarios dice «No se
  pudo conectar con la API de Mamoré», revisar que no haya varios `artisan serve` en el
  mismo puerto (el 26/10 había tres y se trababan) y dejar uno solo.
- **No grabar mientras se compone otro video**: `componer.py` usa todos los
  núcleos, el servidor de desarrollo se pone lento y el guion se corta esperando.
- **El zoom no se hace en la página**: se recorta del cuadro 4K, así que
  acercar hasta 1,9× no pierde nitidez.
- En local el sidebar sale rojo (`x-entorno-local`): el script quita ese estilo
  y el video muestra los colores de producción.
