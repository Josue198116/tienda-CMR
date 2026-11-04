// Frontend: Bootstrap + AJAX (fetch) contra api.php
const $ = s => document.querySelector(s);
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = v => '$' + Number(v || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = v => Number(v || 0).toLocaleString('es-MX');

async function api(r, { m = 'GET', q = {}, body } = {}) {
  const u = new URL('api.php', location.href);
  u.searchParams.set('r', r);
  for (const k in q) u.searchParams.set(k, q[k]);
  const res = await fetch(u, { method: m, headers: { 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  const d = await res.json().catch(() => ({ error: 'Respuesta inválida del servidor' }));
  if (res.status === 401 && r !== 'login') { showLogin(); throw new Error(d.error || 'Sesión requerida'); }
  if (!res.ok) throw new Error(d.error || 'Error');
  return d;
}
function toast(msg, err) {
  const d = document.createElement('div'); d.textContent = msg; if (err) d.className = 'err';
  $('#toast').appendChild(d); setTimeout(() => d.remove(), 3500);
}
const modal = new bootstrap.Modal('#modal');
let chart = [];
const killCharts = () => { chart.forEach(c => c.destroy()); chart = []; };
const mk = (id, cfg) => chart.push(new Chart(document.getElementById(id), cfg));
const table = (cols, rows, act) => `<div class="table-responsive"><table class="table align-middle"><thead><tr>${cols.map(c => `<th>${esc(c[1])}</th>`).join('')}${act ? '<th></th>' : ''}</tr></thead><tbody>${
  rows.map((r, i) => `<tr>${cols.map(c => `<td>${esc(c[2] ? c[2](r[c[0]]) : r[c[0]])}</td>`).join('')}${act ? `<td class="text-end text-nowrap">${act(r, i)}</td>` : ''}</tr>`).join('') || `<tr><td colspan="${cols.length + 1}" class="text-secondary">Sin resultados</td></tr>`}</tbody></table></div>`;

// ---- Entidades (CRUD y listados) ----
const ESTADOS = ['pendiente', 'pagado', 'enviado', 'completado', 'cancelado'];
const E = {
  clientes: { t: 'Clientes', one: 'cliente', icon: 'people', crud: true,
    cols: [['id', 'ID'], ['nombre', 'Nombre'], ['email', 'Email'], ['telefono', 'Teléfono'], ['ciudad', 'Ciudad']],
    form: [{ name: 'nombre', label: 'Nombre' }, { name: 'email', label: 'Email', type: 'email' }, { name: 'telefono', label: 'Teléfono', opt: 1 }, { name: 'ciudad', label: 'Ciudad', opt: 1 }] },
  productos: { t: 'Productos', one: 'producto', icon: 'box-seam', crud: true,
    cols: [['id', 'ID'], ['sku', 'SKU'], ['nombre', 'Producto'], ['categoria', 'Categoría'], ['precio', 'Precio', money], ['stock', 'Stock'], ['activo', 'Activo', v => v == 1 ? 'Sí' : 'No']],
    form: [{ name: 'categoria_id', label: 'Categoría', src: 'categorias' }, { name: 'sku', label: 'SKU' }, { name: 'nombre', label: 'Nombre' }, { name: 'precio', label: 'Precio', type: 'number' }, { name: 'stock', label: 'Stock', type: 'number' }, { name: 'activo', label: 'Activo', opts: [[1, 'Sí'], [0, 'No']] }] },
  categorias: { t: 'Categorías', one: 'categoría', icon: 'tags', crud: true,
    cols: [['id', 'ID'], ['nombre', 'Nombre'], ['descripcion', 'Descripción']],
    form: [{ name: 'nombre', label: 'Nombre' }, { name: 'descripcion', label: 'Descripción', opt: 1 }] },
  pedidos: { t: 'Pedidos', one: 'pedido', icon: 'receipt',
    cols: [['id', 'ID'], ['fecha', 'Fecha'], ['cliente', 'Cliente'], ['estado', 'Estado'], ['total', 'Total', money]],
    form: [{ name: 'cliente_id', label: 'Cliente', src: 'clientes' }, { name: 'producto_id', label: 'Producto', src: 'productos' }, { name: 'cantidad', label: 'Cantidad', type: 'number' }] },
  ventas: { t: 'Ventas', one: 'venta', icon: 'credit-card',
    cols: [['id', 'ID'], ['pedido_id', 'Pedido'], ['fecha', 'Fecha'], ['cliente', 'Cliente'], ['total', 'Total', money], ['metodo_pago', 'Pago']],
    form: [{ name: 'pedido_id', label: 'Nº de pedido', type: 'number' }, { name: 'metodo_pago', label: 'Método de pago', opts: [['efectivo', 'Efectivo'], ['tarjeta', 'Tarjeta'], ['transferencia', 'Transferencia']] }] },
  inventario: { t: 'Inventario', one: 'movimiento', icon: 'archive',
    cols: [['id', 'ID'], ['fecha', 'Fecha'], ['producto', 'Producto'], ['tipo', 'Tipo'], ['cantidad', 'Cantidad'], ['nota', 'Nota']],
    form: [{ name: 'producto_id', label: 'Producto', src: 'productos' }, { name: 'tipo', label: 'Tipo', opts: [['entrada', 'Entrada'], ['salida', 'Salida'], ['ajuste', 'Ajuste (+)']] }, { name: 'cantidad', label: 'Cantidad', type: 'number' }, { name: 'nota', label: 'Nota', opt: 1 }] },
};
const NAV = [['dashboard', 'Dashboard', 'grid-1x2'], ...Object.entries(E).map(([k, e]) => [k, e.t, e.icon]), ['reportes', 'Reportes', 'bar-chart'], ['lab', 'Laboratorio SQL', 'code-slash']];

function buildNav(cur) {
  $('#nav').innerHTML = NAV.map(([k, t, i]) => `<a href="#${k}" class="${k === cur ? 'on' : ''}"><i class="bi bi-${i}"></i>${t}</a>`).join('');
  $('#navSel').innerHTML = NAV.map(([k, t]) => `<option value="${k}" ${k === cur ? 'selected' : ''}>${t}</option>`).join('');
}
$('#navSel').onchange = e => location.hash = e.target.value;

// ---- Dashboard ----
async function viewDashboard() {
  const d = await api('dashboard');
  const k = [['Clientes', num(d.clientes), 'Registros totales', 'people'], ['Productos activos', num(d.productos), 'Catálogo disponible', 'box-seam'], ['Pedidos', num(d.pedidos), 'Todos los estados', 'receipt'],
    ['Ventas acumuladas', money(d.ventas_total), 'Ventas completadas', 'cash-stack'], ['Ingresos del mes', money(d.ingresos_mes), 'Mes en curso', 'calendar-check'], ['Stock bajo', num(d.stock_bajo), 'Existencias ≤ 5', 'exclamation-triangle', 'warn']];
  $('#view').innerHTML = `<div class="row g-3 mb-3">${k.map(x => `<div class="col-12 col-md-6 col-xl-4"><div class="cardx kpi ${x[4] || ''}"><div><small>${x[0]}</small><b>${x[1]}</b><small>${x[2]}</small></div><span class="ic"><i class="bi bi-${x[3]}"></i></span></div></div>`).join('')}</div>
  <div class="row g-3"><div class="col-xl-7"><div class="cardx"><h6 class="mb-3">Ventas por mes · últimos 12 meses</h6><div class="chartbox"><canvas id="c1"></canvas></div></div></div>
  <div class="col-xl-5"><div class="cardx"><h6 class="mb-3">Productos más vendidos (unidades)</h6><div class="chartbox"><canvas id="c2"></canvas></div></div></div></div>`;
  mk('c1', { type: 'line', data: { labels: d.meses.map(x => x.mes), datasets: [{ data: d.meses.map(x => +x.total), borderColor: '#1d5fd8', backgroundColor: 'rgba(29,95,216,.12)', fill: true, tension: .35 }] }, options: { maintainAspectRatio: false, plugins: { legend: { display: false } } } });
  mk('c2', { type: 'bar', data: { labels: d.top.map(x => x.nombre), datasets: [{ data: d.top.map(x => +x.unidades), backgroundColor: '#5b9bff', borderRadius: 6 }] }, options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } } } });
}

// ---- Listados con búsqueda, paginación y CRUD ----
let state = { q: '', page: 1, rows: [] };
async function viewEntity(key) {
  const e = E[key];
  $('#view').innerHTML = `<div class="cardx"><div class="d-flex flex-wrap gap-2 justify-content-between mb-3"><input id="q" class="form-control" style="max-width:300px" placeholder="Buscar…" value="${esc(state.q)}"><button class="btn btn-primary" id="add"><i class="bi bi-plus-lg"></i> Nuevo ${e.one}</button></div><div id="tbl"></div><div id="pg" class="d-flex justify-content-between align-items-center"></div></div>`;
  let tm; $('#q').oninput = ev => { clearTimeout(tm); tm = setTimeout(() => { state.q = ev.target.value; state.page = 1; load(key); }, 300); };
  $('#add').onclick = () => openForm(key);
  await load(key);
}
async function load(key) {
  const e = E[key];
  try {
    const d = await api(key, { q: { q: state.q, page: state.page } });
    state.rows = d.data;
    const act = e.crud ? (r, i) => `<button class="btn btn-sm btn-light" data-e="${i}"><i class="bi bi-pencil"></i></button> <button class="btn btn-sm btn-light text-danger" data-d="${r.id}"><i class="bi bi-trash"></i></button>` : null;
    const html = table(e.cols, d.data, act);
    $('#tbl').innerHTML = html;
    $('#pg').innerHTML = `<small class="text-secondary">${num(d.total)} registros</small><div class="btn-group"><button class="btn btn-light btn-sm" id="prev" ${d.page <= 1 ? 'disabled' : ''}>‹</button><button class="btn btn-light btn-sm" disabled>${d.page} / ${d.pages}</button><button class="btn btn-light btn-sm" id="next" ${d.page >= d.pages ? 'disabled' : ''}>›</button></div>`;
    $('#prev').onclick = () => { state.page--; load(key); };
    $('#next').onclick = () => { state.page++; load(key); };
    document.querySelectorAll('[data-e]').forEach(b => b.onclick = () => openForm(key, state.rows[b.dataset.e]));
    document.querySelectorAll('[data-d]').forEach(b => b.onclick = async () => {
      if (!confirm('¿Eliminar este registro?')) return;
      try { await api(key, { m: 'DELETE', q: { id: b.dataset.d } }); toast('Eliminado'); load(key); } catch (er) { toast(er.message, true); }
    });
  } catch (er) { $('#tbl').innerHTML = `<div class="alert alert-danger">${esc(er.message)}</div>`; }
}
async function openForm(key, row) {
  const e = E[key], opts = {};
  for (const f of e.form) if (f.src) opts[f.name] = await api('opciones', { q: { t: f.src } });
  $('#mTitle').textContent = (row ? 'Editar ' : 'Nuevo ') + e.one;
  $('#mBody').innerHTML = e.form.map(f => {
    const v = row ? (row[f.name] ?? '') : '';
    const sel = list => `<select class="form-select" name="${f.name}">${list.map(([id, n]) => `<option value="${esc(id)}" ${String(id) === String(v) ? 'selected' : ''}>${esc(n)}</option>`).join('')}</select>`;
    const inp = f.src ? sel(opts[f.name].map(o => [o.id, o.nombre])) : f.opts ? sel(f.opts)
      : `<input class="form-control" name="${f.name}" type="${f.type || 'text'}" step="any" value="${esc(v)}" ${f.opt ? '' : 'required'}>`;
    return `<div class="mb-3"><label class="form-label">${f.label}</label>${inp}</div>`;
  }).join('');
  $('#mForm').onsubmit = async ev => {
    ev.preventDefault();
    try { await api(key, { m: row ? 'PUT' : 'POST', q: row ? { id: row.id } : {}, body: Object.fromEntries(new FormData(ev.target)) }); modal.hide(); toast('Guardado'); load(key); }
    catch (er) { toast(er.message, true); }
  };
  modal.show();
}

// ---- Reportes ----
async function viewReportes() {
  const d = await api('reportes');
  $('#view').innerHTML = `<div class="row g-3"><div class="col-12"><div class="cardx"><h6>Ventas por mes</h6>${table([['mes', 'Mes'], ['ventas', 'Ventas'], ['total', 'Total', money]], d.meses)}</div></div>
  <div class="col-xl-6"><div class="cardx"><h6>Top 10 categorías por ingresos</h6>${table([['categoria', 'Categoría'], ['unidades', 'Unidades'], ['ingresos', 'Ingresos', money]], d.categorias)}</div></div>
  <div class="col-xl-6"><div class="cardx"><h6>Top 10 clientes</h6>${table([['cliente', 'Cliente'], ['compras', 'Compras'], ['total', 'Total', money]], d.clientes)}</div></div></div>`;
}

// ---- Laboratorio SQL (consultas predefinidas: JOIN, HAVING, subconsulta, vistas, procedures) ----
async function viewLab() {
  const list = await api('lab');
  $('#view').innerHTML = `<div class="cardx"><div class="d-flex gap-2 mb-3"><select id="ls" class="form-select">${list.map(x => `<option value="${x.n}">${esc(x.titulo)}</option>`).join('')}</select><button class="btn btn-primary" id="run"><i class="bi bi-play-fill"></i> Ejecutar</button></div><pre class="sql" id="sqlt"></pre><div id="res"></div></div>`;
  const show = () => $('#sqlt').textContent = list[$('#ls').value].sql;
  $('#ls').onchange = show; show();
  $('#run').onclick = async () => {
    try { const d = await api('lab', { q: { n: $('#ls').value } }); const ks = d.rows[0] ? Object.keys(d.rows[0]) : []; $('#res').innerHTML = table(ks.map(k => [k, k]), d.rows); }
    catch (er) { toast(er.message, true); }
  };
}

// ---- Router por hash ----
async function route() {
  const k = location.hash.slice(1) || 'dashboard';
  killCharts(); state = { q: '', page: 1, rows: [] };
  buildNav(k);
  const e = E[k];
  $('#title').textContent = e ? e.t : ({ dashboard: 'Resumen de la tienda', reportes: 'Reportes', lab: 'Laboratorio SQL' }[k] || '');
  $('#sub').textContent = k === 'dashboard' ? 'Indicadores calculados desde la base de datos' : '';
  $('#view').innerHTML = '<div class="text-secondary">Cargando…</div>';
  try {
    if (k === 'dashboard') await viewDashboard();
    else if (e) await viewEntity(k);
    else if (k === 'reportes') await viewReportes();
    else if (k === 'lab') await viewLab();
  } catch (er) { $('#view').innerHTML = `<div class="alert alert-danger"><b>No se pudo cargar.</b> ${esc(er.message)}<br><small>Revisa que MariaDB/MySQL esté encendido y las credenciales en server/config.php.</small></div>`; }
}
// ---- Sesión (login) ----
function showLogin() { document.body.classList.add('locked'); }
function enter(u) {
  document.body.classList.remove('locked');
  $('#uName').textContent = u.usuario;
  $('#uRol').textContent = u.rol === 'administrador' ? 'Administrador' : 'Vendedor';
  $('#uAv').textContent = u.usuario[0].toUpperCase();
  route();
}
$('#lForm').onsubmit = async ev => {
  ev.preventDefault(); $('#lErr').textContent = '';
  try { enter(await api('login', { m: 'POST', body: Object.fromEntries(new FormData(ev.target)) })); ev.target.reset(); }
  catch (er) { $('#lErr').textContent = er.message; }
};
$('#logout').onclick = async () => { await api('logout', { m: 'POST' }).catch(() => {}); location.hash = ''; showLogin(); };
addEventListener('hashchange', () => { if (!document.body.classList.contains('locked')) route(); });
api('me').then(enter).catch(showLogin);
