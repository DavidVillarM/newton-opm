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
    let data;
    if (ct.includes('application/json')) {
      try {
        data = await res.json();
      } catch (e) {
        const text = await res.text().catch(() => '');
        throw new Error(`Error al parsear respuesta JSON: ${text.substring(0, 200)}`);
      }
    } else {
      data = await res.text();
    }
    if (!res.ok) {
      // Si recibimos HTML en lugar de JSON, es un error crítico de WordPress
      if (typeof data === 'string' && data.includes('<!DOCTYPE') && data.includes('error crítico')) {
        throw new Error('Error crítico en el servidor. Por favor, revisa los logs de WordPress.');
      }
      throw new Error((data && data.message) || (typeof data === 'string' ? data.substring(0, 200) : res.statusText));
    }
    return data;
  }

  /**
   * Evita doble envío: deshabilita el botón mientras se ejecuta la acción asíncrona.
   * @param {HTMLButtonElement} button - Botón a bloquear
   * @param {() => Promise<void>} asyncFn - Función async a ejecutar
   * @param {{ loadingText?: string }} options - loadingText: texto mientras carga (ej. "Guardando...")
   */
  function withButtonLock(button, asyncFn, options = {}) {
    const loadingText = options.loadingText != null ? options.loadingText : null;
    return async function lockedHandler(e) {
      if (button.disabled || button.getAttribute('aria-busy') === 'true') return;
      const originalText = button.textContent;
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      if (loadingText != null) button.textContent = loadingText;
      button.classList.add('nc-loading');
      try {
        await Promise.resolve(asyncFn(e));
      } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        if (loadingText != null) button.textContent = originalText;
        button.classList.remove('nc-loading');
      }
    };
  }

  /** Exportar archivo vía fetch (misma sesión/nonce) para evitar 401 al abrir en nueva pestaña */
  async function exportAlumnoFile(alumnoId, format) {
    const url = API + '/alumnos/' + alumnoId + '/export/' + format + '?nc_ts=' + Date.now();
    const headers = { 'X-WP-Nonce': nonce };
    const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers });
    if (!res.ok) {
      const err = await res.text().catch(() => '');
      let msg = 'No tienes permisos para exportar.';
      try {
        const j = JSON.parse(err);
        if (j && j.message) msg = j.message;
      } catch (_) {}
      throw new Error(msg);
    }
    const blob = await res.blob();
    const disposition = res.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="?([^";\n]+)"?/);
    const defaultName = format === 'csv' ? 'rendimiento_' + alumnoId + '.csv' : 'rendimiento_' + alumnoId + '.pdf';
    const fileName = (match && match[1]) ? match[1].trim() : defaultName;
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
  }

  /** Exportar historial de ASISTENCIA del alumno (CSV/PDF) desde el modal Editar/Ver Alumno */
  async function exportAlumnoAsistenciaFile(alumnoId, format, fechaDesde, fechaHasta) {
    const baseUrl = API || ((window.NC_APP && window.NC_APP.apiUrl)
      ? (String(window.NC_APP.apiUrl).startsWith('http')
          ? String(window.NC_APP.apiUrl)
          : (window.location.origin + String(window.NC_APP.apiUrl)))
          .replace(/\/$/, '')
      : window.location.origin + '/wp-json/conducta/v1');
    let url = baseUrl + '/asistencia/alumno/' + alumnoId + '/export/' + format + '?nc_ts=' + Date.now();
    if (fechaDesde) url += '&fecha_desde=' + encodeURIComponent(fechaDesde);
    if (fechaHasta) url += '&fecha_hasta=' + encodeURIComponent(fechaHasta);
    try {
      const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': nonce } });
      if (!res.ok) {
        const err = await res.text().catch(() => '');
        let msg = 'Error al exportar.';
        try {
          const j = JSON.parse(err);
          if (j && j.message) msg = j.message;
        } catch (_) {}
        // Si hay problema con fetch (por permisos o cabeceras), abrimos en nueva pestaña como fallback
        window.open(url, '_blank');
        throw new Error(msg);
      }
      const blob = await res.blob();
      const disposition = res.headers.get('Content-Disposition') || '';
      const match = disposition.match(/filename="?([^";\n]+)"?/);
      const defaultName = format === 'csv' ? 'asistencia-alumno-' + alumnoId + '.csv' : 'asistencia-alumno-' + alumnoId + '.pdf';
      const fileName = (match && match[1]) ? match[1].trim() : defaultName;
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = fileName;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 5000);
    } catch (e) {
      // Si el error viene de red / CORS / etc., intentamos igualmente abrir en nueva pestaña
      try { window.open(url, '_blank'); } catch (_) {}
      if (e && e.message) throw e;
      throw new Error('Error al exportar.');
    }
  }

  function el(tag, attrs, html) {
    const e = document.createElement(tag);
    if (attrs) for (const k in attrs) e.setAttribute(k, String(attrs[k]));
    if (html !== undefined) e.innerHTML = html;
    return e;
  }
  function escapeHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  /** Fecha YYYY-MM-DD en zona horaria local (evita desfase de toISOString/UTC). */
  function localDateYMD(d) {
    const x = d instanceof Date ? d : new Date();
    const yyyy = x.getFullYear();
    const mm = String(x.getMonth() + 1).padStart(2, '0');
    const dd = String(x.getDate()).padStart(2, '0');
    return yyyy + '-' + mm + '-' + dd;
  }

  function defaultAsisDateRange(monthsBack) {
    const to = new Date();
    const from = new Date();
    from.setMonth(from.getMonth() - (monthsBack || 24));
    return { from: localDateYMD(from), to: localDateYMD(to) };
  }

  function parseSesionesItems(res) {
    if (Array.isArray(res)) return res;
    if (res && Array.isArray(res.items)) return res.items;
    if (res && res.data && Array.isArray(res.data.items)) return res.data.items;
    return [];
  }

  function buildSesionesQuery(opts) {
    const parts = [];
    if (opts.fecha_desde) parts.push('fecha_desde=' + encodeURIComponent(opts.fecha_desde));
    if (opts.fecha_hasta) parts.push('fecha_hasta=' + encodeURIComponent(opts.fecha_hasta));
    if (opts.materia_id) parts.push('materia_id=' + encodeURIComponent(opts.materia_id));
    const grupoId = opts.grupo_id || opts.aula_id;
    if (grupoId) {
      parts.push('grupo_id=' + encodeURIComponent(grupoId));
      parts.push('aula_id=' + encodeURIComponent(grupoId));
    }
    if (opts.solo_mias) parts.push('solo_mias=1');
    parts.push('nc_ts=' + Date.now());
    return parts.join('&');
  }

  function canUserEditSesion(s, perms) {
    if (perms && perms.can_manage_attendance) return true;
    const uid = (window.NC_APP && window.NC_APP.currentUserId != null) ? Number(window.NC_APP.currentUserId) : 0;
    return uid > 0 && Number(s.creado_por) === uid;
  }

  /** Refuerzo en cliente por si el servidor no aplicó filtros. */
  function filterSesionesItems(items, opts) {
    if (!Array.isArray(items)) return [];
    let out = items;
    const o = opts || {};
    if (o.fecha_desde) out = out.filter(s => String(s.fecha || '') >= o.fecha_desde);
    if (o.fecha_hasta) out = out.filter(s => String(s.fecha || '') <= o.fecha_hasta);
    if (o.materia_id) {
      const mid = Number(o.materia_id);
      out = out.filter(s => Number(s.materia_id) === mid);
    }
    const gid = o.grupo_id || o.aula_id;
    if (gid) {
      const g = Number(gid);
      out = out.filter(s => Number(s.grupo_id) === g || Number(s.aula_id) === g);
    }
    if (o.search) {
      const q = String(o.search).toLowerCase().trim();
      if (q) {
        out = out.filter(s => {
          const txt = [
            s.fecha, s.curso_nombre, s.materia_nombre, s.docente_encargado_nombre,
            s.grupo_nombre, s.aula_fisica_nombre, s.creado_por_nombre, s.presentes_total
          ].join(' ').toLowerCase();
          return txt.indexOf(q) >= 0;
        });
      }
    }
    return out;
  }

  function listPanelReady(selector) {
    return !!(contentEl && contentEl.querySelector(selector));
  }

  /** Aulas/grupos para filtros: excluye aulas físicas puras (K, L…) sin alumnos de grupo. */
  function filterAulasGrupoDropdown(aulas) {
    const fisicas = new Set(['K', 'L', 'M', 'N', 'X', 'P', 'Z', 'S']);
    return (aulas || []).filter(a => {
      const raw = String(a.nombre || '').trim();
      const hasArrow = raw.indexOf('->') !== -1;
      const code = raw.split('->')[0].trim().toUpperCase();
      return !(fisicas.has(code) && !hasArrow);
    });
  }

  function aulaGrupoOptionLabel(a) {
    const raw = String(a.nombre || '').trim();
    return raw.indexOf('->') !== -1 ? raw.split('->')[1].trim() : raw;
  }

  // ✨ NUEVAS FUNCIONES: Actualizar dinámicamente presentes
  /**
   * Obtiene el total actualizado de presentes para una sesión
   * @param {number} asistenciaId - ID de la sesión de asistencia
   * @returns {Promise<Object>} { presentes, total, porcentaje, formato }
   */
  async function getTotalPresentes(asistenciaId) {
    try {
      const res = await api(`/asistencia/sesiones/${asistenciaId}/total-presentes`);
      return res;
    } catch (e) {
      console.error('Error al obtener totales:', e);
      return null;
    }
  }

  /**
   * Actualiza el contador visual de presentes en la tabla de reportes
   * @param {number} asistenciaId - ID de la sesión
   * @param {string} presentes - Formato "X/Y" ej: "19/84"
   */
  function actualizarContadorPresentes(asistenciaId, presentes) {
    const filas = document.querySelectorAll('[data-asistencia-id="' + asistenciaId + '"]');
    filas.forEach(fila => {
      const celdaPresentes = fila.querySelector('.nc-presentes-cell');
      if (celdaPresentes) {
        celdaPresentes.textContent = presentes;
        // Animación visual
        celdaPresentes.style.backgroundColor = '#c8e6c9';
        setTimeout(() => {
          celdaPresentes.style.backgroundColor = '';
        }, 500);
      }
    });
  }

  let permissionsCache = null;
  async function getPermissions() {
    if (permissionsCache) return permissionsCache;
    try {
      permissionsCache = await api('/user/permissions');
      return permissionsCache;
    } catch (_) {
      return {};
    }
  }
  function canManageStudents() {
    return !!(permissionsCache && permissionsCache.can_manage_students);
  }

  let viewEl = null;
  let contentEl = null;
  let subTabBtns = [];
  /** Subpestaña activa; evita que renders async obsoletos pisen otra vista. */
  let activeSubId = null;
  function isActiveSub(id) {
    return activeSubId === id && contentEl && contentEl.isConnected;
  }

  const SUB_TABS = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'marcar', label: 'Marcar asistencia' },
    { id: 'simulacro', label: 'Simulacro' },
    { id: 'mis-asistencias', label: 'Mis asistencias registradas' },
    { id: 'reportes', label: 'Reportes' },
    { id: 'lista-alumnos', label: 'Lista de alumnos' },
    { id: 'materias', label: 'Materias' },
  ];

  function setActiveSub(id) {
    subTabBtns.forEach(b => b.dataset.active = (b.dataset.sub === id ? '1' : '0'));
  }

  function getPersistedActiveSub(validIds) {
    if (window.NC_AppState) {
      const sub = window.NC_AppState.load().asistenciaSub;
      if (sub && validIds.includes(sub)) return sub;
    }
    try {
      const saved = sessionStorage.getItem('nc_asistencia_active_sub');
      if (saved && validIds.includes(saved)) return saved;
    } catch (_) { /* ignore */ }
    return 'dashboard';
  }
  function persistActiveSub(id) {
    if (window.NC_AppState) window.NC_AppState.persistRoute({ mainTab: 'asistencia-main', asistenciaSub: id });
    try { sessionStorage.setItem('nc_asistencia_active_sub', id); } catch (_) { /* ignore */ }
  }

  async function render(view, opts) {
    opts = opts || {};
    viewEl = view;
    viewEl.innerHTML = '';
    if (window.NC_AppState) window.NC_AppState.persistRoute({ mainTab: 'asistencia-main' });
    await getPermissions();

    if (!document.getElementById('nc-asistencia-styles')) {
      const style = el('style', { id: 'nc-asistencia-styles' }, `
        .nc-asistencia-wrap { padding: 0 4px; }
        .nc-asistencia-subtabs { margin-bottom: 12px; }
        .nc-asistencia-subtabs .nc-tab { padding: 8px 14px; font-size: 13px; }
        .nc-asis-acciones { white-space: nowrap; }
        .nc-asis-btn-asistio { width: 36px; height: 36px; border-radius: 8px; border: 1px solid #ccc; background: #fff; cursor: pointer; font-size: 16px; margin-right: 4px; }
        .nc-asis-btn-asistio.activo { background: #2e7d32; color: #fff; border-color: #2e7d32; }
        .nc-toggle-switch { position: relative; display: inline-block; width: 50px; height: 24px; }
        .nc-toggle-switch input { opacity: 0; width: 0; height: 0; }
        .nc-toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 24px; }
        .nc-toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        .nc-toggle-switch input:checked + .nc-toggle-slider { background-color: #2e7d32; }
        .nc-toggle-switch input:checked + .nc-toggle-slider:before { transform: translateX(26px); }
        .nc-toggle-switch input:focus + .nc-toggle-slider { box-shadow: 0 0 1px #2e7d32; }
        .nc-asistencia-content .nc-table { font-size: 14px; }
        .nc-asistencia-content .nc-row-wrap { flex-wrap: wrap; }
        .nc-asistencia-content .nc-row { min-width: 0; }
        .nc-asistencia-content .nc-field { min-width: 0; max-width: 100%; }
        .nc-asistencia-content .nc-card { max-width: 100%; overflow-x: auto; box-sizing: border-box; }
        /* Campos de fecha y formularios: alineación y espaciado consistente */
        .nc-asistencia-wrap .nc-field label,
        .nc-asistencia-content .nc-field label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: #333; }
        .nc-asistencia-wrap .nc-field input,
        .nc-asistencia-wrap .nc-field select,
        .nc-asistencia-content .nc-field input,
        .nc-asistencia-content .nc-field select { width: 100%; min-width: 0; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; box-sizing: border-box; text-align: left; background: #fff; }
        .nc-asistencia-wrap input[type="date"],
        .nc-asistencia-content input[type="date"],
        .nc-modal-overlay input[type="date"] { text-align: left !important; padding: 8px 10px !important; border: 1px solid #ddd !important; border-radius: 6px !important; font-size: 14px !important; box-sizing: border-box !important; min-width: 0; width: 100%; background: #fff; }
        .nc-asis-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; max-width: 100%; }
        .nc-asis-table-scroll .nc-table { min-width: 560px; }
        @media (max-width: 768px) {
          .nc-asistencia-wrap .nc-tabs { flex-direction: column; }
          .nc-asistencia-stats { grid-template-columns: 1fr !important; }
        }
        .nc-modal-overlay { position: fixed !important; inset: 0 !important; background: rgba(0,0,0,0.5) !important; display: flex !important; align-items: center !important; justify-content: center !important; z-index: 99999 !important; padding: 16px !important; overflow-y: auto !important; }
        .nc-modal-overlay .nc-modal { position: relative !important; margin: auto !important; flex-shrink: 0 !important; }
      `);
      document.head.appendChild(style);
    }

    subTabBtns = [];
    const wrap = el('div', { class: 'nc-asistencia-wrap' });
    const subTabs = el('div', { class: 'nc-tabs nc-asistencia-subtabs' });
    const isAdmin = !!(permissionsCache && permissionsCache.is_admin);
    const canViewReportesAsistencia = !!(permissionsCache && permissionsCache.can_view_reportes_asistencia);
    const tabsToShow = SUB_TABS.filter(t => (t.id !== 'materias' || isAdmin) && (t.id !== 'reportes' || canViewReportesAsistencia));
    tabsToShow.forEach(t => {
      const b = el('button', { class: 'nc-tab', 'data-sub': t.id }, t.label);
      b.onclick = () => { setActiveSub(t.id); renderSub(t.id); };
      subTabs.appendChild(b);
      subTabBtns.push(b);
    });
    wrap.appendChild(subTabs);
    contentEl = el('div', { id: 'nc-asistencia-content', class: 'nc-asistencia-content' });
    wrap.appendChild(contentEl);
    viewEl.appendChild(wrap);

    const validSubIds = tabsToShow.map(t => t.id);
    const initialSub = (opts.initialSub && validSubIds.includes(opts.initialSub))
      ? opts.initialSub
      : getPersistedActiveSub(validSubIds);
    setActiveSub(initialSub);
    renderSub(initialSub);
  }

  async function renderSub(id) {
    if (!contentEl) return;
    activeSubId = id;
    persistActiveSub(id);
    if (id === 'dashboard') return renderDashboard();
    if (id === 'marcar') return renderMarcar();
    if (id === 'simulacro') return renderSimulacroHub();
    if (id === 'simulacro-crear') return renderSimulacroCrear();
    if (id === 'simulacro-lista') return renderSimulacroLista();
    if (id === 'mis-asistencias') return renderMisAsistencias();
    if (id === 'reportes') return renderReportes();
    if (id === 'lista-alumnos') return renderListaAlumnos();
    if (id === 'materias') return renderMaterias();
  }

  // ---------- Dashboard ----------
  async function renderDashboard() {
    if (!isActiveSub('dashboard')) return;
    contentEl.innerHTML = '<div class="nc-card"><p>Cargando...</p></div>';
    
    // Generar lista de meses disponibles (últimos 12 meses)
    const meses = [];
    const hoy = new Date();
    for (let i = 0; i < 12; i++) {
      const fecha = new Date(hoy.getFullYear(), hoy.getMonth() - i, 1);
      const mesStr = fecha.getFullYear() + '-' + String(fecha.getMonth() + 1).padStart(2, '0');
      meses.push(mesStr);
    }
    const mesActual = meses[0];
    
    async function loadDashboard(mes) {
      if (!isActiveSub('dashboard')) return;
      let chartAgrupacion = window._ncChartAgrupacion || 'mes';
      try {
        if (!document.getElementById('nc-asistencia-dashboard-styles')) {
          const st = document.createElement('style');
          st.id = 'nc-asistencia-dashboard-styles';
          st.textContent = `
            .nc-dashboard-wrap {
              max-width: 1200px;
              margin: 0 auto;
            }
            @media (max-width: 900px) {
              .nc-dashboard-wrap {
                padding: 8px 4px;
                border-radius: 14px;
              }
              .nc-asistencia-main-grid {
                grid-template-columns: 1fr !important;
              }
            }
            @media (max-width: 600px) {
              .nc-asistencia-stats {
                grid-template-columns: 1fr !important;
              }
              .nc-dashboard-wrap select {
                width: 100%;
              }
            }
          `;
          document.head.appendChild(st);
        }
        const [data, historial] = await Promise.all([
          api('/asistencia/dashboard?mes=' + encodeURIComponent(mes)),
          api('/asistencia/dashboard/historial?agrupacion=' + encodeURIComponent(chartAgrupacion))
        ]);
        if (!isActiveSub('dashboard')) return;
        
        const pctActual = Number(data.promedio_actual ?? 0);
        const pctAnt = Number(data.promedio_anterior ?? 0);
        const diff = Math.round((pctActual - pctAnt) * 100) / 100;
        const fmt = (n) => Number(n).toFixed(2);
        const mesAnterior = data.mes_anterior || '';
        
        contentEl.innerHTML = `
          <div class="nc-dashboard-wrap" style="padding:4px;background:#f5f7ff;border-radius:18px;border:1px solid #e0e5ff">
            <div class="nc-card" style="border:none;background:transparent;box-shadow:none;">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;gap:16px">
                <div>
                  <div style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#6b6f80;margin-bottom:4px">Dashboard de asistencia</div>
                  <h3 style="margin:0;font-size:20px;color:#141522">Resumen general</h3>
                </div>
                <div>
                  <label style="display:block;margin-bottom:4px;font-size:12px;color:#6b6f80">Mes</label>
                  <select id="nc-asis-dash-mes" style="padding:8px 12px;min-width:150px;border:1px solid #c7ceff;border-radius:999px;background:#fff;color:#141522">
                    ${meses.map(m => `<option value="${m}" ${m === mes ? 'selected' : ''}>${m}</option>`).join('')}
                  </select>
                </div>
              </div>

              <div class="nc-asistencia-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px">
                <div class="nc-card" style="padding:14px 16px;border-radius:14px;background:linear-gradient(135deg,#4c6fff,#7b9dff);color:#fff;box-shadow:0 10px 25px rgba(76,111,255,0.25)">
                  <div style="font-size:12px;opacity:.9">Promedio mes (${escapeHtml(mes || '')})</div>
                  <div style="font-size:24px;font-weight:700;margin-top:4px">${fmt(pctActual)}%</div>
                  <div style="font-size:11px;margin-top:6px;opacity:.9">Asistencia global del mes seleccionado</div>
                </div>
                <div class="nc-card" style="padding:14px 16px;border-radius:14px;background:#ffffff;box-shadow:0 4px 14px rgba(15,23,42,0.08);border:1px solid #e4e7ff">
                  <div style="font-size:12px;color:#6b6f80">Promedio mes anterior (${escapeHtml(mesAnterior)})</div>
                  <div style="font-size:20px;font-weight:700;color:#141522;margin-top:4px">${fmt(pctAnt)}%</div>
                  <div style="font-size:11px;margin-top:6px;color:${diff >= 0 ? '#15803d' : '#b91c1c'}">
                    ${diff >= 0 ? '▲ Mejora de ' : '▼ Disminución de '}${fmt(Math.abs(diff))} puntos
                  </div>
                </div>
                <div class="nc-card" style="padding:14px 16px;border-radius:14px;background:#ffffff;box-shadow:0 4px 14px rgba(15,23,42,0.08);border:1px solid #e4e7ff">
                  <div style="font-size:12px;color:#6b6f80">Sesiones del mes</div>
                  <div style="font-size:20px;font-weight:700;color:#141522;margin-top:4px">${data.sesiones_mes_actual ?? 0}</div>
                  <div style="font-size:11px;margin-top:6px;color:#6b6f80">Total de clases con asistencia registrada</div>
                </div>
                <div class="nc-card" style="padding:14px 16px;border-radius:14px;background:#ffffff;box-shadow:0 4px 14px rgba(15,23,42,0.08);border:1px solid #e4e7ff">
                  <div style="font-size:12px;color:#6b6f80">Sesiones mes anterior</div>
                  <div style="font-size:20px;font-weight:700;color:#141522;margin-top:4px">${data.sesiones_mes_anterior ?? 0}</div>
                  <div style="font-size:11px;margin-top:6px;color:#6b6f80">Para comparar la evolución en el tiempo</div>
                </div>
              </div>

              <div class="nc-asistencia-main-grid" style="display:grid;grid-template-columns:minmax(0,2.2fr) minmax(0,1.3fr);gap:16px;align-items:flex-start">
                <div class="nc-card" style="padding:16px 18px;border-radius:16px;background:#ffffff;box-shadow:0 10px 25px rgba(15,23,42,0.08);border:1px solid #e4e7ff">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <div>
                      <div style="font-size:12px;color:#6b6f80;text-transform:uppercase;letter-spacing:.08em">Tendencia de asistencia</div>
                      <div style="font-size:13px;color:#6b6f80">Porcentaje de asistencia histórica</div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center">
                      <span style="font-weight:600;font-size:12px;color:#6b6f80">Ver por:</span>
                      <button type="button" class="nc-btn nc-chart-agrupacion ${'mes' === (window._ncChartAgrupacion || 'mes') ? 'primary' : 'secondary'}" data-agrupacion="mes">Mes</button>
                      <button type="button" class="nc-btn nc-chart-agrupacion ${'semanas' === (window._ncChartAgrupacion || 'mes') ? 'primary' : 'secondary'}" data-agrupacion="semanas">Semanas</button>
                      <button type="button" class="nc-btn nc-chart-agrupacion ${'dias' === (window._ncChartAgrupacion || 'mes') ? 'primary' : 'secondary'}" data-agrupacion="dias">Días</button>
                    </div>
                  </div>
                  <div style="max-width:100%;overflow-x:auto;"><div id="nc-asis-dash-chart" style="height:280px;min-width:0"></div></div>
                </div>

                <div class="nc-card" style="padding:16px 18px;border-radius:16px;background:#ffffff;box-shadow:0 10px 25px rgba(15,23,42,0.08);border:1px solid #e4e7ff">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:10px">
                    <div>
                      <div style="font-size:12px;color:#6b6f80;text-transform:uppercase;letter-spacing:.08em">Promedio por grupo aula</div>
                      <div style="font-size:13px;color:#6b6f80">Distribución por grupos en el mes</div>
                    </div>
                    <div>
                      <label style="display:block;margin-bottom:4px;font-size:11px;color:#6b6f80">Mes</label>
                      <select id="nc-asis-dash-mes-aula" style="padding:6px 10px;min-width:130px;border:1px solid #c7ceff;border-radius:999px;background:#f8f9ff;color:#141522;font-size:12px">
                        ${meses.map(m => `<option value="${m}" ${m === mes ? 'selected' : ''}>${m}</option>`).join('')}
                      </select>
                    </div>
                  </div>
                  <div id="nc-asis-dash-aulas" style="overflow:auto;max-height:260px"></div>
                </div>
              </div>
            </div>
          </div>
        `;
        
        function getChartTitle() {
          if (chartAgrupacion === 'dias') return 'Gráfico de progreso por día (últimos 30 días)';
          if (chartAgrupacion === 'semanas') return 'Gráfico de progreso por semana (últimas 12 semanas)';
          return 'Gráfico de progreso por mes (últimos 12 meses)';
        }
        function setChartAgrupacion(agr) {
          chartAgrupacion = agr;
          window._ncChartAgrupacion = agr;
        }
        
        // Cargar datos por aula para el mes seleccionado
        async function loadAulas(mesAula) {
          if (!isActiveSub('dashboard')) return;
          const [dataAula, perms] = await Promise.all([
            api('/asistencia/dashboard?mes=' + encodeURIComponent(mesAula)),
            getPermissions()
          ]);
          if (!isActiveSub('dashboard')) return;
          const showPresentesCol = !!(perms && perms.is_admin);
          const aulasDiv = contentEl.querySelector('#nc-asis-dash-aulas');
          if (!aulasDiv) return;
          const porAula = dataAula.por_aula || [];
          if (!porAula.length) {
            aulasDiv.innerHTML = '<p style="opacity:.7">No hay datos por grupo para este mes.</p>';
          } else {
            aulasDiv.innerHTML = `
              <table class="nc-table" style="width:100%">
                <thead><tr><th>Grupo</th><th>Sesiones</th>${showPresentesCol ? '<th>Presentes / Total</th>' : ''}<th>Porcentaje</th></tr></thead>
                <tbody>
                  ${porAula.map(a => `
                    <tr>
                      <td>${escapeHtml(a.aula_nombre || '')}</td>
                      <td>${a.sesiones ?? 0}</td>
                      ${showPresentesCol ? `<td>${a.total_presentes ?? 0} / ${a.total_registros ?? 0}</td>` : ''}
                      <td>${a.porcentaje ?? 0}%</td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            `;
          }
        }
        
        // Renderizar gráfico (mesesData = array de { mes, porcentaje, sesiones })
        function renderChart(mesesData) {
          const chartDiv = contentEl.querySelector('#nc-asis-dash-chart');
          if (!chartDiv) return;
          mesesData = mesesData || historial.meses || [];
          
          if (mesesData.length === 0) {
            chartDiv.innerHTML = '<p style="opacity:.7">No hay datos suficientes para mostrar el gráfico.</p>';
            return;
          }
          
          // Crear gráfico simple con SVG
          const width = chartDiv.clientWidth || chartDiv.offsetWidth || 400;
          const height = 300;
          const padding = { top: 20, right: 40, bottom: 40, left: 60 };
          const chartWidth = width - padding.left - padding.right;
          const chartHeight = height - padding.top - padding.bottom;
          
          const maxPct = Math.max(100, ...mesesData.map(m => m.porcentaje || 0));
          const stepX = chartWidth / Math.max(1, mesesData.length - 1);
          
          let svg = `<svg width="${width}" height="${height}" style="border:1px solid #ddd;border-radius:4px;background:#fff">
            <defs>
              <linearGradient id="grad1" x1="0%" y1="0%" x2="0%" y2="100%">
                <stop offset="0%" style="stop-color:#2e7d32;stop-opacity:0.3" />
                <stop offset="100%" style="stop-color:#2e7d32;stop-opacity:0.05" />
              </linearGradient>
            </defs>
            <g transform="translate(${padding.left},${padding.top})">`;
          
          // Ejes
          svg += `<line x1="0" y1="${chartHeight}" x2="${chartWidth}" y2="${chartHeight}" stroke="#333" stroke-width="2"/>`;
          svg += `<line x1="0" y1="0" x2="0" y2="${chartHeight}" stroke="#333" stroke-width="2"/>`;
          
          // Líneas de referencia
          for (let i = 0; i <= 5; i++) {
            const y = (chartHeight / 5) * i;
            const val = maxPct - (maxPct / 5) * i;
            svg += `<line x1="0" y1="${y}" x2="${chartWidth}" y2="${y}" stroke="#ddd" stroke-width="1" stroke-dasharray="2,2"/>`;
            svg += `<text x="-10" y="${y + 4}" text-anchor="end" font-size="11" fill="#666">${Math.round(val)}%</text>`;
          }
          
          // Línea de datos
          let pathData = '';
          mesesData.forEach((m, idx) => {
            const x = idx * stepX;
            const y = chartHeight - ((m.porcentaje || 0) / maxPct) * chartHeight;
            pathData += (idx === 0 ? 'M' : 'L') + ` ${x} ${y}`;
          });
          
          // Área bajo la curva
          const areaPath = pathData + ` L ${chartWidth} ${chartHeight} L 0 ${chartHeight} Z`;
          svg += `<path d="${areaPath}" fill="url(#grad1)"/>`;
          
          // Línea
          svg += `<path d="${pathData}" fill="none" stroke="#2e7d32" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>`;
          
          // Etiqueta del eje X: abreviar según agrupación
          function formatEjeLabel(mesStr) {
            if (!mesStr) return '';
            if (chartAgrupacion === 'dias' && /^\d{4}-\d{2}-(\d{2})$/.test(mesStr)) return mesStr.slice(8);
            if (chartAgrupacion === 'semanas' && /^\d{4}-S(\d{2})$/.test(mesStr)) return 'S' + mesStr.slice(-2);
            return mesStr;
          }
          // Puntos y etiquetas
          mesesData.forEach((m, idx) => {
            const x = idx * stepX;
            const y = chartHeight - ((m.porcentaje || 0) / maxPct) * chartHeight;
            svg += `<circle cx="${x}" cy="${y}" r="5" fill="#2e7d32"/>`;
            svg += `<text x="${x}" y="${chartHeight + 15}" text-anchor="middle" font-size="10" fill="#666">${escapeHtml(formatEjeLabel(m.mes))}</text>`;
            svg += `<title>${escapeHtml(m.mes)}: ${m.porcentaje}%</title>`;
          });
          
          svg += '</g></svg>';
          chartDiv.innerHTML = svg;
        }
        
        async function loadChartByAgrupacion() {
          try {
            const h = await api('/asistencia/dashboard/historial?agrupacion=' + encodeURIComponent(chartAgrupacion));
            if (!isActiveSub('dashboard')) return;
            renderChart(h.meses || []);
          } catch (_) {
            if (!isActiveSub('dashboard')) return;
            const chartDiv = contentEl.querySelector('#nc-asis-dash-chart');
            if (chartDiv) chartDiv.innerHTML = '<p style="opacity:.7">Error al cargar el gráfico.</p>';
          }
        }
        
        function updateChartButtonsHighlight() {
          contentEl.querySelectorAll('.nc-chart-agrupacion').forEach(btn => {
            const isActive = (btn.dataset.agrupacion || '') === chartAgrupacion;
            btn.className = 'nc-btn nc-chart-agrupacion ' + (isActive ? 'primary' : 'secondary');
          });
        }
        
        if (!isActiveSub('dashboard')) return;
        await loadAulas(mes);
        if (!isActiveSub('dashboard')) return;
        renderChart(historial.meses);
        
        contentEl.querySelectorAll('.nc-chart-agrupacion').forEach(btn => {
          btn.onclick = () => {
            setChartAgrupacion(btn.dataset.agrupacion || 'mes');
            updateChartButtonsHighlight();
            loadChartByAgrupacion();
          };
        });
        
        const mesSel = contentEl.querySelector('#nc-asis-dash-mes');
        const mesAulaSel = contentEl.querySelector('#nc-asis-dash-mes-aula');
        if (mesSel) mesSel.onchange = (e) => loadDashboard(e.target.value);
        if (mesAulaSel) mesAulaSel.onchange = (e) => loadAulas(e.target.value);
      } catch (e) {
        if (isActiveSub('dashboard')) contentEl.innerHTML = '<div class="nc-card"><p style="color:#b00">Error: ' + escapeHtml(e.message) + '</p></div>';
      }
    }
    
    await loadDashboard(mesActual);
  }

  // ---------- Marcar asistencia ----------
  let marcarState = { fecha: '', materia_id: '', grupo_id: '', aula_id: '', alumnos: [], items: {} };
  let marcarDraftDirty = false;
  let marcarDraftSaveTimer = null;
  const STORAGE_KEY_MARCAR_DRAFT = 'nc_asistencia_marcar_draft';
  const MARCAR_DRAFT_MAX_AGE_MS = 48 * 60 * 60 * 1000;

  function getNcUserId() {
    const id = window.NC_APP && window.NC_APP.currentUserId;
    return id != null ? Number(id) : 0;
  }

  function hasMarcarUnsavedChanges() {
    if (!marcarState.alumnos || !marcarState.alumnos.length) return false;
    for (const a of marcarState.alumnos) {
      const it = marcarState.items[a.id] || marcarState.items[String(a.id)];
      if (!it) continue;
      if (it.asistio === true) return true;
      if (String(it.observacion || '').trim()) return true;
    }
    return false;
  }

  function buildMarcarDraftFromUi(extra) {
    const fechaInp = contentEl && contentEl.querySelector('#nc-asis-fecha');
    const docenteSel = contentEl && contentEl.querySelector('#nc-asis-docente');
    const aulaSel = contentEl && contentEl.querySelector('#nc-asis-aula');
    const grupoSel = contentEl && contentEl.querySelector('#nc-asis-grupo');
    const materiaSel = contentEl && contentEl.querySelector('#nc-asis-materia');
    const reemplazanteChk = contentEl && contentEl.querySelector('#nc-asis-reemplazante-chk');
    const reemplazanteSel = contentEl && contentEl.querySelector('#nc-asis-reemplazante');
    const reemplazanteMotivoInp = contentEl && contentEl.querySelector('#nc-asis-reemplazante-motivo');
    const xiFacWrap = contentEl && contentEl.querySelector('#nc-asis-xi-fac-wrap');
    const xiIds = [];
    if (xiFacWrap) {
      xiFacWrap.querySelectorAll('.nc-asis-xi-fac-cb:checked').forEach(cb => {
        const fid = Number(cb.getAttribute('data-facultad-id') || 0);
        if (fid > 0) xiIds.push(fid);
      });
    }
    const titularId = docenteSel && docenteSel.value ? Number(docenteSel.value) : null;
    const replId = (reemplazanteChk && reemplazanteChk.checked && reemplazanteSel && reemplazanteSel.value)
      ? Number(reemplazanteSel.value)
      : null;
    const encargadoId = replId || titularId || marcarState.docente_encargado_id || null;
    const items = {};
    Object.keys(marcarState.items || {}).forEach(k => {
      const it = marcarState.items[k];
      items[String(k)] = { asistio: !!it.asistio, observacion: String(it.observacion || '') };
    });
    return {
      userId: getNcUserId(),
      savedAt: Date.now(),
      fecha: (fechaInp && fechaInp.value) || marcarState.fecha || '',
      docente_encargado_id: encargadoId,
      docente_titular_id: titularId,
      materia_id: materiaSel && materiaSel.value ? Number(materiaSel.value) : (marcarState.materia_id || null),
      grupo_id: grupoSel && grupoSel.value ? Number(grupoSel.value) : (marcarState.grupo_id || null),
      aula_id: aulaSel && aulaSel.value ? Number(aulaSel.value) : (marcarState.aula_id || null),
      curso_id: marcarState.curso_id || null,
      facultad_id: marcarState.facultad_id || null,
      carrera_id: marcarState.carrera_id || null,
      subgrupo: marcarState.subgrupo || null,
      sortBy: marcarState.sortBy || 'apellido',
      reemplazante: {
        checked: !!(reemplazanteChk && reemplazanteChk.checked),
        id: reemplazanteSel && reemplazanteSel.value ? Number(reemplazanteSel.value) : null,
        motivo: reemplazanteMotivoInp ? String(reemplazanteMotivoInp.value || '') : '',
      },
      xiFacultadIds: xiIds,
      alumnos: (marcarState.alumnos || []).map(a => ({
        id: a.id,
        nombres: a.nombres,
        apellidos: a.apellidos,
        ci: a.ci,
        aula_id: a.aula_id,
        aula_nombre: a.aula_nombre,
        curso_nombre: a.curso_nombre,
      })),
      items,
      ...(extra || {}),
    };
  }

  function loadMarcarDraft() {
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY_MARCAR_DRAFT);
      if (!raw) return null;
      const d = JSON.parse(raw);
      if (!d || typeof d !== 'object') return null;
      if (Number(d.userId) !== getNcUserId()) return null;
      if (d.savedAt && Date.now() - Number(d.savedAt) > MARCAR_DRAFT_MAX_AGE_MS) {
        sessionStorage.removeItem(STORAGE_KEY_MARCAR_DRAFT);
        return null;
      }
      return d;
    } catch (_) {
      return null;
    }
  }

  function clearMarcarDraft() {
    marcarDraftDirty = false;
    try { sessionStorage.removeItem(STORAGE_KEY_MARCAR_DRAFT); } catch (_) { /* ignore */ }
    updateMarcarDraftHint();
  }

  function saveMarcarDraftImmediate() {
    if (!isActiveSub('marcar') || !contentEl) return;
    const hasSession = marcarState.grupo_id && marcarState.materia_id;
    if (!hasSession && (!marcarState.alumnos || !marcarState.alumnos.length)) {
      if (!marcarDraftDirty) {
        try { sessionStorage.removeItem(STORAGE_KEY_MARCAR_DRAFT); } catch (_) { /* ignore */ }
      }
      return;
    }
    if ((!marcarState.alumnos || !marcarState.alumnos.length) && !marcarDraftDirty) return;
    try {
      sessionStorage.setItem(STORAGE_KEY_MARCAR_DRAFT, JSON.stringify(buildMarcarDraftFromUi()));
    } catch (_) { /* ignore */ }
    updateMarcarDraftHint();
  }

  function scheduleMarcarDraftSave() {
    marcarDraftDirty = true;
    if (marcarDraftSaveTimer) clearTimeout(marcarDraftSaveTimer);
    marcarDraftSaveTimer = setTimeout(() => {
      marcarDraftSaveTimer = null;
      saveMarcarDraftImmediate();
    }, 400);
  }

  function updateMarcarDraftHint(restored) {
    const hint = contentEl && contentEl.querySelector('#nc-asis-marcar-draft-hint');
    if (!hint) return;
    const draft = loadMarcarDraft();
    if (restored) {
      hint.textContent = 'Borrador recuperado en este dispositivo. Revise la lista y pulse «Guardar asistencia» para registrar en el servidor.';
      hint.style.background = '#e8f5e9';
      hint.style.borderColor = '#a5d6a7';
      return;
    }
    if (draft && draft.grupo_id && hasMarcarUnsavedChanges()) {
      hint.textContent = 'Hay un borrador sin guardar en el servidor en este dispositivo. Los cambios se actualizan solos; use «Guardar asistencia» cuando termine.';
      hint.style.background = '#fff8e1';
      hint.style.borderColor = '#ffe082';
    } else {
      hint.textContent = 'Los cambios se guardan automáticamente en este dispositivo hasta pulsar «Guardar asistencia». Si cierra la pestaña, podrá recuperarlos al volver aquí.';
      hint.style.background = '#f5f5f5';
      hint.style.borderColor = '#e0e0e0';
    }
  }

  function installMarcarLeaveWarning() {
    if (installMarcarLeaveWarning._done) return;
    installMarcarLeaveWarning._done = true;
    window.addEventListener('beforeunload', (e) => {
      if (!marcarDraftDirty || !hasMarcarUnsavedChanges()) return;
      e.preventDefault();
      e.returnValue = '';
      return '';
    });
    window.addEventListener('pagehide', () => {
      if (marcarDraftDirty || hasMarcarUnsavedChanges()) saveMarcarDraftImmediate();
    });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden' && (marcarDraftDirty || hasMarcarUnsavedChanges())) {
        saveMarcarDraftImmediate();
      }
    });
  }

  async function renderMarcar() {
    if (!isActiveSub('marcar')) return;
    installMarcarLeaveWarning();
    let materias = [], aulas = [], cursos = [], facultades = [];
    try {
      const [aulasRes, facRes, docRes] = await Promise.all([
        api('/aulas'),
        api('/facultades'),
        api('/docentes'),
      ]);
      aulas = Array.isArray(aulasRes) ? aulasRes : (aulasRes && aulasRes.items ? aulasRes.items : []);
      facultades = Array.isArray(facRes) ? facRes : (facRes && facRes.items ? facRes.items : []);
      const docItems = (docRes && docRes.items) ? docRes.items : [];
      window.ncAsisDocentesCache = docItems;
    } catch (_) {}
    if (!isActiveSub('marcar')) return;
    const codigoAulaFisicaSet = new Set(['K','L','M','N','X','P','Z','S']);
    const aulasFisicas = aulas.filter(a => {
      const raw = String(a.nombre || '').trim();
      const code = raw.split('->')[0].trim().toUpperCase();
      return codigoAulaFisicaSet.has(code);
    });
    const today = localDateYMD(new Date());
    function normXiKey(s) {
      let t = String(s || '').toLowerCase().trim();
      try {
        t = t.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
      } catch (e) { /* ignore */ }
      return t.replace(/\s+/g, ' ');
    }
    function isXiGroupName(nombre) {
      const key = normXiKey(nombre);
      return /(^|[^a-z0-9])xi($|[^a-z0-9])/.test(key);
    }
    function buildXiFacCheckboxHtml(facList) {
      const facs = Array.isArray(facList) ? facList : [];
      function findId(matchFn) {
        for (let i = 0; i < facs.length; i++) {
          const fn = normXiKey(facs[i].nombre).replace(/\./g, '');
          if (matchFn(fn)) return facs[i].id;
        }
        return null;
      }
      const defs = [
        { label: 'Humanidades', id: findId(fn => fn.includes('humanidad')) },
        // FACEA = Ciencias Económicas y Administrativas; en BD suele figurar sin el acrónimo.
        { label: 'FACEA', id: findId(fn => fn.includes('facea') || fn.includes('econom') || fn.includes('administr')) },
        { label: 'Derecho', id: findId(fn => fn.includes('derecho')) },
        { label: 'CyT', id: findId(fn => fn.includes('cyt') || fn.includes('c y t') || (fn.includes('ciencia') && fn.includes('tecnolog'))) },
      ];
      return defs.map(d => {
        const dis = !d.id;
        const idAttr = d.id ? String(d.id) : '';
        return '<label style="display:inline-flex;align-items:center;gap:6px;cursor:' + (dis ? 'not-allowed' : 'pointer') + ';opacity:' + (dis ? '0.55' : '1') + '"><input type="checkbox" class="nc-asis-xi-fac-cb" data-facultad-id="' + idAttr + '" ' + (dis ? 'disabled' : '') + ' />' + escapeHtml(d.label) + '</label>';
      }).join('');
    }
    const xiFacCbHtml = buildXiFacCheckboxHtml(facultades);
    marcarState = { fecha: today, materia_id: '', grupo_id: '', aula_id: '', curso_id: '', facultad_id: '', carrera_id: '', docente_encargado_id: null, alumnos: [], items: {}, sortBy: 'apellido' };
    contentEl.innerHTML = `
      <div class="nc-card">
        <h3 style="margin:0 0 14px">Marcar asistencia de un grupo</h3>
        <p id="nc-asis-marcar-draft-hint" style="margin:0 0 14px;padding:10px 12px;font-size:13px;color:#555;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:6px;line-height:1.45">Los cambios se guardan automáticamente en este dispositivo hasta pulsar «Guardar asistencia». Si cierra la pestaña, podrá recuperarlos al volver aquí.</p>
        <div class="nc-row nc-row-wrap" style="gap:12px;margin-bottom:16px">
          <div class="nc-field">
            <label>Fecha</label>
            <input type="date" id="nc-asis-fecha" value="${today}" />
          </div>
          <div class="nc-field">
            <label>Docente Titular</label>
            <select id="nc-asis-docente">
              <option value="">Seleccione docente</option>
              ${(window.ncAsisDocentesCache || []).map(d => '<option value="' + d.id + '">' + escapeHtml(d.display_name || ('Usuario ' + d.id)) + '</option>').join('')}
            </select>
          </div>
          <div class="nc-field">
            <label>Materia</label>
            <select id="nc-asis-materia">
              <option value="">Seleccione docente primero</option>
            </select>
          </div>
          <div class="nc-field">
            <label>Aula física</label>
            <select id="nc-asis-aula">
              <option value="">Seleccione aula</option>
              ${aulasFisicas.map(a => {
                const raw = String(a.nombre || '').trim();
                const code = raw.split('->')[0].trim().toUpperCase();
                return '<option value="' + a.id + '">' + escapeHtml(code) + '</option>';
              }).join('')}
            </select>
          </div>
          <div class="nc-field">
            <label>Grupo</label>
            <select id="nc-asis-grupo">
              <option value="">Seleccione grupo</option>
              ${aulas.map(a => '<option value="' + a.id + '">' + escapeHtml(a.nombre) + '</option>').join('')}
            </select>
          </div>
        </div>
        <div class="nc-row nc-row-wrap" id="nc-asis-xi-fac-wrap" style="display:none;gap:14px;margin-bottom:16px;align-items:flex-start">
          <div class="nc-field" style="flex:1;min-width:280px">
            <label style="display:block;margin-bottom:6px;font-weight:500">Filtrar por facultad (grupo Xi)</label>
            <div class="nc-row nc-row-wrap" style="gap:16px;align-items:center">
              ${xiFacCbHtml}
            </div>
            <p style="margin:6px 0 0;font-size:12px;color:#666">Podés marcar una o varias facultades para acotar la lista de alumnos del grupo.</p>
          </div>
        </div>
        <div class="nc-row nc-row-wrap" style="gap:12px;margin-bottom:16px;align-items:flex-end">
          <div class="nc-field" style="min-width:220px;display: flex;align-items: flex-start;">
            <label style="display: flex;align-items: flex-start;gap:8px;user-select:none">
              <input type="checkbox" id="nc-asis-reemplazante-chk" />
              Reemplazante
            </label>
          </div>
          <div class="nc-field" id="nc-asis-reemplazante-wrap" style="display:none;min-width:260px">
            <label>Docente reemplazante *</label>
            <select id="nc-asis-reemplazante">
              <option value="">Seleccione reemplazante</option>
              ${(window.ncAsisDocentesCache || []).map(d => '<option value="' + d.id + '">' + escapeHtml(d.display_name || ('Usuario ' + d.id)) + '</option>').join('')}
            </select>
          </div>
          <div class="nc-field" id="nc-asis-reemplazante-motivo-wrap" style="display:none;min-width:300px;flex:1">
            <label>Motivo *</label>
            <textarea id="nc-asis-reemplazante-motivo" rows="2" placeholder="Motivo del reemplazo y materia/actividad desarrollada por el grupo" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></textarea>
          </div>
        </div>
        <div class="nc-row nc-row-wrap" style="gap:12px;margin-bottom:16px;align-items:flex-end">
          <div class="nc-field" style="min-width:220px;display:none">
            <label style="display:flex;align-items:center;gap:8px;user-select:none">
              <input type="checkbox" id="nc-asis-use-subgrupos" />
              Usar subgrupos
            </label>
          </div>
          <div class="nc-field" id="nc-asis-sub-fac-wrap" style="display:none">
            <label>Facultad (subgrupos)</label>
            <select id="nc-asis-sub-fac">
              <option value="">Seleccione facultad</option>
              ${facultades.map(f => '<option value="' + f.id + '">' + escapeHtml(f.nombre) + '</option>').join('')}
            </select>
          </div>
          <div class="nc-field" id="nc-asis-sub-car-wrap" style="display:none">
            <label>Carrera (opcional)</label>
            <select id="nc-asis-sub-car">
              <option value="">Todas</option>
            </select>
          </div>
          <div class="nc-field" id="nc-asis-subgrupo-wrap" style="display:none">
            <label>Subgrupo</label>
            <select id="nc-asis-subgrupo" disabled>
              <option value="">Seleccione facultad o carrera</option>
            </select>
          </div>
        </div>
        <div id="nc-asis-marcar-list-wrap" style="display:none">
          <div class="nc-row" style="margin-bottom:12px;flex-wrap:wrap;gap:8px;align-items:center">
            <input type="text" id="nc-asis-buscar" placeholder="Buscar por nombre, apellido o CI" style="max-width:280px;padding:8px" />
            <div>
              <label style="display:block;margin-bottom:4px;font-size:12px;color:#666">Ordenar por</label>
              <select id="nc-asis-orden" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px">
                <option value="apellido" selected>Apellido</option>
                <option value="nombre">Nombre</option>
              </select>
            </div>
            <span id="nc-asis-contador" style="font-weight:600;margin-left:auto"></span>
          </div>
          <div style="overflow:auto">
            <table class="nc-table" id="nc-asis-marcar-table">
              <thead><tr><th>Nombre</th><th>Apellido</th><th>Asistió</th><th>Observación</th></tr></thead>
              <tbody id="nc-asis-marcar-tbody"></tbody>
            </table>
          </div>
          <div class="nc-row" style="margin-top:16px;gap:8px;flex-wrap:wrap">
            <button type="button" class="nc-btn" id="nc-asis-guardar">Guardar asistencia</button>
            <button type="button" class="nc-btn" id="nc-asis-agregar-invitado" style="background:#fff;color:#333;border:1px solid #ccc">Agregar alumno invitado</button>
          </div>
        </div>
        <p id="nc-asis-marcar-msg" style="opacity:.8">Seleccione Fecha, Docente, Materia y Grupo para cargar la lista. La materia define el curso para la sesión, junto al grupo seleccionado.</p>
      </div>
    `;

    const fechaInp = contentEl.querySelector('#nc-asis-fecha');
    const docenteSel = contentEl.querySelector('#nc-asis-docente');
    const aulaSel = contentEl.querySelector('#nc-asis-aula');
    const grupoSel = contentEl.querySelector('#nc-asis-grupo');
    const materiaSel = contentEl.querySelector('#nc-asis-materia');
    const reemplazanteChk = contentEl.querySelector('#nc-asis-reemplazante-chk');
    const reemplazanteWrap = contentEl.querySelector('#nc-asis-reemplazante-wrap');
    const reemplazanteSel = contentEl.querySelector('#nc-asis-reemplazante');
    const reemplazanteMotivoWrap = contentEl.querySelector('#nc-asis-reemplazante-motivo-wrap');
    const reemplazanteMotivoInp = contentEl.querySelector('#nc-asis-reemplazante-motivo');
    const useSubgruposChk = contentEl.querySelector('#nc-asis-use-subgrupos');
    if (useSubgruposChk) {
      // Eliminamos el uso de subgrupos: los ocultamos para que los grupos (nuevos) sean la única forma.
      useSubgruposChk.checked = false;
      useSubgruposChk.style.display = 'none';
    }
    const subFacWrap = contentEl.querySelector('#nc-asis-sub-fac-wrap');
    const subCarWrap = contentEl.querySelector('#nc-asis-sub-car-wrap');
    const subFacSel = contentEl.querySelector('#nc-asis-sub-fac');
    const subCarSel = contentEl.querySelector('#nc-asis-sub-car');
    const subgrupoWrap = contentEl.querySelector('#nc-asis-subgrupo-wrap');
    const subgrupoSel = contentEl.querySelector('#nc-asis-subgrupo');
    const listWrap = contentEl.querySelector('#nc-asis-marcar-list-wrap');
    const msg = contentEl.querySelector('#nc-asis-marcar-msg');
    const xiFacWrap = contentEl.querySelector('#nc-asis-xi-fac-wrap');
    const btnAgregarInvitado = contentEl.querySelector('#nc-asis-agregar-invitado');
    function getSelectedGrupo() {
      const id = grupoSel && grupoSel.value ? grupoSel.value : '';
      if (!id) return null;
      const fromList = aulas.find(a => String(a.id) === String(id)) || null;
      if (fromList) return fromList;
      const opt = grupoSel && grupoSel.selectedOptions && grupoSel.selectedOptions[0];
      const label = opt ? String(opt.textContent || '').trim() : '';
      if (!label) return null;
      return { id, nombre: label, curso_id: null };
    }
    function syncXiFacultadesPanel() {
      const g = getSelectedGrupo();
      const show = !!(g && isXiGroupName(g.nombre));
      if (!xiFacWrap) return;
      xiFacWrap.style.display = show ? 'block' : 'none';
      if (!show) {
        xiFacWrap.querySelectorAll('.nc-asis-xi-fac-cb').forEach(cb => { cb.checked = false; });
      }
    }
    function isCASS() {
      const a = getSelectedGrupo();
      return a && a.curso_nombre && String(a.curso_nombre).toUpperCase().indexOf('CASS') !== -1;
    }
    function setSubgruposUIEnabled(on) {
      const show = !!on;
      if (subFacWrap) subFacWrap.style.display = show ? 'block' : 'none';
      if (subCarWrap) subCarWrap.style.display = show ? 'block' : 'none';
      if (subgrupoWrap) subgrupoWrap.style.display = show ? 'block' : 'none';
      if (!show) {
        if (subFacSel) subFacSel.value = '';
        if (subCarSel) subCarSel.innerHTML = '<option value="">Todas</option>';
        if (subgrupoSel) {
          subgrupoSel.value = '';
          subgrupoSel.disabled = true;
          subgrupoSel.innerHTML = '<option value="">Seleccione facultad o carrera</option>';
        }
        marcarState.facultad_id = null;
        marcarState.carrera_id = null;
        marcarState.subgrupo = null;
      }
    }

    async function refreshCarrerasPorFacultad(fid) {
      if (!subCarSel) return;
      if (!fid) {
        subCarSel.innerHTML = '<option value="">Todas</option>';
        return;
      }
      try {
        const rows = await api('/carreras?facultad_id=' + encodeURIComponent(fid));
        const list = Array.isArray(rows) ? rows : (rows && Array.isArray(rows.items) ? rows.items : []);
        subCarSel.innerHTML = '<option value="">Todas</option>' + list.map(c => '<option value="' + c.id + '">' + escapeHtml(c.nombre) + '</option>').join('');
      } catch (_) {
        subCarSel.innerHTML = '<option value="">Error al cargar</option>';
      }
    }

    async function refreshSubgruposOpciones() {
      if (!subgrupoSel) return;
      // Prioridad: carrera > facultad.
      const fid = subFacSel && subFacSel.value ? Number(subFacSel.value) : null;
      const cid = subCarSel && subCarSel.value ? Number(subCarSel.value) : null;
      marcarState.facultad_id = fid;
      marcarState.carrera_id = cid;

      const grupo_id = grupoSel && grupoSel.value ? Number(grupoSel.value) : null;
      const materia_id = materiaSel && materiaSel.value ? Number(materiaSel.value) : null;
      if (!grupo_id || !materia_id || (!fid && !cid)) {
        subgrupoSel.disabled = true;
        subgrupoSel.innerHTML = '<option value="">Seleccione facultad o carrera</option>';
        subgrupoSel.value = '';
        marcarState.subgrupo = null;
        return;
      }
      let url = '/alumnos/subgrupos?aula_id=' + encodeURIComponent(grupo_id) + '&materia_id=' + encodeURIComponent(materia_id);
      if (cid) url += '&carrera_id=' + encodeURIComponent(cid);
      else if (fid) url += '&facultad_id=' + encodeURIComponent(fid);

      try {
        const res = await api(url);
        const items = (res && Array.isArray(res.items)) ? res.items : [];
        if (!items.length) {
          subgrupoSel.disabled = true;
          subgrupoSel.innerHTML = '<option value="">Sin subgrupos</option>';
          subgrupoSel.value = '';
          marcarState.subgrupo = null;
          return;
        }
        subgrupoSel.disabled = false;
        subgrupoSel.innerHTML = '<option value="">Todos los subgrupos</option>' + items.map(sg => '<option value="' + escapeHtml(String(sg)) + '">' + escapeHtml(String(sg)) + '</option>').join('');
      } catch (_) {
        subgrupoSel.disabled = true;
        subgrupoSel.innerHTML = '<option value="">Error al cargar</option>';
      }
    }

    function openAgregarInvitadoModal() {
      const modal = el('div', { class: 'nc-modal-overlay' });
      modal.innerHTML = `
        <div class="nc-modal nc-modal-scroll" style="max-width:600px;width:100%;background:#fff;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.12)">
          <div class="nc-modal-header" style="padding:16px 20px;border-bottom:1px solid #e0e0e0;background:#fff;border-radius:8px 8px 0 0;display:flex;align-items:center;justify-content:space-between">
            <h3 style="margin:0;font-size:18px;font-weight:600;color:#333">Agregar alumno invitado</h3>
            <button type="button" class="nc-modal-close" style="color:#555;font-size:24px;font-weight:bold;background:transparent;border:none;cursor:pointer;padding:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;line-height:1">&times;</button>
          </div>
          <div class="nc-modal-body" style="padding:20px;max-height:calc(90vh - 160px);overflow-y:auto">
            <p style="margin:0 0 8px;color:#555;font-size:14px">Busque por CI, nombre o apellido para agregar un alumno que no pertenece a este grupo aula.</p>
            <input type="text" id="nc-asis-extra-search" placeholder="Buscar por CI, nombre o apellido" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:14px;margin-bottom:12px" />
            <div id="nc-asis-extra-results" style="min-height:40px;font-size:14px;color:#555"></div>
          </div>
          <div class="nc-modal-footer" style="padding:12px 20px;border-top:1px solid #e0e0e0;background:#fafafa;border-radius:0 0 8px 8px;display:flex;justify-content:flex-end;gap:10px">
            <button type="button" class="nc-btn nc-modal-close-btn" style="background:#fff;color:#333;padding:8px 18px;border:1px solid #333;border-radius:6px;cursor:pointer">Cerrar</button>
          </div>
        </div>
      `;
      const close = () => modal.remove();
      modal.onclick = (e) => { if (e.target === modal) close(); };
      const btnClose = modal.querySelector('.nc-modal-close-btn');
      const btnX = modal.querySelector('.nc-modal-close');
      if (btnClose) btnClose.onclick = close;
      if (btnX) btnX.onclick = close;
      document.body.appendChild(modal);

      const searchInput = modal.querySelector('#nc-asis-extra-search');
      const resultsEl = modal.querySelector('#nc-asis-extra-results');
      let timer = null;

      async function doSearch(term) {
        const q = (term || '').trim();
        if (q.length < 3) {
          resultsEl.innerHTML = '<p style="margin:8px 0;color:#777">Escriba al menos 3 caracteres para buscar.</p>';
          return;
        }
        resultsEl.innerHTML = '<p style="margin:8px 0;color:#777">Buscando...</p>';
        try {
          const res = await api('/alumnos?search=' + encodeURIComponent(q) + '&order_by=apellidos&order=ASC');
          const items = (res && res.items) ? res.items : (Array.isArray(res) ? res : []);
          if (!items.length) {
            resultsEl.innerHTML = '<p style="margin:8px 0;color:#777">No se encontraron alumnos con ese criterio.</p>';
            return;
          }
          resultsEl.innerHTML = items.map(a => {
            const ya = marcarState.alumnos.some(x => Number(x.id) === Number(a.id));
            const esOtraAula = a.aula_id && String(a.aula_id) !== String(marcarState.aula_id);
            const extraLabel = esOtraAula ? ' • Otro grupo' : '';
            return `
              <div data-alumno-id="${a.id}" style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #eee">
                <div>
                  <div style="font-weight:500">${escapeHtml(((a.nombres || '') + ' ' + (a.apellidos || '')).trim() || '-')}</div>
                  <div style="font-size:12px;color:#666">CI: ${escapeHtml(a.ci || '-')}<span style="color:${esOtraAula ? '#c62828' : '#999'}">${extraLabel}</span></div>
                </div>
                ${ya ? '<span style="font-size:12px;color:#999">Ya agregado</span>' : '<button type="button" class="nc-btn" data-add-invitado="' + a.id + '" style="padding:4px 10px;font-size:12px">Agregar</button>'}
              </div>
            `;
          }).join('');
          resultsEl.querySelectorAll('[data-add-invitado]').forEach(btn => {
            btn.onclick = () => {
              const id = Number(btn.getAttribute('data-add-invitado'));
              const alumno = items.find(x => Number(x.id) === id);
              if (!alumno) return;
              if (!marcarState.alumnos.some(x => Number(x.id) === id)) {
                marcarState.alumnos.push(alumno);
                if (!marcarState.items[id]) marcarState.items[id] = { asistio: false, observacion: '' };
                renderMarcarTable();
                scheduleMarcarDraftSave();
              }
              close();
            };
          });
        } catch (e) {
          resultsEl.innerHTML = '<p style="margin:8px 0;color:#b00">Error al buscar: ' + escapeHtml(e && e.message ? e.message : String(e)) + '</p>';
        }
      }

      if (searchInput) {
        searchInput.addEventListener('input', () => {
          const value = searchInput.value || '';
          if (timer) clearTimeout(timer);
          timer = setTimeout(() => doSearch(value), 350);
        });
        searchInput.focus();
      }
      doSearch('');
    }

    async function loadAlumnos(opts) {
      const mergeItems = opts && opts.mergeItems ? opts.mergeItems : null;
      const mergeAlumnos = opts && opts.mergeAlumnos ? opts.mergeAlumnos : null;
      const grupo_id = grupoSel && grupoSel.value ? grupoSel.value : '';
      const materia_id = materiaSel && materiaSel.value ? materiaSel.value : '';
      // Importante: el grupo se filtra por docentes+materia en el frontend y por
      // membresías reales en el backend. No aplicamos filtro de curso aquí,
      // porque el curso asociado a una "aula/grupo" puede no coincidir con el curso
      // en el que el alumno está inscrito.
      const curso_id = '';
      const docente_id = docenteSel && docenteSel.value ? docenteSel.value : '';
      if (!docente_id) { listWrap.style.display = 'none'; msg.style.display = 'block'; msg.textContent = 'Seleccione docente.'; return; }
      if (!materia_id) { listWrap.style.display = 'none'; msg.style.display = 'block'; msg.textContent = 'Seleccione materia.'; return; }
      if (!grupo_id) { listWrap.style.display = 'none'; msg.style.display = 'block'; msg.textContent = 'Seleccione grupo.'; return; }
      msg.style.display = 'none';
      try {
        // Volvemos a filtrar principalmente por aula_id (compatibilidad con datos existentes)
        let url = '/alumnos?aula_id=' + encodeURIComponent(grupo_id);
        // Sin filtro por curso.
        if (useSubgruposChk && useSubgruposChk.checked) {
          const fid = subFacSel && subFacSel.value ? subFacSel.value : '';
          const cid = subCarSel && subCarSel.value ? subCarSel.value : '';
          if (cid) url += '&carrera_id=' + encodeURIComponent(cid);
          else if (fid) url += '&facultad_id=' + encodeURIComponent(fid);
        }
        if (subgrupoSel && subgrupoSel.value) url += '&subgrupo=' + encodeURIComponent(subgrupoSel.value);
        if (materia_id) url += '&materia_id=' + encodeURIComponent(materia_id);
        if (xiFacWrap && xiFacWrap.style.display !== 'none') {
          const xiIds = [];
          xiFacWrap.querySelectorAll('.nc-asis-xi-fac-cb:checked').forEach(cb => {
            const fid = Number(cb.getAttribute('data-facultad-id') || 0);
            if (fid > 0) xiIds.push(fid);
          });
          if (xiIds.length) url += '&facultad_ids=' + encodeURIComponent(xiIds.join(','));
        }
        const res = await api(url);
        let list = (res && res.items) ? res.items : (Array.isArray(res) ? res : []);
        marcarState.alumnos = list;
        marcarState.grupo_id = grupo_id;
        marcarState.aula_id = aulaSel && aulaSel.value ? aulaSel.value : '';
        marcarState.curso_id = null;
        marcarState.facultad_id = (useSubgruposChk && useSubgruposChk.checked && subFacSel && subFacSel.value) ? Number(subFacSel.value) : null;
        marcarState.carrera_id = (useSubgruposChk && useSubgruposChk.checked && subCarSel && subCarSel.value) ? Number(subCarSel.value) : null;
        marcarState.materia_id = materia_id;
        marcarState.fecha = fechaInp.value;
        const titularId = docenteSel && docenteSel.value ? Number(docenteSel.value) : null;
        const replId = (reemplazanteChk && reemplazanteChk.checked && reemplazanteSel && reemplazanteSel.value)
          ? Number(reemplazanteSel.value)
          : null;
        marcarState.docente_encargado_id = replId || titularId;
        marcarState.subgrupo = subgrupoSel && subgrupoSel.value ? subgrupoSel.value : null;
        if (mergeItems) {
          const merged = {};
          list.forEach(a => {
            const sid = String(a.id);
            const fromDraft = mergeItems[sid] || mergeItems[a.id];
            merged[a.id] = fromDraft
              ? { asistio: !!fromDraft.asistio, observacion: String(fromDraft.observacion || '') }
              : { asistio: false, observacion: '' };
          });
          if (mergeAlumnos && Array.isArray(mergeAlumnos)) {
            mergeAlumnos.forEach(a => {
              const aid = Number(a.id);
              if (!aid) return;
              if (!marcarState.alumnos.some(x => Number(x.id) === aid)) {
                marcarState.alumnos.push(a);
              }
              const sid = String(aid);
              if (!merged[aid]) {
                const fromDraft = mergeItems[sid] || mergeItems[aid];
                merged[aid] = fromDraft
                  ? { asistio: !!fromDraft.asistio, observacion: String(fromDraft.observacion || '') }
                  : { asistio: false, observacion: '' };
              }
            });
          }
          marcarState.items = merged;
          marcarDraftDirty = hasMarcarUnsavedChanges();
        } else {
          marcarState.items = {};
          list.forEach(a => { marcarState.items[a.id] = { asistio: false, observacion: '' }; });
        }
        renderMarcarTable();
        listWrap.style.display = 'block';
        scheduleMarcarDraftSave();
        if (!mergeItems) updateMarcarDraftHint();
      } catch (e) {
        msg.textContent = 'Error al cargar alumnos: ' + e.message;
        msg.style.display = 'block';
        listWrap.style.display = 'none';
      }
    }

    function renderMarcarTable() {
      const search = (contentEl.querySelector('#nc-asis-buscar') || {}).value || '';
      const term = search.toLowerCase().trim();
      let filtered = marcarState.alumnos.slice();
      if (term) {
        filtered = filtered.filter(a =>
          (a.nombres || '').toLowerCase().includes(term) ||
          (a.apellidos || '').toLowerCase().includes(term) ||
          (a.ci || '').toLowerCase().includes(term)
        );
      }
      const sortBy = marcarState.sortBy || 'apellido';
      filtered.sort((a, b) => {
        const an = (a.nombres || '').toLowerCase();
        const aa = (a.apellidos || '').toLowerCase();
        const bn = (b.nombres || '').toLowerCase();
        const ba = (b.apellidos || '').toLowerCase();
        if (sortBy === 'nombre') {
          return an.localeCompare(bn) || aa.localeCompare(ba);
        }
        return aa.localeCompare(ba) || an.localeCompare(bn);
      });
      const tbody = contentEl.querySelector('#nc-asis-marcar-tbody');
      if (!tbody) return;
      tbody.innerHTML = filtered.map(a => {
        const it = marcarState.items[a.id] || { asistio: false, observacion: '' };
        const asistioId = `nc-asis-toggle-${a.id}`;
        return `
          <tr data-alumno-id="${a.id}">
            <td>${escapeHtml(a.nombres || '')}</td>
            <td>${escapeHtml(a.apellidos || '')}</td>
            <td class="nc-asis-acciones">
              <label class="nc-toggle-switch" title="${it.asistio ? 'Asistió' : 'No asistió'}">
                <input type="checkbox" id="${asistioId}" data-alumno-id="${a.id}" ${it.asistio ? 'checked' : ''} />
                <span class="nc-toggle-slider"></span>
              </label>
            </td>
            <td><input type="text" class="nc-asis-obs" data-alumno-id="${a.id}" value="${escapeHtml(it.observacion || '')}" placeholder="Observación" style="width:100%;max-width:200px;padding:6px" /></td>
          </tr>
        `;
      }).join('');

      const presentes = marcarState.alumnos.filter(a => (marcarState.items[a.id] || {}).asistio !== false).length;
      const contador = contentEl.querySelector('#nc-asis-contador');
      if (contador) contador.textContent = presentes + ' / ' + marcarState.alumnos.length + ' alumnos';

      tbody.querySelectorAll('input[type="checkbox"][data-alumno-id]').forEach(toggle => {
        toggle.onchange = function () {
          const aid = Number(toggle.dataset.alumnoId);
          const asistio = toggle.checked;
          if (!marcarState.items[aid]) marcarState.items[aid] = { asistio: false, observacion: '' };
          marcarState.items[aid].asistio = asistio;
          const pres = marcarState.alumnos.filter(a => (marcarState.items[a.id] || {}).asistio !== false).length;
          if (contador) contador.textContent = pres + ' / ' + marcarState.alumnos.length + ' alumnos';
          scheduleMarcarDraftSave();
        };
      });
      tbody.querySelectorAll('.nc-asis-obs').forEach(inp => {
        inp.oninput = function () {
          const aid = Number(inp.dataset.alumnoId);
          if (!marcarState.items[aid]) marcarState.items[aid] = { asistio: false, observacion: '' };
          marcarState.items[aid].observacion = inp.value.trim();
          scheduleMarcarDraftSave();
        };
      });
    }

    async function loadDocentesPorMateria(materiaId) {
      const sel = contentEl.querySelector('#nc-asis-docente');
      if (!sel) return;
      sel.innerHTML = '<option value="">Seleccione docente</option>';
      marcarState.docente_encargado_id = null;
      if (!materiaId) {
        sel.innerHTML = '<option value="">Seleccione materia primero</option>';
        return;
      }
      try {
        const res = await api('/materias/' + encodeURIComponent(materiaId) + '/docentes');
        const items = (res && res.items) ? res.items : [];
        items.forEach(d => {
          const opt = document.createElement('option');
          opt.value = d.user_id;
          opt.textContent = escapeHtml(d.display_name || ('Usuario ' + d.user_id));
          sel.appendChild(opt);
        });
        // Autocompletar el docente si el usuario actual es uno de los docentes de la materia.
        const currentId = (typeof currentUserId !== 'undefined' && currentUserId != null)
          ? Number(currentUserId)
          : null;
        if (currentId && items.some(d => Number(d.user_id) === currentId)) {
          sel.value = String(currentId);
          marcarState.docente_encargado_id = currentId;
        }
      } catch (_) {
        sel.innerHTML = '<option value="">Error al cargar docentes</option>';
      }
    }

    let allowedCursoIds = [];

    function getAulaFisicaCodeFromNombre(nombre) {
      return String(nombre || '').trim().split('->')[0].trim().toUpperCase();
    }
    function getAulaById(id) {
      return aulas.find(a => String(a.id) === String(id)) || null;
    }

    function refiltrarGrupoYCargarOpciones(filtroFisicaCode) {
      if (!grupoSel) return;
      // Regla: el aula física (K,L,M,N,X,Z,P,S) NO filtra los grupos.
      // Pero: si una fila tiene nombre tipo `K -> Kappa`, `L -> Lambda`, etc,
      // en "Grupo" debemos mostrar Kappa/Lambda (no excluirla).
      let gruposFiltrados = [];
      for (const a of (aulas || [])) {
        const nombre = String(a.nombre || '');
        const hasArrow = nombre.indexOf('->') !== -1;
        const code = getAulaFisicaCodeFromNombre(nombre);
        const isFisica = (codigoAulaFisicaSet && codigoAulaFisicaSet.has(code));

        // Excluimos solo las aulas físicas "puras" sin `->` (ej. nombre = "K")
        if (isFisica && !hasArrow) continue;
        gruposFiltrados.push(a);
      }
      grupoSel.innerHTML = '<option value="">Seleccione grupo</option>' + gruposFiltrados.map(a => {
        const nombre = String(a.nombre || '');
        const label = nombre.indexOf('->') !== -1 ? nombre.split('->')[1].trim() : nombre;
        return '<option value="' + a.id + '">' + escapeHtml(label) + '</option>';
      }).join('');

      marcarState.grupo_id = null;
      marcarState.curso_id = null;
      grupoSel.value = '';
      marcarState.alumnos = [];
      marcarState.items = {};
      if (listWrap) listWrap.style.display = 'none';
      if (msg) { msg.style.display = 'block'; msg.textContent = 'Seleccione el Grupo para cargar la lista.'; }
      syncXiFacultadesPanel();
    }

    async function cargarGruposPorMateria(materiaId) {
      if (!grupoSel) return;
      if (!materiaId) {
        grupoSel.innerHTML = '<option value="">Seleccione grupo</option>';
        syncXiFacultadesPanel();
        return;
      }
      try {
        const res = await api('/asistencia/materias/' + encodeURIComponent(materiaId) + '/grupos');
        const items = (res && res.items) ? res.items : [];

        // Filtro de UI: excluir las aulas físicas "puras" (K, L, M, ... sin `->`).
        // Si el nombre tiene `->` (ej. `K -> Kappa`), lo mostramos como grupo.
        const filtered = items.filter(a => {
          const raw = String(a.nombre || '').trim();
          const hasArrow = raw.indexOf('->') !== -1;
          const code = getAulaFisicaCodeFromNombre(raw);
          const isFisica = codigoAulaFisicaSet.has(code);
          return !(isFisica && !hasArrow);
        });

        grupoSel.innerHTML = '<option value="">Seleccione grupo</option>' + filtered.map(a => {
          const raw = String(a.nombre || '').trim();
          const label = raw.indexOf('->') !== -1 ? raw.split('->')[1].trim() : raw;
          return '<option value="' + a.id + '">' + escapeHtml(label) + '</option>';
        }).join('');

        // Reset de estado de lista porque cambió el dropdown de grupos.
        marcarState.grupo_id = null;
        marcarState.curso_id = null;
        grupoSel.value = '';
        marcarState.alumnos = [];
        marcarState.items = {};
        if (listWrap) listWrap.style.display = 'none';
        if (msg) { msg.style.display = 'block'; msg.textContent = 'Seleccione el Grupo para cargar la lista.'; }
        syncXiFacultadesPanel();
      } catch (e) {
        grupoSel.innerHTML = '<option value="">Error al cargar grupos</option>';
        syncXiFacultadesPanel();
      }
    }

    async function refreshAulasFisicasYGruposPorDocenteMateria() {
      const didTit = docenteSel && docenteSel.value ? docenteSel.value : '';
      const didRepl = (reemplazanteChk && reemplazanteChk.checked && reemplazanteSel && reemplazanteSel.value)
        ? reemplazanteSel.value
        : '';
      const did = didRepl || didTit;
      const materiaId = materiaSel && materiaSel.value ? materiaSel.value : '';

      if (!did || !materiaId) {
        allowedCursoIds = [];
        if (aulaSel) aulaSel.innerHTML = '<option value="">Seleccione aula</option>';
        if (grupoSel) grupoSel.innerHTML = '<option value="">Seleccione grupo</option>';
        marcarState.aula_id = '';
        marcarState.grupo_id = null;
        marcarState.curso_id = null;
        marcarState.alumnos = [];
        marcarState.items = {};
        if (listWrap) listWrap.style.display = 'none';
        if (msg) { msg.style.display = 'block'; msg.textContent = 'Seleccione docente y materia para cargar las opciones.'; }
        syncXiFacultadesPanel();
        return;
      }

      // Para evitar dropdowns vacíos: mostramos aulas físicas y grupos según la base
      // de `aulas`, y dejamos que `/alumnos` filtre por materia y membresías reales.
      const fisicaCodeSel = aulaSel && aulaSel.value ? getAulaFisicaCodeFromNombre(getAulaById(aulaSel.value)?.nombre) : null;

      if (aulaSel) {
        aulaSel.innerHTML = '<option value="">Seleccione aula</option>' + aulasFisicas.map(a => {
          const code = getAulaFisicaCodeFromNombre(a.nombre);
          return '<option value="' + a.id + '">' + escapeHtml(code) + '</option>';
        }).join('');
        if (fisicaCodeSel && !aulasFisicas.some(a => String(a.id) === String(aulaSel.value))) {
          aulaSel.value = '';
        }
      }
      // El dropdown de grupos debe mostrar solo los grupos con alumnos inscriptos a esta materia.
      await cargarGruposPorMateria(materiaId);
      syncXiFacultadesPanel();
    }

    if (aulaSel) aulaSel.addEventListener('change', async () => {
      marcarState.aula_id = aulaSel.value ? Number(aulaSel.value) : null;
      // El aula física no debe filtrar el dropdown de grupos.
    });

    if (grupoSel) grupoSel.addEventListener('change', async () => {
      marcarState.grupo_id = grupoSel.value ? Number(grupoSel.value) : null;
      const selectedGrupo = getSelectedGrupo();
      marcarState.curso_id = (selectedGrupo && selectedGrupo.curso_id) ? Number(selectedGrupo.curso_id) : null;
      syncXiFacultadesPanel();
      if (useSubgruposChk && useSubgruposChk.checked) await refreshSubgruposOpciones();
      await loadAlumnos();
    });

    if (materiaSel) materiaSel.addEventListener('change', async () => {
      marcarState.materia_id = materiaSel.value ? Number(materiaSel.value) : null;
      // Refrescar opciones de aulas/grupos para que solo muestre grupos de la materia seleccionada.
      await refreshAulasFisicasYGruposPorDocenteMateria();
    });
    if (useSubgruposChk) useSubgruposChk.addEventListener('change', async () => {
      const on = !!useSubgruposChk.checked;
      setSubgruposUIEnabled(on);
      if (on) {
        await refreshCarrerasPorFacultad(subFacSel && subFacSel.value ? subFacSel.value : '');
        await refreshSubgruposOpciones();
      }
      loadAlumnos();
    });
    if (subFacSel) subFacSel.addEventListener('change', async () => {
      await refreshCarrerasPorFacultad(subFacSel.value);
      if (subCarSel) subCarSel.value = '';
      await refreshSubgruposOpciones();
      loadAlumnos();
    });
    if (subCarSel) subCarSel.addEventListener('change', async () => {
      await refreshSubgruposOpciones();
      loadAlumnos();
    });
    if (subgrupoSel) subgrupoSel.addEventListener('change', () => { marcarState.subgrupo = subgrupoSel.value ? subgrupoSel.value : null; loadAlumnos(); });
    if (xiFacWrap) {
      xiFacWrap.addEventListener('change', (e) => {
        const t = e.target;
        if (t && t.classList && t.classList.contains('nc-asis-xi-fac-cb')) loadAlumnos();
      });
    }
    if (reemplazanteChk && reemplazanteWrap) {
      reemplazanteChk.addEventListener('change', () => {
        const on = !!reemplazanteChk.checked;
        reemplazanteWrap.style.display = on ? 'block' : 'none';
        if (reemplazanteMotivoWrap) reemplazanteMotivoWrap.style.display = on ? 'block' : 'none';
        if (!on && reemplazanteSel) reemplazanteSel.value = '';
        if (!on && reemplazanteMotivoInp) reemplazanteMotivoInp.value = '';
      });
    }
    if (reemplazanteSel) reemplazanteSel.addEventListener('change', () => loadAlumnos());
    fechaInp.addEventListener('change', () => {
      marcarState.fecha = fechaInp.value;
      scheduleMarcarDraftSave();
    });
    contentEl.addEventListener('change', (e) => {
      const sel = e.target;
      if (sel && sel.id === 'nc-asis-orden') {
        marcarState.sortBy = sel.value === 'nombre' ? 'nombre' : 'apellido';
        renderMarcarTable();
      }
    });
    async function loadMateriasYCursosPorDocente(did) {
      if (!did) {
        if (materiaSel) materiaSel.innerHTML = '<option value="">Seleccione docente primero</option>';
        return;
      }
      try {
        const matRes = await api('/docentes/' + encodeURIComponent(did) + '/materias');
        const matItems = (matRes && matRes.items) ? matRes.items : [];
        if (materiaSel) materiaSel.innerHTML = '<option value="">Seleccione materia</option>' + matItems.map(m => '<option value="' + m.id + '">' + escapeHtml(m.nombre) + '</option>').join('');
      } catch (_) {
        if (materiaSel) materiaSel.innerHTML = '<option value="">Error al cargar</option>';
      }
    }
    if (docenteSel) docenteSel.addEventListener('change', async () => {
      const did = docenteSel.value;
      const replId = (reemplazanteChk && reemplazanteChk.checked && reemplazanteSel && reemplazanteSel.value)
        ? Number(reemplazanteSel.value)
        : null;
      marcarState.docente_encargado_id = replId || (did ? Number(did) : null);
      await loadMateriasYCursosPorDocente(did);
      // Opciones de Aula/Grupo dependen de Docente+Materia.
      allowedCursoIds = [];
      marcarState.materia_id = null;
      marcarState.grupo_id = null;
      marcarState.curso_id = null;
      marcarState.aula_id = '';
      if (aulaSel) aulaSel.innerHTML = '<option value="">Seleccione aula</option>';
      if (grupoSel) grupoSel.innerHTML = '<option value="">Seleccione grupo</option>';
      marcarState.alumnos = [];
      marcarState.items = {};
      if (listWrap) listWrap.style.display = 'none';
      if (msg) { msg.style.display = 'block'; msg.textContent = 'Seleccione Materia para cargar las opciones.'; }
    });
    const currentUserId = (window.NC_APP && window.NC_APP.currentUserId != null) ? Number(window.NC_APP.currentUserId) : null;
    const docentes = (window.ncAsisDocentesCache && Array.isArray(window.ncAsisDocentesCache)) ? window.ncAsisDocentesCache : [];
    const busqueda = contentEl.querySelector('#nc-asis-buscar');
    if (busqueda) busqueda.addEventListener('input', () => renderMarcarTable());

    // Configuración del selector de cursos (checkbox desplegable)
    // selector de cursos multi ya no se usa

    const btnGuardarAsist = contentEl.querySelector('#nc-asis-guardar');
    btnGuardarAsist.addEventListener('click', withButtonLock(btnGuardarAsist, async () => {
      if (!marcarState.fecha || !marcarState.docente_encargado_id || !marcarState.grupo_id || !marcarState.materia_id) {
        alert('Seleccione fecha, docente, materia y grupo.');
        return;
      }
      const isReemplazo = !!(reemplazanteChk && reemplazanteChk.checked);
      const motivoReemplazo = reemplazanteMotivoInp ? String(reemplazanteMotivoInp.value || '').trim() : '';
      if (isReemplazo) {
        if (!reemplazanteSel || !reemplazanteSel.value) {
          alert('Seleccione el docente reemplazante.');
          return;
        }
        if (!motivoReemplazo) {
          alert('Ingrese el motivo del reemplazo.');
          return;
        }
      }
      if (!confirm('¿Guardar el registro de asistencia? Esta acción guardará la lista actual.')) return;
      const items = marcarState.alumnos.map(a => ({
        alumno_id: a.id,
        asistio: (marcarState.items[a.id] || {}).asistio !== false ? 1 : 0,
        observacion: (marcarState.items[a.id] || {}).observacion || '',
      }));
      const body = {
        fecha: marcarState.fecha,
        materia_id: marcarState.materia_id ? Number(marcarState.materia_id) : null,
        aula_id: marcarState.aula_id ? Number(marcarState.aula_id) : null,
        docente_encargado_id: marcarState.docente_encargado_id,
        items,
      };
      body.observacion_general = isReemplazo ? motivoReemplazo : null;
      if (marcarState.grupo_id) body.grupo_id = Number(marcarState.grupo_id);
      if (marcarState.curso_id) body.curso_id = Number(marcarState.curso_id);
      if (marcarState.subgrupo) body.subgrupo = marcarState.subgrupo;
      try {
        await api('/asistencia/sesiones', {
          method: 'POST',
          body: JSON.stringify(body),
        });
        alert('Asistencia guardada correctamente.');
        marcarState.items = {};
        clearMarcarDraft();
        renderMarcarTable();
      } catch (e) {
        alert('Error al guardar: ' + e.message);
      }
    }, { loadingText: 'Guardando...' }));

    setSubgruposUIEnabled(false);
    const btnInv = contentEl.querySelector('#nc-asis-agregar-invitado');
    if (btnInv) btnInv.addEventListener('click', () => openAgregarInvitadoModal());

    async function tryRestoreMarcarDraft() {
      const draft = loadMarcarDraft();
      if (!draft || !draft.grupo_id || !draft.materia_id || !draft.docente_encargado_id) {
        updateMarcarDraftHint();
        return;
      }
      try {
        if (fechaInp && draft.fecha) {
          fechaInp.value = draft.fecha;
          marcarState.fecha = draft.fecha;
        }
        const titularRestore = draft.docente_titular_id || draft.docente_encargado_id;
        if (docenteSel && titularRestore) {
          docenteSel.value = String(titularRestore);
          marcarState.docente_encargado_id = Number(titularRestore);
          await loadMateriasYCursosPorDocente(String(titularRestore));
        }
        if (materiaSel && draft.materia_id) {
          materiaSel.value = String(draft.materia_id);
          marcarState.materia_id = Number(draft.materia_id);
          await refreshAulasFisicasYGruposPorDocenteMateria();
        }
        if (aulaSel && draft.aula_id) aulaSel.value = String(draft.aula_id);
        if (grupoSel && draft.grupo_id) {
          grupoSel.value = String(draft.grupo_id);
          marcarState.grupo_id = Number(draft.grupo_id);
          const selectedGrupo = getSelectedGrupo();
          marcarState.curso_id = (selectedGrupo && selectedGrupo.curso_id) ? Number(selectedGrupo.curso_id) : null;
        }
        syncXiFacultadesPanel();
        if (draft.xiFacultadIds && draft.xiFacultadIds.length && xiFacWrap) {
          const idSet = new Set(draft.xiFacultadIds.map(Number));
          xiFacWrap.querySelectorAll('.nc-asis-xi-fac-cb').forEach(cb => {
            const fid = Number(cb.getAttribute('data-facultad-id') || 0);
            cb.checked = idSet.has(fid);
          });
        }
        if (draft.reemplazante && reemplazanteChk) {
          reemplazanteChk.checked = !!draft.reemplazante.checked;
          if (reemplazanteWrap) reemplazanteWrap.style.display = draft.reemplazante.checked ? 'block' : 'none';
          if (reemplazanteMotivoWrap) reemplazanteMotivoWrap.style.display = draft.reemplazante.checked ? 'block' : 'none';
          if (reemplazanteSel && draft.reemplazante.id) reemplazanteSel.value = String(draft.reemplazante.id);
          if (reemplazanteMotivoInp && draft.reemplazante.motivo) reemplazanteMotivoInp.value = draft.reemplazante.motivo;
        }
        if (draft.sortBy) {
          marcarState.sortBy = draft.sortBy;
          const ordenSel = contentEl.querySelector('#nc-asis-orden');
          if (ordenSel) ordenSel.value = draft.sortBy === 'nombre' ? 'nombre' : 'apellido';
        }
        await loadAlumnos({
          mergeItems: draft.items || {},
          mergeAlumnos: draft.alumnos || [],
        });
        updateMarcarDraftHint(true);
      } catch (_) {
        updateMarcarDraftHint();
      }
    }

    const draftOnLoad = loadMarcarDraft();
    let restoredFromDraft = false;
    if (draftOnLoad && draftOnLoad.grupo_id && draftOnLoad.materia_id) {
      await tryRestoreMarcarDraft();
      restoredFromDraft = marcarState.alumnos.length > 0;
    }
    if (!restoredFromDraft && currentUserId && docenteSel && docentes.some(d => Number(d.id) === currentUserId)) {
      docenteSel.value = String(currentUserId);
      marcarState.docente_encargado_id = currentUserId;
      await loadMateriasYCursosPorDocente(String(currentUserId));
      allowedCursoIds = [];
      if (aulaSel) aulaSel.innerHTML = '<option value="">Seleccione aula</option>';
      if (grupoSel) grupoSel.innerHTML = '<option value="">Seleccione grupo</option>';
      marcarState.materia_id = null;
      marcarState.grupo_id = null;
      marcarState.curso_id = null;
      marcarState.aula_id = '';
      marcarState.alumnos = [];
      marcarState.items = {};
      updateMarcarDraftHint();
    } else if (!restoredFromDraft) {
      updateMarcarDraftHint();
    }
  }

  // ---------- Reportes ----------
  async function renderReportes() {
    if (!isActiveSub('reportes')) return;
    contentEl.innerHTML = '<div class="nc-card"><p>Cargando...</p></div>';
    let materias = [], aulas = [];
    try {
      const [matRes, aulasRes] = await Promise.all([api('/materias'), api('/aulas')]);
      materias = (matRes && matRes.items) ? matRes.items : [];
      aulas = Array.isArray(aulasRes) ? aulasRes : (aulasRes && aulasRes.items ? aulasRes.items : []);
    } catch (_) {}
    if (!isActiveSub('reportes')) return;
    const perms = await getPermissions();
    const canManageAttendance = !!(perms && perms.can_manage_attendance);
    const canExport = !!(perms && perms.is_admin);
    const showAuditCols = canManageAttendance;
    const aulasGrupo = filterAulasGrupoDropdown(aulas);
    let repLoadGen = 0;
    const rangeRep = defaultAsisDateRange(24);
    const fromStr = rangeRep.from;
    const toStr = rangeRep.to;
    contentEl.innerHTML = `
      <div class="nc-card">
        <h3 style="margin:0 0 14px">Reportes de asistencia</h3>
        <p style="margin:0 0 10px;font-size:13px;color:#666">Filtrá por fechas, materia y/o grupo. Los cambios en los filtros actualizan el listado automáticamente.</p>
        <div class="nc-row nc-row-wrap" style="gap:12px;margin-bottom:16px">
          <div class="nc-field">
            <label>Desde</label>
            <input type="date" id="nc-asis-rep-from" value="${fromStr}" />
          </div>
          <div class="nc-field">
            <label>Hasta</label>
            <input type="date" id="nc-asis-rep-to" value="${toStr}" />
          </div>
          <div class="nc-field">
            <label>Materia</label>
            <select id="nc-asis-rep-materia"><option value="">Todas</option>${materias.map(m => '<option value="' + m.id + '">' + escapeHtml(m.nombre) + '</option>').join('')}</select>
          </div>
          <div class="nc-field">
            <label>Grupo</label>
            <select id="nc-asis-rep-aula"><option value="">Todas</option>${aulasGrupo.map(a => '<option value="' + a.id + '">' + escapeHtml(aulaGrupoOptionLabel(a)) + '</option>').join('')}</select>
          </div>
          <div class="nc-field" style="flex:1;min-width:180px">
            <label>Buscar</label>
            <input type="search" id="nc-asis-rep-search" placeholder="Materia, docente, grupo…" />
          </div>
          <button type="button" class="nc-btn" id="nc-asis-rep-buscar">Buscar</button>
          ${canExport ? '<button type="button" class="nc-btn" id="nc-asis-rep-export-csv" style="margin-left:8px">Exportar CSV</button><button type="button" class="nc-btn" id="nc-asis-rep-export-pdf">Exportar PDF</button>' : ''}
        </div>
        <div id="nc-asis-rep-summary" style="margin-bottom:16px"></div>
        <div id="nc-asis-rep-list" class="nc-asis-table-scroll"></div>
      </div>
    `;

    let lastReportItems = [];

    function grupoLabelSesion(s) {
      return grupoDisplay(s.grupo_nombre, s.es_simulacro)
        || aulaFisicaDisplay(s.aula_fisica_nombre)
        || String(s.grupo_nombre || s.aula_fisica_nombre || '');
    }

    function buildResumenHtml(items) {
      if (!Array.isArray(items) || !items.length) return '';
      let totalPresentes = 0;
      let totalRegistros = 0;
      const porGrupo = {};
      items.forEach(s => {
        const ratio = String(s.presentes_total || '0/0').split('/');
        const presentes = Number(ratio[0]) || 0;
        const total = Number(ratio[1]) || 0;
        totalPresentes += presentes;
        totalRegistros += total;
        const gn = grupoLabelSesion(s);
        const key = gn + '|' + String(s.materia_nombre || '');
        if (!porGrupo[key]) {
          porGrupo[key] = {
            aula_nombre: gn,
            materia_nombre: s.materia_nombre || '',
            sesiones: 0,
            presentes: 0,
            total: 0
          };
        }
        const g = porGrupo[key];
        g.sesiones += 1;
        g.presentes += presentes;
        g.total += total;
      });
      if (!totalRegistros) return '';
      const pctGeneral = (totalPresentes * 100 / totalRegistros).toFixed(1);
      const totalAusencias = totalRegistros - totalPresentes;
      const grupos = Object.values(porGrupo).sort((a, b) => {
        const an = (a.aula_nombre || '') + ' ' + (a.materia_nombre || '');
        const bn = (b.aula_nombre || '') + ' ' + (b.materia_nombre || '');
        return an.localeCompare(bn, 'es');
      });
      const filas = grupos.map(g => {
        const pct = g.total ? (g.presentes * 100 / g.total).toFixed(1) : '0.0';
        const aus = g.total - g.presentes;
        return `
          <tr>
            <td>${escapeHtml(g.aula_nombre || '')}</td>
            <td>${escapeHtml(g.materia_nombre || '')}</td>
            <td>${g.presentes}/${g.total} (${pct}%)</td>
            <td>${aus}</td>
            <td>${g.sesiones}</td>
          </tr>
        `;
      }).join('');
      return `
        <div class="nc-report-summary-card" style="border:1px solid #e0e0e0;border-radius:8px;padding:16px 18px;background:linear-gradient(135deg,#f5fbff,#f0f4ff)">
          <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin-bottom:14px">
            <div style="display:flex;align-items:center;gap:14px">
              <div style="position:relative;width:70px;height:70px">
                <svg viewBox="0 0 42 42" width="70" height="70">
                  <circle cx="21" cy="21" r="15.915" fill="none" stroke="#e0e7ff" stroke-width="6"></circle>
                  <circle cx="21" cy="21" r="15.915" fill="none"
                    stroke="#2e7d32"
                    stroke-width="6"
                    stroke-dasharray="${pctGeneral} ${100 - pctGeneral}"
                    stroke-dashoffset="25"
                    stroke-linecap="round"
                    transform="rotate(-90 21 21)"></circle>
                  <text x="21" y="22" text-anchor="middle" font-size="11" fill="#222" font-weight="600">${pctGeneral}%</text>
                </svg>
              </div>
              <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#777">Promedio general</div>
                <div style="font-size:13px;color:#555">Presentes: <strong>${totalPresentes}</strong> de <strong>${totalRegistros}</strong></div>
              </div>
            </div>
            <div>
              <div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#777">Ausencias totales</div>
              <div style="font-size:18px;font-weight:600;color:#c62828">${totalAusencias}</div>
            </div>
          </div>
          <div style="overflow:auto;max-height:260px;border-top:1px solid #e0e0e0;margin-top:6px;padding-top:8px">
            <table class="nc-table" style="width:100%;font-size:12px">
              <thead>
                <tr>
                  <th>Grupo</th>
                  <th>Materia</th>
                  <th>Asistencia acumulada</th>
                  <th>Ausencias</th>
                  <th>Sesiones</th>
                </tr>
              </thead>
              <tbody>
                ${filas}
              </tbody>
            </table>
          </div>
        </div>
      `;
    }

    function aulaFisicaDisplay(nombre) {
      const s = String(nombre || '').trim();
      if (!s) return '';
      if (s.includes('->')) {
        const left = s.split('->')[0].trim().toUpperCase();
        return left;
      }
      // Soporta casos como "(K) Kappa" o " ( Z ) Zeta "
      const m = s.match(/^\(?\s*([KLMNXPZS])\s*\)?/i);
      if (m && m[1]) return String(m[1]).toUpperCase();
      return s.toUpperCase();
    }

    function grupoDisplay(nombre, esSimulacro) {
      if (Number(esSimulacro) === 1 || String(nombre || '').trim() === 'Simulacro') return 'Simulacro';
      let s = String(nombre || '').trim();
      if (!s) return '';
      if (s.includes('->')) s = s.split('->')[1].trim();
      // Limpia prefijos del tipo "(Z) " => "Zeta"
      s = s.replace(/^\(\s*[A-Za-z0-9]+\s*\)\s*/,'').trim();
      return s;
    }

    function reemplazanteDisplay(s) {
      const flag = (Number(s.es_reemplazante || 0) === 1);
      if (!flag) return 'No';
      const n = String(s.docente_encargado_nombre || '').trim();
      return n ? ('Sí: ' + n) : 'Sí';
    }

    function getRepFilters() {
      return {
        fecha_desde: (contentEl.querySelector('#nc-asis-rep-from') || {}).value || '',
        fecha_hasta: (contentEl.querySelector('#nc-asis-rep-to') || {}).value || '',
        materia_id: (contentEl.querySelector('#nc-asis-rep-materia') || {}).value || '',
        aula_id: (contentEl.querySelector('#nc-asis-rep-aula') || {}).value || '',
        search: (contentEl.querySelector('#nc-asis-rep-search') || {}).value || '',
      };
    }

    async function loadReportes() {
      if (!listPanelReady('#nc-asis-rep-from')) return;
      const filters = getRepFilters();
      if (filters.fecha_desde && filters.fecha_hasta && filters.fecha_desde > filters.fecha_hasta) {
        alert('La fecha «Desde» no puede ser posterior a «Hasta».');
        return;
      }
      const gen = ++repLoadGen;
      const listEl = contentEl.querySelector('#nc-asis-rep-list');
      if (listEl) listEl.innerHTML = '<p style="opacity:.7">Buscando registros...</p>';
      const q = buildSesionesQuery(filters);
      try {
        const res = await api('/asistencia/sesiones?' + q);
        if (gen !== repLoadGen || !listPanelReady('#nc-asis-rep-from')) return;
        const items = filterSesionesItems(parseSesionesItems(res), filters);
        lastReportItems = items;
        const summaryEl = contentEl.querySelector('#nc-asis-rep-summary');
        if (!listEl) return;
        if (!items.length) {
          if (summaryEl) summaryEl.innerHTML = '';
          const rangoTxt = (filters.fecha_desde || filters.fecha_hasta) ? (' entre <strong>' + escapeHtml(filters.fecha_desde || '…') + '</strong> y <strong>' + escapeHtml(filters.fecha_hasta || '…') + '</strong>') : '';
          listEl.innerHTML = '<p style="opacity:.8">No hay registros' + rangoTxt + ' con los filtros elegidos.</p>';
          return;
        }
        if (summaryEl) {
          summaryEl.innerHTML = buildResumenHtml(items);
        }
        listEl.innerHTML = `
          <table class="nc-table" style="width:100%">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Curso</th>
                <th>Materia</th>
                <th>Docente</th>
                <th>Aula (lugar físico)</th>
                <th>Grupo</th>
                <th>Reemplazante</th>
                <th>Presentes</th>
                ${showAuditCols ? '<th>Registró</th><th>Creado</th><th>Modificado por</th><th>Fecha mod.</th>' : ''}
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              ${items.map(s => {
                const puedeEditar = canUserEditSesion(s, perms);
                return `
                <tr data-asistencia-id="${s.id}">
                  <td>${escapeHtml(s.fecha || '')}</td>
                  <td>${escapeHtml(s.curso_nombre || '')}</td>
                  <td>${escapeHtml(s.materia_nombre || '')}</td>
                  <td>${escapeHtml(s.docente_encargado_nombre || '-')}</td>
                  <td>${escapeHtml(aulaFisicaDisplay(s.aula_fisica_nombre))}</td>
                  <td>${escapeHtml(grupoDisplay(s.grupo_nombre, s.es_simulacro))}</td>
                  <td>${escapeHtml(reemplazanteDisplay(s))}</td>
                  <td class="nc-presentes-cell">${escapeHtml(s.presentes_total || '0/0')}</td>
                  ${showAuditCols ? '<td>' + escapeHtml(s.creado_por_nombre || '') + '</td><td>' + escapeHtml(s.created_at || '') + '</td><td>' + escapeHtml(s.modificado_por_nombre || '-') + '</td><td>' + escapeHtml(s.modified_at || '-') + '</td>' : ''}
                  <td>${puedeEditar ? '<button type="button" class="nc-btn" data-edit-sesion="' + s.id + '">Editar</button> ' + '<button type="button" class="nc-btn" data-delete-sesion="' + s.id + '" style="background:#c62828;color:#fff;margin-left:4px">Eliminar</button>' : '<span style="color:#999">—</span>'}</td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        `;
        listEl.querySelectorAll('[data-edit-sesion]').forEach(btn => {
          btn.onclick = () => openEditarSesionModal(Number(btn.dataset.editSesion));
        });
        listEl.querySelectorAll('[data-delete-sesion]').forEach(btn => {
          btn.onclick = async () => {
            if (!confirm('¿Eliminar esta asistencia? No se podrá recuperar.')) return;
            try {
              await api('/asistencia/sesiones/' + btn.dataset.deleteSesion, { method: 'DELETE' });
              loadReportes();
            } catch (err) {
              alert(err && err.message ? err.message : 'Error al eliminar.');
            }
          };
        });
      } catch (e) {
        const repList = contentEl && contentEl.querySelector('#nc-asis-rep-list');
        if (repList) repList.innerHTML = '<p style="color:#b00">Error: ' + escapeHtml(e.message) + '</p>';
      }
    }

    const btnRepBuscar = contentEl.querySelector('#nc-asis-rep-buscar');
    if (btnRepBuscar) btnRepBuscar.onclick = withButtonLock(btnRepBuscar, loadReportes, { loadingText: 'Buscando...' });
    const repFrom = contentEl.querySelector('#nc-asis-rep-from');
    const repTo = contentEl.querySelector('#nc-asis-rep-to');
    const repMat = contentEl.querySelector('#nc-asis-rep-materia');
    const repAula = contentEl.querySelector('#nc-asis-rep-aula');
    const repSearch = contentEl.querySelector('#nc-asis-rep-search');
    let repSearchTimer = null;
    function scheduleRepLoad() {
      clearTimeout(repSearchTimer);
      repSearchTimer = setTimeout(loadReportes, 280);
    }
    if (repFrom) repFrom.addEventListener('change', loadReportes);
    if (repTo) repTo.addEventListener('change', loadReportes);
    if (repMat) repMat.addEventListener('change', loadReportes);
    if (repAula) repAula.addEventListener('change', loadReportes);
    if (repSearch) repSearch.addEventListener('input', scheduleRepLoad);

    function exportResumenCSV() {
      if (!lastReportItems || !lastReportItems.length) {
        alert('Primero genere un reporte para poder exportar el resumen.');
        return;
      }
      const header = ['Grupo', 'Materia', 'Presentes', 'Total', 'Porcentaje', 'Ausencias', 'Sesiones'];
      const map = {};
      lastReportItems.forEach(s => {
        const ratio = String(s.presentes_total || '0/0').split('/');
        const presentes = Number(ratio[0]) || 0;
        const total = Number(ratio[1]) || 0;
        const gn = grupoLabelSesion(s);
        const key = gn + '|' + String(s.materia_nombre || '');
        if (!map[key]) {
          map[key] = {
            aula_nombre: gn,
            materia_nombre: s.materia_nombre || '',
            presentes: 0,
            total: 0,
            sesiones: 0
          };
        }
        const g = map[key];
        g.presentes += presentes;
        g.total += total;
        g.sesiones += 1;
      });
      const rows = Object.values(map).map(g => {
        const pct = g.total ? (g.presentes * 100 / g.total).toFixed(1) : '0.0';
        const aus = g.total - g.presentes;
        return [
          g.aula_nombre,
          g.materia_nombre,
          String(g.presentes),
          String(g.total),
          pct + '%',
          String(aus),
          String(g.sesiones)
        ];
      });
      const csvLines = [header.join(','), ...rows.map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(','))];
      const blob = new Blob([csvLines.join('\n')], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      const fromVal = document.getElementById('nc-asis-rep-from')?.value || '';
      const toVal = document.getElementById('nc-asis-rep-to')?.value || '';
      a.download = 'resumen-asistencia-' + (fromVal || 'desde') + '-' + (toVal || 'hasta') + '.csv';
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 4000);
    }

    function exportResumenPDF() {
      if (!lastReportItems || !lastReportItems.length) {
        alert('Primero genere un reporte para poder exportar el resumen.');
        return;
      }
      const summaryEl = contentEl.querySelector('#nc-asis-rep-summary');
      const fromVal = document.getElementById('nc-asis-rep-from')?.value || '';
      const toVal = document.getElementById('nc-asis-rep-to')?.value || '';
      const win = window.open('', '_blank');
      if (!win) {
        alert('El navegador bloqueó la ventana de impresión. Permita ventanas emergentes para exportar en PDF.');
        return;
      }
      const htmlResumen = summaryEl ? summaryEl.innerHTML : '';
      win.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset="utf-8" />
          <title>Resumen de asistencia</title>
          <style>
            body { font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin:20px; }
            h1 { font-size:20px; margin-bottom:4px; }
            h2 { font-size:14px; margin-top:0; color:#666; }
            table { border-collapse: collapse; width: 100%; font-size:11px; }
            th, td { border: 1px solid #ddd; padding: 6px 8px; text-align:left; }
            th { background:#f0f4ff; }
          </style>
        </head>
        <body>
          <h1>Resumen de asistencia</h1>
          <h2>Rango: ${fromVal || '-'} a ${toVal || '-'}</h2>
          ${htmlResumen}
        </body>
        </html>
      `);
      win.document.close();
      win.focus();
      setTimeout(() => {
        win.print();
      }, 300);
    }

    async function downloadExport(tipo) {
      const aula_id = contentEl.querySelector('#nc-asis-rep-aula').value;
      const fecha_desde = contentEl.querySelector('#nc-asis-rep-from').value;
      const fecha_hasta = contentEl.querySelector('#nc-asis-rep-to').value;
      const materia_id = contentEl.querySelector('#nc-asis-rep-materia').value;
      if (!aula_id) { alert('Para exportar indique grupo.'); return; }
      if (!fecha_desde || !fecha_hasta) { alert('Para exportar indique rango Desde y Hasta.'); return; }
      const baseUrl = (typeof API !== 'undefined' && API) ? API : (window.NC_APP && window.NC_APP.apiUrl ? (String(window.NC_APP.apiUrl).startsWith('http') ? String(window.NC_APP.apiUrl) : (window.location.origin + String(window.NC_APP.apiUrl))).replace(/\/$/, '') : '');
      let url = baseUrl + '/asistencia/export/' + tipo + '?fecha_desde=' + encodeURIComponent(fecha_desde) + '&fecha_hasta=' + encodeURIComponent(fecha_hasta) + '&aula_id=' + encodeURIComponent(aula_id) + '&vista=calendario&nc_ts=' + Date.now();
      if (materia_id) url += '&materia_id=' + encodeURIComponent(materia_id);
      try {
        const res = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } });
        if (!res.ok) {
          let msg = res.status === 401 ? 'Sin permisos para exportar. Inicie sesión.' : (res.status === 404 ? 'No se encontró el servicio de exportación. Compruebe que el módulo esté activo.' : 'Error al exportar.');
          try {
            const text = await res.text();
            const j = JSON.parse(text);
            if (j && j.message) msg = j.message;
          } catch (_) {}
          throw new Error(msg);
        }
        const blob = await res.blob();
        const name = (res.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/);
        const filename = name ? name[1] : 'reporte-asistencia-' + fecha_desde + '-' + fecha_hasta + '.' + (tipo === 'pdf' ? 'pdf' : 'csv');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        a.click();
        URL.revokeObjectURL(a.href);
      } catch (e) {
        alert(e && e.message ? e.message : 'Error al exportar.');
      }
    }
    if (canExport) {
      const btnExportCsv = contentEl.querySelector('#nc-asis-rep-export-csv');
      const btnExportPdf = contentEl.querySelector('#nc-asis-rep-export-pdf');
      if (btnExportCsv) btnExportCsv.onclick = withButtonLock(btnExportCsv, () => { downloadExport('csv'); exportResumenCSV(); }, { loadingText: 'Exportando...' });
      if (btnExportPdf) btnExportPdf.onclick = withButtonLock(btnExportPdf, () => { downloadExport('pdf'); exportResumenPDF(); }, { loadingText: 'Exportando...' });
    }

    loadReportes();
  }

  // ---------- Mis asistencias registradas ----------
  async function renderMisAsistencias() {
    if (!isActiveSub('mis-asistencias')) return;
    contentEl.innerHTML = '<div class="nc-card"><p>Cargando...</p></div>';
    let materias = [], aulas = [];
    try {
      const [matRes, aulasRes] = await Promise.all([api('/materias'), api('/aulas')]);
      materias = (matRes && matRes.items) ? matRes.items : [];
      aulas = Array.isArray(aulasRes) ? aulasRes : (aulasRes && aulasRes.items ? aulasRes.items : []);
    } catch (_) {}
    if (!isActiveSub('mis-asistencias')) return;
    const perms = await getPermissions();
    if (!isActiveSub('mis-asistencias')) return;
    const canManageAttendance = !!(perms && perms.can_manage_attendance);
    const showAuditCols = canManageAttendance;
    let misLoadGen = 0;

    function aulaFisicaDisplay(nombre) {
      const s = String(nombre || '').trim();
      if (!s) return '';
      if (s.includes('->')) {
        const left = s.split('->')[0].trim().toUpperCase();
        return left;
      }
      // Soporta casos como "(K) Kappa" o " ( Z ) Zeta "
      const m = s.match(/^\(?\s*([KLMNXPZS])\s*\)?/i);
      if (m && m[1]) return String(m[1]).toUpperCase();
      return s.toUpperCase();
    }

    function grupoDisplay(nombre, esSimulacro) {
      if (Number(esSimulacro) === 1 || String(nombre || '').trim() === 'Simulacro') return 'Simulacro';
      let s = String(nombre || '').trim();
      if (!s) return '';
      if (s.includes('->')) s = s.split('->')[1].trim();
      // Limpia prefijos del tipo "(Z) " => "Zeta"
      s = s.replace(/^\(\s*[A-Za-z0-9]+\s*\)\s*/,'').trim();
      return s;
    }

    function reemplazanteDisplay(s) {
      const flag = (Number(s.es_reemplazante || 0) === 1);
      if (!flag) return 'No';
      const n = String(s.docente_encargado_nombre || '').trim();
      return n ? ('Sí: ' + n) : 'Sí';
    }
    const aulasGrupoMis = filterAulasGrupoDropdown(aulas);
    const rangeMis = defaultAsisDateRange(24);
    const fromStr = rangeMis.from;
    const toStr = rangeMis.to;
    contentEl.innerHTML = `
      <div class="nc-card">
        <h3 style="margin:0 0 14px">Mis asistencias registradas</h3>
        <p style="margin:0 0 10px;font-size:13px;color:#666">Solo las listas que registraste vos (columna «Registró»). Por defecto: últimos 24 meses.</p>
        <div class="nc-row nc-row-wrap" style="gap:12px;margin-bottom:16px">
          <div class="nc-field">
            <label>Desde</label>
            <input type="date" id="nc-asis-mis-from" value="${fromStr}" />
          </div>
          <div class="nc-field">
            <label>Hasta</label>
            <input type="date" id="nc-asis-mis-to" value="${toStr}" />
          </div>
          <div class="nc-field">
            <label>Materia</label>
            <select id="nc-asis-mis-materia"><option value="">Todas</option>${materias.map(m => '<option value="' + m.id + '">' + escapeHtml(m.nombre) + '</option>').join('')}</select>
          </div>
          <div class="nc-field">
            <label>Grupo</label>
            <select id="nc-asis-mis-aula"><option value="">Todas</option>${aulasGrupoMis.map(a => '<option value="' + a.id + '">' + escapeHtml(aulaGrupoOptionLabel(a)) + '</option>').join('')}</select>
          </div>
          <div class="nc-field" style="flex:1;min-width:180px">
            <label>Buscar</label>
            <input type="search" id="nc-asis-mis-search" placeholder="Materia, grupo…" />
          </div>
          <button type="button" class="nc-btn" id="nc-asis-mis-buscar">Buscar</button>
        </div>
        <div id="nc-asis-mis-list" class="nc-asis-table-scroll"></div>
      </div>
    `;

    function getMisFilters() {
      return {
        fecha_desde: (contentEl.querySelector('#nc-asis-mis-from') || {}).value || '',
        fecha_hasta: (contentEl.querySelector('#nc-asis-mis-to') || {}).value || '',
        materia_id: (contentEl.querySelector('#nc-asis-mis-materia') || {}).value || '',
        aula_id: (contentEl.querySelector('#nc-asis-mis-aula') || {}).value || '',
        search: (contentEl.querySelector('#nc-asis-mis-search') || {}).value || '',
      };
    }

    async function loadMis() {
      if (!listPanelReady('#nc-asis-mis-from')) return;
      const filters = getMisFilters();
      if (filters.fecha_desde && filters.fecha_hasta && filters.fecha_desde > filters.fecha_hasta) {
        alert('La fecha «Desde» no puede ser posterior a «Hasta».');
        return;
      }
      const gen = ++misLoadGen;
      const listEl = contentEl.querySelector('#nc-asis-mis-list');
      if (listEl) listEl.innerHTML = '<p style="opacity:.7">Buscando registros...</p>';
      const q = buildSesionesQuery(Object.assign({}, filters, { solo_mias: true }));
      try {
        const res = await api('/asistencia/sesiones?' + q);
        if (gen !== misLoadGen || !listPanelReady('#nc-asis-mis-from')) return;
        const items = filterSesionesItems(parseSesionesItems(res), filters);
        if (!listEl) return;
        if (!items.length) {
          const rangoTxt = (filters.fecha_desde || filters.fecha_hasta) ? (' entre <strong>' + escapeHtml(filters.fecha_desde || '…') + '</strong> y <strong>' + escapeHtml(filters.fecha_hasta || '…') + '</strong>') : '';
          listEl.innerHTML = '<p style="opacity:.8">No registraste asistencias' + rangoTxt + ' con los filtros elegidos.</p>';
          return;
        }
        listEl.innerHTML = `
          <table class="nc-table" style="width:100%">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Curso</th>
                <th>Materia</th>
                <th>Docente</th>
                <th>Aula (lugar físico)</th>
                <th>Grupo</th>
                <th>Reemplazante</th>
                <th>Presentes</th>
                ${showAuditCols ? '<th>Registró</th><th>Creado</th><th>Modificado por</th><th>Fecha mod.</th>' : ''}
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              ${items.map(s => {
                const puedeEditar = canUserEditSesion(s, perms);
                return `
                <tr data-asistencia-id="${s.id}">
                  <td>${escapeHtml(s.fecha || '')}</td>
                  <td>${escapeHtml(s.curso_nombre || '')}</td>
                  <td>${escapeHtml(s.materia_nombre || '')}</td>
                  <td>${escapeHtml(s.docente_encargado_nombre || '-')}</td>
                  <td>${escapeHtml(aulaFisicaDisplay(s.aula_fisica_nombre))}</td>
                  <td>${escapeHtml(grupoDisplay(s.grupo_nombre, s.es_simulacro))}</td>
                  <td>${escapeHtml(reemplazanteDisplay(s))}</td>
                  <td class="nc-presentes-cell">${escapeHtml(s.presentes_total || '0/0')}</td>
                  ${showAuditCols ? '<td>' + escapeHtml(s.creado_por_nombre || '') + '</td><td>' + escapeHtml(s.created_at || '') + '</td><td>' + escapeHtml(s.modificado_por_nombre || '-') + '</td><td>' + escapeHtml(s.modified_at || '-') + '</td>' : ''}
                  <td>${puedeEditar ? '<button type="button" class="nc-btn" data-edit-sesion="' + s.id + '">Editar</button> ' + '<button type="button" class="nc-btn" data-delete-sesion="' + s.id + '" style="background:#c62828;color:#fff;margin-left:4px">Eliminar</button>' : '<span style="color:#999">—</span>'}</td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        `;
        listEl.querySelectorAll('[data-edit-sesion]').forEach(btn => {
          btn.onclick = () => openEditarSesionModal(Number(btn.dataset.editSesion));
        });
        listEl.querySelectorAll('[data-delete-sesion]').forEach(btn => {
          btn.onclick = async () => {
            if (!confirm('¿Eliminar esta asistencia? No se podrá recuperar.')) return;
            try {
              await api('/asistencia/sesiones/' + btn.dataset.deleteSesion, { method: 'DELETE' });
              loadMis();
            } catch (err) {
              alert(err && err.message ? err.message : 'Error al eliminar.');
            }
          };
        });
      } catch (e) {
        const listEl = contentEl && contentEl.querySelector('#nc-asis-mis-list');
        if (listEl) listEl.innerHTML = '<p style="color:#b00">Error: ' + escapeHtml(e.message) + '</p>';
      }
    }

    const btnBuscar = contentEl.querySelector('#nc-asis-mis-buscar');
    if (btnBuscar) btnBuscar.onclick = withButtonLock(btnBuscar, loadMis, { loadingText: 'Buscando...' });
    const misFrom = contentEl.querySelector('#nc-asis-mis-from');
    const misTo = contentEl.querySelector('#nc-asis-mis-to');
    const misMat = contentEl.querySelector('#nc-asis-mis-materia');
    const misAula = contentEl.querySelector('#nc-asis-mis-aula');
    const misSearch = contentEl.querySelector('#nc-asis-mis-search');
    let misSearchTimer = null;
    function scheduleMisLoad() {
      clearTimeout(misSearchTimer);
      misSearchTimer = setTimeout(loadMis, 280);
    }
    if (misFrom) misFrom.addEventListener('change', loadMis);
    if (misTo) misTo.addEventListener('change', loadMis);
    if (misMat) misMat.addEventListener('change', loadMis);
    if (misAula) misAula.addEventListener('change', loadMis);
    if (misSearch) misSearch.addEventListener('input', scheduleMisLoad);
    loadMis();
  }

  function openEditarSesionModal(sesionId) {
    if (window.NC_AppState) {
      window.NC_AppState.setModal({ type: 'editar_sesion', sesionId: Number(sesionId) });
      window.NC_AppState.persistRoute({ mainTab: 'asistencia-main', asistenciaSub: activeSubId || 'mis-asistencias' });
    }
    const modal = el('div', { class: 'nc-modal-overlay', id: 'nc-asis-edit-modal' });
    modal.innerHTML = `
      <div class="nc-modal nc-modal-scroll" style="max-width:900px;max-height:90vh;background:#fff;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.12)">
        <div class="nc-modal-header" style="padding:16px 20px;border-bottom:1px solid #e0e0e0;background:#fff;border-radius:8px 8px 0 0;display:flex;align-items:center;justify-content:space-between">
          <h3 style="margin:0;font-size:18px;font-weight:600;color:#333">Editar asistencia</h3>
          <button type="button" class="nc-modal-close" style="color:#555;font-size:24px;font-weight:bold;background:transparent;border:none;cursor:pointer;padding:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;line-height:1">&times;</button>
        </div>
        <div class="nc-modal-body" style="padding:20px;max-height:calc(90vh - 160px);overflow-y:auto">
          <p style="color:#666">Cargando...</p>
        </div>
        <div class="nc-modal-footer" style="padding:16px 20px;border-top:1px solid #e0e0e0;background:#fafafa;border-radius:0 0 8px 8px;display:flex;gap:10px;justify-content:flex-end">
          <button type="button" class="nc-btn nc-asis-edit-cancel" style="background:#fff;color:#333;padding:8px 18px;border:1px solid #333;border-radius:6px;cursor:pointer">Cerrar</button>
          <button type="button" class="nc-btn" id="nc-asis-edit-save" style="background:#333;color:#fff;padding:8px 18px;border:none;border-radius:6px;cursor:pointer;font-weight:500">Guardar cambios</button>
        </div>
      </div>
    `;
    const closeEditModal = () => {
      if (window.NC_AppState) window.NC_AppState.clearModal();
      modal.remove();
    };
    modal.querySelector('.nc-modal-close').onclick = closeEditModal;
    modal.querySelector('.nc-asis-edit-cancel').onclick = closeEditModal;
    modal.onclick = (e) => { if (e.target === modal) closeEditModal(); };
    document.body.appendChild(modal);

    let editItemsFull = [];
    let editItems = [];
    let editSortBy = 'apellido'; // 'nombre' | 'apellido' | 'presentes' | 'ausentes'

    function isEditItemPresent(it) {
      return it.asistio === 1 || it.asistio === true || it.asistio === '1';
    }

    function compareEditItemsByName(a, b, byNombre) {
      const an = (a.alumno_nombres || '').toLowerCase();
      const aa = (a.alumno_apellidos || '').toLowerCase();
      const bn = (b.alumno_nombres || '').toLowerCase();
      const ba = (b.alumno_apellidos || '').toLowerCase();
      if (byNombre) return an.localeCompare(bn) || aa.localeCompare(ba);
      return aa.localeCompare(ba) || an.localeCompare(bn);
    }
    let editSesionInfo = null;

    let editMateriasList = [];
    let editDocentesList = [];
    function renderEditTable() {
      const bodyEl = modal.querySelector('.nc-modal-body');

      const sesion = editSesionInfo || {};
      const materiaOpts = editMateriasList.map(m => `<option value="${m.id}" ${Number(sesion.materia_id) === Number(m.id) ? 'selected' : ''}>${escapeHtml(m.nombre)}</option>`).join('');
      const docenteOpts = editDocentesList.map(d => `<option value="${d.user_id}" ${Number(sesion.docente_encargado_id) === Number(d.user_id) ? 'selected' : ''}>${escapeHtml(d.display_name || 'Usuario ' + d.user_id)}</option>`).join('');
      const sessionBlock = editSesionInfo ? `
        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:24px">
          <div style="flex-shrink:0;width:90px;height:90px;background:#f0f7ff;border-radius:8px;border:1px solid #cce;display:flex;align-items:center;justify-content:center;color:#2e7d32;font-size:12px;font-weight:600">SESIÓN</div>
          <div style="flex:1;min-width:200px">
            <div style="margin-bottom:12px">
              <label style="display:block;margin-bottom:4px;font-size:12px;color:#666;font-weight:600">Materia</label>
              <select id="nc-asis-edit-materia" style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:14px;width:100%;max-width:280px">
                <option value="">Seleccione materia</option>${materiaOpts}
              </select>
            </div>
            <div style="margin-bottom:12px">
              <label style="display:block;margin-bottom:4px;font-size:12px;color:#666;font-weight:600">Docente</label>
              <select id="nc-asis-edit-docente" style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:14px;width:100%;max-width:280px">
                <option value="">Sin docente</option>${docenteOpts}
              </select>
            </div>
            <p style="margin:0 0 4px;font-size:14px;color:#555">Fecha: ${escapeHtml(sesion.fecha || '-')}</p>
            <p style="margin:0;font-size:14px;color:#555">Grupo: ${escapeHtml(sesion.aula_nombre || '-')}</p>
          </div>
        </div>
        <h4 style="margin:0 0 8px;font-size:15px;font-weight:600;color:#333">Lista de alumnos <span style="font-weight:400;color:#777;font-size:13px">(${editItemsFull.length} alumnos)</span></h4>
      ` : '<h4 style="margin:0 0 12px;font-size:15px;font-weight:600;color:#333">Lista de alumnos</h4>';
      bodyEl.innerHTML = sessionBlock + `
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px">
          <div style="flex:1;min-width:200px">
            <label style="display:block;margin-bottom:4px;font-size:12px;color:#666">Buscar por nombre, apellido o CI</label>
            <input type="text" id="nc-asis-edit-search" placeholder="Buscar..." style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:14px;width:100%" />
          </div>
          <div style="flex:0 0 200px">
            <label style="display:block;margin-bottom:4px;font-size:12px;color:#666">Ordenar por</label>
            <select id="nc-asis-edit-orden" style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:14px;width:100%">
              <option value="apellido" ${editSortBy === 'apellido' ? 'selected' : ''}>Apellido</option>
              <option value="nombre" ${editSortBy === 'nombre' ? 'selected' : ''}>Nombre</option>
              <option value="presentes" ${editSortBy === 'presentes' ? 'selected' : ''}>Presentes primero</option>
              <option value="ausentes" ${editSortBy === 'ausentes' ? 'selected' : ''}>Ausentes primero</option>
            </select>
          </div>
          <button type="button" class="nc-btn" id="nc-asis-edit-agregar-invitado" style="background:#fff;color:#333;border:1px solid #ccc;padding:8px 14px;border-radius:6px;font-size:13px">Agregar alumno invitado</button>
        </div>
        <div id="nc-asis-historial-tbody" style="overflow:auto;max-height:380px;border:1px solid #e8e8e8;border-radius:6px">
          <table class="nc-table" style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
              <tr style="background:#f5f5f5">
                <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd">Alumno</th>
                <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd">Apellidos</th>
                <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd">CI</th>
                <th style="padding:10px;text-align:center;border-bottom:2px solid #ddd">Asistió</th>
                <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd">Observación</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      `;
      
      const tableWrap = bodyEl.querySelector('#nc-asis-historial-tbody');
      const tableBody = tableWrap ? tableWrap.querySelector('tbody') : null;

      function renderEditRows() {
        if (!tableBody) return;
        const sortFn = (a, b) => {
          if (editSortBy === 'presentes' || editSortBy === 'ausentes') {
            const pa = isEditItemPresent(a);
            const pb = isEditItemPresent(b);
            if (pa !== pb) {
              if (editSortBy === 'presentes') return pa ? -1 : 1;
              return pa ? 1 : -1;
            }
            return compareEditItemsByName(a, b, false);
          }
          if (editSortBy === 'nombre') {
            return compareEditItemsByName(a, b, true);
          }
          return compareEditItemsByName(a, b, false);
        };
        const baseList = [...editItemsFull].sort(sortFn);
        const displayList = editItems.length ? editItems.slice().sort(sortFn) : baseList;

        tableBody.innerHTML = displayList.map(it => {
          const asistioId = `nc-asis-edit-toggle-${it.id > 0 ? it.id : 'n' + it.alumno_id}`;
          const asistioChecked = it.asistio === 1 || it.asistio === true || it.asistio === '1';
          return `
            <tr data-edit-key="${it.id > 0 ? it.id : 'new-' + it.alumno_id}" data-item-id="${it.id}" data-alumno-id="${it.alumno_id}" style="border-bottom:1px solid #eee">
              <td style="padding:10px">${escapeHtml(it.alumno_nombres || '')}</td>
              <td style="padding:10px">${escapeHtml(it.alumno_apellidos || '')}</td>
              <td style="padding:10px">${escapeHtml(it.alumno_ci || '')}</td>
              <td style="padding:10px;text-align:center">
                <label class="nc-toggle-switch" title="${asistioChecked ? 'Asistió' : 'No asistió'}">
                  <input type="checkbox" id="${asistioId}" data-item-id="${it.id}" data-alumno-id="${it.alumno_id}" ${asistioChecked ? 'checked' : ''} />
                  <span class="nc-toggle-slider"></span>
                </label>
              </td>
              <td style="padding:10px">
                <input type="text" class="nc-asis-edit-obs" data-item-id="${it.id}" data-alumno-id="${it.alumno_id}" value="${escapeHtml(it.observacion || '')}" style="width:100%;max-width:180px;padding:6px;border:1px solid #ddd;border-radius:4px" />
              </td>
            </tr>
          `;
        }).join('');

        function findEditItem(itemId, alumnoId) {
          const id = Number(itemId);
          const aid = Number(alumnoId);
          return editItemsFull.find(x => (id > 0 && Number(x.id) === id) || (Number(x.id) === 0 && Number(x.alumno_id) === aid));
        }
        tableBody.querySelectorAll('input[type="checkbox"][data-item-id]').forEach(toggle => {
          toggle.onchange = function () {
            const it = findEditItem(toggle.dataset.itemId, toggle.dataset.alumnoId);
            if (it) it.asistio = toggle.checked;
            if (editSortBy === 'presentes' || editSortBy === 'ausentes') renderEditRows();
          };
        });
  
        tableBody.querySelectorAll('.nc-asis-edit-obs').forEach(inp => {
          inp.oninput = () => {
            const it = findEditItem(inp.dataset.itemId, inp.dataset.alumnoId);
            if (it) it.observacion = inp.value.trim();
          };
        });
      }

      renderEditRows();

      const searchInput = bodyEl.querySelector('#nc-asis-edit-search');
      if (searchInput) {
        searchInput.oninput = function() {
          const term = this.value.toLowerCase().trim();
          if (!term) {
            editItems = [];
          } else {
            editItems = editItemsFull.filter(it =>
              (it.alumno_nombres || '').toLowerCase().includes(term) ||
              (it.alumno_apellidos || '').toLowerCase().includes(term) ||
              (it.alumno_ci || '').toLowerCase().includes(term)
            );
          }
          renderEditRows();
        };
      }

      const ordenSel = bodyEl.querySelector('#nc-asis-edit-orden');
      if (ordenSel) {
        ordenSel.onchange = function() {
          const v = this.value;
          editSortBy = (v === 'nombre' || v === 'presentes' || v === 'ausentes') ? v : 'apellido';
          renderEditRows();
        };
      }
      const btnAgregarInv = bodyEl.querySelector('#nc-asis-edit-agregar-invitado');
      if (btnAgregarInv) {
        btnAgregarInv.onclick = function openEditInvitadoModal() {
          const overlay = el('div', { class: 'nc-modal-overlay' });
          overlay.innerHTML = `
            <div class="nc-modal" style="max-width:500px;width:100%;background:#fff;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.12);padding:20px">
              <h4 style="margin:0 0 12px;font-size:16px">Agregar alumno invitado</h4>
              <p style="margin:0 0 10px;font-size:13px;color:#666">Busque por CI, nombre o apellido (mín. 3 caracteres).</p>
              <input type="text" id="nc-asis-edit-extra-search" placeholder="Buscar..." style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;margin-bottom:12px" />
              <div id="nc-asis-edit-extra-results" style="max-height:240px;overflow-y:auto;font-size:14px"></div>
              <div style="margin-top:12px;display:flex;justify-content:flex-end"><button type="button" class="nc-btn nc-modal-close-btn" style="background:#fff;color:#333;border:1px solid #333;padding:8px 16px;border-radius:6px">Cerrar</button></div>
            </div>
          `;
          const closeOverlay = () => overlay.remove();
          overlay.onclick = (e) => { if (e.target === overlay) closeOverlay(); };
          overlay.querySelector('.nc-modal-close-btn').onclick = closeOverlay;
          document.body.appendChild(overlay);
          const searchInput = overlay.querySelector('#nc-asis-edit-extra-search');
          const resultsEl = overlay.querySelector('#nc-asis-edit-extra-results');
          let timer = null;
          async function doSearch(term) {
            const q = (term || '').trim();
            if (q.length < 3) { resultsEl.innerHTML = '<p style="color:#777;margin:8px 0">Escriba al menos 3 caracteres.</p>'; return; }
            resultsEl.innerHTML = '<p style="color:#777;margin:8px 0">Buscando...</p>';
            try {
              const res = await api('/alumnos?search=' + encodeURIComponent(q) + '&order_by=apellidos&order=ASC');
              const items = (res && res.items) ? res.items : (Array.isArray(res) ? res : []);
              const existingIds = new Set(editItemsFull.map(x => Number(x.alumno_id)));
              if (!items.length) { resultsEl.innerHTML = '<p style="color:#777;margin:8px 0">No se encontraron alumnos.</p>'; return; }
              resultsEl.innerHTML = items.map(a => {
                const ya = existingIds.has(Number(a.id));
                return `<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #eee">
                  <span>${escapeHtml(((a.nombres||'')+' '+(a.apellidos||'')).trim() || '-')} <span style="color:#666;font-size:12px">CI: ${escapeHtml(a.ci||'')}</span></span>
                  ${ya ? '<span style="font-size:12px;color:#999">Ya en lista</span>' : '<button type="button" class="nc-btn" data-add-edit-inv="' + a.id + '" style="padding:4px 10px;font-size:12px">Agregar</button>'}
                </div>`;
              }).join('');
              resultsEl.querySelectorAll('[data-add-edit-inv]').forEach(btn => {
                btn.onclick = () => {
                  const id = Number(btn.dataset.addEditInv);
                  const a = items.find(x => Number(x.id) === id);
                  if (!a || existingIds.has(id)) return;
                  editItemsFull.push({ id: 0, alumno_id: a.id, alumno_nombres: a.nombres || '', alumno_apellidos: a.apellidos || '', alumno_ci: a.ci || '', asistio: 0, observacion: '' });
                  closeOverlay();
                  renderEditTable();
                };
              });
            } catch (e) {
              resultsEl.innerHTML = '<p style="color:#b00;margin:8px 0">Error: ' + escapeHtml(e && e.message ? e.message : String(e)) + '</p>';
            }
          }
          if (searchInput) {
            searchInput.oninput = () => { const v = searchInput.value || ''; if (timer) clearTimeout(timer); timer = setTimeout(() => doSearch(v), 350); };
            searchInput.focus();
          }
          doSearch('');
        };
      }
    }
    
    Promise.all([
      api('/asistencia/sesiones/' + sesionId),
      api('/materias?activo=1')
    ]).then(([sesion, matRes]) => {
      editSesionInfo = {
        fecha: sesion.fecha,
        materia_id: sesion.materia_id,
        materia_nombre: sesion.materia_nombre,
        aula_nombre: sesion.aula_nombre,
        docente_encargado_id: sesion.docente_encargado_id,
        docente_encargado_nombre: sesion.docente_encargado_nombre
      };
      editMateriasList = (matRes && matRes.items) ? matRes.items : [];
      editItemsFull = (sesion.items || []).map(i => ({
        ...i,
        id: Number(i.id) || 0,
        alumno_id: Number(i.alumno_id) || 0,
        asistio: i.asistio === 1 || i.asistio === true || i.asistio === '1'
      }));
      editItems = [];
      const materiaId = sesion.materia_id;
      return materiaId ? api('/materias/' + materiaId + '/docentes') : Promise.resolve({ items: [] });
    }).then(docRes => {
      editDocentesList = (docRes && docRes.items) ? docRes.items : [];
      renderEditTable();
      const materiaSel = modal.querySelector('#nc-asis-edit-materia');
      const docenteSel = modal.querySelector('#nc-asis-edit-docente');
      if (materiaSel) {
        materiaSel.addEventListener('change', async () => {
          const mid = materiaSel.value;
          editDocentesList = [];
          if (mid) {
            try {
              const r = await api('/materias/' + mid + '/docentes');
              editDocentesList = (r && r.items) ? r.items : [];
            } catch (_) {}
          }
          docenteSel.innerHTML = '<option value="">Sin docente</option>' + editDocentesList.map(d => `<option value="${d.user_id}">${escapeHtml(d.display_name || 'Usuario ' + d.user_id)}</option>`).join('');
        });
      }
    }).catch(() => {
      modal.querySelector('.nc-modal-body').innerHTML = '<p style="color:#b00">Error al cargar la sesión.</p>';
    });

    const btnEditSave = modal.querySelector('#nc-asis-edit-save');
    btnEditSave.onclick = withButtonLock(btnEditSave, async () => {
      modal.querySelectorAll('.nc-asis-edit-obs').forEach(inp => {
        const itemId = Number(inp.dataset.itemId);
        const it = editItemsFull.find(x => Number(x.id) === itemId);
        if (it) it.observacion = (inp.value || '').trim();
      });
      if (editItemsFull.length === 0) {
        alert('No hay datos de asistencia para guardar.');
        return;
      }
      const itemsPayload = editItemsFull.map(i => ({
        id: Number(i.id) || 0,
        alumno_id: Number(i.alumno_id) || 0,
        asistio: (i.asistio === true || i.asistio === 1 || i.asistio === '1') ? 1 : 0,
        observacion: (i.observacion != null ? i.observacion : '') + ''
      }));
      const materiaSel = modal.querySelector('#nc-asis-edit-materia');
      const docenteSel = modal.querySelector('#nc-asis-edit-docente');
      const materia_id = materiaSel && materiaSel.value ? Number(materiaSel.value) : null;
      const docente_encargado_id = docenteSel ? (docenteSel.value ? Number(docenteSel.value) : null) : undefined;
      const body = { items: itemsPayload };
      if (materia_id) body.materia_id = materia_id;
      if (docenteSel) body.docente_encargado_id = docente_encargado_id;
      try {
        const res = await fetch(API + '/asistencia/sesiones/' + sesionId, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
          body: JSON.stringify(body)
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error((data && data.message) || data.code || res.statusText || 'Error al guardar');
        
        // ✨ NUEVO: Obtener totales actualizados desde la API
        const presentesTotal = (data && data.presentes_total) || (data.presentes != null && data.total != null ? data.presentes + '/' + data.total : null);
        if (!presentesTotal) {
          // Si no viene en la respuesta, llamar al endpoint /total-presentes
          const totales = await getTotalPresentes(sesionId);
          if (totales) {
            const presentesFormato = totales.formato;
            actualizarContadorPresentes(sesionId, presentesFormato);
          }
        } else {
          // Actualizar con los datos que viene en la respuesta
          actualizarContadorPresentes(sesionId, presentesTotal);
        }
        
        alert('Cambios guardados correctamente.');
        closeEditModal();
        // Mantener la subsección actual y recargar su lista (Mis asistencias o Reportes)
        const activeSub = (subTabBtns && subTabBtns.find(b => b.dataset.active === '1')) ? (subTabBtns.find(b => b.dataset.active === '1').dataset.sub || 'mis-asistencias') : 'mis-asistencias';
        renderSub(activeSub);
      } catch (e) {
        alert('Error al guardar: ' + (e && e.message ? e.message : String(e)));
      }
    }, { loadingText: 'Guardando...' });
  }

  // ---------- Lista de alumnos (asistencia) ----------
  async function renderListaAlumnos() {
    contentEl.innerHTML = '<div class="nc-card"><p>Cargando...</p></div>';
    await getPermissions();
    const canEdit = canManageStudents();
    let aulas = [], cursos = [], materias = [];
    try {
      const [aulasRes, cursosRes, matRes] = await Promise.all([
        api('/aulas'),
        api('/cursos'),
        api('/materias?activo=1'),
      ]);
      aulas = Array.isArray(aulasRes) ? aulasRes : (aulasRes && aulasRes.items ? aulasRes.items : []);
      cursos = Array.isArray(cursosRes) ? cursosRes : (cursosRes && cursosRes.items ? cursosRes.items : []);
      materias = (matRes && matRes.items) ? matRes.items : [];
    } catch (_) {}
    contentEl.innerHTML = `
      <div class="nc-card">
        <h3 style="margin:0 0 14px">Lista de alumnos (asistencia)</h3>
        <div class="nc-row nc-row-wrap" style="margin-bottom:16px;gap:12px;align-items:flex-end">
          <div class="nc-field" style="flex:1;min-width:180px">
            <label style="display:block;margin-bottom:6px;font-weight:600">Grupo</label>
            <select id="nc-asis-list-aula" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
              <option value="">Todas</option>
              ${aulas.map(a => '<option value="' + a.id + '">' + escapeHtml(a.nombre) + '</option>').join('')}
            </select>
          </div>
          <div class="nc-field" style="flex:1;min-width:180px">
            <label style="display:block;margin-bottom:6px;font-weight:600">Curso</label>
            <select id="nc-asis-list-curso" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
              <option value="">Todos</option>
              ${cursos.map(c => '<option value="' + c.id + '">' + escapeHtml(c.nombre) + '</option>').join('')}
            </select>
          </div>
          <div class="nc-field" style="flex:1;min-width:180px">
            <label style="display:block;margin-bottom:6px;font-weight:600">Materia</label>
            <select id="nc-asis-list-materia" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
              <option value="">Todas</option>
              ${materias.map(m => '<option value="' + m.id + '">' + escapeHtml(m.nombre) + '</option>').join('')}
            </select>
          </div>
          <div class="nc-field" style="flex:2;min-width:220px">
            <label style="display:block;margin-bottom:6px;font-weight:600">Buscar por CI, nombre o apellido</label>
            <input type="text" id="nc-asis-list-search" placeholder="Buscar..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px" />
          </div>
          <div class="nc-field" style="min-width:150px">
            <label style="display:block;margin-bottom:6px;font-weight:600">Desde</label>
            <input type="date" id="nc-asis-list-fecha-desde" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px" />
          </div>
          <div class="nc-field" style="min-width:150px">
            <label style="display:block;margin-bottom:6px;font-weight:600">Hasta</label>
            <input type="date" id="nc-asis-list-fecha-hasta" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px" />
          </div>
          <button type="button" class="nc-btn primary" id="nc-asis-list-buscar" style="background:#2e7d32;color:#fff;padding:8px 20px">Buscar</button>
          <button type="button" class="nc-btn" id="nc-asis-list-export-csv" style="background:#fff;color:#2e7d32;border:1px solid #2e7d32;padding:8px 20px">Exportar asistencias CSV</button>
          <button type="button" class="nc-btn" id="nc-asis-list-export-xlsx" style="background:#1b5e20;color:#fff;border:1px solid #1b5e20;padding:8px 20px" title="Excel con fondo verde (Sí) y rojo (No)">Exportar Excel (colores)</button>
        </div>
        <div id="nc-asis-list-table-wrap"></div>
      </div>
    `;

    let allItems = [];
    let listSortBy = 'apellido'; // 'nombre' | 'apellido' | 'pct-asc' | 'pct-desc'
    const LIST_PAGE_SIZE = 15;
    let listPaginator = { page: 1 };

    function csvEscape(val) {
      const s = String(val == null ? '' : val);
      return '"' + s.replace(/"/g, '""') + '"';
    }

    function downloadCsv(filename, rows) {
      const csvContent = '\ufeff' + rows.map(row => row.map(csvEscape).join(',')).join('\n');
      const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = filename;
      a.style.display = 'none';
      document.body.appendChild(a);
      a.click();
      setTimeout(() => {
        URL.revokeObjectURL(a.href);
        a.remove();
      }, 500);
    }

    /** YYYY-MM-DD -> nombre del día en español (zona local). */
    function diaSemanaEsLargo(ymd) {
      const s = String(ymd || '').trim();
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
      if (!m) return '';
      const y = Number(m[1]);
      const mo = Number(m[2]);
      const d = Number(m[3]);
      if (!y || !mo || !d) return '';
      const dt = new Date(y, mo - 1, d);
      const nombres = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
      return nombres[dt.getDay()] || '';
    }

    async function exportListaAsistenciasCsv() {
      if (!allItems.length) {
        alert('No hay alumnos para exportar. Primero aplique filtros y cargue la lista.');
        return;
      }
      const materiaIdRaw = (contentEl.querySelector('#nc-asis-list-materia') || {}).value || '';
      const materiaId = Number(materiaIdRaw) || 0;
      const fechaDesde = (contentEl.querySelector('#nc-asis-list-fecha-desde') || {}).value || '';
      const fechaHasta = (contentEl.querySelector('#nc-asis-list-fecha-hasta') || {}).value || '';
      if (fechaDesde && fechaHasta && fechaDesde > fechaHasta) {
        alert('El rango de fechas es inválido: "Desde" no puede ser mayor que "Hasta".');
        return;
      }
      const byKey = {};
      const rowsByAlumnoId = {};

      const alumnoIds = allItems.map(al => Number(al.id) || 0).filter(id => id > 0);
      const CHUNK_SIZE = 20;
      const chunks = [];
      for (let i = 0; i < alumnoIds.length; i += CHUNK_SIZE) {
        chunks.push(alumnoIds.slice(i, i + CHUNK_SIZE));
      }

      let historialQuery = '';
      if (fechaDesde) historialQuery += (historialQuery ? '&' : '') + 'fecha_desde=' + encodeURIComponent(fechaDesde);
      if (fechaHasta) historialQuery += (historialQuery ? '&' : '') + 'fecha_hasta=' + encodeURIComponent(fechaHasta);
      historialQuery = historialQuery ? ('?' + historialQuery) : '';

      for (const chunk of chunks) {
        const batchResults = await Promise.all(chunk.map(async (alumnoId) => {
          const historial = await api('/asistencia/alumno/' + alumnoId + '/historial' + historialQuery);
          return { alumnoId, items: (historial && historial.items) ? historial.items : [] };
        }));

        batchResults.forEach(({ alumnoId, items }) => {
          if (!rowsByAlumnoId[alumnoId]) rowsByAlumnoId[alumnoId] = {};
          items.forEach(it => {
            if (materiaId && Number(it.materia_id || 0) !== materiaId) return;
            const sesionId = Number(it.asistencia_id || 0);
            const fecha = String(it.fecha || '').trim();
            const materia = String(it.materia_nombre || 'Materia').trim();
            const key = sesionId > 0 ? ('sid_' + sesionId) : (materia + '__' + fecha);
            const dia = diaSemanaEsLargo(fecha);
            const labelBase = materia + ' - ' + fecha;
            const label = dia ? (materia + ' - ' + dia + ' ' + fecha) : labelBase;
            if (!byKey[key]) {
              byKey[key] = { key, fecha, materia, label };
            }
            rowsByAlumnoId[alumnoId][key] = (it.asistio === 1 || it.asistio === true || it.asistio === '1') ? 'Sí' : 'No';
          });
        });
      }

      const cols = Object.values(byKey).sort((a, b) => {
        const fa = String(a.fecha || '');
        const fb = String(b.fecha || '');
        if (fa !== fb) return fa.localeCompare(fb);
        return String(a.materia || '').localeCompare(String(b.materia || ''));
      });

      if (!cols.length) {
        alert('No hay asistencias para exportar con los filtros actuales.');
        return;
      }

      const csvRows = [];
      csvRows.push(['CI', 'Estudiante', 'Grupo', ...cols.map(c => c.label)]);
      allItems.forEach(al => {
        const alumnoId = Number(al.id) || 0;
        const ci = String(al.ci || '').trim();
        const nombres = String(al.nombres || '').trim();
        const apellidos = String(al.apellidos || '').trim();
        const alumnoNombre = (apellidos + ', ' + nombres).trim().replace(/^,\s*/, '');
        const grupo = String(al.aula_nombre || '').trim();
        const marks = cols.map(c => (rowsByAlumnoId[alumnoId] && rowsByAlumnoId[alumnoId][c.key]) ? rowsByAlumnoId[alumnoId][c.key] : '');
        csvRows.push([ci, alumnoNombre, grupo, ...marks]);
      });

      const aulaNombre = (contentEl.querySelector('#nc-asis-list-aula option:checked') || {}).textContent || 'todos';
      const fechaHoy = new Date().toISOString().slice(0, 10);
      const rangoTxt = (fechaDesde || fechaHasta) ? ('-' + (fechaDesde || 'inicio') + '_a_' + (fechaHasta || 'hoy')) : '';
      const filename = 'asistencias-lista-alumnos-' + String(aulaNombre).trim().replace(/\s+/g, '-').toLowerCase() + rangoTxt + '-' + fechaHoy + '.csv';
      downloadCsv(filename, csvRows);
    }

    async function exportListaAsistenciasXlsx() {
      if (!allItems.length) {
        alert('No hay alumnos para exportar. Primero aplique filtros y cargue la lista.');
        return;
      }
      const aula_id = (contentEl.querySelector('#nc-asis-list-aula') || {}).value || '';
      const curso_id = (contentEl.querySelector('#nc-asis-list-curso') || {}).value || '';
      const materia_id = (contentEl.querySelector('#nc-asis-list-materia') || {}).value || '';
      const search = (contentEl.querySelector('#nc-asis-list-search') || {}).value || '';
      const fechaDesde = (contentEl.querySelector('#nc-asis-list-fecha-desde') || {}).value || '';
      const fechaHasta = (contentEl.querySelector('#nc-asis-list-fecha-hasta') || {}).value || '';
      if (fechaDesde && fechaHasta && fechaDesde > fechaHasta) {
        alert('El rango de fechas es inválido: "Desde" no puede ser mayor que "Hasta".');
        return;
      }
      let q = 'nc_ts=' + Date.now();
      if (aula_id) q += '&aula_id=' + encodeURIComponent(aula_id);
      if (curso_id) q += '&curso_id=' + encodeURIComponent(curso_id);
      if (materia_id) q += '&materia_id=' + encodeURIComponent(materia_id);
      if (search) q += '&search=' + encodeURIComponent(search);
      if (fechaDesde) q += '&fecha_desde=' + encodeURIComponent(fechaDesde);
      if (fechaHasta) q += '&fecha_hasta=' + encodeURIComponent(fechaHasta);
      const baseUrl = (typeof API !== 'undefined' && API)
        ? API
        : (window.NC_APP && window.NC_APP.apiUrl
          ? (String(window.NC_APP.apiUrl).startsWith('http')
            ? String(window.NC_APP.apiUrl)
            : (window.location.origin + String(window.NC_APP.apiUrl))).replace(/\/$/, '')
          : '');
      const url = baseUrl + '/asistencia/lista-alumnos/export/xlsx?' + q;
      try {
        const res = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } });
        if (!res.ok) {
          let msg = res.status === 401 ? 'Sin permisos para exportar. Inicie sesión.' : (res.status === 404 ? 'No se encontró la exportación Excel.' : 'Error al exportar.');
          try {
            const text = await res.text();
            const j = JSON.parse(text);
            if (j && j.message) msg = j.message;
          } catch (_) {}
          throw new Error(msg);
        }
        const blob = await res.blob();
        const name = (res.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/);
        const filename = name ? name[1] : ('asistencias-lista-alumnos-' + new Date().toISOString().slice(0, 10) + '.xlsx');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        a.click();
        URL.revokeObjectURL(a.href);
      } catch (e) {
        alert(e && e.message ? e.message : 'Error al exportar.');
      }
    }
    
    async function loadLista() {
      const aula_id = contentEl.querySelector('#nc-asis-list-aula').value;
      const curso_id = contentEl.querySelector('#nc-asis-list-curso').value;
      const materia_id = contentEl.querySelector('#nc-asis-list-materia').value;
      const search = (contentEl.querySelector('#nc-asis-list-search') || {}).value || '';
      let q = '';
      if (aula_id) q += '?aula_id=' + encodeURIComponent(aula_id);
      if (curso_id) q += (q ? '&' : '?') + 'curso_id=' + encodeURIComponent(curso_id);
      if (materia_id) q += (q ? '&' : '?') + 'materia_id=' + encodeURIComponent(materia_id);
      if (search) q += (q ? '&' : '?') + 'search=' + encodeURIComponent(search);
      try {
        const res = await api('/asistencia/lista-alumnos' + q);
        allItems = (res && res.items) ? res.items : [];
        const sortFn = (a, b) => {
          if (listSortBy === 'pct-asc' || listSortBy === 'pct-desc') {
            const pa = Number(a.porcentaje ?? 0);
            const pb = Number(b.porcentaje ?? 0);
            if (pa !== pb) return listSortBy === 'pct-asc' ? pa - pb : pb - pa;
          }
          const an = (a.nombres || '').toLowerCase();
          const aa = (a.apellidos || '').toLowerCase();
          const bn = (b.nombres || '').toLowerCase();
          const ba = (b.apellidos || '').toLowerCase();
          if (listSortBy === 'nombre') {
            return an.localeCompare(bn) || aa.localeCompare(ba);
          }
          return aa.localeCompare(ba) || an.localeCompare(bn);
        };
        allItems.sort(sortFn);
        listPaginator.page = 1;
        renderTable();
      } catch (e) {
        const listWrap = contentEl && contentEl.querySelector('#nc-asis-list-table-wrap');
        if (listWrap) listWrap.innerHTML = '<p style="color:#b00">Error: ' + escapeHtml(e.message) + '</p>';
      }
    }
    
    function renderTable() {
      const wrap = contentEl.querySelector('#nc-asis-list-table-wrap');
      if (!wrap) return;
      
      if (!allItems.length) {
        wrap.innerHTML = '<p style="opacity:.8;padding:20px;text-align:center">No hay alumnos que coincidan con los filtros.</p>';
        return;
      }
      
      const totalPages = Math.ceil(allItems.length / LIST_PAGE_SIZE) || 1;
      const start = (listPaginator.page - 1) * LIST_PAGE_SIZE;
      const pageItems = allItems.slice(start, start + LIST_PAGE_SIZE);
      
      wrap.innerHTML = `
        <table class="nc-table" style="width:100%;border-collapse:collapse">
          <thead>
            <tr style="background:#f5f5f5">
              <th style="padding:12px;text-align:left;border-bottom:2px solid #ddd;font-weight:600">Nombre</th>
              <th style="padding:12px;text-align:left;border-bottom:2px solid #ddd;font-weight:600">Apellido</th>
              <th style="padding:12px;text-align:left;border-bottom:2px solid #ddd;font-weight:600">CI</th>
              <th style="padding:12px;text-align:left;border-bottom:2px solid #ddd;font-weight:600">Curso</th>
              <th style="padding:12px;text-align:left;border-bottom:2px solid #ddd;font-weight:600">Grupo</th>
              <th style="padding:12px;text-align:left;border-bottom:2px solid #ddd;font-weight:600">Promedio asistencia</th>
              <th style="padding:12px;text-align:center;border-bottom:2px solid #ddd;font-weight:600">Acciones</th>
            </tr>
          </thead>
          <tbody>
            ${pageItems.map(a => {
              const pct = a.porcentaje ?? 0;
              const color = pct < 30 ? '#c62828' : pct < 70 ? '#f9a825' : '#2e7d32';
              return `
                <tr data-alumno-id="${a.id}" style="cursor:pointer;border-bottom:1px solid #eee;transition:background 0.2s" 
                    onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background=''">
                  <td style="padding:12px">${escapeHtml(a.nombres || '')}</td>
                  <td style="padding:12px">${escapeHtml(a.apellidos || '')}</td>
                  <td style="padding:12px">${escapeHtml(a.ci || '')}</td>
                  <td style="padding:12px">${escapeHtml(a.curso_nombre || '')}</td>
                  <td style="padding:12px">${escapeHtml(a.aula_nombre || '')}</td>
                  <td style="padding:12px"><span style="color:${color};font-weight:600">${a.promedio_texto || '0/0'} (${pct}%)</span></td>
                  <td style="padding:12px;text-align:center">
                    <div style="display:flex;gap:8px;justify-content:center">
                      ${canEdit ? '<button type="button" class="nc-btn" data-action="editar" style="padding:6px 12px;background:#2196f3;color:#fff;border:none;border-radius:4px;cursor:pointer">Editar</button>' : ''}
                      <button type="button" class="nc-btn" data-action="ver" style="padding:6px 12px;background:#2e7d32;color:#fff;border:none;border-radius:4px;cursor:pointer">Ver</button>
                    </div>
                  </td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
        ${totalPages > 1 ? `
          <div style="display:flex;justify-content:center;align-items:center;gap:12px;margin-top:16px;padding:12px">
            <button type="button" class="nc-btn" data-list-page="prev" ${listPaginator.page <= 1 ? 'disabled' : ''} style="padding:8px 16px">Anterior</button>
            <span style="font-weight:600;color:#666">Página ${listPaginator.page} de ${totalPages}</span>
            <button type="button" class="nc-btn" data-list-page="next" ${listPaginator.page >= totalPages ? 'disabled' : ''} style="padding:8px 16px">Siguiente</button>
          </div>
        ` : ''}
      `;
      
      wrap.querySelectorAll('tr[data-alumno-id]').forEach(row => {
        const aid = Number(row.dataset.alumnoId);
        const verBtn = row.querySelector('[data-action="ver"]');
        const editarBtn = row.querySelector('[data-action="editar"]');
        
        if (verBtn) {
          verBtn.onclick = (e) => { e.stopPropagation(); openModalAlumno(aid, false, allItems); };
        }
        if (editarBtn) {
          editarBtn.onclick = (e) => { e.stopPropagation(); openModalAlumno(aid, true, allItems); };
        }
        row.onclick = () => openModalAlumno(aid, false, allItems);
      });
      
      wrap.querySelector('[data-list-page="prev"]')?.addEventListener('click', () => {
        if (listPaginator.page > 1) { listPaginator.page--; renderTable(); }
      });
      wrap.querySelector('[data-list-page="next"]')?.addEventListener('click', () => {
        if (listPaginator.page < totalPages) { listPaginator.page++; renderTable(); }
      });
    }

    // Controles
    const searchInput = contentEl.querySelector('#nc-asis-list-search');
    if (searchInput) {
      searchInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') loadLista();
      });
    }
    const sortWrapper = document.createElement('div');
    sortWrapper.className = 'nc-field';
    sortWrapper.style.margin = '0 0 12px 0';
    sortWrapper.innerHTML = `
      <label style="display:block;margin-bottom:4px;font-weight:600;font-size:13px">Ordenar por</label>
      <select id="nc-asis-list-orden" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px">
        <option value="apellido" selected>Apellido (A-Z)</option>
        <option value="nombre">Nombre (A-Z)</option>
        <option value="pct-asc">% asistencia (menor a mayor)</option>
        <option value="pct-desc">% asistencia (mayor a menor)</option>
      </select>
    `;
    const filtersRow = contentEl.querySelector('.nc-card .nc-row.nc-row-wrap');
    if (filtersRow) {
      filtersRow.appendChild(sortWrapper);
    }
    contentEl.addEventListener('change', (e) => {
      const sel = e.target;
      if (sel && sel.id === 'nc-asis-list-orden') {
        const val = sel.value;
        if (val === 'nombre' || val === 'apellido' || val === 'pct-asc' || val === 'pct-desc') {
          listSortBy = val;
        } else {
          listSortBy = 'apellido';
        }
        if (allItems.length) {
          const sortFn = (a, b) => {
            if (listSortBy === 'pct-asc' || listSortBy === 'pct-desc') {
              const pa = Number(a.porcentaje ?? 0);
              const pb = Number(b.porcentaje ?? 0);
              if (pa !== pb) return listSortBy === 'pct-asc' ? pa - pb : pb - pa;
            }
            const an = (a.nombres || '').toLowerCase();
            const aa = (a.apellidos || '').toLowerCase();
            const bn = (b.nombres || '').toLowerCase();
            const ba = (b.apellidos || '').toLowerCase();
            if (listSortBy === 'nombre') {
              return an.localeCompare(bn) || aa.localeCompare(ba);
            }
            return aa.localeCompare(ba) || an.localeCompare(bn);
          };
          allItems.sort(sortFn);
          listPaginator.page = 1;
          renderTable();
        }
      }
    });

    const btnListaBuscar = contentEl.querySelector('#nc-asis-list-buscar');
    btnListaBuscar.onclick = withButtonLock(btnListaBuscar, loadLista, { loadingText: 'Buscando...' });
    const btnExportCsv = contentEl.querySelector('#nc-asis-list-export-csv');
    if (btnExportCsv) {
      btnExportCsv.onclick = withButtonLock(btnExportCsv, exportListaAsistenciasCsv, { loadingText: 'Exportando...' });
    }
    const btnExportXlsx = contentEl.querySelector('#nc-asis-list-export-xlsx');
    if (btnExportXlsx) {
      btnExportXlsx.onclick = withButtonLock(btnExportXlsx, exportListaAsistenciasXlsx, { loadingText: 'Exportando...' });
    }
    contentEl.querySelector('#nc-asis-list-aula').onchange = loadLista;
    contentEl.querySelector('#nc-asis-list-curso').onchange = loadLista;
    contentEl.querySelector('#nc-asis-list-materia').onchange = loadLista;
    contentEl.querySelector('#nc-asis-list-search').addEventListener('keypress', (e) => {
      if (e.key === 'Enter') loadLista();
    });
    loadLista();
  }

  function openModalAlumno(alumnoId, editMode, alumnosList) {
    const canEditHistorial = canManageStudents();
    const list = Array.isArray(alumnosList) ? alumnosList : [];
    const currentIndex = list.length ? list.findIndex(a => Number(a.id) === Number(alumnoId)) : -1;
    const hasNav = list.length > 0 && currentIndex >= 0;

    if (window.NC_AppState) {
      window.NC_AppState.setModal({
        type: 'alumno_asistencia',
        alumnoId: Number(alumnoId),
        editMode: !!editMode,
        listIds: list.map(a => Number(a.id)).filter(Boolean),
        listIndex: currentIndex >= 0 ? currentIndex : 0,
      });
      window.NC_AppState.persistRoute({ mainTab: 'asistencia-main', asistenciaSub: activeSubId || 'lista-alumnos' });
    }

    const modal = el('div', { class: 'nc-modal-overlay' });
    modal.innerHTML = `
      <div class="nc-modal nc-modal-scroll" style="max-width:900px;max-height:90vh;background:#fff;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.12)">
        <div class="nc-modal-header" style="padding:16px 20px;border-bottom:1px solid #e0e0e0;background:#fff;border-radius:8px 8px 0 0;display:flex;align-items:center;justify-content:space-between">
          <h3 style="margin:0;font-size:18px;font-weight:600;color:#333">Alumno</h3>
          <button type="button" class="nc-modal-close" style="color:#555;font-size:24px;font-weight:bold;background:transparent;border:none;cursor:pointer;padding:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;line-height:1">&times;</button>
        </div>
        <div class="nc-modal-body" style="padding:20px;max-height:calc(90vh - 160px);overflow-y:auto"><p style="color:#666">Cargando...</p></div>
        <div class="nc-modal-footer" style="padding:16px 20px;border-top:1px solid #e0e0e0;background:#fafafa;border-radius:0 0 8px 8px;display:flex;gap:10px;justify-content:flex-end">
          ${editMode && canEditHistorial ? '<button type="button" class="nc-btn" id="nc-asis-hist-guardar-todos" style="background:#333;color:#fff;padding:8px 18px;border:none;border-radius:6px;cursor:pointer;font-weight:500">Guardar cambios en historial</button>' : ''}
          <button type="button" class="nc-btn nc-modal-close-btn" style="background:#fff;color:#333;padding:8px 18px;border:1px solid #333;border-radius:6px;cursor:pointer">Cerrar</button>
        </div>
      </div>
    `;
    const closeAlumnoModal = () => {
      if (window.NC_AppState) window.NC_AppState.clearModal();
      modal.remove();
    };
    modal.querySelector('.nc-modal-close').onclick = closeAlumnoModal;
    modal.querySelector('.nc-modal-close-btn').onclick = closeAlumnoModal;
    modal.onclick = (e) => { if (e.target === modal) closeAlumnoModal(); };
    document.body.appendChild(modal);
    const body = modal.querySelector('.nc-modal-body');

    function renderBody(a, historial, listIndex) {
      const idx = listIndex ?? currentIndex;
      const resumen = historial.resumen || {};
      const pct = resumen.porcentaje ?? 0;
      const color = pct < 30 ? '#c62828' : pct < 70 ? '#f9a825' : '#2e7d32';
      const navHtml = hasNav ? `
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;padding:12px 0;border-bottom:1px solid #eee">
          <button type="button" id="nc-asis-nav-prev" style="background:#fff;color:#333;border:1px solid #ccc;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px" ${idx <= 0 ? 'disabled' : ''}>Anterior</button>
          <span style="font-weight:500;color:#555;font-size:14px">Estudiante ${idx + 1} de ${list.length}</span>
          <button type="button" id="nc-asis-nav-next" style="background:#fff;color:#333;border:1px solid #ccc;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px" ${idx >= list.length - 1 ? 'disabled' : ''}>Siguiente</button>
        </div>
      ` : '';
      return navHtml + `
        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:24px">
          <div style="flex-shrink:0">${a.foto_url ? '<img src="' + escapeHtml(a.foto_url) + '" alt="" style="width:90px;height:90px;object-fit:cover;border-radius:8px;border:1px solid #e0e0e0" />' : '<div style="width:90px;height:90px;background:#eee;border-radius:8px;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;color:#888;font-size:12px;font-weight:500">SIN FOTO</div>'}</div>
          <div style="flex:1;min-width:200px">
            <p style="margin:0 0 6px;font-weight:700;font-size:16px;color:#333">${escapeHtml((a.nombres || '') + ' ' + (a.apellidos || '')).trim() || '-'}</p>
            <p style="margin:0 0 4px;font-size:14px;color:#555">CI: ${escapeHtml(a.ci || '-')}</p>
            <p style="margin:0 0 4px;font-size:14px;color:#555">Curso: ${escapeHtml(a.curso_nombre || '-')}</p>
            <p style="margin:0 0 4px;font-size:14px;color:#555">Grupo: ${escapeHtml(a.aula_nombre || '-')}</p>
            <p style="margin:0 0 4px;font-size:14px;color:#555">Facultad: ${escapeHtml(a.facultad_nombre || '-')}</p>
            <p style="margin:0;font-size:14px;color:#555">Carrera: ${escapeHtml(a.carrera_nombre || '-')}</p>
          </div>
        </div>
        <h4 style="margin:0 0 8px;font-size:15px;font-weight:600;color:#333">Estadísticas de asistencia</h4>
        <p style="margin:0 0 20px;font-size:15px"><span style="color:${color};font-weight:700">${resumen.promedio_texto || '0/0'}</span> (${pct}%)</p>
        <h4 style="margin:0 0 8px;font-size:15px;font-weight:600;color:#333">Historial de asistencia <span style="font-weight:400;color:#777;font-size:13px">(más reciente arriba)</span></h4>
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px">
          <div>
            <label style="display:block;margin-bottom:4px;font-size:12px;color:#666">Desde</label>
            <input type="date" id="nc-asis-hist-from" style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:14px" />
          </div>
          <div>
            <label style="display:block;margin-bottom:4px;font-size:12px;color:#666">Hasta</label>
            <input type="date" id="nc-asis-hist-to" style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:14px" />
          </div>
          <button type="button" id="nc-asis-hist-refresh" style="background:#333;color:#fff;padding:8px 16px;border:none;border-radius:6px;cursor:pointer;font-size:14px">Buscar</button>
          <button type="button" id="nc-asis-hist-limpiar" style="background:#fff;color:#333;padding:8px 16px;border:1px solid #333;border-radius:6px;cursor:pointer;font-size:14px">Limpiar</button>
          ${(permissionsCache && permissionsCache.is_admin) ? '<button type="button" id="nc-asis-hist-csv" style="background:#fff;color:#333;padding:8px 16px;border:1px solid #333;border-radius:6px;cursor:pointer;font-size:14px">Exportar CSV</button><button type="button" id="nc-asis-hist-pdf" style="background:#fff;color:#333;padding:8px 16px;border:1px solid #333;border-radius:6px;cursor:pointer;font-size:14px">Exportar PDF</button>' : ''}
        </div>
        <div id="nc-asis-historial-tbody" style="overflow:auto;max-height:380px;border:1px solid #e8e8e8;border-radius:6px"></div>
      `;
    }

    const state = { alumnoId, listIndex: currentIndex };
    let historialItems = [];
    Promise.all([api('/alumnos/' + alumnoId), api('/asistencia/alumno/' + alumnoId + '/historial')]).then(([alumno, historial]) => {
      body.innerHTML = renderBody(alumno, historial, state.listIndex);
    function bindHandlers() {
      const histTbody = body.querySelector('#nc-asis-historial-tbody');
      if (!histTbody) return;
      function renderHistorial() {
        const from = body.querySelector('#nc-asis-hist-from')?.value || '';
        const to = body.querySelector('#nc-asis-hist-to')?.value || '';
        let q = '';
        if (from) q += '&fecha_desde=' + encodeURIComponent(from);
        if (to) q += '&fecha_hasta=' + encodeURIComponent(to);
        api('/asistencia/alumno/' + state.alumnoId + '/historial?' + q.replace(/^&/, '')).then(h => {
          historialItems = (h.items || []).map(it => ({
            ...it,
            item_id: Number(it.item_id) || 0,
            asistio: it.asistio === 1 || it.asistio === true || it.asistio === '1'
          }));
          const items = historialItems;
          const isEdit = editMode && canEditHistorial;
          if (!items.length) {
            histTbody.innerHTML = '<p style="opacity:.8">Sin registros.</p>';
            return;
          }
          histTbody.innerHTML = `
            <table class="nc-table" style="width:100%;border-collapse:collapse;font-size:13px">
              <thead>
                <tr style="background:#f5f5f5">
                  <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd">Fecha</th>
                  <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd">Materia</th>
                  <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd">Docente</th>
                  <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd">Registrado por</th>
                  <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd">Última modificación</th>
                  <th style="padding:10px;text-align:center;border-bottom:2px solid #ddd">Asistió</th>
                  <th style="padding:10px;text-align:left;border-bottom:2px solid #ddd">Observación</th>
                </tr>
              </thead>
              <tbody>
                ${items.map((it, idx) => {
                  const id = Number(it.item_id) || 0;
                  const ultimaMod = it.item_modified_at ? (it.item_modified_at + '').replace('T', ' ').substring(0, 16) : '-';
                  if (isEdit) {
                    return `
                      <tr data-item-id="${id}" style="border-bottom:1px solid #eee">
                        <td style="padding:10px">${escapeHtml(it.fecha || '')}</td>
                        <td style="padding:10px">${escapeHtml(it.materia_nombre || '')}</td>
                        <td style="padding:10px">${escapeHtml(it.docente_nombre || '')}</td>
                        <td style="padding:10px">${escapeHtml(it.creado_por_nombre || '-')}</td>
                        <td style="padding:10px">${escapeHtml(ultimaMod)}</td>
                        <td style="padding:10px;text-align:center">
                          <label class="nc-toggle-switch" title="Asistió">
                            <input type="checkbox" class="nc-hist-asistio" data-item-id="${id}" ${it.asistio ? 'checked' : ''} />
                            <span class="nc-toggle-slider"></span>
                          </label>
                        </td>
                        <td style="padding:10px">
                          <input type="text" class="nc-hist-obs" data-item-id="${id}" value="${escapeHtml(it.observacion || '')}" style="width:100%;max-width:180px;padding:6px;border:1px solid #ddd;border-radius:4px" />
                        </td>
                      </tr>
                    `;
                  }
                  return `
                    <tr style="border-bottom:1px solid #eee">
                      <td style="padding:10px">${escapeHtml(it.fecha || '')}</td>
                      <td style="padding:10px">${escapeHtml(it.materia_nombre || '')}</td>
                      <td style="padding:10px">${escapeHtml(it.docente_nombre || '')}</td>
                      <td style="padding:10px">${escapeHtml(it.creado_por_nombre || '-')}</td>
                      <td style="padding:10px">${escapeHtml(ultimaMod)}</td>
                      <td style="padding:10px;text-align:center">${it.asistio ? 'Sí' : 'No'}</td>
                      <td style="padding:10px">${escapeHtml(it.observacion || '')}</td>
                    </tr>
                  `;
                }).join('')}
              </tbody>
            </table>
          `;
          if (isEdit) {
            histTbody.querySelectorAll('.nc-hist-asistio').forEach(toggle => {
              toggle.onchange = function() {
                const itemId = Number(this.dataset.itemId);
                const it = historialItems.find(x => Number(x.item_id) === itemId);
                if (it) it.asistio = this.checked;
              };
            });
            histTbody.querySelectorAll('.nc-hist-obs').forEach(inp => {
              inp.oninput = function() {
                const itemId = Number(this.dataset.itemId);
                const it = historialItems.find(x => Number(x.item_id) === itemId);
                if (it) it.observacion = this.value.trim();
              };
            });
          }
        }).catch(() => { histTbody.innerHTML = '<p style="color:#b00">Error al cargar historial.</p>'; });
      }
      renderHistorial();
      (function () {
        const refreshBtn = body.querySelector('#nc-asis-hist-refresh');
        const csvBtn = body.querySelector('#nc-asis-hist-csv');
        const pdfBtn = body.querySelector('#nc-asis-hist-pdf');
        if (refreshBtn) refreshBtn.onclick = withButtonLock(refreshBtn, () => { renderHistorial(); }, { loadingText: 'Actualizando...' });
        if (csvBtn) csvBtn.onclick = withButtonLock(csvBtn, () => {
          const from = body.querySelector('#nc-asis-hist-from')?.value || '';
          const to = body.querySelector('#nc-asis-hist-to')?.value || '';
          return exportAlumnoAsistenciaFile(state.alumnoId, 'csv', from || undefined, to || undefined).catch(e => alert(e.message));
        }, { loadingText: 'Exportando...' });
        if (pdfBtn) pdfBtn.onclick = withButtonLock(pdfBtn, () => {
          const from = body.querySelector('#nc-asis-hist-from')?.value || '';
          const to = body.querySelector('#nc-asis-hist-to')?.value || '';
          return exportAlumnoAsistenciaFile(state.alumnoId, 'pdf', from || undefined, to || undefined).catch(e => alert(e.message));
        }, { loadingText: 'Exportando...' });
      })();
      const limpiarBtn = body.querySelector('#nc-asis-hist-limpiar');
      if (limpiarBtn) {
        limpiarBtn.onclick = () => {
          const fromEl = body.querySelector('#nc-asis-hist-from');
          const toEl = body.querySelector('#nc-asis-hist-to');
          if (fromEl) fromEl.value = '';
          if (toEl) toEl.value = '';
          renderHistorial();
        };
      }
      const navPrev = body.querySelector('#nc-asis-nav-prev');
      const navNext = body.querySelector('#nc-asis-nav-next');
      if (hasNav && (navPrev || navNext)) {
        if (navPrev) navPrev.onclick = () => {
          if (state.listIndex <= 0) return;
          state.listIndex--;
          state.alumnoId = list[state.listIndex].id;
          Promise.all([api('/alumnos/' + state.alumnoId), api('/asistencia/alumno/' + state.alumnoId + '/historial')]).then(([al, hist]) => {
            body.innerHTML = renderBody(al, hist, state.listIndex);
            bindHandlers();
          }).catch(() => { body.innerHTML = '<p style="color:#b00">Error al cargar.</p>'; });
        };
        if (navNext) navNext.onclick = () => {
          if (state.listIndex >= list.length - 1) return;
          state.listIndex++;
          state.alumnoId = list[state.listIndex].id;
          Promise.all([api('/alumnos/' + state.alumnoId), api('/asistencia/alumno/' + state.alumnoId + '/historial')]).then(([al, hist]) => {
            body.innerHTML = renderBody(al, hist, state.listIndex);
            bindHandlers();
          }).catch(() => { body.innerHTML = '<p style="color:#b00">Error al cargar.</p>'; });
        };
      }
      const btnGuardar = modal.querySelector('#nc-asis-hist-guardar-todos');
      if (btnGuardar && editMode && canEditHistorial) {
        btnGuardar.onclick = withButtonLock(btnGuardar, async () => {
          const toggles = histTbody.querySelectorAll('.nc-hist-asistio');
          const inputs = histTbody.querySelectorAll('.nc-hist-obs');
          const toSave = [];
          toggles.forEach(t => toSave.push({ item_id: Number(t.dataset.itemId) || 0, asistio: t.checked, observacion: '' }));
          inputs.forEach(inp => {
            const id = Number(inp.dataset.itemId) || 0;
            const o = toSave.find(x => x.item_id === id);
            if (o) o.observacion = inp.value.trim();
          });
          try {
            for (const it of toSave) {
              await api('/asistencia/items/' + it.item_id, {
                method: 'PUT',
                body: JSON.stringify({ asistio: it.asistio ? 1 : 0, observacion: it.observacion || '' }),
              });
            }
            alert('Cambios guardados.');
            closeAlumnoModal();
            if (contentEl && contentEl.isConnected) renderSub('lista-alumnos');
          } catch (e) {
            alert('Error al guardar: ' + e.message);
          }
        }, { loadingText: 'Guardando...' });
      }
    };
    bindHandlers();
    }).catch(() => {
      body.innerHTML = '<p style="color:#b00">Error al cargar datos del alumno.</p>';
    });
  }

  // ---------- Materias ----------
  async function renderMaterias() {
    contentEl.innerHTML = '<div class="nc-card"><p>Cargando...</p></div>';
    let materias = [], usuarios = [];
    try {
      const [matRes, usrRes] = await Promise.all([api('/materias'), api('/usuarios')]);
      materias = (matRes && matRes.items) ? matRes.items : [];
      usuarios = (usrRes && usrRes.items) ? usrRes.items : [];
    } catch (_) {}
    contentEl.innerHTML = `
      <div class="nc-card" style="background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1)">
        <h3 style="margin:0 0 20px;font-size:24px;color:#333;border-bottom:3px solid #2e7d32;padding-bottom:12px">Materias y Docentes</h3>
        <div style="background:linear-gradient(135deg,#e8f5e9 0%,#c8e6c9 100%);padding:20px;border-radius:8px;margin-bottom:24px;border:1px solid #a5d6a7">
          <div class="nc-row" style="flex-wrap:wrap;gap:12px;align-items:flex-end">
            <div style="flex:1;min-width:250px">
              <label style="display:block;margin-bottom:8px;font-weight:600;color:#2e7d32">Nombre de la materia</label>
              <input type="text" id="nc-asis-mat-nombre" placeholder="Ej: Álgebra, Física, Química..." style="width:100%;padding:12px;border:2px solid #a5d6a7;border-radius:6px;font-size:14px;transition:border-color 0.3s" 
                     onfocus="this.style.borderColor='#2e7d32'" onblur="this.style.borderColor='#a5d6a7'" />
            </div>
            <button type="button" class="nc-btn primary" id="nc-asis-mat-crear" style="background:#2e7d32;color:#fff;padding:12px 24px;border:none;border-radius:6px;font-weight:600;cursor:pointer;transition:background 0.3s;box-shadow:0 2px 4px rgba(0,0,0,0.1)"
                    onmouseover="this.style.background='#1b5e20'" onmouseout="this.style.background='#2e7d32'">
              ➕ Crear materia
            </button>
          </div>
        </div>
        <div style="overflow-x:auto">
          <table class="nc-table" style="width:100%;border-collapse:collapse;background:#fff">
            <thead>
              <tr style="background:linear-gradient(135deg,#2e7d32 0%,#1b5e20 100%);color:#fff">
                <th style="padding:16px;text-align:left;font-weight:600;font-size:15px">Materia</th>
                <th style="padding:16px;text-align:left;font-weight:600;font-size:15px">Docentes asignados</th>
                <th style="padding:16px;text-align:center;font-weight:600;font-size:15px">Acciones</th>
              </tr>
            </thead>
            <tbody id="nc-asis-mat-tbody">
              ${materias.map(m => `
                <tr data-materia-id="${m.id}" style="border-bottom:1px solid #e0e0e0;transition:background 0.2s" 
                    onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background=''">
                  <td style="padding:16px;font-weight:600;color:#333;font-size:15px">${escapeHtml(m.nombre || '')}</td>
                  <td class="nc-asis-mat-docentes" data-materia-id="${m.id}" style="padding:16px">-</td>
                  <td style="padding:16px;text-align:center">
                    <button type="button" class="nc-btn" data-add-doc="${m.id}" style="background:#2196f3;color:#fff;padding:8px 16px;border:none;border-radius:6px;cursor:pointer;font-weight:500;transition:background 0.3s"
                            onmouseover="this.style.background='#1976d2'" onmouseout="this.style.background='#2196f3'">
                      👤 Añadir docente
                    </button>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      </div>
    `;
    materias.forEach(m => loadDocentesMateria(m.id, contentEl.querySelector('.nc-asis-mat-docentes[data-materia-id="' + m.id + '"]')));
    const btnMatCrear = contentEl.querySelector('#nc-asis-mat-crear');
    btnMatCrear.onclick = withButtonLock(btnMatCrear, async () => {
      const nombre = contentEl.querySelector('#nc-asis-mat-nombre').value.trim();
      if (!nombre) { alert('Escriba el nombre de la materia.'); return; }
      try {
        await api('/materias', { method: 'POST', body: JSON.stringify({ nombre }) });
        contentEl.querySelector('#nc-asis-mat-nombre').value = '';
        renderSub('materias');
      } catch (e) {
        alert('Error: ' + e.message);
      }
    }, { loadingText: 'Creando...' });
    contentEl.querySelectorAll('[data-add-doc]').forEach(btn => {
      btn.onclick = () => {
        const mid = Number(btn.dataset.addDoc);
        const td = contentEl.querySelector('.nc-asis-mat-docentes[data-materia-id="' + mid + '"]');
        const existing = td ? td.querySelectorAll('[data-docente-user-id]') : [];
        const existingIds = Array.from(existing).map(e => Number(e.dataset.docenteUserId));
        const disponibles = usuarios.filter(u => !existingIds.includes(Number(u.id)));
        if (!disponibles.length) { alert('No hay más usuarios para agregar o ya están todos asignados.'); return; }
        const sel = document.createElement('select');
        sel.innerHTML = '<option value="">Seleccione docente</option>' + disponibles.map(u => '<option value="' + u.id + '">' + escapeHtml(u.display_name) + '</option>').join('');
        const addBtn = document.createElement('button');
        addBtn.className = 'nc-btn';
        addBtn.textContent = 'Añadir';
        addBtn.onclick = () => {
          const uid = Number(sel.value);
          if (!uid) return;
          api('/materias/' + mid + '/docentes', { method: 'POST', body: JSON.stringify({ user_id: uid }) }).then(() => {
            loadDocentesMateria(mid, td);
            sel.remove();
            addBtn.remove();
          }).catch(e => alert('Error: ' + e.message));
        };
        td.appendChild(sel);
        td.appendChild(addBtn);
      };
    });
  }

  async function loadDocentesMateria(materiaId, td) {
    if (!td) return;
    try {
      const res = await api('/materias/' + materiaId + '/docentes');
      const items = (res && res.items) ? res.items : [];
      td.innerHTML = items.length ? items.map(d => `
        <span data-docente-user-id="${d.user_id}" style="display:inline-flex;align-items:center;margin:4px 6px 4px 0;padding:8px 12px;background:linear-gradient(135deg,#e3f2fd 0%,#bbdefb 100%);border:1px solid #90caf9;border-radius:20px;font-size:13px;font-weight:500;color:#1976d2;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
          👤 ${escapeHtml(d.display_name || '')}
          <button type="button" data-remove-doc="${materiaId}" data-user-id="${d.user_id}" 
                  style="margin-left:8px;cursor:pointer;background:transparent;border:none;color:#d32f2f;font-size:18px;font-weight:bold;padding:0;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;transition:background 0.2s"
                  onmouseover="this.style.background='#ffebee'" onmouseout="this.style.background='transparent'"
                  title="Eliminar docente">×</button>
        </span>
      `).join('') : '<span style="color:#999;font-style:italic">Sin docentes asignados</span>';
      td.querySelectorAll('[data-remove-doc]').forEach(b => {
        b.onclick = () => {
          if (confirm('¿Eliminar este docente de la materia?')) {
            api('/materias/' + b.dataset.removeDoc + '/docentes/' + b.dataset.userId, { method: 'DELETE' }).then(() => loadDocentesMateria(materiaId, td)).catch(e => alert(e.message));
          }
        };
      });
    } catch (_) {
      td.innerHTML = '<span style="color:#999;font-style:italic">Error al cargar</span>';
    }
  }

  // ---------- Simulacro ----------
  function simulacroBackBtn(label) {
    const b = el('button', { type: 'button', class: 'nc-btn', style: 'margin-bottom:12px;background:#fff;color:#333;border:1px solid #ccc' }, '\u2190 ' + (label || 'Volver'));
    b.onclick = () => { setActiveSub('simulacro'); renderSimulacroHub(); };
    return b;
  }

  function getAulasFisicasFromList(aulas) {
    const codigoAulaFisicaSet = new Set(['K', 'L', 'M', 'N', 'X', 'P', 'Z', 'S']);
    return (aulas || []).filter(a => {
      const raw = String(a.nombre || '').trim();
      const code = raw.split('->')[0].trim().toUpperCase();
      return codigoAulaFisicaSet.has(code);
    });
  }

  function aulaFisicaCode(aula) {
    return String(aula && aula.nombre ? aula.nombre : '').trim().split('->')[0].trim().toUpperCase();
  }

  function simAlumnoRowHtml(a, mode) {
    const nombre = escapeHtml(((a.nombres || '') + ' ' + (a.apellidos || '')).trim());
    const ci = escapeHtml(a.ci || '-');
    let action;
    if (mode === 'selected') {
      action = '<button type="button" class="nc-btn" data-sim-quitar="' + a.id + '" style="padding:2px 8px;font-size:12px;background:#fff;color:#c62828;border:1px solid #ef9a9a">Quitar</button>';
    } else if (mode === 'inList') {
      action = '<span style="font-size:12px;color:#999">En lista</span>';
    } else {
      action = '<button type="button" class="nc-btn" data-sim-add="' + a.id + '" style="padding:4px 10px;font-size:12px">Agregar</button>';
    }
    const border = mode === 'selected' ? '#e8f5e9' : '#eee';
    return '<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid ' + border + '">'
      + '<div><div style="font-weight:500">' + nombre + '</div><div style="font-size:12px;color:#666">CI: ' + ci + '</div></div>'
      + action + '</div>';
  }

  async function renderSimulacroHub() {
    if (!contentEl) return;
    contentEl.innerHTML = '<div class="nc-card"><h3 style="margin:0 0 8px">Simulacro</h3>'
      + '<p style="margin:0 0 20px;opacity:.85;font-size:14px">Cre\u00e1 listas personalizadas para ex\u00e1menes y llam\u00e1 asistencia con esas listas.</p>'
      + '<div class="nc-row" style="gap:12px;flex-wrap:wrap">'
      + '<button type="button" class="nc-btn" id="nc-sim-go-crear" style="min-width:200px">Crear simulacro</button>'
      + '<button type="button" class="nc-btn" id="nc-sim-go-lista" style="min-width:200px;background:#1565c0">Lista simulacro</button>'
      + '</div></div>';
    contentEl.querySelector('#nc-sim-go-crear').onclick = () => { setActiveSub('simulacro-crear'); renderSimulacroCrear(); };
    contentEl.querySelector('#nc-sim-go-lista').onclick = () => { setActiveSub('simulacro-lista'); renderSimulacroLista(); };
  }

  async function renderSimulacroCrear() {
    if (!contentEl) return;
    contentEl.innerHTML = '<div class="nc-card"><p>Cargando...</p></div>';
    let config = { materias_examen: [], docente_examen_id: null, docente_examen_nombre: 'Examen' };
    let aulas = [];
    try {
      const [cfgRes, aulasRes] = await Promise.all([api('/asistencia/simulacros/config'), api('/aulas')]);
      config = cfgRes || config;
      aulas = Array.isArray(aulasRes) ? aulasRes : (aulasRes && aulasRes.items ? aulasRes.items : []);
    } catch (e) {
      contentEl.innerHTML = '<div class="nc-card"><p>Error: ' + escapeHtml(e.message) + '</p></div>';
      return;
    }
    const materias = Array.isArray(config.materias_examen) ? config.materias_examen : [];
    const aulasFisicas = getAulasFisicasFromList(aulas);
    const selected = new Map();
    contentEl.innerHTML = '';
    contentEl.appendChild(simulacroBackBtn('Simulacro'));
    const card = el('div', { class: 'nc-card' });
    contentEl.appendChild(card);
    card.innerHTML = '<h3 style="margin:0 0 14px">Crear simulacro</h3>'
      + '<div class="nc-row nc-row-wrap" style="gap:12px;margin-bottom:16px">'
      + '<div class="nc-field" style="min-width:260px;flex:1"><label>Tipo de examen</label>'
      + '<select id="nc-sim-materia"><option value="">Seleccione examen</option>'
      + materias.map(m => '<option value="' + m.id + '">' + escapeHtml(m.nombre) + '</option>').join('')
      + '</select></div>'
      + '<div class="nc-field" style="min-width:200px"><label>Docente</label>'
      + '<input type="text" id="nc-sim-docente" value="' + escapeHtml(config.docente_examen_nombre || 'Examen') + '" readonly style="background:#f5f5f5;cursor:not-allowed" /></div>'
      + '<div class="nc-field" style="min-width:140px"><label>Aula f\u00edsica</label>'
      + '<select id="nc-sim-aula"><option value="">Seleccione aula</option>'
      + aulasFisicas.map(a => '<option value="' + a.id + '">' + escapeHtml(aulaFisicaCode(a)) + '</option>').join('')
      + '</select></div></div>'
      + '<p id="nc-sim-crear-msg" style="font-size:13px;color:#666;margin:0 0 12px">Busque alumnos y agr\u00e9guelos a la lista personalizada. El docente para el registro es <strong>Examen</strong> (fijo).</p>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">'
      + '<div><label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Buscar alumnos</label>'
      + '<input type="text" id="nc-sim-buscar" placeholder="CI, nombre o apellido (m\u00edn. 2 caracteres)" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;margin-bottom:8px" />'
      + '<div id="nc-sim-resultados" style="max-height:320px;overflow:auto;border:1px solid #eee;border-radius:6px;padding:4px 8px;min-height:80px"></div></div>'
      + '<div><label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px">Lista personalizada (<span id="nc-sim-count">0</span>)</label>'
      + '<div id="nc-sim-seleccionados" style="max-height:360px;overflow:auto;border:1px solid #c8e6c9;border-radius:6px;padding:4px 8px;background:#f9fff9;min-height:80px"></div></div></div>'
      + '<div class="nc-row" style="margin-top:16px;gap:8px"><button type="button" class="nc-btn" id="nc-sim-crear-btn">Crear simulacro</button></div>';
    if (!config.docente_examen_id) {
      const msgEl = card.querySelector('#nc-sim-crear-msg');
      if (msgEl) msgEl.innerHTML = '<span style="color:#b00">No se encontr\u00f3 el docente "Examen". Contacte al administrador.</span>';
    }
    const resultadosEl = card.querySelector('#nc-sim-resultados');
    const seleccionadosEl = card.querySelector('#nc-sim-seleccionados');
    const countEl = card.querySelector('#nc-sim-count');
    let searchTimer = null;
    function renderSeleccionados() {
      const list = Array.from(selected.values());
      if (countEl) countEl.textContent = String(list.length);
      if (!seleccionadosEl) return;
      if (!list.length) {
        seleccionadosEl.innerHTML = '<p style="margin:8px 0;color:#888;font-size:13px">Sin alumnos en la lista.</p>';
        return;
      }
      list.sort((a, b) => String(a.apellidos || '').localeCompare(String(b.apellidos || ''), 'es'));
      seleccionadosEl.innerHTML = list.map(a => simAlumnoRowHtml(a, 'selected')).join('');
      seleccionadosEl.querySelectorAll('[data-sim-quitar]').forEach(btn => {
        btn.onclick = () => { selected.delete(Number(btn.getAttribute('data-sim-quitar'))); renderSeleccionados(); };
      });
    }
    async function buscarAlumnos(term) {
      const q = (term || '').trim();
      if (!resultadosEl) return;
      if (q.length < 2) {
        resultadosEl.innerHTML = '<p style="margin:8px 0;color:#888;font-size:13px">Escriba al menos 2 caracteres.</p>';
        return;
      }
      resultadosEl.innerHTML = '<p style="margin:8px 0;color:#888">Buscando...</p>';
      try {
        const res = await api('/alumnos?search=' + encodeURIComponent(q) + '&order_by=apellidos&order=ASC');
        const items = (res && res.items) ? res.items : (Array.isArray(res) ? res : []);
        if (!items.length) {
          resultadosEl.innerHTML = '<p style="margin:8px 0;color:#888">Sin resultados.</p>';
          return;
        }
        resultadosEl.innerHTML = items.slice(0, 50).map(a => simAlumnoRowHtml(a, selected.has(Number(a.id)) ? 'inList' : 'search')).join('');
        resultadosEl.querySelectorAll('[data-sim-add]').forEach(btn => {
          btn.onclick = () => {
            const id = Number(btn.getAttribute('data-sim-add'));
            const alumno = items.find(x => Number(x.id) === id);
            if (alumno) selected.set(id, alumno);
            renderSeleccionados();
            buscarAlumnos(card.querySelector('#nc-sim-buscar').value);
          };
        });
      } catch (e) {
        resultadosEl.innerHTML = '<p style="color:#b00">' + escapeHtml(e.message) + '</p>';
      }
    }
    const buscarInp = card.querySelector('#nc-sim-buscar');
    if (buscarInp) {
      buscarInp.addEventListener('input', () => {
        if (searchTimer) clearTimeout(searchTimer);
        searchTimer = setTimeout(() => buscarAlumnos(buscarInp.value), 350);
      });
    }
    renderSeleccionados();
    const btnCrear = card.querySelector('#nc-sim-crear-btn');
    if (btnCrear) {
      btnCrear.addEventListener('click', withButtonLock(btnCrear, async () => {
        const materiaId = Number((card.querySelector('#nc-sim-materia') || {}).value || 0);
        const aulaId = Number((card.querySelector('#nc-sim-aula') || {}).value || 0);
        const alumnoIds = Array.from(selected.keys());
        if (!materiaId) { alert('Seleccione el tipo de examen.'); return; }
        if (!aulaId) { alert('Seleccione el aula f\u00edsica.'); return; }
        if (!alumnoIds.length) { alert('Agregue al menos un alumno a la lista.'); return; }
        if (!config.docente_examen_id) { alert('No est\u00e1 configurado el docente Examen.'); return; }
        if (!confirm('\u00bfCrear el simulacro con ' + alumnoIds.length + ' alumno(s)?')) return;
        try {
          const res = await api('/asistencia/simulacros', { method: 'POST', body: JSON.stringify({ materia_id: materiaId, aula_id: aulaId, alumno_ids: alumnoIds }) });
          alert('Simulacro creado: ' + (res.nombre || 'OK') + ' (' + (res.total_alumnos || alumnoIds.length) + ' alumnos).');
          setActiveSub('simulacro-lista');
          renderSimulacroLista();
        } catch (e) { alert('Error: ' + e.message); }
      }, { loadingText: 'Creando...' }));
    }
  }

  async function renderSimulacroLista() {
    if (!contentEl) return;
    contentEl.innerHTML = '<div class="nc-card"><p>Cargando...</p></div>';
    let simulacros = [];
    try {
      const res = await api('/asistencia/simulacros');
      simulacros = (res && res.items) ? res.items : [];
    } catch (e) {
      contentEl.innerHTML = '<div class="nc-card"><p>Error: ' + escapeHtml(e.message) + '</p></div>';
      return;
    }
    const today = localDateYMD(new Date());
    const simState = { simulacro_id: '', fecha: today, alumnos: [], items: {}, sortBy: 'apellido', simulacro: null };
    contentEl.innerHTML = '';
    contentEl.appendChild(simulacroBackBtn('Simulacro'));
    const card = el('div', { class: 'nc-card' });
    card.className = 'nc-card';
    contentEl.appendChild(card);
    card.innerHTML = '<h3 style="margin:0 0 14px">Lista simulacro</h3>'
      + '<div class="nc-row nc-row-wrap" style="gap:12px;margin-bottom:16px;align-items:flex-end">'
      + '<div class="nc-field" style="min-width:280px;flex:1"><label>Simulacro</label>'
      + '<select id="nc-sim-lista-sel"><option value="">Seleccione un simulacro</option>'
      + simulacros.map(s => '<option value="' + s.id + '">' + escapeHtml(s.nombre) + ' (' + (s.total_alumnos || 0) + ' alumnos)</option>').join('')
      + '</select></div>'
      + '<div class="nc-field"><label>Fecha</label><input type="date" id="nc-sim-lista-fecha" value="' + today + '" /></div></div>'
      + '<div id="nc-sim-lista-meta" style="display:none;margin-bottom:12px;padding:12px;background:#f5f7ff;border-radius:8px;font-size:14px"></div>'
      + '<div id="nc-sim-lista-wrap" style="display:none">'
      + '<div class="nc-row" style="margin-bottom:12px;flex-wrap:wrap;gap:8px;align-items:center">'
      + '<input type="text" id="nc-sim-lista-buscar" placeholder="Buscar en la lista" style="max-width:280px;padding:8px" />'
      + '<select id="nc-sim-lista-orden" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px">'
      + '<option value="apellido" selected>Ordenar por apellido</option><option value="nombre">Ordenar por nombre</option></select>'
      + '<span id="nc-sim-lista-contador" style="font-weight:600;margin-left:auto"></span></div>'
      + '<div style="overflow:auto"><table class="nc-table"><thead><tr><th>Nombre</th><th>Apellido</th><th>Asisti\u00f3</th><th>Observaci\u00f3n</th></tr></thead>'
      + '<tbody id="nc-sim-lista-tbody"></tbody></table></div>'
      + '<div class="nc-row" style="margin-top:16px"><button type="button" class="nc-btn" id="nc-sim-lista-guardar">Guardar asistencia (llamar lista)</button></div></div>'
      + '<p id="nc-sim-lista-msg" style="opacity:.8">Seleccione un simulacro para cargar la lista y llamar asistencia.</p>';
    const sel = card.querySelector('#nc-sim-lista-sel');
    const meta = card.querySelector('#nc-sim-lista-meta');
    const wrap = card.querySelector('#nc-sim-lista-wrap');
    const msg = card.querySelector('#nc-sim-lista-msg');
    const tbody = card.querySelector('#nc-sim-lista-tbody');
    const fechaInp = card.querySelector('#nc-sim-lista-fecha');
    function renderSimListaTable() {
      if (!tbody) return;
      const search = ((card.querySelector('#nc-sim-lista-buscar') || {}).value || '').toLowerCase().trim();
      let filtered = simState.alumnos.slice();
      if (search) {
        filtered = filtered.filter(a => ((a.nombres || '') + ' ' + (a.apellidos || '') + ' ' + (a.ci || '')).toLowerCase().indexOf(search) !== -1);
      }
      const sortBy = simState.sortBy || 'apellido';
      filtered.sort((a, b) => sortBy === 'nombre'
        ? String(a.nombres || '').localeCompare(String(b.nombres || ''), 'es')
        : String(a.apellidos || '').localeCompare(String(b.apellidos || ''), 'es'));
      const cont = card.querySelector('#nc-sim-lista-contador');
      if (cont) cont.textContent = filtered.filter(a => (simState.items[a.id] || {}).asistio !== false).length + '/' + filtered.length + ' presentes';
      tbody.innerHTML = filtered.map(a => {
        const st = simState.items[a.id] || { asistio: false, observacion: '' };
        const activo = st.asistio !== false;
        return '<tr data-alumno-id="' + a.id + '"><td>' + escapeHtml(a.nombres || '') + '</td><td>' + escapeHtml(a.apellidos || '') + '</td>'
          + '<td class="nc-asis-acciones"><button type="button" class="nc-asis-btn-asistio ' + (activo ? 'activo' : '') + '" data-toggle-asistio="' + a.id + '" title="Marcar asistencia">\u2713</button></td>'
          + '<td><input type="text" data-obs="' + a.id + '" value="' + escapeHtml(st.observacion || '') + '" style="width:100%;padding:4px 8px;border:1px solid #ddd;border-radius:4px" placeholder="Observaci\u00f3n" /></td></tr>';
      }).join('');
      tbody.querySelectorAll('[data-toggle-asistio]').forEach(btn => {
        btn.onclick = () => {
          const aid = Number(btn.getAttribute('data-toggle-asistio'));
          if (!simState.items[aid]) simState.items[aid] = { asistio: false, observacion: '' };
          simState.items[aid].asistio = !simState.items[aid].asistio;
          renderSimListaTable();
        };
      });
      tbody.querySelectorAll('[data-obs]').forEach(inp => {
        inp.onchange = inp.oninput = () => {
          const aid = Number(inp.getAttribute('data-obs'));
          if (!simState.items[aid]) simState.items[aid] = { asistio: false, observacion: '' };
          simState.items[aid].observacion = inp.value.trim();
        };
      });
    }
    async function loadSimulacro(id) {
      if (!id) {
        if (wrap) wrap.style.display = 'none';
        if (meta) meta.style.display = 'none';
        if (msg) { msg.style.display = 'block'; msg.textContent = 'Seleccione un simulacro para cargar la lista y llamar asistencia.'; }
        return;
      }
      if (msg) msg.style.display = 'none';
      try {
        const data = await api('/asistencia/simulacros/' + encodeURIComponent(id));
        simState.simulacro = data;
        simState.simulacro_id = id;
        simState.alumnos = Array.isArray(data.alumnos) ? data.alumnos : [];
        simState.items = {};
        simState.alumnos.forEach(a => { simState.items[a.id] = { asistio: false, observacion: '' }; });
        if (meta) {
          meta.style.display = 'block';
          meta.innerHTML = '<strong>' + escapeHtml(data.nombre || '') + '</strong><br/><span style="color:#555">Examen: '
            + escapeHtml(data.materia_nombre || '') + ' \u00b7 Aula: ' + escapeHtml(aulaFisicaCode({ nombre: data.aula_nombre }))
            + ' \u00b7 Docente: ' + escapeHtml(data.docente_nombre || 'Examen') + '</span>';
        }
        if (wrap) wrap.style.display = 'block';
        renderSimListaTable();
      } catch (e) {
        if (msg) { msg.style.display = 'block'; msg.textContent = 'Error: ' + e.message; }
        if (wrap) wrap.style.display = 'none';
      }
    }
    if (sel) sel.addEventListener('change', () => loadSimulacro(sel.value));
    if (fechaInp) fechaInp.addEventListener('change', () => { simState.fecha = fechaInp.value; });
    const buscarLista = card.querySelector('#nc-sim-lista-buscar');
    if (buscarLista) buscarLista.addEventListener('input', renderSimListaTable);
    const ordenLista = card.querySelector('#nc-sim-lista-orden');
    if (ordenLista) ordenLista.addEventListener('change', () => {
      simState.sortBy = ordenLista.value === 'nombre' ? 'nombre' : 'apellido';
      renderSimListaTable();
    });
    const btnGuardar = card.querySelector('#nc-sim-lista-guardar');
    if (btnGuardar) {
      btnGuardar.addEventListener('click', withButtonLock(btnGuardar, async () => {
        const sim = simState.simulacro;
        if (!sim || !simState.alumnos.length) { alert('Seleccione un simulacro con alumnos.'); return; }
        const fecha = (fechaInp && fechaInp.value) ? fechaInp.value : simState.fecha;
        if (!fecha) { alert('Seleccione la fecha.'); return; }
        if (!confirm('\u00bfGuardar la asistencia de este simulacro?')) return;
        const items = simState.alumnos.map(a => ({
          alumno_id: a.id,
          asistio: (simState.items[a.id] || {}).asistio !== false ? 1 : 0,
          observacion: (simState.items[a.id] || {}).observacion || '',
        }));
        try {
          await api('/asistencia/sesiones', {
            method: 'POST',
            body: JSON.stringify({
              fecha,
              materia_id: Number(sim.materia_id),
              aula_id: Number(sim.aula_id),
              docente_encargado_id: Number(sim.docente_encargado_id),
              simulacro_id: Number(sim.id || simState.simulacro_id || 0),
              items,
            }),
          });
          alert('Asistencia guardada correctamente.');
          simState.alumnos.forEach(a => { simState.items[a.id] = { asistio: false, observacion: '' }; });
          renderSimListaTable();
        } catch (e) { alert('Error al guardar: ' + e.message); }
      }, { loadingText: 'Guardando...' }));
    }
    if (!simulacros.length && msg) msg.textContent = 'No hay simulacros creados. Us\u00e1 "Crear simulacro" primero.';
  }


  window.NC_ModalRestoreHandlers = Object.assign(window.NC_ModalRestoreHandlers || {}, {
    editar_sesion: async (m) => {
      if (m && m.sesionId) openEditarSesionModal(Number(m.sesionId));
    },
    alumno_asistencia: async (m) => {
      if (!m || !m.alumnoId) return;
      const list = (m.listIds && m.listIds.length)
        ? m.listIds.map(id => ({ id: Number(id) }))
        : [];
      openModalAlumno(Number(m.alumnoId), !!m.editMode, list);
    },
  });

  window.NC_Asistencia = { render, openEditarSesionModal, openModalAlumno };
})();