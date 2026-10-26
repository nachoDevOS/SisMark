// Tutorial «Localizar a un funcionario y ver su ficha». Solo lee: no da de alta
// ni modifica nada. Uso: ver docs/manual/README.md.
const { abrir, guion } = require('./grabador.cjs');

const CI = process.env.CI_DEMO || '7633685';

guion(async () => {
  const { page, narrar, foco, clic, teclear, cargado, grabar, terminar } = await abrir();
  const solapa = async (nombre, panel) => {
    const boton = page.locator(`.tabs__boton[data-tab="${nombre}"]`);
    await foco(boton);
    await clic(boton);
    await cargado(panel);
  };

  await grabar();
  await narrar('Presentación', 'Cómo <b>localizar a un funcionario</b> y <b>ver su ficha</b> en el Sistema de Control de Asistencia.', 4200);

  const menu = page.locator('.sidebar a[href$="/funcionarios"]').first();
  await foco(menu);
  await narrar('Entrar a Funcionarios', 'En el menú de la izquierda, entre a <b>Funcionarios</b>.', 2800);
  await clic(menu); await foco(null);
  await page.waitForURL('**/funcionarios');
  await cargado('#div-results');
  await narrar('Entrar a Funcionarios', 'Aquí está el <b>listado del personal</b>, con los datos que llegan de Mamoré.', 3400);

  const buscar = page.locator('#f-buscar');
  await foco(buscar);
  await narrar('Buscar por cédula o nombre', 'Escriba la <b>cédula</b> o el <b>nombre</b> del funcionario en el buscador.', 3400);
  await clic(buscar);
  await teclear(buscar, CI);
  await narrar('Buscar por cédula o nombre', 'Y presione <b>Enter</b> para buscar.', 1600);
  await buscar.press('Enter');
  await cargado('#div-results');
  const fila = page.locator('#div-results tbody tr', { hasText: CI }).first();
  await fila.waitFor({ timeout: 120000 });
  await foco(fila);
  await narrar('Buscar por cédula o nombre', 'Ahí está: su <b>nombre</b>, la <b>cédula</b>, el <b>cargo</b> y la <b>dirección</b> donde trabaja.', 4600);

  const ver = fila.locator('a[title="Ver"]');
  await foco(ver);
  await narrar('Abrir la ficha', 'Para ver toda su información, presione el <b>ojo</b> de la derecha.', 3000);
  await clic(ver); await foco(null);
  await page.waitForURL('**/funcionarios/mamore/**');
  await page.waitForLoadState('networkidle');
  await cargado('#m-results');

  await foco(page.locator('main .cabecera').first());
  await narrar('Abrir la ficha', 'Esta es la <b>ficha del funcionario</b>. Arriba dice si tiene <b>contrato</b> y de dónde vienen sus datos.', 4400);

  await foco(page.locator('main .tarjeta', { has: page.locator('h2', { hasText: 'Datos personales' }) }).first());
  await narrar('Datos personales', 'En <b>Datos personales</b>: foto, cédula, fecha de nacimiento, profesión y contacto.', 4600);

  const contrato = page.locator('main .tarjeta', { has: page.locator('h2', { hasText: 'Contrato vigente' }) }).first();
  if (await contrato.count()) {
    await foco(contrato);
    await narrar('Contrato vigente', 'En <b>Contrato vigente</b>: el cargo, la unidad donde trabaja y <b>hasta cuándo</b> vale el contrato.', 4600);
  }

  await foco(page.locator('.tabs').first());
  await narrar('Marcaciones', 'Abajo, en las <b>pestañas</b>, está su <b>control de asistencia</b>.', 3000);
  await foco(page.locator('#m-results'));
  await narrar('Marcaciones', 'En <b>Marcaciones</b> se ven sus entradas y salidas del reloj, de la más nueva a la más vieja.', 4400);
  await foco(page.locator('#m-desde').locator('..'));
  await narrar('Marcaciones', 'Con las <b>fechas</b> y el botón <b>Buscar</b> se cambia el período que se mira.', 3600);

  if (await page.locator('.tabs__boton[data-tab="licencias"]').count()) {
    await solapa('licencias', '#l-results');
    await foco(page.locator('#l-results'));
    await narrar('Licencias', 'En <b>Licencias</b>, los permisos y licencias del mes elegido.', 3800);
  }

  if (await page.locator('.tabs__boton[data-tab="horarios"]').count()) {
    await solapa('horarios', '#t-results');
    await foco(page.locator('#t-results'));
    await narrar('Turnos y horarios', 'En <b>Turnos/Horarios</b>, el turno que tiene asignado en cada contrato y su horario de cada día.', 4600);
  }

  if (await page.locator('.tabs__boton[data-tab="procesado"]').count()) {
    await solapa('procesado', '#p-results');
    await foco(page.locator('#p-results'));
    await narrar('Asistencia procesada', 'Y en <b>Asistencia procesada</b>, el mes día por día: <b>atrasos</b>, <b>faltas</b> y <b>licencias</b> ya calculados.', 5200);
  }

  const volver = page.locator('main .cabecera a', { hasText: 'Volver' }).first();
  await foco(volver);
  await narrar('Volver al listado', 'Con <b>Volver</b> regresa al listado para buscar a otro funcionario.', 3600);

  await terminar();
});
