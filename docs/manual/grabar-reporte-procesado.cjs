// Tutorial «Reporte procesado de un funcionario»: su asistencia día por día en un
// rango, y cómo imprimirla o bajarla en Excel. Solo lee.
// Uso: ver docs/manual/README.md.
const { abrir, guion } = require('./grabador.cjs');

const CI = process.env.CI_DEMO || '7633685';
const DESDE = process.env.DESDE_DEMO || '2026-10-01';
const HASTA = process.env.HASTA_DEMO || '2026-10-26';

guion(async () => {
  const { page, narrar, foco, clic, teclear, espera, grabar, terminar } = await abrir();
  const resultado = page.locator('div[x-html="resultado"]');

  await grabar();
  await narrar('Presentación', 'Cómo sacar el <b>reporte procesado</b> de un funcionario: su asistencia <b>día por día</b>.', 4200);

  const grupo = page.locator('.sidebar__grouptoggle', { hasText: 'Reporte de marcaciones' }).first();
  await foco(grupo);
  await narrar('Entrar al reporte', 'Abra <b>Reporte de marcaciones</b> en el menú.', 2600);
  await clic(grupo);
  const enlace = page.locator('.sidebar a[href$="/reportes/marcaciones/procesado"]').first();
  await enlace.waitFor();
  await foco(enlace);
  await narrar('Entrar al reporte', 'Y entre a <b>Procesado</b>.', 2000);
  await clic(enlace); await foco(null);
  await page.waitForURL('**/reportes/marcaciones/procesado');
  await page.waitForLoadState('networkidle');

  const tarjeta = page.locator('main .card').first();
  await foco(tarjeta);
  await narrar('Entrar al reporte', 'Cruza sus <b>marcaciones</b> con su <b>horario</b>, los <b>días excepcionales</b> y sus <b>licencias</b>.', 4400);

  const combo = page.locator('#combo-funcionario');
  await foco(combo.locator('..'));
  await narrar('Elegir funcionario y fechas', 'Escriba la <b>cédula</b> o el <b>nombre</b> y elíjalo de la lista.', 3000);
  await clic(combo);
  await teclear(combo, CI);
  const opcion = combo.locator('xpath=..').locator('button', { hasText: CI }).first();
  await opcion.waitFor({ timeout: 120000 });
  await espera(600);
  await clic(opcion);
  await espera(600);

  const desde = page.locator('#desde');
  const hasta = page.locator('#hasta');
  await foco(desde.locator('xpath=ancestor::div[contains(@class,"toolbar")][1]'));
  await narrar('Elegir funcionario y fechas', 'Ponga el <b>rango de fechas</b>: por ejemplo, el mes.', 2800);
  await clic(desde); await desde.fill(DESDE); await espera(600);
  await clic(hasta); await hasta.fill(HASTA); await espera(600);
  const generar = page.locator('main button.btn', { hasText: 'Generar' }).first();
  await foco(generar);
  await narrar('Elegir funcionario y fechas', 'Presione <b>Generar</b>.', 2000);
  await clic(generar); await foco(null);
  await resultado.locator('table').first().waitFor({ timeout: 180000 });
  await espera(1000);

  await foco(resultado.locator('table thead').first());
  await narrar('Leer el reporte', 'Cada fila es un <b>día</b>: su horario, a qué hora <b>entró</b> y <b>salió</b>, y si hubo <b>atraso</b>, <b>abandono</b> o <b>falta</b>.', 5200);
  const conLicencia = resultado.locator('tbody tr', { hasText: 'licencia' }).first();
  if (await conLicencia.count()) {
    await foco(conLicencia);
    await narrar('Leer el reporte', 'Los días con <b>licencia</b> la muestran con su horario y motivo: no cuentan como falta.', 4400);
  }
  const total = resultado.locator('tfoot').first();
  await foco(total);
  await narrar('Leer el reporte', 'Abajo, el <b>total</b> de minutos de atraso del rango.', 3200);

  const botones = resultado.locator('a.btn', { hasText: 'Imprimir' }).first().locator('..');
  await foco(botones);
  await narrar('Imprimir o Excel', 'Con <b>Imprimir</b> sale la hoja para firmar, y con <b>Excel</b> se baja la planilla.', 4400);

  await narrar('Listo', 'Listo. El mismo reporte está en la ficha del funcionario, en la pestaña <b>Asistencia procesada</b>.', 4800);

  await terminar();
});
