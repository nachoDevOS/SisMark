// Tutorial «Licenciar desde la pantalla Licencias»: una licencia institucional de
// horario completo a un funcionario, y su aprobación desde el listado.
// ESCRIBE EN LA BASE: anota la licencia de CI_DEMO el FECHA_DEMO y la aprueba.
// Uso: ver docs/manual/README.md.
const { abrir, guion } = require('./grabador.cjs');

const CI = process.env.CI_DEMO || '10799999';
const FECHA = process.env.FECHA_DEMO || '2026-10-29';
const FECHA_TEXTO = FECHA.split('-').reverse().join('/');
const MOTIVO = process.env.MOTIVO_DEMO || 'Capacitación en el uso del sistema de asistencia';

guion(async () => {
  const { page, narrar, foco, clic, teclear, escribir, cargado, espera, grabar, terminar } = await abrir();

  await grabar();
  await narrar('Presentación', 'Cómo <b>licenciar</b> desde la pantalla <b>Licencias</b>: sirve para uno, varios, una dirección o todos.', 4600);

  const menu = page.locator('.sidebar a[href$="/licencias"]').first();
  await foco(menu);
  await narrar('Nueva licencia', 'Entre a <b>Licencias</b> en el menú.', 2600);
  await clic(menu); await foco(null);
  await page.waitForURL('**/licencias');
  await cargado('#div-results');
  const nueva = page.locator('main a.btn', { hasText: 'Nueva licencia' }).first();
  await foco(nueva);
  await narrar('Nueva licencia', 'Presione <b>Nueva licencia</b>.', 2400);
  await clic(nueva); await foco(null);
  await page.waitForURL('**/licencias/create**');
  await page.waitForLoadState('networkidle');

  // Cuándo
  const cuando = page.locator('.card', { has: page.locator('h2', { hasText: '¿Cuándo?' }) }).first();
  await foco(cuando);
  await narrar('Cuándo', 'Primero, <b>cuándo</b>: hoy, mañana, un día o un rango de fechas.', 3400);
  await clic(page.locator('.cuando-opcion', { hasText: 'Un día' }));
  const desde = page.locator('#desde');
  await clic(desde); await desde.fill(FECHA); await espera(900);
  await foco(page.locator('.cuando-resumen'));
  await narrar('Cuándo', 'Abajo confirma la fecha elegida.', 2600);

  // A quién
  const quien = page.locator('.card', { has: page.locator('h2', { hasText: '¿A quién se licencia?' }) }).first();
  await foco(quien);
  await narrar('A quién', 'Después, <b>a quién</b>. Para una persona, deje <b>Un funcionario</b>.', 3400);
  const combo = page.locator('#combo-funcionario');
  await clic(combo);
  await teclear(combo, CI);
  const opcion = page.locator('button', { hasText: CI }).filter({ has: page.locator('span') }).first();
  await opcion.waitFor({ timeout: 120000 });
  await espera(600);
  await foco(opcion);
  await narrar('A quién', 'Elíjalo de la lista: solo aparece quien tuvo <b>contrato</b> esa fecha.', 3400);
  await clic(opcion); await foco(null);
  await page.waitForURL(/licencias\/create\?ci=/);
  await page.waitForLoadState('networkidle');
  await espera(800);

  const horarios = page.locator('.card', { has: page.locator('h2', { hasText: 'Horarios' }) }).first();
  await foco(horarios);
  await narrar('A quién', 'Se cargan sus <b>horarios</b>: la licencia cae en los días que tiene horario.', 4000);

  // Qué licencia
  const institucional = page.locator('#tipo-institucional');
  await foco(institucional.locator('xpath=ancestor::div[contains(@class,"campo")][2]'));
  await narrar('Tipo y motivo', 'Esta la dispone la institución: <b>licencia institucional</b>. No cuenta para el tope de permisos.', 4200);
  await clic(institucional.locator('xpath=following-sibling::label'));
  await espera(700);
  const completo = page.locator('#alcance-completo');
  if (! (await completo.isChecked())) {
    await clic(completo.locator('xpath=following-sibling::label'));
  }
  await foco(completo.locator('xpath=ancestor::div[contains(@class,"toolbar")][1]'));
  await narrar('Tipo y motivo', '<b>Horario completo</b>: no marca en todo el día. <b>Con goce de haberes</b>: se le paga.', 4200);

  await narrar('Tipo y motivo', 'Escriba el <b>motivo</b> y, si hay, suba el <b>respaldo</b>.', 2400);
  await escribir(page.locator('#motivo'), MOTIVO, 50);

  const anotar = page.locator('form button[type=submit]', { hasText: 'Anotar licencia' });
  await foco(anotar);
  await narrar('Tipo y motivo', 'Presione <b>Anotar licencia(s)</b>.', 2200);
  await clic(anotar); await foco(null);
  await page.waitForURL('**/licencias?**');
  await cargado('#div-results');
  await espera(800);

  const fila = page.locator('#div-results tbody tr', { hasText: FECHA_TEXTO }).first();
  await fila.waitFor({ timeout: 60000 });
  await foco(fila);
  await narrar('Aprobarla', 'Queda en el listado como <b>pendiente</b>: hay que aprobarla para que justifique.', 4000);

  // Aprobar desde el listado
  const marca = fila.locator('input.lote-check');
  await foco(marca.locator('..'));
  await narrar('Aprobarla', 'Márquela. Se pueden marcar <b>varias</b> y aprobarlas juntas.', 3000);
  await clic(marca);
  const barra = page.locator('.lote-barra');
  await barra.waitFor({ state: 'visible' });
  await espera(600);
  await foco(barra);
  const aprobar = barra.locator('button.btn', { hasText: 'Aprobar' });
  await narrar('Aprobarla', 'Abajo aparece cuántas marcó. Presione <b>Aprobar</b>.', 3000);
  await clic(aprobar); await foco(null);
  const modal = page.locator('.modal-caja', { has: page.locator('h2', { hasText: /Aprobar 1 solicitud/ }) }).first();
  await modal.waitFor({ state: 'visible' });
  await espera(700);
  await foco(modal);
  await narrar('Aprobarla', 'Revise <b>qué</b> se aprueba y <b>a quién</b>, y confirme.', 3600);
  await clic(modal.locator('button[type=submit]'));
  await foco(null);
  await page.waitForLoadState('networkidle');
  await cargado('#div-results');
  await espera(800);

  await foco(page.locator('#div-results tbody tr', { hasText: FECHA_TEXTO }).first());
  await narrar('Listo', 'Listo: la licencia está <b>aprobada</b>. Ese día no sale como falta en los reportes.', 5200);

  await terminar();
});
