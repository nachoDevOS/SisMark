// Tutorial «Registrar una licencia desde la ficha»: un permiso personal por horas
// para un funcionario, y su aprobación, sin salir de su ficha.
// ESCRIBE EN LA BASE: anota la licencia de CI_DEMO el FECHA_DEMO y la aprueba.
// Uso: ver docs/manual/README.md.
const { abrir, guion } = require('./grabador.cjs');

const CI = process.env.CI_DEMO || '10799999';
const FECHA = process.env.FECHA_DEMO || '2026-10-27';
const FECHA_TEXTO = FECHA.split('-').reverse().join('/');
const MOTIVO = process.env.MOTIVO_DEMO || 'Cita médica en la Caja Nacional de Salud';

guion(async () => {
  const { page, narrar, foco, clic, teclear, escribir, cargado, espera, grabar, terminar } = await abrir();
  const panel = page.locator('[data-panel="licencias"]');

  await grabar();
  await narrar('Presentación', 'Cómo <b>registrar una licencia</b> a un funcionario desde su <b>ficha</b>, y cómo <b>aprobarla</b>.', 4400);

  const funcionarios = page.locator('.sidebar a[href$="/funcionarios"]').first();
  await foco(funcionarios);
  await narrar('Abrir su ficha', 'Entre a <b>Funcionarios</b> y búsquelo por su cédula o nombre.', 3000);
  await clic(funcionarios); await foco(null);
  await page.waitForURL('**/funcionarios');
  await cargado('#div-results');
  const buscar = page.locator('#f-buscar');
  await foco(buscar);
  await clic(buscar);
  await teclear(buscar, CI);
  await buscar.press('Enter');
  await cargado('#div-results');
  const fila = page.locator('#div-results tbody tr', { hasText: CI }).first();
  await fila.waitFor({ timeout: 120000 });
  await foco(fila);
  await narrar('Abrir su ficha', 'Abra su ficha con el <b>ojo</b>.', 2400);
  await clic(fila.locator('a[title="Ver"]')); await foco(null);
  await page.waitForURL('**/funcionarios/mamore/**');
  await page.waitForLoadState('networkidle');
  await cargado('#m-results');

  const solapa = page.locator('.tabs__boton[data-tab="licencias"]');
  await foco(solapa);
  await narrar('Abrir su ficha', 'Vaya a la pestaña <b>Licencias</b>: ahí están todas las del mes.', 3200);
  await clic(solapa);
  await cargado('#l-results');

  // Registrar
  const registrar = panel.locator('button.btn', { hasText: 'Registrar licencia' }).first();
  await foco(registrar);
  await narrar('Registrar la licencia', 'Presione <b>Registrar licencia</b>.', 2400);
  await clic(registrar); await foco(null);
  const modal = panel.locator('.modal-caja', { has: page.locator('h2', { hasText: 'Licenciar a CI' }) }).first();
  await modal.waitFor({ state: 'visible' });
  await page.waitForFunction(() => ! document.querySelector('.modal-licencia__horarios')?.textContent.includes('Cargando…'), null, { timeout: 120000 });
  await espera(800);

  await foco(modal.locator('.modal-licencia__horarios'));
  await narrar('Registrar la licencia', 'Arriba están sus <b>horarios vigentes</b>: la licencia se anota en los días que tiene horario.', 4400);

  const desde = modal.locator('input[name="desde"]');
  const hasta = modal.locator('input[name="hasta"]');
  await foco(desde.locator('xpath=ancestor::div[contains(@class,"grid-2")]'));
  await narrar('Fecha y horas', 'Ponga <b>desde</b> y <b>hasta</b> qué día. Para un solo día, la misma fecha en los dos.', 3800);
  await clic(desde); await desde.fill(FECHA); await espera(700);
  await clic(hasta); await hasta.fill(FECHA); await espera(700);

  const tipo = modal.locator('input[name="tipo"][value="personal"]').locator('xpath=ancestor::div[contains(@class,"campo")][2]');
  await foco(tipo);
  await narrar('Fecha y horas', 'Es un <b>permiso personal</b>: lo pide el funcionario, es <b>por horas</b> y cuenta para su tope del mes.', 4800);

  const entra = modal.locator('input[name="lEntra"]');
  const sale = modal.locator('input[name="lSale"]');
  await foco(entra.locator('xpath=ancestor::div[contains(@class,"grid-2")]'));
  await narrar('Fecha y horas', 'Ponga <b>desde qué hora</b> y <b>hasta qué hora</b> falta.', 2800);
  await clic(entra); await entra.fill('08:00'); await espera(700);
  await clic(sale); await sale.fill('09:00'); await espera(900);

  const goce = modal.locator('input[name="goceHaberes"]').locator('..');
  await foco(goce);
  await narrar('Motivo', '<b>Con goce de haberes</b> viene marcado: se le paga ese tiempo.', 3000);
  const motivo = modal.locator('input[name="motivo"]');
  await narrar('Motivo', 'Escriba el <b>motivo</b>. Si tiene certificado o memorándum, súbalo en <b>Respaldo</b>.', 3200);
  await escribir(motivo, MOTIVO, 55);

  const anotar = modal.locator('button[type=submit]', { hasText: 'Anotar licencia' });
  await foco(anotar);
  await narrar('Motivo', 'Presione <b>Anotar licencia</b>.', 2200);
  await clic(anotar); await foco(null);
  await page.waitForURL('**/funcionarios/mamore/**');
  await page.waitForLoadState('networkidle');
  await cargado('#l-results');
  await espera(800);

  const anotada = page.locator('#l-results tbody tr', { hasText: FECHA_TEXTO }).first();
  await anotada.waitFor({ timeout: 60000 });
  await foco(anotada);
  await narrar('Aprobarla', 'Quedó anotada, pero <b>pendiente</b>: todavía <b>no justifica</b> la ausencia.', 4200);

  // Aprobar
  const resolver = anotada.locator('button[title="Aprobar o rechazar"]');
  await foco(resolver);
  await narrar('Aprobarla', 'Quien tiene permiso de aprobar presiona <b>Aprobar o rechazar</b>.', 3200);
  await clic(resolver); await foco(null);
  const ventana = anotada.locator('.modal-caja', { has: page.locator('h2', { hasText: 'Resolver solicitud' }) });
  await ventana.waitFor({ state: 'visible' });
  await espera(600);
  await foco(ventana);
  await narrar('Aprobarla', 'Se revisa el <b>período</b>, las <b>horas</b> y el <b>motivo</b>. La observación es opcional.', 4200);
  const aprobar = ventana.locator('button[type=submit]', { hasText: 'Aprobar' });
  await foco(aprobar);
  await narrar('Aprobarla', 'Presione <b>Aprobar</b>.', 2000);
  await clic(aprobar); await foco(null);
  await page.waitForURL('**/funcionarios/mamore/**');
  await page.waitForLoadState('networkidle');
  await cargado('#l-results');
  await espera(800);

  await foco(page.locator('#l-results tbody tr', { hasText: FECHA_TEXTO }).first());
  await narrar('Listo', 'Listo: la licencia está <b>aprobada</b> y esas horas ya <b>no cuentan como atraso ni falta</b> en los reportes.', 5400);

  await terminar();
});
