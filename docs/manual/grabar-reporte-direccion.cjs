// Tutorial «Reporte por dirección»: todos los funcionarios de una dirección (o una
// de sus unidades) con sus totales del rango. Solo lee.
// Uso: ver docs/manual/README.md.
const { abrir, guion } = require('./grabador.cjs');

const DIRECCION = process.env.DIRECCION_DEMO || 'SDAF';
const UNIDAD = process.env.UNIDAD_DEMO || 'SISTEMAS';
const DESDE = process.env.DESDE_DEMO || '2026-10-01';
const HASTA = process.env.HASTA_DEMO || '2026-10-26';

guion(async () => {
  const { page, narrar, foco, clic, teclear, espera, grabar, terminar } = await abrir();
  const resultado = page.locator('div[x-html="resultado"]');

  await grabar();
  await narrar('Presentación', 'Cómo sacar el reporte de <b>toda una dirección</b> o <b>unidad</b>: un renglón por funcionario con sus totales.', 4600);

  const grupo = page.locator('.sidebar__grouptoggle', { hasText: 'Reporte de marcaciones' }).first();
  await foco(grupo);
  await narrar('Entrar al reporte', 'Abra <b>Reporte de marcaciones</b> y entre a <b>Por dirección</b>.', 3000);
  await clic(grupo);
  const enlace = page.locator('.sidebar a[href$="/reportes/marcaciones/direccion"]').first();
  await enlace.waitFor();
  await clic(enlace); await foco(null);
  await page.waitForURL('**/reportes/marcaciones/direccion');
  const combo = page.locator('#combo-direccion');
  await page.waitForFunction(() => ! document.querySelector('#combo-direccion')?.disabled, null, { timeout: 180000 });
  await espera(800);

  await foco(page.locator('main .card').first());
  await narrar('Entrar al reporte', 'Entra quien tuvo <b>contrato</b> en el rango, aunque hoy ya no esté.', 3600);

  await foco(combo.locator('..'));
  await narrar('Dirección y unidad', 'Escriba y elija la <b>dirección</b>. Cada una dice cuántos funcionarios tiene.', 3600);
  await clic(combo);
  await teclear(combo, DIRECCION);
  const opcion = combo.locator('xpath=..').locator('button', { hasText: DIRECCION }).first();
  await opcion.waitFor({ timeout: 60000 });
  await espera(600);
  await clic(opcion);
  await espera(600);

  const unidad = page.locator('#combo-unidad');
  await unidad.waitFor({ state: 'visible' });
  await foco(unidad.locator('..'));
  await narrar('Dirección y unidad', 'Si quiere, acótelo a una <b>unidad</b>. Sin elegir, entra la dirección entera.', 3800);
  await clic(unidad);
  await teclear(unidad, UNIDAD);
  const opcionUnidad = unidad.locator('xpath=..').locator('button', { hasText: new RegExp(UNIDAD, 'i') }).first();
  await opcionUnidad.waitFor({ timeout: 60000 });
  await espera(600);
  await clic(opcionUnidad);
  await espera(600);

  const desde = page.locator('#desde');
  const hasta = page.locator('#hasta');
  await foco(desde.locator('xpath=ancestor::div[contains(@class,"toolbar")][1]'));
  await narrar('Fechas y filtro', 'El <b>rango de fechas</b>, y en <b>Mostrar</b> puede dejar solo a quien tuvo atrasos, faltas o licencias.', 4600);
  await clic(desde); await desde.fill(DESDE); await espera(600);
  await clic(hasta); await hasta.fill(HASTA); await espera(600);
  const generar = page.locator('main button.btn', { hasText: 'Generar' }).first();
  await foco(generar);
  await narrar('Fechas y filtro', 'Presione <b>Generar</b>. Con muchos funcionarios puede tardar un poco.', 3000);
  await clic(generar); await foco(null);
  await resultado.locator('table').first().waitFor({ timeout: 300000 });
  await espera(1000);

  await foco(resultado.locator('table thead').first());
  await narrar('Leer el reporte', 'Un renglón por <b>funcionario</b>: sus <b>atrasos</b>, <b>abandonos</b>, <b>faltas</b> y <b>licencias</b> del rango.', 5000);
  await foco(resultado.locator('tfoot').first());
  await narrar('Leer el reporte', 'Abajo, los <b>totales</b> de todos.', 2800);

  const botones = resultado.locator('a.btn', { hasText: 'Imprimir' }).first().locator('..');
  await foco(botones);
  await narrar('Imprimir o Excel', '<b>Imprimir</b> para la hoja, <b>Excel</b> para trabajarlo en planilla.', 3800);

  await narrar('Listo', 'Listo: así se revisa de un vistazo la asistencia de toda una dirección.', 4000);

  await terminar();
});
