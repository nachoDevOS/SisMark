// Lo común a los guiones grabar-*.cjs: abre el navegador a 2x (3840x2160), entra
// al sistema sin grabar, captura los cuadros y anota cada foco, movimiento del
// cursor, clic y subtítulo. Cámara, foco, cursor y subtítulos los dibuja
// componer.py a 30 fps. Uso: ver docs/manual/README.md.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const W = 1920, H = 1080;
// A 4K el screencast da ~3 cuadros/s: se graba en cámara lenta y componer.py comprime el tiempo.
const LENTO = Number(process.env.LENTO || 5);
const espera = (ms) => new Promise((r) => setTimeout(r, ms * LENTO));

const CSS_NARRA = `
  #demo-narra{position:fixed;left:50%;bottom:44px;transform:translateX(-50%);max-width:1250px;width:max-content;
    background:rgba(13,59,62,.95);color:#fff;font:500 27px/1.4 'Segoe UI',system-ui,sans-serif;
    padding:20px 34px;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.35);border-left:6px solid #00a65a}
  #demo-narra.arriba{bottom:auto;top:44px}
  #demo-narra b{color:#6ee7b7}
  #demo-paso{display:block;font-size:17px;letter-spacing:.08em;text-transform:uppercase;color:#6ee7b7;margin-bottom:4px}`;

const CURSOR = '<svg width="28" height="28" viewBox="0 0 24 24" style="filter:drop-shadow(0 2px 3px rgba(0,0,0,.45));margin:8px">'
  + '<path d="M4 2l16 9.5-7 1.6-3.6 6.9z" fill="#fff" stroke="#0f172a" stroke-width="1.6" stroke-linejoin="round"/></svg>';

/**
 * Entra al sistema y deja la página lista en `inicio` (sin grabar todavía).
 * Devuelve las acciones del guion; `grabar()` arranca la captura y `terminar()`
 * la corta y guarda eventos.json, capitulos.json, los subtítulos y el cursor.
 */
