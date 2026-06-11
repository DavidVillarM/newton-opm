(function () {
  'use strict';

  const API = (String(window.NC_APP && window.NC_APP.apiUrl || '').startsWith('http'))
    ? String(window.NC_APP.apiUrl || '').replace(/\/$/, '')
    : (window.location.origin + String(window.NC_APP && window.NC_APP.apiUrl || '')).replace(/\/$/, '');
  const nonce = (window.NC_APP && window.NC_APP.nonce) || '';

  async function api(path, opts = {}) {
    const method = (opts.method || 'GET').toUpperCase();
    let url = path.startsWith('http') ? path : API + path;
    if (method === 'GET') url += (url.includes('?') ? '&' : '?') + 'nc_ts=' + Date.now();
    const headers = { 'X-WP-Nonce': nonce, ...(opts.headers || {}) };
    if (!('Content-Type' in headers) && opts.body && !(opts.body instanceof FormData))
      headers['Content-Type'] = 'application/json';
    const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...opts, headers });
    const ct = res.headers.get('content-type') || '';
    const data = ct.includes('application/json') ? await res.json().catch(() => ({})) : await res.text();
    if (!res.ok) throw new Error((data && data.message) || (typeof data === 'string' ? data.substring(0, 200) : res.statusText));
    return data;
  }

  function escapeHtml(s) {
    if (s == null) return '';
    const t = document.createElement('textarea');
    t.textContent = s;
    return t.innerHTML;
  }

  function withButtonLock(btn, fn, opts = {}) {
    return async function (e) {
      if (btn.disabled || btn.getAttribute('aria-busy') === 'true') return;
      const originalText = btn.textContent;
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      if (opts.loadingText) btn.textContent = opts.loadingText;
      try {
        await Promise.resolve(fn(e));
      } finally {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        if (opts.loadingText) btn.textContent = originalText;
      }
    };
  }

  const SUB_TABS = [
    { id: 'crear', label: 'Crear exámen (Ingeniería)' },
    { id: 'medicina', label: 'Medicina (importar)' },
    { id: 'cargar', label: 'Cargar puntajes' },
    { id: 'ver', label: 'Ver puntajes' },
  ];

  /** Refresco sub-pestaña desde handlers fuera del closure de render (p. ej. tras crear examen). */
  let switchExamSubTab = function () {};

  function render(container, opts) {
    opts = opts || {};
    if (!container) return;
    if (window.NC_AppState) window.NC_AppState.persistRoute({ mainTab: 'puntajes-main' });
    let contentEl = null;
    let subTabBtns = [];

    function setActiveSub(id) {
      subTabBtns.forEach(b => { b.dataset.active = b.dataset.sub === id ? '1' : '0'; });
      if (window.NC_AppState) window.NC_AppState.persistRoute({ mainTab: 'puntajes-main', examenesSub: id });
      renderSub(id);
    }

    switchExamSubTab = setActiveSub;

    function renderSub(id) {
      if (!contentEl) return;
      if (id === 'crear') return renderCrear();
      if (id === 'medicina') return renderMedicina();
      if (id === 'cargar') return renderCargar();
      if (id === 'ver') return renderVer();
    }

    container.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'nc-examenes-wrap';
    const header = document.createElement('div');
    header.innerHTML = '<h3 style="margin:0 0 14px">Puntajes o exámenes</h3>';
    wrap.appendChild(header);
    const subTabs = document.createElement('div');
    subTabs.className = 'nc-tabs nc-row-wrap';
    subTabs.style.cssText = 'gap:8px;margin-bottom:16px';
    SUB_TABS.forEach(t => {
      const b = document.createElement('button');
      b.className = 'nc-tab';
      b.dataset.sub = t.id;
      b.dataset.active = t.id === 'crear' ? '1' : '0';
      b.textContent = t.label;
      b.onclick = () => setActiveSub(t.id);
      subTabs.appendChild(b);
      subTabBtns.push(b);
    });
    wrap.appendChild(subTabs);
    contentEl = document.createElement('div');
    contentEl.id = 'nc-examenes-content';
    wrap.appendChild(contentEl);
    container.appendChild(wrap);
    const validSubs = SUB_TABS.map(t => t.id);
    const initialSub = (opts.initialSub && validSubs.includes(opts.initialSub))
      ? opts.initialSub
      : (window.NC_AppState && window.NC_AppState.load().examenesSub && validSubs.includes(window.NC_AppState.load().examenesSub)
        ? window.NC_AppState.load().examenesSub
        : 'crear');
    setActiveSub(initialSub);
  }

  async function refreshMedicinaStatus(contentEl, examenId) {
    const st = contentEl.querySelector('#nc-med-status');
    if (!st || !examenId) return;
    st.innerHTML = '<span style="opacity:.8">Consultando…</span>';
    try {
      const [itemsRes, shRes] = await Promise.all([
        api('/examenes/' + examenId + '/items?limit=1'),
        api('/examenes/' + examenId + '/shuffle'),
      ]);
      const nItems = (itemsRes && itemsRes.total != null) ? itemsRes.total : 0;
      const nSh = (shRes && shRes.count != null) ? shRes.count : 0;
      st.innerHTML = '<strong>Ítems cargados:</strong> ' + nItems + ' &nbsp;|&nbsp; <strong>Orden hoja:</strong> ' + nSh + ' filas';
    } catch (e) {
      st.innerHTML = '<span style="color:#b00">' + escapeHtml(e.message || String(e)) + '</span>';
    }
  }

  async function renderMedicina() {
    const contentEl = document.getElementById('nc-examenes-content');
    if (!contentEl) return;
    let examenes = [], cursos = [];
    try {
      const [res, cRes] = await Promise.all([api('/examenes'), api('/cursos')]);
      examenes = (res && res.items) ? res.items : [];
      cursos = Array.isArray(cRes) ? cRes : (cRes && cRes.items ? cRes.items : []);
    } catch (_) {}
    const medicina = examenes.filter(e => (e.tipo || '') === 'medicina');
    const options = (medicina.length ? medicina : examenes).map(e =>
      '<option value="' + e.id + '">' + escapeHtml((e.nombre || '') + ' (' + (e.fecha || '') + ')') + '</option>'
    ).join('');
    contentEl.innerHTML = `
      <div class="nc-card">
        <h4 style="margin:0 0 10px">Medicina: importar puntajes desde archivo</h4>
        <p style="margin:0 0 14px;font-size:13px;color:#555;max-width:900px;line-height:1.45">
          Flujo nuevo para Medicina: cree (o seleccione) el examen y cargue un archivo
          <strong>PDF, Excel o Word</strong> con
          columnas por materia (<code>GUA CAS EST MAT FIS IND/INO ORG BIO ANA</code>) y puntajes por alumno.
          No se usan ítems, orden aleatorio ni respuestas por burbuja.
        </p>
        <div style="border:1px solid #eee;border-radius:6px;padding:12px;margin-bottom:14px;background:#fafafa">
          <h5 style="margin:0 0 10px">Crear examen Medicina automáticamente</h5>
          <div class="nc-row nc-row-wrap" style="gap:10px;align-items:flex-end">
            <div class="nc-field">
              <label>Nombre</label>
              <input type="text" id="nc-med-new-nombre" placeholder="Ej. Medicina Marzo 2026" style="padding:8px;min-width:240px;border:1px solid #ddd;border-radius:4px" />
            </div>
            <div class="nc-field">
              <label>Fecha</label>
              <input type="date" id="nc-med-new-fecha" value="${new Date().toISOString().slice(0,10)}" style="padding:8px;border:1px solid #ddd;border-radius:4px" />
            </div>
            <div class="nc-field">
              <label>Curso (opcional)</label>
              <select id="nc-med-new-curso" style="padding:8px;min-width:220px;border:1px solid #ddd;border-radius:4px">
                <option value="">Todos / Sin curso</option>
                ${(cursos || []).map(c => '<option value="' + c.id + '">' + escapeHtml(c.nombre || '') + '</option>').join('')}
              </select>
            </div>
            <button type="button" class="nc-btn primary" id="nc-med-new-btn">Crear examen</button>
          </div>
        </div>
        <div class="nc-field" style="margin-bottom:12px">
          <label>Exámen</label>
          <select id="nc-med-examen" style="padding:8px;min-width:320px;border:1px solid #ddd;border-radius:4px">
            <option value="">Seleccione…</option>${options}
          </select>
        </div>
        <p id="nc-med-status" style="margin:0 0 16px;font-size:13px;opacity:.8">Seleccione un examen para importar archivo.</p>
        <div style="border-top:1px solid #eee;padding-top:12px">
          <div class="nc-field" style="margin-bottom:10px">
            <label>Archivo de resultados (CI + materias)</label><br/>
            <input type="file" id="nc-med-file-pdf" accept=".pdf,.xlsx,.xls,.docx" style="margin-top:6px" />
          </div>
          <button type="button" class="nc-btn primary" id="nc-med-btn-pdf">Importar puntajes</button>
        </div>
      </div>
    `;
    const sel = contentEl.querySelector('#nc-med-examen');
    contentEl.querySelector('#nc-med-new-btn').onclick = withButtonLock(
      contentEl.querySelector('#nc-med-new-btn'),
      async () => {
        const nombre = (contentEl.querySelector('#nc-med-new-nombre').value || '').trim();
        const fecha = contentEl.querySelector('#nc-med-new-fecha').value;
        const cursoRaw = (contentEl.querySelector('#nc-med-new-curso').value || '').trim();
        if (!nombre) { alert('Indique nombre.'); return; }
        if (!fecha) { alert('Indique fecha.'); return; }
        const fd = new FormData();
        fd.append('nombre', nombre);
        fd.append('fecha', fecha);
        if (cursoRaw) fd.append('curso_id', cursoRaw);
        const out = await api('/examenes/medicina/auto-import', { method: 'POST', body: fd });
        alert('Examen Medicina creado. ID: ' + out.id);
        const rs = await api('/examenes?tipo=medicina');
        const nuevos = (rs && rs.items) ? rs.items : [];
        sel.innerHTML = '<option value="">Seleccione…</option>' + nuevos.map(e =>
          '<option value="' + e.id + '">' + escapeHtml((e.nombre || '') + ' (' + (e.fecha || '') + ')') + '</option>'
        ).join('');
        sel.value = String(out.id || '');
        contentEl.querySelector('#nc-med-status').textContent = 'Examen listo. Ahora importe el archivo de puntajes.';
      },
      { loadingText: 'Creando…' }
    );
    sel.onchange = () => {
      const st = contentEl.querySelector('#nc-med-status');
      if (st) st.textContent = sel.value ? 'Examen seleccionado. Puede importar el archivo.' : 'Seleccione un examen para importar archivo.';
    };

    function needExamen() {
      const id = sel.value;
      if (!id) { alert('Seleccione un examen.'); return null; }
      return id;
    }

    contentEl.querySelector('#nc-med-btn-pdf').onclick = withButtonLock(
      contentEl.querySelector('#nc-med-btn-pdf'),
      async () => {
        const id = needExamen(); if (!id) return;
        const inp = contentEl.querySelector('#nc-med-file-pdf');
        if (!inp.files || !inp.files[0]) { alert('Elija un archivo (PDF/Excel/Word).'); return; }
        const fd = new FormData();
        fd.append('file', inp.files[0]);
        const data = await api('/examenes/' + id + '/medicina/pdf/import', { method: 'POST', body: fd });
        let msg = 'Alumnos importados: ' + (data.imported != null ? data.imported : 0);
        if (data.skipped) msg += '. Omitidos (sin alumno en sistema): ' + data.skipped;
        const ps = data.pdf_parseo;
        if (ps && typeof ps === 'object') {
          msg += '\n\nParseo: páginas ' + (ps.paginas_pdf != null ? ps.paginas_pdf : '?')
            + ', filas leídas ' + (ps.filas_parseadas != null ? ps.filas_parseadas : '?')
            + ', duplicados CI ' + (ps.ci_duplicado != null ? ps.ci_duplicado : 0)
            + ', líneas no interpretadas ' + (ps.lineas_sin_parsear != null ? ps.lineas_sin_parsear : '?')
            + ' (candidatos de línea ' + (ps.candidatos_linea != null ? ps.candidatos_linea : '?') + ').';
        }
        if (data.errors && data.errors.length) msg += '\n\n' + data.errors.slice(0, 15).join('\n');
        alert(msg);
      },
      { loadingText: 'Importando…' }
    );
  }

  async function renderCrear() {
    const contentEl = document.getElementById('nc-examenes-content');
    if (!contentEl) return;
    contentEl.innerHTML = '<div class="nc-card"><p>Cargando...</p></div>';
    let cursos = [], aulas = [];
    try {
      const [cRes, aRes] = await Promise.all([api('/cursos'), api('/aulas')]);
      cursos = Array.isArray(cRes) ? cRes : (cRes && cRes.items ? cRes.items : []);
      aulas = Array.isArray(aRes) ? aRes : (aRes && aRes.items ? aRes.items : []);
    } catch (_) {}
    const hoy = new Date().toISOString().slice(0, 10);
    const grupoMateriaMap = {
      nu: ['Algebra', 'Trigonometria', 'Aritmetica', 'Geometria Analitica', 'Informatica'],
      mu: ['Algebra', 'Trigonometria', 'Aritmetica', 'Geometria Analitica', 'Fisica'],
      zeta: ['Algebra', 'Trigonometria', 'Aritmetica', 'Geometria Analitica', 'Fisica'],
    };
    function normKey(s) {
      return String(s || '').toLowerCase()
        .replace(/[áàäâ]/g, 'a')
        .replace(/[éèëê]/g, 'e')
        .replace(/[íìïî]/g, 'i')
        .replace(/[óòöô]/g, 'o')
        .replace(/[úùüû]/g, 'u')
        .replace(/ñ/g, 'n')
        .replace(/[^a-z0-9]+/g, '');
    }
    function allowedGrupoKey(nombre) {
      const k = normKey(nombre);
      if (k.includes('nu')) return 'nu';
      if (k.includes('mu')) return 'mu';
      if (k.includes('zeta')) return 'zeta';
      return '';
    }
    const gruposIngenieria = (aulas || []).filter(g => !!allowedGrupoKey(g && g.nombre));
    contentEl.innerHTML = `
      <div class="nc-card">
        <h4 style="margin:0 0 12px">Nuevo exámen (solo Ingeniería)</h4>
        <p style="margin:0 0 14px;font-size:13px;color:#666">Ingrese solo el <strong>nombre</strong>, la <strong>fecha</strong> y el <strong>total del examen</strong>. Luego cargue el puntaje total por estudiante en <strong>Cargar puntajes</strong>. Para Medicina use <strong>Medicina (importar)</strong>.</p>
        <div class="nc-row nc-row-wrap" style="gap:12px;margin-bottom:14px">
          <div class="nc-field">
            <label>Nombre del exámen</label>
            <input type="text" id="nc-ex-nombre" placeholder="Ej. Examen marzo 2026" style="padding:8px;min-width:220px;border:1px solid #ddd;border-radius:4px" />
          </div>
          <div class="nc-field">
            <label>Fecha</label>
            <input type="date" id="nc-ex-fecha" value="${hoy}" style="padding:8px;border:1px solid #ddd;border-radius:4px" />
          </div>
          <div class="nc-field">
            <label>Curso (opcional)</label>
            <select id="nc-ex-curso" style="padding:8px;min-width:200px;border:1px solid #ddd;border-radius:4px">
              <option value="">Todos / Sin filtrar</option>
              ${(cursos || []).map(c => '<option value="' + c.id + '">' + escapeHtml(c.nombre) + '</option>').join('')}
            </select>
          </div>
          <div class="nc-field">
            <label>Total del exámen</label>
            <input type="number" id="nc-ex-total" min="1" step="1" value="100" style="padding:8px;width:120px;border:1px solid #ddd;border-radius:4px" />
          </div>
        </div>
        <div class="nc-field" style="margin-bottom:12px">
          <label>Materia (obligatoria)</label>
          <select id="nc-ex-materia" style="padding:8px;min-width:280px;border:1px solid #ddd;border-radius:4px">
            <option value="">Seleccione primero uno o más grupos</option>
          </select>
          <div style="font-size:12px;color:#666;margin-top:6px">
            Reglas: Algebra/Trigonometria/Aritmetica/Geometria Analitica para Nu, Mu y Zeta; Fisica solo Mu y Zeta; Informatica solo Nu.
          </div>
        </div>
        <div class="nc-field" style="margin-bottom:12px">
          <label>Grupos a los que aplica este examen (obligatorio)</label>
          <div id="nc-ex-grupos-wrap" style="display:flex;flex-wrap:wrap;gap:8px 12px;padding:10px;border:1px solid #eee;border-radius:6px;background:#fafafa;max-width:900px">
            ${(gruposIngenieria || []).map(g => '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" class="nc-ex-grupo-chk" value="' + g.id + '" data-grupo-key="' + allowedGrupoKey(g.nombre || '') + '" /> ' + escapeHtml(g.nombre || '') + '</label>').join('') || '<span style="opacity:.7">No hay grupos Nu/Mu/Zeta disponibles.</span>'}
          </div>
        </div>
        <button type="button" class="nc-btn primary" id="nc-ex-guardar">Crear exámen</button>
      </div>
    `;

    const selMateria = contentEl.querySelector('#nc-ex-materia');
    const chksGrupo = Array.from(contentEl.querySelectorAll('.nc-ex-grupo-chk'));
    function refreshMateriasByGrupo() {
      const keys = Array.from(contentEl.querySelectorAll('.nc-ex-grupo-chk:checked')).map(ch => String(ch.dataset.grupoKey || '')).filter(Boolean);
      let materias = [];
      if (keys.length > 0) {
        let inter = null;
        keys.forEach(k => {
          const arr = (grupoMateriaMap[k] || []).slice();
          if (inter === null) {
            inter = arr;
          } else {
            inter = inter.filter(m => arr.includes(m));
          }
        });
        materias = (inter || []).sort((a, b) => a.localeCompare(b));
      }
      const prev = selMateria.value;
      selMateria.innerHTML = '<option value="">' + (materias.length ? 'Seleccione materia' : 'Seleccione primero uno o más grupos') + '</option>' + materias.map(m => '<option value="' + escapeHtml(m) + '">' + escapeHtml(m) + '</option>').join('');
      if (materias.includes(prev)) selMateria.value = prev;
    }
    chksGrupo.forEach(ch => { ch.onchange = refreshMateriasByGrupo; });
    refreshMateriasByGrupo();

    const btnGuardar = contentEl.querySelector('#nc-ex-guardar');
    btnGuardar.onclick = withButtonLock(btnGuardar, async () => {
      const nombre = contentEl.querySelector('#nc-ex-nombre').value.trim();
      const fecha = contentEl.querySelector('#nc-ex-fecha').value;
      const curso_id = contentEl.querySelector('#nc-ex-curso').value;
      const total_puntos = Number(contentEl.querySelector('#nc-ex-total').value || '0');
      const materia = (contentEl.querySelector('#nc-ex-materia').value || '').trim();
      const grupo_ids = Array.from(contentEl.querySelectorAll('.nc-ex-grupo-chk:checked')).map(ch => Number(ch.value)).filter(v => Number.isFinite(v) && v > 0);
      if (!nombre) { alert('Indique el nombre del exámen.'); return; }
      if (!fecha) { alert('Indique la fecha.'); return; }
      if (!Number.isInteger(total_puntos) || total_puntos <= 0) { alert('Indique un total de examen entero y mayor que cero.'); return; }
      if (!grupo_ids.length) { alert('Seleccione al menos un grupo (Nu, Mu o Zeta).'); return; }
      if (!materia) { alert('Seleccione la materia del examen.'); return; }
      await api('/examenes', {
        method: 'POST',
        body: JSON.stringify({
          nombre,
          tipo: 'ingenieria',
          fecha,
          curso_id: curso_id ? Number(curso_id) : null,
          total_puntos,
          grupo_ids,
          temas: [{ nombre: materia, puntos_maximos: total_puntos }],
        }),
      });
      alert('Exámen creado correctamente.');
      switchExamSubTab('cargar');
    }, { loadingText: 'Guardando...' });
  }

  async function renderCargar() {
    const contentEl = document.getElementById('nc-examenes-content');
    if (!contentEl) return;
    contentEl.innerHTML = '<div class="nc-card"><p>Cargando...</p></div>';
    const hoy = new Date().toISOString().slice(0, 10);
    const hace30 = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    contentEl.innerHTML = `
      <div class="nc-card">
        <h4 style="margin:0 0 12px">Cargar puntajes por alumno</h4>
        <p style="margin:0 0 12px;font-size:13px;color:#666"><strong>Ingeniería:</strong> cargue el puntaje <em>total</em> por alumno (no puede superar el total del examen). <strong>Medicina:</strong> suele cargarse en <em>Medicina (importar)</em>; aquí solo si hace falta ajuste manual.</p>
        <div class="nc-row nc-row-wrap" style="gap:12px;margin-bottom:14px">
          <div class="nc-field">
            <label>Tipo de exámen</label>
            <select id="nc-ex-cargar-tipo" style="padding:8px;min-width:170px;border:1px solid #ddd;border-radius:4px">
              <option value="ingenieria">Ingeniería</option>
              <option value="medicina">Medicina</option>
            </select>
          </div>
          <div class="nc-field">
            <label>Desde</label>
            <input type="date" id="nc-ex-cargar-desde" value="${hace30}" style="padding:8px;border:1px solid #ddd;border-radius:4px" />
          </div>
          <div class="nc-field">
            <label>Hasta</label>
            <input type="date" id="nc-ex-cargar-hasta" value="${hoy}" style="padding:8px;border:1px solid #ddd;border-radius:4px" />
          </div>
          <button type="button" class="nc-btn secondary" id="nc-ex-cargar-buscar-ex">Buscar exámenes</button>
          <div class="nc-field">
            <label>Exámen</label>
            <select id="nc-ex-sel-examen" style="padding:8px;min-width:280px;border:1px solid #ddd;border-radius:4px">
              <option value="">Primero busque por tipo/fecha</option>
            </select>
          </div>
          <button type="button" class="nc-btn primary" id="nc-ex-load-grid">Cargar lista</button>
        </div>
        <div id="nc-ex-grid-filters" style="display:none;gap:10px;align-items:flex-end;margin:8px 0 10px" class="nc-row nc-row-wrap">
          <div class="nc-field">
            <label>Filtrar grupo</label>
            <select id="nc-ex-grid-group" style="padding:8px;min-width:180px;border:1px solid #ddd;border-radius:4px">
              <option value="">Todos</option>
            </select>
          </div>
          <div class="nc-field">
            <label>Buscar alumno</label>
            <input id="nc-ex-grid-search" placeholder="Nombre, apellido o CI" style="padding:8px;min-width:240px;border:1px solid #ddd;border-radius:4px" />
          </div>
          <div class="nc-field">
            <label>Orden alfabético</label>
            <select id="nc-ex-grid-order" style="padding:8px;min-width:180px;border:1px solid #ddd;border-radius:4px">
              <option value="asc">A-Z (menor a mayor)</option>
              <option value="desc">Z-A (mayor a menor)</option>
            </select>
          </div>
        </div>
        <div id="nc-ex-grid-wrap" style="overflow:auto;max-height:70vh"></div>
      </div>
    `;

    function normKey(s) {
      return String(s || '').toLowerCase()
        .replace(/[áàäâ]/g, 'a')
        .replace(/[éèëê]/g, 'e')
        .replace(/[íìïî]/g, 'i')
        .replace(/[óòöô]/g, 'o')
        .replace(/[úùüû]/g, 'u')
        .replace(/ñ/g, 'n');
    }
    function grupoPermitido(tipo, aulaNombre) {
      const k = normKey(aulaNombre);
      if (!k) return false;
      if (tipo === 'medicina') return /(lambda|kappa|pi|xi)/.test(k);
      return /(nu|mu|zeta)/.test(k);
    }

    async function buscarExamenes() {
      const tipo = contentEl.querySelector('#nc-ex-cargar-tipo').value;
      const desde = contentEl.querySelector('#nc-ex-cargar-desde').value;
      const hasta = contentEl.querySelector('#nc-ex-cargar-hasta').value;
      let q = 'tipo=' + encodeURIComponent(tipo);
      if (desde) q += '&fecha_desde=' + encodeURIComponent(desde);
      if (hasta) q += '&fecha_hasta=' + encodeURIComponent(hasta);
      const res = await api('/examenes?' + q);
      const examenes = (res && res.items) ? res.items : [];
      const sel = contentEl.querySelector('#nc-ex-sel-examen');
      sel.innerHTML = '<option value="">Seleccione un exámen</option>' + examenes.map(e =>
        '<option value="' + e.id + '">' + escapeHtml(e.nombre || '') + ' (' + (e.fecha || '') + ') - ' + (e.tipo || '') + '</option>'
      ).join('');
      if (!examenes.length) {
        contentEl.querySelector('#nc-ex-grid-wrap').innerHTML = '<p style="opacity:.8">No hay exámenes para los filtros de tipo/fecha.</p>';
      }
    }

    contentEl.querySelector('#nc-ex-cargar-buscar-ex').onclick = withButtonLock(
      contentEl.querySelector('#nc-ex-cargar-buscar-ex'),
      buscarExamenes,
      { loadingText: 'Buscando...' }
    );

    contentEl.querySelector('#nc-ex-load-grid').onclick = withButtonLock(
      contentEl.querySelector('#nc-ex-load-grid'),
      async () => {
        const examenId = contentEl.querySelector('#nc-ex-sel-examen').value;
        if (!examenId) { alert('Seleccione un exámen.'); return; }
        const [exam, alumnosRes, puntajesRes] = await Promise.all([
          api('/examenes/' + examenId),
          api('/examenes/' + examenId + '/alumnos'),
          api('/examenes/' + examenId + '/puntajes'),
        ]);
        const alumnosRaw = (alumnosRes && alumnosRes.items) ? alumnosRes.items : [];
        const tipoExamen = String((exam && exam.tipo) || contentEl.querySelector('#nc-ex-cargar-tipo').value || '').toLowerCase();
        const gruposExamenIds = (exam && Array.isArray(exam.grupos)) ? exam.grupos.map(g => Number(g.aula_id || g.id || 0)).filter(v => v > 0) : [];
        const temasEx = (exam && exam.temas && exam.temas.length) ? exam.temas : [];
        const materiasEx = (exam && exam.materias && exam.materias.length) ? exam.materias : [];
        const lineas = (tipoExamen === 'ingenieria' && temasEx.length)
          ? temasEx.map(t => ({ kind: 'tema', id: t.id, label: t.nombre || '', max: parseFloat(t.puntos_maximos) || 0 }))
          : materiasEx.map(m => ({ kind: 'materia', id: m.materia_id, label: m.materia_nombre || '', max: parseFloat(m.puntos_maximos) || 0 }));
        const alumnos = alumnosRaw.filter(a => {
          if (gruposExamenIds.length) return gruposExamenIds.includes(Number(a.aula_id || 0));
          return grupoPermitido(tipoExamen, a.aula_nombre || '');
        });
        const puntajesByKey = {};
        ((puntajesRes && puntajesRes.items) ? puntajesRes.items : []).forEach(p => {
          const tid = p.tema_id != null && Number(p.tema_id) > 0 ? Number(p.tema_id) : 0;
          const mid = p.materia_id != null && Number(p.materia_id) > 0 ? Number(p.materia_id) : 0;
          const suffix = tid > 0 ? ('t' + tid) : ('m' + mid);
          puntajesByKey[p.alumno_id + '_' + suffix] = p.puntaje;
        });
        const gridWrap = contentEl.querySelector('#nc-ex-grid-wrap');
        if (!lineas.length) {
          gridWrap.innerHTML = '<p style="opacity:.8">Este exámen no tiene ' + (tipoExamen === 'ingenieria' ? 'temas' : 'materias') + ' definidos. Para ingeniería créelo en <strong>Crear exámen (Ingeniería)</strong>.</p>';
          return;
        }
        if (!alumnos.length) {
          const txt = gruposExamenIds.length
            ? 'No hay alumnos en los grupos seleccionados para este examen.'
            : (tipoExamen === 'medicina'
              ? 'No hay alumnos en grupos Lambda/Kappa/Pi/Xi para este examen.'
              : 'No hay alumnos en grupos Nu/Mu/Zeta para este examen.');
          gridWrap.innerHTML = '<p style="opacity:.8">' + txt + '</p>';
          return;
        }
        const groupFilterWrap = contentEl.querySelector('#nc-ex-grid-filters');
        const groupSel = contentEl.querySelector('#nc-ex-grid-group');
        const searchInp = contentEl.querySelector('#nc-ex-grid-search');
        const orderSel = contentEl.querySelector('#nc-ex-grid-order');
        const gruposSet = {};
        alumnos.forEach(a => { gruposSet[a.aula_nombre || 'Sin grupo'] = 1; });
        groupSel.innerHTML = '<option value="">Todos</option>' + Object.keys(gruposSet).sort((a, b) => a.localeCompare(b)).map(g => '<option value="' + escapeHtml(g) + '">' + escapeHtml(g) + '</option>').join('');
        groupFilterWrap.style.display = 'flex';
        const totalMaxExam = lineas.reduce((s, ln) => s + (parseFloat(ln.max) || 0), 0);
        const colLabel = tipoExamen === 'ingenieria' ? 'tema' : 'materia';
        function currentInputValues() {
          const map = {};
          gridWrap.querySelectorAll('input[data-alumno-id]').forEach(inp => {
            const suf = inp.dataset.temaId != null && inp.dataset.temaId !== '' ? ('t' + inp.dataset.temaId) : ('m' + (inp.dataset.materiaId || ''));
            map[String(inp.dataset.alumnoId) + '_' + suf] = inp.value;
          });
          return map;
        }
        function renderGridFiltered() {
          const prevVals = currentInputValues();
          const group = groupSel.value;
          const query = normKey(searchInp.value || '');
          const order = orderSel.value === 'desc' ? 'desc' : 'asc';
          let filtered = alumnos.filter(a => {
            const g = a.aula_nombre || 'Sin grupo';
            if (group && g !== group) return false;
            if (!query) return true;
            const bag = normKey(((a.apellidos || '') + ' ' + (a.nombres || '') + ' ' + (a.ci || '')));
            return bag.includes(query);
          });
          filtered = filtered.sort((a, b) => {
            const na = ((a.apellidos || '') + ', ' + (a.nombres || '')).trim();
            const nb = ((b.apellidos || '') + ', ' + (b.nombres || '')).trim();
            return order === 'desc' ? nb.localeCompare(na) : na.localeCompare(nb);
          });
          if (!filtered.length) {
            gridWrap.innerHTML = '<p style="opacity:.8">No hay alumnos para los filtros seleccionados.</p>';
            return;
          }
          const grupos = {};
          filtered.forEach(a => {
            const g = (a.aula_nombre || 'Sin grupo');
            if (!grupos[g]) grupos[g] = [];
            grupos[g].push(a);
          });
          let html = '<p style="margin:0 0 10px;font-size:13px">Puntaje máximo total del examen: <strong>' + totalMaxExam.toFixed(2) + '</strong> pts. Columnas = cada ' + colLabel + ' (ingrese lo obtenido por alumno).</p>';
          html += '<table class="nc-table" style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr><th style="padding:8px;text-align:left;border:1px solid #ddd">Alumno</th>';
          lineas.forEach(ln => {
            const mx = ln.max != null ? ln.max : 0;
            html += '<th style="padding:8px;text-align:center;border:1px solid #ddd" title="Máximo ' + mx + ' pts">' + escapeHtml(ln.label || '') + ' <span style="font-weight:400;color:#888">(máx ' + mx + ')</span></th>';
          });
          html += '</tr></thead><tbody>';
          Object.keys(grupos).sort().forEach(gName => {
            html += '<tr style="background:#f5f7ff"><td colspan="' + (lineas.length + 1) + '" style="padding:8px;border:1px solid #ddd;font-weight:600">Grupo: ' + escapeHtml(gName) + '</td></tr>';
            grupos[gName].forEach(al => {
              html += '<tr><td style="padding:8px;border:1px solid #ddd">' + escapeHtml((al.apellidos || '') + ', ' + (al.nombres || '')) + '</td>';
              lineas.forEach(ln => {
                const key = ln.kind === 'tema' ? (al.id + '_t' + ln.id) : (al.id + '_m' + ln.id);
                const rawVal = prevVals[key] != null ? prevVals[key] : (puntajesByKey[key] != null ? puntajesByKey[key] : '');
                const cap = ln.max != null ? ln.max : '';
                const dTipo = ln.kind === 'tema' ? ' data-tema-id="' + ln.id + '"' : ' data-materia-id="' + ln.id + '"';
                html += '<td style="padding:4px;border:1px solid #ddd;text-align:center"><input type="number" step="1" min="0" max="' + cap + '" data-alumno-id="' + al.id + '"' + dTipo + ' value="' + (rawVal !== '' ? rawVal : '') + '" style="width:64px;padding:4px 6px;border:1px solid #ccc;border-radius:4px" title="Máx. ' + cap + '" /></td>';
              });
              html += '</tr>';
            });
          });
          html += '</tbody></table>';
          gridWrap.innerHTML = html;
          let examDraftTimer = null;
          function persistExamCargarDraft() {
            if (!window.NC_AppState) return;
            window.NC_AppState.setExamenesCargarDraft({
              examenId: Number(examenId),
              tipo: contentEl.querySelector('#nc-ex-cargar-tipo')?.value || '',
              desde: contentEl.querySelector('#nc-ex-cargar-desde')?.value || '',
              hasta: contentEl.querySelector('#nc-ex-cargar-hasta')?.value || '',
              valores: currentInputValues(),
            });
          }
          gridWrap.querySelectorAll('input[data-alumno-id]').forEach(inp => {
            inp.addEventListener('input', () => {
              if (examDraftTimer) clearTimeout(examDraftTimer);
              examDraftTimer = setTimeout(persistExamCargarDraft, 400);
            });
          });
          persistExamCargarDraft();
          const btnSave = document.createElement('button');
          btnSave.className = 'nc-btn primary';
          btnSave.textContent = 'Guardar puntajes';
          btnSave.style.marginTop = '12px';
          gridWrap.appendChild(btnSave);
          btnSave.onclick = withButtonLock(btnSave, async () => {
            const allInp = gridWrap.querySelectorAll('input[data-alumno-id]');
            const puntajes = [];
            allInp.forEach(inp => {
              const v = Number(inp.value);
              if (!Number.isInteger(v) || v < 0) return;
              const maxV = Number(inp.max || '0');
              if (Number.isFinite(maxV) && maxV > 0 && v > maxV) return;
              const alumno_id = Number(inp.dataset.alumnoId);
              const row = { alumno_id, puntaje: v };
              if (inp.dataset.temaId != null && inp.dataset.temaId !== '') {
                row.tema_id = Number(inp.dataset.temaId);
              } else if (inp.dataset.materiaId != null && inp.dataset.materiaId !== '') {
                row.materia_id = Number(inp.dataset.materiaId);
              } else {
                return;
              }
              puntajes.push(row);
            });
            await api('/examenes/' + examenId + '/puntajes', { method: 'POST', body: JSON.stringify({ puntajes }) });
            if (window.NC_AppState) window.NC_AppState.setExamenesCargarDraft(null);
            alert('Puntajes guardados: ' + puntajes.length + ' registros.');
          }, { loadingText: 'Guardando...' });
        }
        groupSel.onchange = renderGridFiltered;
        searchInp.oninput = renderGridFiltered;
        orderSel.onchange = renderGridFiltered;
        renderGridFiltered();
      },
      { loadingText: 'Cargando...' }
    );

    (async function restoreExamCargarDraft() {
      if (!window.NC_AppState) return;
      const d = window.NC_AppState.load().examenesCargarDraft;
      if (!d || !d.examenId) return;
      const tipoEl = contentEl.querySelector('#nc-ex-cargar-tipo');
      const desdeEl = contentEl.querySelector('#nc-ex-cargar-desde');
      const hastaEl = contentEl.querySelector('#nc-ex-cargar-hasta');
      if (d.tipo && tipoEl) tipoEl.value = d.tipo;
      if (d.desde && desdeEl) desdeEl.value = d.desde;
      if (d.hasta && hastaEl) hastaEl.value = d.hasta;
      try {
        await buscarExamenes();
        const sel = contentEl.querySelector('#nc-ex-sel-examen');
        if (sel) sel.value = String(d.examenId);
        const loadBtn = contentEl.querySelector('#nc-ex-load-grid');
        if (loadBtn) loadBtn.click();
        setTimeout(() => {
          if (!d.valores) return;
          const gridWrap = contentEl.querySelector('#nc-ex-grid-wrap');
          if (!gridWrap) return;
          Object.keys(d.valores).forEach(key => {
            const km = /^(\d+)_([tm])(\d+)$/.exec(String(key));
            if (!km) return;
            const aid = km[1];
            const inp = km[2] === 't'
              ? gridWrap.querySelector('input[data-alumno-id="' + aid + '"][data-tema-id="' + km[3] + '"]')
              : gridWrap.querySelector('input[data-alumno-id="' + aid + '"][data-materia-id="' + km[3] + '"]');
            if (inp) inp.value = d.valores[key];
          });
          if (window.NC_AppState) {
            window.NC_AppState.setExamenesCargarDraft(d);
          }
        }, 800);
      } catch (_) { /* ignore */ }
    })();
  }

  async function renderVer() {
    const contentEl = document.getElementById('nc-examenes-content');
    if (!contentEl) return;
    contentEl.innerHTML = '<div class="nc-card"><p>Cargando...</p></div>';
    let alumnos = [], cursos = [], aulas = [], examenes = [];
    try {
      const [aRes, cRes, auRes, exRes] = await Promise.all([api('/alumnos'), api('/cursos'), api('/aulas'), api('/examenes')]);
      alumnos = Array.isArray(aRes) ? aRes : (aRes && aRes.items ? aRes.items : []);
      cursos = Array.isArray(cRes) ? cRes : (cRes && cRes.items ? cRes.items : []);
      aulas = Array.isArray(auRes) ? auRes : (auRes && auRes.items ? auRes.items : []);
      examenes = (exRes && exRes.items) ? exRes.items : [];
    } catch (_) {}
    const hoy = new Date().toISOString().slice(0, 10);
    const hace30 = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);

    contentEl.innerHTML = `
      <div class="nc-card">
        <h4 style="margin:0 0 12px">Ver y filtrar puntajes</h4>
        <div class="nc-row nc-row-wrap" style="gap:10px;margin-bottom:14px;align-items:flex-end">
          <div class="nc-field">
            <label>Alumno (buscador)</label>
            <input list="nc-ex-ver-alumnos-list" id="nc-ex-ver-alumno-search" placeholder="Nombre o CI" style="padding:8px;min-width:230px;border:1px solid #ddd;border-radius:4px" />
            <datalist id="nc-ex-ver-alumnos-list">
              ${alumnos.slice(0, 2000).map(a => '<option value="' + escapeHtml((a.apellidos || '') + ', ' + (a.nombres || '') + ' — CI ' + (a.ci || '')) + '" data-id="' + a.id + '"></option>').join('')}
            </datalist>
          </div>
          <div class="nc-field">
            <label>Grupo</label>
            <select id="nc-ex-ver-grupo" style="padding:8px;min-width:170px;border:1px solid #ddd;border-radius:4px">
              <option value="">Todos</option>
              ${(aulas || []).map(g => '<option value="' + g.id + '">' + escapeHtml(g.nombre || '') + '</option>').join('')}
            </select>
          </div>
          <div class="nc-field">
            <label>Curso</label>
            <select id="nc-ex-ver-curso" style="padding:8px;min-width:170px;border:1px solid #ddd;border-radius:4px">
              <option value="">Todos</option>
              ${(cursos || []).map(c => '<option value="' + c.id + '">' + escapeHtml(c.nombre || '') + '</option>').join('')}
            </select>
          </div>
          <div class="nc-field">
            <label>Exámen</label>
            <select id="nc-ex-ver-examen" style="padding:8px;min-width:220px;border:1px solid #ddd;border-radius:4px">
              <option value="">Todos</option>
              ${(examenes || []).map(e => '<option value="' + e.id + '">' + escapeHtml((e.nombre || '') + ' (' + (e.fecha || '') + ')') + '</option>').join('')}
            </select>
          </div>
          <div class="nc-field">
            <label>Tipo</label>
            <select id="nc-ex-ver-tipo" style="padding:8px;min-width:130px;border:1px solid #ddd;border-radius:4px">
              <option value="">Todos</option>
              <option value="medicina">Medicina</option>
              <option value="ingenieria">Ingeniería</option>
            </select>
          </div>
          <div class="nc-field">
            <label>Ordenar por</label>
            <select id="nc-ex-ver-order" style="padding:8px;min-width:170px;border:1px solid #ddd;border-radius:4px">
              <option value="pct_desc">% mayor a menor</option>
              <option value="pct_asc">% menor a mayor</option>
              <option value="alumno_asc">Alumno A-Z</option>
              <option value="alumno_desc">Alumno Z-A</option>
            </select>
          </div>
          <div class="nc-field"><label>Desde</label><input type="date" id="nc-ex-ver-desde" value="${hace30}" style="padding:8px;border:1px solid #ddd;border-radius:4px" /></div>
          <div class="nc-field"><label>Hasta</label><input type="date" id="nc-ex-ver-hasta" value="${hoy}" style="padding:8px;border:1px solid #ddd;border-radius:4px" /></div>
          <button type="button" class="nc-btn primary" id="nc-ex-ver-buscar">Buscar</button>
        </div>
        <div id="nc-ex-ver-table-wrap"><p style="opacity:.75;margin:0">Aplique filtros y presione Buscar.</p></div>
        <div id="nc-ex-ver-student-wrap" style="margin-top:16px"></div>
      </div>`;

    function resolveAlumnoIdBySearch() {
      const val = (contentEl.querySelector('#nc-ex-ver-alumno-search').value || '').trim().toLowerCase();
      if (!val) return '';
      const m = alumnos.find(a => (((a.apellidos || '') + ', ' + (a.nombres || '') + ' — CI ' + (a.ci || '')).toLowerCase() === val));
      if (m) return String(m.id);
      const ci = val.replace(/\D+/g, '');
      if (ci) {
        const byCi = alumnos.find(a => String(a.ci || '').replace(/\D+/g, '') === ci);
        if (byCi) return String(byCi.id);
      }
      return '';
    }

    function aggregateRows(items) {
      const map = {};
      items.forEach(p => {
        const key = String(p.examen_id) + '_' + String(p.alumno_id);
        if (!map[key]) {
          map[key] = {
            examen_id: p.examen_id,
            examen_nombre: p.examen_nombre || '',
            examen_fecha: p.examen_fecha || '',
            alumno_id: p.alumno_id,
            alumno_nombre: ((p.alumno_apellidos || '') + ', ' + (p.alumno_nombres || '')).trim(),
            alumno_ci: p.alumno_ci || '',
            curso_nombre: p.curso_nombre || '',
            aula_nombre: p.aula_nombre || '',
            total_obtenido: 0,
            total_puntos: 0,
          };
        }
        map[key].total_obtenido += parseFloat(p.puntaje || 0) || 0;
        map[key].total_puntos += parseFloat(p.puntos_maximos || 0) || 0;
      });
      return Object.values(map).map(r => {
        const pct = r.total_puntos > 0 ? (r.total_obtenido / r.total_puntos) * 100 : 0;
        r.porcentaje = pct;
        return r;
      });
    }

    function sortAggregated(rows, order) {
      const copy = rows.slice();
      copy.sort((a, b) => {
        if (order === 'pct_asc') return a.porcentaje - b.porcentaje;
        if (order === 'alumno_asc') return (a.alumno_nombre || '').localeCompare(b.alumno_nombre || '');
        if (order === 'alumno_desc') return (b.alumno_nombre || '').localeCompare(a.alumno_nombre || '');
        return b.porcentaje - a.porcentaje;
      });
      return copy;
    }

    async function openStudentSummaryModal(alumnoId, fechaDesde, fechaHasta) {
      if (!alumnoId) return;
      const modal = document.createElement('div');
      modal.className = 'nc-modal-overlay';
      modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.48);display:flex;align-items:center;justify-content:center;z-index:99999;padding:14px;overflow:auto';
      modal.innerHTML = `
        <div class="nc-modal nc-modal-scroll" style="max-width:1100px;width:100%;max-height:92vh;background:#fff;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.16);display:flex;flex-direction:column">
          <div style="padding:14px 18px;border-bottom:1px solid #e6e6e6;display:flex;justify-content:space-between;align-items:center">
            <h4 style="margin:0">Resumen del estudiante</h4>
            <button type="button" class="nc-modal-close" style="border:none;background:transparent;font-size:26px;cursor:pointer;line-height:1">×</button>
          </div>
          <div class="nc-modal-body" style="padding:16px;overflow:auto"><p style="opacity:.75">Cargando…</p></div>
        </div>`;
      const close = () => modal.remove();
      modal.onclick = (e) => { if (e.target === modal) close(); };
      modal.querySelector('.nc-modal-close').onclick = close;
      document.body.appendChild(modal);

      const bodyEl = modal.querySelector('.nc-modal-body');
      let q = 'alumno_id=' + encodeURIComponent(alumnoId);
      if (fechaDesde) q += '&fecha_desde=' + encodeURIComponent(fechaDesde);
      if (fechaHasta) q += '&fecha_hasta=' + encodeURIComponent(fechaHasta);
      const [alumno, res, hist] = await Promise.all([
        api('/alumnos/' + encodeURIComponent(alumnoId)),
        api('/puntajes?' + q),
        api('/puntajes/historial?alumno_id=' + encodeURIComponent(alumnoId) + '&limit=120').catch(() => ({ items: [] })),
      ]);
      const items = (res && res.items) ? res.items : [];
      const canEdit = !!(res && res.can_edit_notas);
      if (!items.length) {
        bodyEl.innerHTML = '<p style="opacity:.75">Sin resultados del alumno en ese rango.</p>';
        return;
      }
      const byEx = {};
      items.forEach(p => {
        const k = String(p.examen_id);
        if (!byEx[k]) byEx[k] = { examen_id: p.examen_id, nombre: p.examen_nombre || '', fecha: p.examen_fecha || '', total: 0, max: 0, lineas: [] };
        const punt = parseFloat(p.puntaje || 0) || 0;
        const max = parseFloat(p.puntos_maximos || 0) || 0;
        byEx[k].total += punt;
        byEx[k].max += max;
        byEx[k].lineas.push({
          id: p.id,
          materia_id: p.materia_id,
          tema_id: p.tema_id,
          materia: p.materia_nombre || '',
          puntaje: punt,
          max,
        });
      });
      const exams = Object.values(byEx).sort((a, b) => String(a.fecha).localeCompare(String(b.fecha)));
      const pts = exams.map(e => ({ x: e.fecha, y: e.max > 0 ? (e.total / e.max) * 100 : 0 }));
      const graphW = 980, graphH = 180, pad = 28;
      const n = Math.max(pts.length, 1);
      const line = pts.map((p, i) => {
        const x = pad + (i * (graphW - pad * 2)) / Math.max(n - 1, 1);
        const y = graphH - pad - ((Math.max(0, Math.min(100, p.y)) / 100) * (graphH - pad * 2));
        return `${x},${y}`;
      }).join(' ');

      const foto = (alumno && alumno.foto_url) ? '<img src="' + escapeHtml(alumno.foto_url) + '" alt="" style="width:92px;height:92px;border-radius:10px;object-fit:cover;border:1px solid #ddd" />' : '<div style="width:92px;height:92px;border-radius:10px;border:1px solid #ddd;background:#f2f2f2;display:flex;align-items:center;justify-content:center;color:#888;font-size:11px">SIN FOTO</div>';
      bodyEl.innerHTML = `
        <div style="display:flex;gap:14px;align-items:flex-start;margin-bottom:12px">
          <div>${foto}</div>
          <div>
            <h4 style="margin:0 0 6px">${escapeHtml(((alumno && alumno.apellidos) || '') + ', ' + ((alumno && alumno.nombres) || ''))}</h4>
            <div style="font-size:13px;color:#555;line-height:1.6">
              <div><b>CI:</b> ${escapeHtml((alumno && alumno.ci) || '-')}</div>
              <div><b>Curso:</b> ${escapeHtml((alumno && alumno.curso_nombre) || '-')}</div>
              <div><b>Grupo:</b> ${escapeHtml((alumno && alumno.aula_nombre) || '-')}</div>
              <div><b>Carrera:</b> ${escapeHtml((alumno && alumno.carrera_nombre) || '-')}</div>
            </div>
          </div>
        </div>
        <h5 style="margin:8px 0">Historial de exámenes</h5>
        <div style="overflow:auto;max-height:290px;border:1px solid #e8e8e8;border-radius:6px">
          <table class="nc-table" style="width:100%;border-collapse:collapse;font-size:13px">
            <thead><tr style="background:#f6f6f6"><th style="padding:8px;border:1px solid #ddd">Fecha</th><th style="padding:8px;border:1px solid #ddd">Examen</th><th style="padding:8px;border:1px solid #ddd;text-align:right">Obtenido</th><th style="padding:8px;border:1px solid #ddd;text-align:right">Total</th><th style="padding:8px;border:1px solid #ddd;text-align:right">%</th><th style="padding:8px;border:1px solid #ddd">Editar</th></tr></thead>
            <tbody>
              ${exams.map(e => {
                const pct = e.max > 0 ? ((e.total / e.max) * 100).toFixed(1) : '0.0';
                return '<tr class="nc-st-ex" data-ex="' + e.examen_id + '"><td style="padding:8px;border:1px solid #ddd">' + escapeHtml(e.fecha) + '</td><td style="padding:8px;border:1px solid #ddd">' + escapeHtml(e.nombre) + '</td><td style="padding:8px;border:1px solid #ddd;text-align:right">' + e.total.toFixed(2) + '</td><td style="padding:8px;border:1px solid #ddd;text-align:right">' + e.max.toFixed(2) + '</td><td style="padding:8px;border:1px solid #ddd;text-align:right">' + pct + '%</td><td style="padding:8px;border:1px solid #ddd">' + (canEdit ? '<button class="nc-btn secondary small nc-edit-ex" data-ex="' + e.examen_id + '">Editar notas</button>' : '<span style="opacity:.65">Sin permiso</span>') + '</td></tr>';
              }).join('')}
            </tbody>
          </table>
        </div>
        <div id="nc-st-ex-det" style="margin-top:10px"></div>
        <h5 style="margin:12px 0 6px">Historial de modificaciones</h5>
        <div style="overflow:auto;max-height:180px;border:1px solid #e8e8e8;border-radius:6px">
          <table class="nc-table" style="width:100%;border-collapse:collapse;font-size:12px">
            <thead><tr style="background:#f8f8f8"><th style="padding:6px;border:1px solid #ddd">Fecha/Hora</th><th style="padding:6px;border:1px solid #ddd">Examen</th><th style="padding:6px;border:1px solid #ddd">Materia/Tema</th><th style="padding:6px;border:1px solid #ddd;text-align:right">Antes</th><th style="padding:6px;border:1px solid #ddd;text-align:right">Después</th><th style="padding:6px;border:1px solid #ddd">Usuario</th></tr></thead>
            <tbody>${((hist && hist.items) ? hist.items : []).map(h => '<tr><td style="padding:6px;border:1px solid #ddd">' + escapeHtml(h.created_at || '') + '</td><td style="padding:6px;border:1px solid #ddd">' + escapeHtml(h.examen_nombre || '') + '</td><td style="padding:6px;border:1px solid #ddd">' + escapeHtml(h.materia_nombre || '-') + '</td><td style="padding:6px;border:1px solid #ddd;text-align:right">' + escapeHtml(String(h.puntaje_anterior ?? '')) + '</td><td style="padding:6px;border:1px solid #ddd;text-align:right">' + escapeHtml(String(h.puntaje_nuevo ?? '')) + '</td><td style="padding:6px;border:1px solid #ddd">' + escapeHtml(h.editado_por_nombre || h.editado_por_login || '') + '</td></tr>').join('') || '<tr><td colspan="6" style="padding:8px;border:1px solid #ddd;opacity:.7">Sin modificaciones registradas.</td></tr>'}</tbody>
          </table>
        </div>
        <h5 style="margin:12px 0 6px">Progreso (%)</h5>
        <svg width="${graphW}" height="${graphH}" style="border:1px solid #eee;background:#fff;max-width:100%"><line x1="${pad}" y1="${graphH - pad}" x2="${graphW - pad}" y2="${graphH - pad}" stroke="#ccc"/><line x1="${pad}" y1="${pad}" x2="${pad}" y2="${graphH - pad}" stroke="#ccc"/><polyline fill="none" stroke="#2c7be5" stroke-width="2" points="${line}"/></svg>
      `;
      const detWrap = bodyEl.querySelector('#nc-st-ex-det');
      bodyEl.querySelectorAll('.nc-st-ex').forEach(tr => {
        tr.onclick = () => {
          const exId = tr.getAttribute('data-ex');
          const ex = exams.find(x => String(x.examen_id) === String(exId));
          if (!ex) return;
          let det = '<h5 style="margin:8px 0">Puntajes por materia/tema</h5><table class="nc-table" style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr style="background:#f5f5f5"><th style="padding:6px;border:1px solid #ddd">Materia/Tema</th><th style="padding:6px;border:1px solid #ddd;text-align:right">Obtenido</th><th style="padding:6px;border:1px solid #ddd;text-align:right">Total</th><th style="padding:6px;border:1px solid #ddd;text-align:right">%</th></tr></thead><tbody>';
          ex.lineas.forEach(m => {
            const pct = m.max > 0 ? ((m.puntaje / m.max) * 100).toFixed(1) : '0.0';
            det += '<tr><td style="padding:6px;border:1px solid #ddd">' + escapeHtml(m.materia) + '</td><td style="padding:6px;border:1px solid #ddd;text-align:right">' + m.puntaje.toFixed(2) + '</td><td style="padding:6px;border:1px solid #ddd;text-align:right">' + m.max.toFixed(2) + '</td><td style="padding:6px;border:1px solid #ddd;text-align:right">' + pct + '%</td></tr>';
          });
          det += '</tbody></table>';
          detWrap.innerHTML = det;
        };
      });

      bodyEl.querySelectorAll('.nc-edit-ex').forEach(btn => {
        btn.onclick = withButtonLock(btn, async () => {
          const exId = btn.getAttribute('data-ex');
          const ex = exams.find(x => String(x.examen_id) === String(exId));
          if (!ex) return;
          const motivo = prompt('Motivo del ajuste (opcional):', '') || '';
          const edits = ex.lineas.map(m => {
            const nv = prompt('Nuevo puntaje para ' + m.materia + ' (actual: ' + m.puntaje.toFixed(2) + ')', String(m.puntaje));
            if (nv == null) return null;
            const v = parseFloat(String(nv).replace(',', '.'));
            if (!isFinite(v)) return null;
            return { alumno_id: Number(alumnoId), materia_id: Number(m.materia_id || 0), tema_id: Number(m.tema_id || 0), puntaje: v };
          }).filter(Boolean);
          if (!edits.length) return;
          const save = await api('/examenes/' + exId + '/puntajes', { method: 'POST', body: JSON.stringify({ motivo, puntajes: edits }) });
          alert('Notas guardadas. Cambios: ' + (save.updated != null ? save.updated : save.saved) + ', bloqueados: ' + (save.blocked || 0));
          close();
          await openStudentSummaryModal(alumnoId, fechaDesde, fechaHasta);
        }, { loadingText: 'Guardando...' });
      });
    }

    contentEl.querySelector('#nc-ex-ver-buscar').onclick = withButtonLock(
      contentEl.querySelector('#nc-ex-ver-buscar'),
      async () => {
        const alumno_id = resolveAlumnoIdBySearch();
        const curso_id = contentEl.querySelector('#nc-ex-ver-curso').value;
        const aula_id = contentEl.querySelector('#nc-ex-ver-grupo').value;
        const examen_id = contentEl.querySelector('#nc-ex-ver-examen').value;
        const tipo_ex = contentEl.querySelector('#nc-ex-ver-tipo').value;
        const order = contentEl.querySelector('#nc-ex-ver-order').value;
        const fecha_desde = contentEl.querySelector('#nc-ex-ver-desde').value;
        const fecha_hasta = contentEl.querySelector('#nc-ex-ver-hasta').value;
        let q = '';
        if (alumno_id) q += '&alumno_id=' + encodeURIComponent(alumno_id);
        if (curso_id) q += '&curso_id=' + encodeURIComponent(curso_id);
        if (aula_id) q += '&aula_id=' + encodeURIComponent(aula_id);
        if (examen_id) q += '&examen_id=' + encodeURIComponent(examen_id);
        if (tipo_ex) q += '&tipo=' + encodeURIComponent(tipo_ex);
        if (fecha_desde) q += '&fecha_desde=' + encodeURIComponent(fecha_desde);
        if (fecha_hasta) q += '&fecha_hasta=' + encodeURIComponent(fecha_hasta);
        const res = await api('/puntajes?' + q.replace(/^&/, ''));
        const items = (res && res.items) ? res.items : [];
        const wrap = contentEl.querySelector('#nc-ex-ver-table-wrap');
        if (!items.length) {
          wrap.innerHTML = '<p style="opacity:.8">No hay puntajes con los filtros seleccionados.</p>';
          contentEl.querySelector('#nc-ex-ver-student-wrap').innerHTML = '';
          return;
        }
        const agg = sortAggregated(aggregateRows(items), order);
        wrap.innerHTML = '<table class="nc-table" style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr style="background:#f5f5f5"><th style="padding:8px;border:1px solid #ddd">Fecha</th><th style="padding:8px;border:1px solid #ddd">Examen</th><th style="padding:8px;border:1px solid #ddd">Grupo</th><th style="padding:8px;border:1px solid #ddd">Alumno</th><th style="padding:8px;border:1px solid #ddd;text-align:right">Puntos totales</th><th style="padding:8px;border:1px solid #ddd;text-align:right">Puntos obtenidos</th><th style="padding:8px;border:1px solid #ddd;text-align:right">%</th></tr></thead><tbody>' +
          agg.map(r => '<tr class="nc-ver-row" data-alumno="' + r.alumno_id + '" style="cursor:pointer"><td style="padding:8px;border:1px solid #ddd">' + escapeHtml(r.examen_fecha || '') + '</td><td style="padding:8px;border:1px solid #ddd">' + escapeHtml(r.examen_nombre || '') + '</td><td style="padding:8px;border:1px solid #ddd">' + escapeHtml(r.aula_nombre || '-') + '</td><td style="padding:8px;border:1px solid #ddd">' + escapeHtml(r.alumno_nombre || '') + '</td><td style="padding:8px;border:1px solid #ddd;text-align:right">' + r.total_puntos.toFixed(2) + '</td><td style="padding:8px;border:1px solid #ddd;text-align:right">' + r.total_obtenido.toFixed(2) + '</td><td style="padding:8px;border:1px solid #ddd;text-align:right">' + r.porcentaje.toFixed(1) + '%</td></tr>').join('') +
          '</tbody></table>';
        wrap.querySelectorAll('.nc-ver-row').forEach(tr => {
          tr.onclick = () => openStudentSummaryModal(tr.getAttribute('data-alumno'), fecha_desde, fecha_hasta);
        });
        if (alumno_id) await openStudentSummaryModal(alumno_id, fecha_desde, fecha_hasta);
      },
      { loadingText: 'Buscando...' }
    );
  }

  window.NC_Examenes = { render };
})();
