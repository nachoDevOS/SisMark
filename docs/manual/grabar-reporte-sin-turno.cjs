// Tutorial «Reporte de funcionarios sin turno»: quién tiene contrato y hoy no
// tiene turno ni horario, para asignárselo. Solo lee.
// Uso: ver docs/manual/README.md.
const { abrir, guion } = require('./grabador.cjs');

guion(async () => {
  const { page, narrar, foco, clic, espera, grabar, terminar } = await abrir();
  const resultado = page.locator('div[x-html="resultado"]');

  await grabar();
  await narrar('Presentación', 'Cómo ver quién tiene <b>contrato</b> pero <b>no tiene turno</b>: a ellos <b>no se les controla la asistencia</b>.', 4800);

  const grupo = page.locator('.sidebar__grouptoggle', { hasText: 'Reporte de marcaciones' }).first();
  await foco(grupo);
  await narrar('Entrar al reporte', 'Abra <b>Reporte de marcaciones</b> y entre a <b>Sin turno</b>.', 3000);
  await clic(grupo);
  const enlace = page.locator('.sidebar a[href$="/reportes/funcionarios/sin-turno"]').first();
  await enlace.waitFor();
  await clic(enlace); await foco(null);
  await page.waitForURL('**/reportes/funcionarios/sin-turno');
  await page.waitForLoadState('networkidle');
  await espera(800);

  await foco(page.locator('main .card').first());
  await narrar('Filtrar', 'Puede elegir una <b>dirección</b> y el <b>tipo de contrato</b>. Sin elegir, entra la <b>Gobernación entera</b>.', 4600);
  const generar = page.locator('main button.btn', { hasText: 'Generar' }).first();
  await foco(generar);
  await narrar('Filtrar', 'Presione <b>Generar</b>.', 2000);
  await clic(generar); await foco(null);
  await resultado.locator('tbody tr').first().waitFor({ timeout: 300000 });
  await espera(1000);

  await foco(resultado.locator('table').first());
  await narrar('Leer el reporte', 'Cada uno con su <b>cargo</b>, su <b>dirección</b> y las fechas de su <b>contrato</b>.', 4400);
  await foco(resultado.locator('tbody tr').first());
  await narrar('Leer el reporte', 'Con el <b>ojo</b> se abre su ficha. Para asignarle turno, vea el video <b>«Crear un turno y asignárselo»</b>.', 5200);

  const botones = resultado.locator('a.btn', { hasText: 'Imprimir' }).first().locator('..');
  await foco(botones);
  await narrar('Imprimir o Excel', 'La lista se puede <b>imprimir</b> o bajar en <b>Excel</b> para repartirla a cada dirección.', 4200);

  await narrar('Listo', 'Listo: revíselo seguido para que <b>nadie quede sin control</b> de asistencia.', 4000);

  await terminar();
});