async function abrir({ base = process.env.BASE || 'http://127.0.0.1:8002', inicio = '/' } = {}) {
  if (!process.env.USUARIO || !process.env.CLAVE) throw new Error('Faltan USUARIO y CLAVE en el entorno.');

  const salida = process.env.SALIDA || path.join(require('os').tmpdir(), 'sismark-manual');
  const dir = path.join(salida, 'frames');
  fs.rmSync(salida, { recursive: true, force: true });
  fs.mkdirSync(dir, { recursive: true });

  const frames = [];
  const eventos = [];
  const capitulos = [];
  let pasoPrevio = null;
  let t0 = 0;
  let grabando = false;
  const ahora = () => Date.now() / 1000;

  const browser = await chromium.launch({
    // Sin la bandera, deviceScaleFactor 2 se emula y la captura sale a 1x.
    args: ['--hide-scrollbars', '--force-device-scale-factor=2'],
  });
  const ctx = await browser.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 2, locale: 'es-BO' });
  // En local el sidebar se tiñe de rojo (x-entorno-local): el video muestra los colores de producción.
  // El botón «Ayuda» tintinea: quieto en el video, para que no le robe la mirada al trámite.
  await ctx.addInitScript(() => document.addEventListener('DOMContentLoaded', () => {
    document.querySelector('style.entorno-local')?.remove();
    document.head.insertAdjacentHTML('beforeend', '<style>.ayuda__boton{animation:none!important}</style>');
  }));
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);

  await page.goto(`${base}/login`);
  await page.fill('#email', process.env.USUARIO);
  await page.locator('input[type=password]').fill(process.env.CLAVE);
  await page.locator('form button[type=submit]').click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
  await page.goto(`${base}${inicio}`);
  await page.waitForLoadState('networkidle');
  await espera(800);

  cdp.on('Page.screencastFrame', ({ data, metadata, sessionId }) => {
    cdp.send('Page.screencastFrameAck', { sessionId }).catch(() => {});
    if (!grabando) return;
    const archivo = path.join(dir, `f${String(frames.length).padStart(6, '0')}.jpg`);
    fs.writeFileSync(archivo, Buffer.from(data, 'base64'));
    frames.push({ archivo, t: metadata.timestamp });
  });

  const narrar = async (paso, txt, ms = 3200) => {
    if (paso && paso !== pasoPrevio) { capitulos.push({ paso, texto: txt.replace(/<[^>]+>/g, ''), t: (ahora() - t0) / LENTO }); pasoPrevio = paso; }
    eventos.push({ tipo: 'narra', t: ahora(), paso, html: txt });
    await espera(ms);
  };
  const caja = async (loc) => { await loc.scrollIntoViewIfNeeded(); await espera(250); return loc.boundingBox(); };
  const foco = async (loc, ms = 0) => { const r = loc ? await caja(loc) : null; eventos.push({ tipo: 'foco', t: ahora(), r }); if (ms) await espera(ms); };
  const mover = async (loc) => { const r = await caja(loc); const x = r.x + r.width / 2, y = r.y + r.height / 2; eventos.push({ tipo: 'cursor', t: ahora(), x, y }); await espera(800); return [x, y]; };
  const clic = async (loc) => { const [x, y] = await mover(loc); eventos.push({ tipo: 'clic', t: ahora(), x, y }); await espera(250); await loc.click(); };
  const teclear = async (loc, txt, ms = 110) => { await loc.pressSequentially(txt, { delay: ms * LENTO }); await espera(350); };
  const escribir = async (loc, txt, ms = 90) => { await foco(loc); await clic(loc); await loc.fill(''); await teclear(loc, txt, ms); };
  const elegir = async (loc, valor) => { await clic(loc); await espera(300); await loc.selectOption(valor); await espera(700); };
  // Las tablas se cargan por AJAX y muestran «Cargando…» mientras tanto.
  const cargado = async (selector) => {
    await page.waitForFunction((s) => { const el = document.querySelector(s); return el && !el.textContent.includes('Cargando…'); }, selector, { timeout: 120000 });
    await espera(500);
  };

  const grabar = async () => {
    grabando = true;
    await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 95, maxWidth: W * 2, maxHeight: H * 2 });
    await espera(300);
    t0 = frames[0]?.t ?? ahora();
    eventos.push({ tipo: 'cursor', t: ahora(), x: 1500, y: 760, salto: true });
  };

  const terminar = async () => {
    await foco(null);
    await narrar('', '', 600);
    grabando = false;
    await cdp.send('Page.stopScreencast');

    // Un PNG transparente por subtítulo, abajo y arriba (componer.py elige), y el cursor a 4x.
    const lienzo = await browser.newPage({ viewport: { width: W, height: H }, deviceScaleFactor: 1 });
    const subtitulos = eventos.filter((e) => e.tipo === 'narra' && e.html);
    for (let i = 0; i < subtitulos.length; i++) {
      for (const lado of ['abajo', 'arriba']) {
        await lienzo.setContent(`<html><body style="margin:0;background:transparent"><style>${CSS_NARRA}</style>
          <div id="demo-narra" class="${lado}">${subtitulos[i].paso ? `<span id="demo-paso">${subtitulos[i].paso}</span>` : ''}${subtitulos[i].html}</div></body></html>`);
        await lienzo.screenshot({ path: path.join(salida, `sub${i}-${lado}.png`), omitBackground: true });
      }
      subtitulos[i].png = `sub${i}`;
    }
    const lienzo4 = await browser.newPage({ viewport: { width: 44, height: 44 }, deviceScaleFactor: 4 });
    await lienzo4.setContent(`<html><body style="margin:0;background:transparent">${CURSOR}</body></html>`);
    await lienzo4.screenshot({ path: path.join(salida, 'cursor.png'), omitBackground: true });

    fs.writeFileSync(path.join(salida, 'eventos.json'), JSON.stringify({ lento: LENTO, frames, eventos }, null, 1));
    fs.writeFileSync(path.join(salida, 'capitulos.json'), JSON.stringify(capitulos, null, 1));
    await browser.close();
    console.log('frames', frames.length);
    console.log(capitulos.map((c) => `${Math.floor(c.t)}s ${c.paso}`).join('\n'));
  };

  return { page, base, espera, narrar, foco, mover, clic, teclear, escribir, elegir, cargado, grabar, terminar };
}

/** Corre un guion y, si falla, sale con error. */
function guion(fn) {
  fn().catch((e) => { console.error(e); process.exit(1); });
}

module.exports = { abrir, guion };
