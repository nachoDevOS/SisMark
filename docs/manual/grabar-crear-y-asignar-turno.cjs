// Tutorial «Crear un turno y asignárselo a quien no tiene»: registra un turno
// nuevo y se lo asigna a un funcionario con contrato y sin turno.
// ESCRIBE EN LA BASE: crea el turno TURNO_DEMO y la asignación de CI_DEMO.
// Uso: ver docs/manual/README.md.
const { abrir, guion } = require('./grabador.cjs');

const CI = process.env.CI_DEMO || '10799999';
const TURNO = process.env.TURNO_DEMO || 'Horario Continuo 07:30 - 15:30';

guion(async () => {
  const { page, narrar, foco, clic, teclear, escribir, elegir, cargado, espera, grabar, terminar } = await abrir();

  await grabar();
  await narrar('Presentación', 'Cómo <b>crear un turno nuevo</b> y <b>asignárselo</b> a un funcionario que todavía no tiene turno.', 4400);

  // Parte 1: el turno
  const parametros = page.locator('.sidebar__grouptoggle', { hasText: 'Parámetros' }).first();
  await foco(parametros);
  await narrar('Entrar a Turnos', 'Los turnos están en <b>Parámetros</b>.', 2400);
  await clic(parametros);
  const turnos = page.locator('.sidebar a[href$="/turnos"]').first();
  await turnos.waitFor();
  await foco(turnos);
  await narrar('Entrar a Turnos', 'Entre a <b>Turnos</b>: es la jornada de la semana que tiene que cumplir el funcionario.', 3600);
  await clic(turnos); await foco(null);
  await page.waitForURL('**/turnos');
  await page.waitForLoadState('networkidle');

  const nuevo = page.locator('main a.btn', { hasText: 'Nuevo turno' }).first();
  await foco(nuevo);
  await narrar('Entrar a Turnos', 'Presione <b>Nuevo turno</b>.', 2400);
  await clic(nuevo); await foco(null);
  await page.waitForURL('**/turnos/create');
  await page.waitForLoadState('networkidle');

  const dias = page.locator('.carga-rapida').first();
  await foco(dias);
  await narrar('Días y horario', 'Primero, <b>qué días</b> trabaja y <b>a qué hora</b> entra y sale.', 3400);
  const lunesAViernes = page.locator('.atajo', { hasText: 'Lunes a viernes' });
  await foco(page.locator('.dias-chips').locator('..'));
  await clic(lunesAViernes);
  await narrar('Días y horario', 'Con <b>Lunes a viernes</b> quedan marcados los cinco días.', 3000);

  await foco(page.locator('.carga-rapida__horas'));
  await narrar('Días y horario', 'Ponga la hora de <b>entrada</b> y la de <b>salida</b>.', 2400);
  const entrada = page.locator('#rapida-entrada');
  await clic(entrada); await entrada.fill('07:30'); await espera(900);
  const salida = page.locator('#rapida-salida');
  await clic(salida); await salida.fill('15:30'); await espera(900);
  await foco(page.locator('.vista-previa').first());
  await narrar('Días y horario', 'Abajo se lee lo que se va a cargar: <b>de lunes a viernes, de 07:30 a 15:30</b>.', 3800);
  await foco(page.locator('.carga-rapida__horas'));
  await clic(page.locator('.carga-rapida__horas button', { hasText: 'Agregar' }));
  await espera(800);

  await foco(page.locator('.semana').first());
  await narrar('Revisar la semana', 'En el paso 3 se ve <b>cómo queda la semana</b>. La tolerancia y las ventanas del reloj <b>ya vienen calculadas</b>.', 5200);

  const nombre = page.locator('#nombre');
  await foco(nombre.locator('..'));
  await narrar('Nombre del turno', 'El <b>nombre</b> se arma solo con el horario. Póngale uno claro, que se reconozca al asignarlo.', 3800);
  await escribir(nombre, TURNO, 70);

  const guardar = page.locator('form button[type=submit]', { hasText: 'Guardar turno' });
  await foco(guardar);
  await narrar('Nombre del turno', 'Revise y presione <b>Guardar turno</b>. Después de guardado <b>no se edita</b>: para otra jornada se crea otro turno.', 4800);
  await clic(guardar); await foco(null);
  await page.waitForURL(/\/turnos\/\d+$/);
  await page.waitForLoadState('networkidle');
  await espera(600);
  await foco(page.locator('main').first());
  await narrar('Nombre del turno', 'Listo: el turno <b>quedó registrado</b> con sus horarios.', 3400);

  // Parte 2: a quién le falta turno
  const funcionarios = page.locator('.sidebar a[href$="/funcionarios"]').first();
  await foco(funcionarios);
  await narrar('Buscar a quien no tiene turno', 'Ahora, a quién asignárselo. Entre a <b>Funcionarios</b>.', 3000);
  await clic(funcionarios); await foco(null);
  await page.waitForURL('**/funcionarios');
  await cargado('#div-results');

  const filtros = page.locator('.tabla-filtros__extra').first();
  await foco(filtros);
  await narrar('Buscar a quien no tiene turno', 'Elija <b>Todos con contrato</b> y, en el que aparece al lado, <b>Sin turnos</b>.', 3600);
  await elegir(page.locator('#f-contrato'), 'con');
  await page.locator('#f-horario').waitFor({ state: 'visible' });
  await espera(600);
  await elegir(page.locator('#f-horario'), 'sin');

  const buscar = page.locator('#f-buscar');
  await foco(buscar);
  await narrar('Buscar a quien no tiene turno', 'Si ya sabe a quién, escriba su <b>cédula</b>. Si no, la lista muestra a <b>todos los que no tienen turno</b>.', 4200);
  await clic(buscar);
  await teclear(buscar, CI);
  await clic(page.locator('#btn-buscar'));
  await espera(300);
  await cargado('#div-results');
  const fila = page.locator('#div-results tbody tr', { hasText: CI }).first();
  await fila.waitFor({ timeout: 120000 });
  await foco(fila);
  await narrar('Buscar a quien no tiene turno', 'Ahí está, con su contrato, y <b>sin turno</b>: hoy <b>no se le controla la asistencia</b>.', 4400);

  // Parte 3: asignar
  const asignar = fila.locator('a[title="Asignar turno"]');
  await foco(asignar);
  await narrar('Asignar el turno', 'Presione el <b>calendario</b> para asignarle turno.', 2800);
  await clic(asignar); await foco(null);
  await page.waitForURL('**/turnos-asignados/create**');
  await page.locator('.contrato-opcion').first().waitFor({ timeout: 120000 });
  await espera(800);

  await foco(page.locator('.contrato-opcion').first());
  await narrar('Asignar el turno', 'Ya viene el funcionario y su <b>contrato vigente</b>: el turno queda atado a ese contrato.', 4400);

  const opcion = page.locator('.turno-opcion', { hasText: TURNO }).first();
  await foco(opcion);
  await narrar('Asignar el turno', 'Elija el <b>turno</b> que acaba de crear.', 2600);
  await clic(opcion.locator('.turno-opcion__nombre'));
  await espera(600);

  await foco(page.locator('#desde').locator('xpath=ancestor::div[contains(@class,"grid-2")]'));
  await narrar('Fechas', 'Empieza <b>hoy</b> y termina con el <b>contrato</b>: la fecha de fin viene fija y no se cambia.', 4400);

  const resumen = page.locator('.tarjeta', { hasText: 'Resumen' }).first();
  await foco(resumen);
  await narrar('Fechas', 'El <b>resumen</b> dice exactamente qué se va a guardar.', 3400);

  const boton = page.locator('form button[type=submit]', { hasText: 'Asignar turno' });
  await foco(boton);
  await narrar('Fechas', 'Presione <b>Asignar turno</b>.', 2200);
  await clic(boton); await foco(null);
  await page.waitForURL('**/turnos-asignados?**');
  await page.waitForLoadState('networkidle');
  await cargado('#div-results');
  await espera(800);

  const asignada = page.locator('main tbody tr', { hasText: CI }).first();
  await foco(asignada);
  await narrar('Listo', 'Quedó <b>asignado</b>: desde hoy se le controla la asistencia con ese turno.', 4800);

  await terminar();
});
