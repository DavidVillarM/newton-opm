(async function () {
  const root = document.getElementById('conducta-root');
  if (!root) return;

  // Soporta apiUrl relativo o absoluto
  const API = (String(NC_APP.apiUrl || '').startsWith('http'))
    ? String(NC_APP.apiUrl).replace(/\/$/, '')
    : (window.location.origin + String(NC_APP.apiUrl || '')).replace(/\/$/, '');

  // WP REST root (para subir fotos a la Media Library)
  const WP_API = window.location.origin + '/wp-json';

  async function api(path, opts = {}) {
    // Cache-buster para evitar respuestas viejas (caches de hosting/CDN)
    const method = (opts.method || 'GET').toUpperCase();
    let url = `${API}${path}`;
    if (method === 'GET') {
      url += (url.includes('?') ? '&' : '?') + `nc_ts=${Date.now()}`;
    }
    const headers = { ...(opts.headers || {}) };
    // Solo setear Content-Type cuando corresponde (no en GET ni en FormData)
    if (!('Content-Type' in headers) && opts.body && !(opts.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
    }
    if (NC_APP && NC_APP.nonce) {
      headers['X-WP-Nonce'] = NC_APP.nonce;
    }

    const res = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...opts,
      headers
    });

    const contentType = res.headers.get('content-type') || '';
    const data = contentType.includes('application/json')
      ? await res.json().catch(() => ({}))
      : await res.text();

    if (!res.ok) {
      const msg = (data && data.message) ? data.message : (typeof data === 'string' ? data : res.statusText);
      throw new Error(msg);
    }
    return data;
  }

  /**
   * Evita doble envío: deshabilita el botón mientras se ejecuta la acción asíncrona.
   * @param {HTMLButtonElement} button - Botón a bloquear
   * @param {() => Promise<void>} asyncFn - Función async a ejecutar (p. ej. guardar, buscar)
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

  /** Grupo Nu / N (Ingeniería): mismo criterio que en backend (token nu o n). */
  function isNuGrupoFromAulaSelect(selectEl) {
    if (!selectEl || !selectEl.value) return false;
    const opt = selectEl.options[selectEl.selectedIndex];
    const label = (opt && opt.textContent) ? String(opt.textContent) : '';
    const s = label
      .toLowerCase()
      .replace(/[áéíóúñ]/g, (c) => ({ á: 'a', é: 'e', í: 'i', ó: 'o', ú: 'u', ñ: 'n' }[c] || c));
    const tokens = s.split(/[^a-z0-9]+/).filter(Boolean);
    return tokens.includes('nu') || tokens.includes('n');
  }

    // ==================== SISTEMA DE PAGINACIÓN ====================
  class Paginator {
    constructor(options = {}) {
      this.itemsPerPage = options.itemsPerPage || 20;
      this.currentPage = 1;
      this.items = [];
      this.filteredItems = [];
      this.onPageChange = options.onPageChange || (() => {});
    }

    setItems(items) {
      this.items = items;
      this.filteredItems = items;
      this.currentPage = 1;
    }

    setFilteredItems(items) {
      this.filteredItems = items;
      this.currentPage = 1;
    }

    getTotalPages() {
      return Math.ceil(this.filteredItems.length / this.itemsPerPage);
    }

    getCurrentPageItems() {
      const start = (this.currentPage - 1) * this.itemsPerPage;
      const end = start + this.itemsPerPage;
      return this.filteredItems.slice(start, end);
    }

    goToPage(page) {
      const totalPages = this.getTotalPages();
      if (page < 1) page = 1;
      if (page > totalPages) page = totalPages;
      this.currentPage = page;
      this.onPageChange(page);
    }

    nextPage() {
      this.goToPage(this.currentPage + 1);
    }

    prevPage() {
      this.goToPage(this.currentPage - 1);
    }

    getPageInfo() {
      const totalPages = this.getTotalPages();
      const start = (this.currentPage - 1) * this.itemsPerPage + 1;
      const end = Math.min(start + this.itemsPerPage - 1, this.filteredItems.length);
      return {
        currentPage: this.currentPage,
        totalPages,
        totalItems: this.filteredItems.length,
        start,
        end,
        hasNext: this.currentPage < totalPages,
        hasPrev: this.currentPage > 1
      };
    }

    renderControls(containerId) {
      const container = document.getElementById(containerId);
      if (!container) return;

      const info = this.getPageInfo();
      
      if (info.totalPages <= 1) {
        container.innerHTML = '';
        return;
      }

      container.innerHTML = `
        <div class="nc-pagination">
          <div class="nc-pagination-info">
            Mostrando ${info.start} - ${info.end} de ${info.totalItems} registros
          </div>
          <div class="nc-pagination-controls">
            <button class="nc-btn secondary small" id="${containerId}_first" ${!info.hasPrev ? 'disabled' : ''}>
              « Primera
            </button>
            <button class="nc-btn secondary small" id="${containerId}_prev" ${!info.hasPrev ? 'disabled' : ''}>
              ‹ Anterior
            </button>
            <span class="nc-pagination-page">
              Página ${info.currentPage} de ${info.totalPages}
            </span>
            <button class="nc-btn secondary small" id="${containerId}_next" ${!info.hasNext ? 'disabled' : ''}>
              Siguiente ›
            </button>
            <button class="nc-btn secondary small" id="${containerId}_last" ${!info.hasNext ? 'disabled' : ''}>
              Última »
            </button>
          </div>
          <div class="nc-pagination-jump">
            <label>Ir a página:</label>
            <input type="number" id="${containerId}_jump" min="1" max="${info.totalPages}" value="${info.currentPage}" style="width:60px;padding:6px">
            <button class="nc-btn secondary small" id="${containerId}_go">Ir</button>
          </div>
        </div>
      `;

      const btnFirst = document.getElementById(`${containerId}_first`);
      const btnPrev = document.getElementById(`${containerId}_prev`);
      const btnNext = document.getElementById(`${containerId}_next`);
      const btnLast = document.getElementById(`${containerId}_last`);
      const btnGo = document.getElementById(`${containerId}_go`);
      const inputJump = document.getElementById(`${containerId}_jump`);

      if (btnFirst) btnFirst.onclick = () => this.goToPage(1);
      if (btnPrev) btnPrev.onclick = () => this.prevPage();
      if (btnNext) btnNext.onclick = () => this.nextPage();
      if (btnLast) btnLast.onclick = () => this.goToPage(info.totalPages);
      if (btnGo) btnGo.onclick = () => {
        const page = parseInt(inputJump.value);
        if (!isNaN(page)) this.goToPage(page);
      };
      if (inputJump) {
        inputJump.onkeypress = (e) => {
          if (e.key === 'Enter') {
            const page = parseInt(inputJump.value);
            if (!isNaN(page)) this.goToPage(page);
          }
        };
      }
    }
  }
  
  
    // ==================== SISTEMA DE SELECCIÓN MÚLTIPLE ====================
  class BulkSelector {
    constructor(options = {}) {
      this.selectedIds = new Set();
      this.onSelectionChange = options.onSelectionChange || (() => {});
    }

    toggleItem(id) {
      if (this.selectedIds.has(id)) {
        this.selectedIds.delete(id);
      } else {
        this.selectedIds.add(id);
      }
      this.onSelectionChange(this.getSelectedIds());
    }

    selectAll(ids) {
      ids.forEach(id => this.selectedIds.add(id));
      this.onSelectionChange(this.getSelectedIds());
    }

    deselectAll() {
      this.selectedIds.clear();
      this.onSelectionChange(this.getSelectedIds());
    }

    toggleAll(ids) {
      // Verificar si todos los IDs de la página actual están seleccionados
      const allCurrentSelected = ids.every(id => this.selectedIds.has(id));
      
      if (allCurrentSelected) {
        // Si todos están seleccionados, deseleccionar solo los de esta página
        ids.forEach(id => this.selectedIds.delete(id));
      } else {
        // Si no todos están seleccionados, seleccionar solo los de esta página
        // Primero deseleccionar todos los que no están en esta página
        const idsSet = new Set(ids);
        const toRemove = [];
        this.selectedIds.forEach(id => {
          if (!idsSet.has(id)) {
            toRemove.push(id);
          }
        });
        toRemove.forEach(id => this.selectedIds.delete(id));
        // Luego seleccionar todos los de esta página
        ids.forEach(id => this.selectedIds.add(id));
      }
      this.onSelectionChange(this.getSelectedIds());
    }

    isSelected(id) {
      return this.selectedIds.has(id);
    }

    isAllSelected(ids) {
      return ids.every(id => this.selectedIds.has(id));
    }

    getSelectedIds() {
      return Array.from(this.selectedIds);
    }

    getCount() {
      return this.selectedIds.size;
    }

    clear() {
      this.selectedIds.clear();
      this.onSelectionChange([]);
    }

    renderActionBar(containerId, actions = []) {
      const container = document.getElementById(containerId);
      if (!container) return;

      const count = this.getCount();
      
      if (count === 0) {
        container.innerHTML = '';
        container.style.display = 'none';
        return;
      }

      container.style.display = 'block';
      container.innerHTML = `
        <div class="nc-bulk-actions">
          <div class="nc-bulk-info">
            <strong>${count}</strong> ${count === 1 ? 'elemento seleccionado' : 'elementos seleccionados'}
          </div>
          <div class="nc-bulk-buttons" id="${containerId}_buttons"></div>
          <button class="nc-btn secondary small" id="${containerId}_deselect">Deseleccionar todo</button>
        </div>
      `;

      const buttonsContainer = document.getElementById(`${containerId}_buttons`);
      actions.forEach(action => {
        const btn = document.createElement('button');
        btn.className = action.className || 'nc-btn small';
        btn.textContent = action.label;
        btn.onclick = () => action.onClick(this.getSelectedIds());
        buttonsContainer.appendChild(btn);
      });

      const btnDeselect = document.getElementById(`${containerId}_deselect`);
      if (btnDeselect) {
        btnDeselect.onclick = () => this.deselectAll();
      }
    }
  }

  // ==================== SISTEMA DE PERMISOS ====================
  class UserPermissions {
    constructor() {
      this.permissions = null;
      this.loaded = false;
    }

    async load() {
      if (this.loaded) return this.permissions;
      
      try {
        this.permissions = await api('/user/permissions');
        this.loaded = true;
        return this.permissions;
      } catch (error) {
        console.error('Error loading permissions:', error);
        this.permissions = {
          is_admin: false,
          can_view_evaluator: false,
          can_manage_students: false,
          can_manage_courses: false,
          can_manage_aulas: false,
          can_manage_facultades: false,
          can_view_reports: false
        };
        this.loaded = true;
        return this.permissions;
      }
    }

    isAdmin() {
      return this.permissions?.is_admin || false;
    }

    canViewEvaluator() {
      return this.permissions?.can_view_evaluator || false;
    }

    canManageStudents() {
      return this.permissions?.can_manage_students || false;
    }

    canManageCourses() {
      return this.permissions?.can_manage_courses || false;
    }

    canManageAulas() {
      return this.permissions?.can_manage_aulas || false;
    }

    canManageFacultades() {
      return this.permissions?.can_manage_facultades || false;
    }

    canViewReports() {
      return this.permissions?.can_view_reports || false;
    }
  }

  // Función auxiliar para eliminación masiva
  async function bulkDeleteAlumnos(ids) {
    if (!ids || ids.length === 0) {
      throw new Error('No hay alumnos seleccionados');
    }

    if (!confirm(`¿Estás seguro de que deseas eliminar ${ids.length} alumno(s)? Esta acción no se puede deshacer.`)) {
      return null;
    }

    try {
      const result = await api('/alumnos/bulk-delete', {
        method: 'POST',
        body: JSON.stringify({ ids })
      });
      return result;
    } catch (error) {
      throw new Error('Error al eliminar alumnos: ' + error.message);
    }
  }

  // Inicializar sistema de permisos
  const userPermissions = new UserPermissions();

  // Subir foto a Media Library (requiere capacidad upload_files)
  async function uploadToMediaLibrary(file) {
    const fd = new FormData();
    fd.append('file', file, file.name);
    const upHeaders = {
      ...(NC_APP && NC_APP.nonce ? { 'X-WP-Nonce': NC_APP.nonce } : {}),
      // Opcional pero ayuda a WP a nombrar el archivo
      'Content-Disposition': `attachment; filename="${file.name}"`
    };

    const res = await fetch(`${WP_API}/wp/v2/media?nc_ts=${Date.now()}`, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: upHeaders,
      body: fd
    });
    const ct = res.headers.get('content-type') || '';
    const data = ct.includes('application/json') ? await res.json().catch(() => ({})) : await res.text();
    if (!res.ok) {
      const msg = (data && data.message) ? data.message : (typeof data === 'string' ? data : res.statusText);
      throw new Error(msg);
    }
    if (!data || typeof data !== 'object') return '';
    const url = data.source_url || data.url || (data.guid && data.guid.rendered) || data.link || '';
    return url ? String(url).trim() : '';
  }
  
  /**
     * Convierte HEIC/HEIF a JPEG en el navegador (fotos de iPhone). Si no es HEIC, devuelve el mismo archivo.
     */
    async function ensureFotoFileForUpload(file) {
      if (!file) return file;
      const isHeic = file.type === 'image/heic' || file.type === 'image/heif' ||
        /\.(heic|heif)$/i.test(file.name || '');
      if (!isHeic || typeof window.heic2any !== 'function') return file;
      try {
        const result = await window.heic2any({ blob: file, toType: 'image/jpeg', quality: 0.92 });
        const blob = Array.isArray(result) ? result[0] : result;
        const name = (file.name || 'foto.jpg').replace(/\.(heic|heif)$/i, '.jpg');
        return new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() });
      } catch (e) {
        console.warn('Conversión HEIC fallida:', e);
        return file;
      }
    }

    /**
     * Sube una foto de alumno (acepta JPG, PNG, GIF, WEBP o HEIC; HEIC se convierte a JPEG antes de subir).
     */
    async function uploadAlumnoFoto(alumnoId, file) {
      if (!alumnoId || !file) {
        throw new Error('ID de alumno y archivo son requeridos');
      }
      file = await ensureFotoFileForUpload(file);
      const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
      if (!allowedTypes.includes(file.type)) {
        throw new Error('Tipo de archivo no permitido. Usa JPG, PNG, GIF, WEBP o HEIC.');
      }
    
      const maxSize = 5 * 1024 * 1024; // 5MB
      if (file.size > maxSize) {
        throw new Error('El archivo es demasiado grande. Máximo 5MB.');
      }
    
      // Crear FormData con el campo correcto
      const formData = new FormData();
      formData.append('foto', file); // ⚠️ IMPORTANTE: campo 'foto', no 'file'
    
      const url = `${API}/alumnos/${alumnoId}/foto`;
      
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-WP-Nonce': NC_APP.nonce
          // NO incluir Content-Type, el browser lo configura automáticamente
        },
        body: formData
      });
    
      const contentType = res.headers.get('content-type') || '';
      const data = contentType.includes('application/json')
        ? await res.json().catch(() => ({}))
        : await res.text();
    
      if (!res.ok) {
        const msg = (data && data.message) 
          ? data.message 
          : (typeof data === 'string' ? data : res.statusText);
        throw new Error(msg);
      }
    
      // Retornar la URL de la foto
      return data.foto_url || '';
    }
    
    
    // Helpers UI
      let loadingOverlay = null;
      function ensureLoadingOverlay() {
        if (loadingOverlay) return;
        if (!document.getElementById('nc-loader-styles')) {
          const style = document.createElement('style');
          style.id = 'nc-loader-styles';
          style.textContent = `
            #conducta-root.nc-loading .nc-wrap { visibility: hidden; }
            .nc-loading-overlay { position: fixed; inset: 0; background: rgba(245,245,245,0.98); display: flex; align-items: center; justify-content: center; z-index: 99998; }
            .nc-loading-overlay .typewriter { --blue: #5C86FF; --blue-dark: #275EFE; --key: #fff; --paper: #EEF0FD; --text: #D3D4EC; --tool: #FBC56C; --duration: 3s; position: relative; animation: nc-bounce05 var(--duration) linear infinite; }
            .nc-loading-overlay .typewriter .slide { width: 92px; height: 20px; border-radius: 3px; margin-left: 14px; transform: translateX(14px); background: linear-gradient(var(--blue), var(--blue-dark)); animation: nc-slide05 var(--duration) ease infinite; }
            .nc-loading-overlay .typewriter .slide:before, .nc-loading-overlay .typewriter .slide:after, .nc-loading-overlay .typewriter .slide i:before { content: ""; position: absolute; background: var(--tool); }
            .nc-loading-overlay .typewriter .slide:before { width: 2px; height: 8px; top: 6px; left: 100%; }
            .nc-loading-overlay .typewriter .slide:after { left: 94px; top: 3px; height: 14px; width: 6px; border-radius: 3px; }
            .nc-loading-overlay .typewriter .slide i { display: block; position: absolute; right: 100%; width: 6px; height: 4px; top: 4px; background: var(--tool); }
            .nc-loading-overlay .typewriter .slide i:before { right: 100%; top: -2px; width: 4px; border-radius: 2px; height: 14px; }
            .nc-loading-overlay .typewriter .paper { position: absolute; left: 24px; top: -26px; width: 40px; height: 46px; border-radius: 5px; background: var(--paper); transform: translateY(46px); animation: nc-paper05 var(--duration) linear infinite; }
            .nc-loading-overlay .typewriter .paper:before { content: ""; position: absolute; left: 6px; right: 6px; top: 7px; border-radius: 2px; height: 4px; transform: scaleY(0.8); background: var(--text); box-shadow: 0 12px 0 var(--text), 0 24px 0 var(--text), 0 36px 0 var(--text); }
            .nc-loading-overlay .typewriter .keyboard { width: 120px; height: 56px; margin-top: -10px; z-index: 1; position: relative; }
            .nc-loading-overlay .typewriter .keyboard:before, .nc-loading-overlay .typewriter .keyboard:after { content: ""; position: absolute; }
            .nc-loading-overlay .typewriter .keyboard:before { top: 0; left: 0; right: 0; bottom: 0; border-radius: 7px; background: linear-gradient(135deg, var(--blue), var(--blue-dark)); transform: perspective(10px) rotateX(2deg); transform-origin: 50% 100%; }
            .nc-loading-overlay .typewriter .keyboard:after { left: 2px; top: 25px; width: 11px; height: 4px; border-radius: 2px; box-shadow: 15px 0 0 var(--key), 30px 0 0 var(--key), 45px 0 0 var(--key), 60px 0 0 var(--key), 75px 0 0 var(--key), 90px 0 0 var(--key), 22px 10px 0 var(--key), 37px 10px 0 var(--key), 52px 10px 0 var(--key), 60px 10px 0 var(--key), 68px 10px 0 var(--key), 83px 10px 0 var(--key); animation: nc-keyboard05 var(--duration) linear infinite; }
            @keyframes nc-bounce05 { 85%, 92%, 100% { transform: translateY(0); } 89% { transform: translateY(-4px); } 95% { transform: translateY(2px); } }
            @keyframes nc-slide05 { 5% { transform: translateX(14px); } 15%, 30% { transform: translateX(6px); } 40%, 55% { transform: translateX(0); } 65%, 70% { transform: translateX(-4px); } 80%, 89% { transform: translateX(-12px); } 100% { transform: translateX(14px); } }
            @keyframes nc-paper05 { 5% { transform: translateY(46px); } 20%, 30% { transform: translateY(34px); } 40%, 55% { transform: translateY(22px); } 65%, 70% { transform: translateY(10px); } 80%, 85% { transform: translateY(0); } 92%, 100% { transform: translateY(46px); } }
            @keyframes nc-keyboard05 { 5%, 12%, 21%, 30%, 39%, 48%, 57%, 66%, 75%, 84% { box-shadow: 15px 0 0 var(--key), 30px 0 0 var(--key), 45px 0 0 var(--key), 60px 0 0 var(--key), 75px 0 0 var(--key), 90px 0 0 var(--key), 22px 10px 0 var(--key), 37px 10px 0 var(--key), 52px 10px 0 var(--key), 60px 10px 0 var(--key), 68px 10px 0 var(--key), 83px 10px 0 var(--key); } 9% { box-shadow: 15px 2px 0 var(--key), 30px 0 0 var(--key), 45px 0 0 var(--key), 60px 0 0 var(--key), 75px 0 0 var(--key), 90px 0 0 var(--key), 22px 10px 0 var(--key), 37px 10px 0 var(--key), 52px 10px 0 var(--key), 60px 10px 0 var(--key), 68px 10px 0 var(--key), 83px 10px 0 var(--key); } 18% { box-shadow: 15px 0 0 var(--key), 30px 0 0 var(--key), 45px 0 0 var(--key), 60px 2px 0 var(--key), 75px 0 0 var(--key), 90px 0 0 var(--key), 22px 10px 0 var(--key), 37px 10px 0 var(--key), 52px 10px 0 var(--key), 60px 10px 0 var(--key), 68px 10px 0 var(--key), 83px 10px 0 var(--key); } 27% { box-shadow: 15px 0 0 var(--key), 30px 0 0 var(--key), 45px 0 0 var(--key), 60px 0 0 var(--key), 75px 0 0 var(--key), 90px 0 0 var(--key), 22px 12px 0 var(--key), 37px 10px 0 var(--key), 52px 10px 0 var(--key), 60px 10px 0 var(--key), 68px 10px 0 var(--key), 83px 10px 0 var(--key); } 36% { box-shadow: 15px 0 0 var(--key), 30px 0 0 var(--key), 45px 0 0 var(--key), 60px 0 0 var(--key), 75px 0 0 var(--key), 90px 0 0 var(--key), 22px 10px 0 var(--key), 37px 10px 0 var(--key), 52px 12px 0 var(--key), 60px 12px 0 var(--key), 68px 12px 0 var(--key), 83px 10px 0 var(--key); } 45% { box-shadow: 15px 0 0 var(--key), 30px 0 0 var(--key), 45px 0 0 var(--key), 60px 0 0 var(--key), 75px 0 0 var(--key), 90px 2px 0 var(--key), 22px 10px 0 var(--key), 37px 10px 0 var(--key), 52px 10px 0 var(--key), 60px 10px 0 var(--key), 68px 10px 0 var(--key), 83px 10px 0 var(--key); } 54% { box-shadow: 15px 0 0 var(--key), 30px 2px 0 var(--key), 45px 0 0 var(--key), 60px 0 0 var(--key), 75px 0 0 var(--key), 90px 0 0 var(--key), 22px 10px 0 var(--key), 37px 10px 0 var(--key), 52px 10px 0 var(--key), 60px 10px 0 var(--key), 68px 10px 0 var(--key), 83px 10px 0 var(--key); } 63% { box-shadow: 15px 0 0 var(--key), 30px 0 0 var(--key), 45px 0 0 var(--key), 60px 0 0 var(--key), 75px 0 0 var(--key), 90px 0 0 var(--key), 22px 10px 0 var(--key), 37px 10px 0 var(--key), 52px 10px 0 var(--key), 60px 10px 0 var(--key), 68px 10px 0 var(--key), 83px 12px 0 var(--key); } 72% { box-shadow: 15px 0 0 var(--key), 30px 0 0 var(--key), 45px 2px 0 var(--key), 60px 0 0 var(--key), 75px 0 0 var(--key), 90px 0 0 var(--key), 22px 10px 0 var(--key), 37px 10px 0 var(--key), 52px 10px 0 var(--key), 60px 10px 0 var(--key), 68px 10px 0 var(--key), 83px 10px 0 var(--key); } 81% { box-shadow: 15px 0 0 var(--key), 30px 0 0 var(--key), 45px 0 0 var(--key), 60px 0 0 var(--key), 75px 0 0 var(--key), 90px 0 0 var(--key), 22px 10px 0 var(--key), 37px 12px 0 var(--key), 52px 10px 0 var(--key), 60px 10px 0 var(--key), 68px 10px 0 var(--key), 83px 10px 0 var(--key); } }
          `;
          document.head.appendChild(style);
        }
        loadingOverlay = document.createElement('div');
        loadingOverlay.className = 'nc-loading-overlay';
        loadingOverlay.setAttribute('aria-hidden', 'true');
        loadingOverlay.innerHTML = '<div class="typewriter"><div class="slide"><i></i></div><div class="paper"></div><div class="keyboard"></div></div>';
        document.body.appendChild(loadingOverlay);
      }
      function setLoading(state) {
        if (state) {
          ensureLoadingOverlay();
          if (loadingOverlay) loadingOverlay.style.display = 'flex';
          root.classList.add('nc-loading');
        } else {
          if (loadingOverlay) loadingOverlay.style.display = 'none';
          root.classList.remove('nc-loading');
        }
      }
    
      function toast(msg, type = 'ok') {
        let t = document.getElementById('nc-toast');
        if (!t) {
          t = document.createElement('div');
          t.id = 'nc-toast';
          t.style.cssText = 'position:fixed;top:20px;right:20px;background:#2c3e50;color:#fff;padding:12px 16px;border-radius:6px;box-shadow:0 4px 6px rgba(0,0,0,0.3);z-index:9999;min-width:200px;transition:opacity 0.3s;';
          document.body.appendChild(t);
        }
        t.style.background = type === 'err' ? '#e74c3c' : '#27ae60';
        t.textContent = msg;
        t.style.opacity = '1';
        setTimeout(() => { t.style.opacity = '0'; }, 3000);
      }
    
      function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
      }
    
    /**
     * Abre modal para recortar imagen
     * @param {File} file - Archivo de imagen original
     * @param {Function} onCropped - Callback que recibe el archivo recortado
     */
    
    

  // Helpers UI
  function el(tag, attrs = {}, html = '') {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') n.className = v;
      else if (k === 'style') n.setAttribute('style', v);
      else n.setAttribute(k, v);
    }
    if (html) n.innerHTML = html;
    return n;
  }


  // Escape values intended for HTML attributes (src/href/value).
  // A previous build referenced `escapeAttr` but it was missing, causing runtime errors.
  function escapeAttr(s) {
    return escapeHtml(s).replaceAll('`', '&#096;');
  }

  // Normaliza URLs de imagen (acepta absoluta, //, o relativa /...)
  function normalizeFotoUrl(url) {
    const u = String(url || '').trim();
    if (!u) return '';
    if (u.startsWith('//')) return window.location.protocol + u;
    if (u.startsWith('/')) return window.location.origin + u;
    return u;
  }


  // Modal simple (sin dependencias)
  function openModal(title, bodyNode, actions = []) {
    const back = el('div', { class: 'nc-modal-backdrop', role: 'dialog', 'aria-modal': 'true' });
    const modal = el('div', { class: 'nc-modal' });
    const header = el('div', { class: 'nc-modal-h' }, `
      <strong>${escapeHtml(title)}</strong>
    `);
    const closeBtn = el('button', { class: 'nc-modal-x', 'aria-label': 'Cerrar' }, '×');
    header.appendChild(closeBtn);

    const body = el('div', { class: 'nc-modal-b' });
    body.appendChild(bodyNode);

    const footer = el('div', { class: 'nc-modal-f' });
    const modalApi = {
      close: () => {
        if (window.NC_AppState) window.NC_AppState.clearModal();
        back.remove();
      },
    };
    closeBtn.onclick = () => modalApi.close();
    actions.forEach((a) => {
      if (!a) return;
      // Accept either DOM Nodes or simple config objects.
      if (a instanceof Node) {
        footer.appendChild(a);
        return;
      }
      if (typeof a === 'object') {
        const baseClass = 'nc-btn';
        const additionalClass = a.className || '';
        const fullClass = additionalClass ? `${baseClass} ${additionalClass}` : baseClass;
        const btn = el('button', { class: fullClass }, a.label || 'OK');
        if (a.type) btn.type = a.type;
        if (a.disabled) btn.disabled = true;
        if (typeof a.onClick === 'function') {
          const fn = a.onClick;
          const opts = a.loadingText != null ? { loadingText: a.loadingText } : {};
          btn.onclick = withButtonLock(btn, () => fn(modalApi), opts);
        }
        footer.appendChild(btn);
      }
    });

    modal.appendChild(header);
    modal.appendChild(body);
    if (actions.length) modal.appendChild(footer);
    back.appendChild(modal);
    document.body.appendChild(back);
    back.addEventListener('click', (ev) => {
      if (ev.target === back) modalApi.close();
    });
    return modalApi;
  }

  function trackModal(payload) {
    if (window.NC_AppState && payload) window.NC_AppState.setModal(payload);
  }

  function alumnoDisplay(r) {
    const n = (r && (r.nombres || r.nombre)) || '';
    const a = (r && r.apellidos) || '';
    return `${n}${a ? ' ' + a : ''}`.trim();
  }

  async function openAlumnoEditModal(alumno, onSaved) {
    trackModal({ type: 'alumno_edit', alumnoId: Number(alumno.id) });
    await loadBase(true);

    setLoading(true);
    let data = alumno;
    try {
      data = await api('/alumnos/' + alumno.id);
    } catch (e) {
      toast('No se pudo cargar los datos del alumno.', 'err');
      setLoading(false);
      return;
    }
    setLoading(false);

    const body = el('div', {});
    body.innerHTML = `
      <div class="nc-row">
        <div class="nc-field"><label>Nombres *</label><input id="m_nombres" /></div>
        <div class="nc-field"><label>Apellidos *</label><input id="m_apellidos" /></div>
        <div class="nc-field"><label>CI *</label><input id="m_ci" /></div>
      </div>
      <div class="nc-row" style="margin-top:10px;align-items:flex-start">
        <div class="nc-field" style="flex:2">
          <label>Foto (opcional)</label>
          <input id="m_foto_url" placeholder="Pegá una URL o subí una foto" />
          <div style="margin-top:8px">
            <input id="m_foto_file" type="file" accept="image/*,.heic,.heif" />
          </div>
        </div>
        <div class="nc-field" style="flex:1;min-width:180px">
          <label>Vista previa</label>
          <div id="m_foto_box" class="nc-photo">
            <span class="nc-badge">SIN FOTO</span>
          </div>
        </div>
      </div>
      <div class="nc-row" style="margin-top:10px">
        <div class="nc-field"><label>Curso</label><select id="m_curso">${cursosOptions(true)}</select></div>
        <div class="nc-field"><label>Grupo</label><select id="m_aula">${aulasOptions(true)}</select></div>
        <div class="nc-field"><label>Subgrupo (opcional)</label><input id="m_subgrupo" placeholder="Ej: 1, 2, 3, A, B, C" /></div>
      </div>
      <div class="nc-row" style="margin-top:10px">
        <div class="nc-field"><label>Facultad</label><select id="m_facultad">${facultadesOptions(true)}</select></div>
        <div class="nc-field"><label>Carrera</label><select id="m_carrera"><option value="">(Sin carrera)</option></select></div>
      </div>
      <div class="nc-row" style="margin-top:10px" id="m_materias_cass_row">
        <div class="nc-field" style="flex:1;min-width:100%">
          <label>Materias inscritas (CASS)</label>
          <div id="m_materias_cass" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:4px"></div>
        </div>
      </div>
    `;

    // Prefill con datos frescos de la API (incl. foto_url)
    body.querySelector('#m_nombres').value = data.nombres || data.nombre || '';
    body.querySelector('#m_apellidos').value = data.apellidos || '';
    body.querySelector('#m_ci').value = data.ci || '';
    body.querySelector('#m_foto_url').value = data.foto_url || '';
    body.querySelector('#m_curso').value = String(data.curso_id || '');
    body.querySelector('#m_aula').value = String(data.aula_id || '');
    const mSubgrupo = body.querySelector('#m_subgrupo');
    if (mSubgrupo) mSubgrupo.value = data.subgrupo != null ? String(data.subgrupo) : '';
    body.querySelector('#m_facultad').value = String(data.facultad_id || '');

    function renderFotoPreview(url) {
      const box = body.querySelector('#m_foto_box');
      if (!box) return;
      const nurl = normalizeFotoUrl(url || '');
      if (!nurl) {
        box.innerHTML = `<span class="nc-badge">SIN FOTO</span>`;
        return;
      }
      box.innerHTML = `<img src="${escapeAttr(nurl)}" alt="Foto" />`;
    }
    renderFotoPreview(data.foto_url || '');

    body.querySelector('#m_foto_url').addEventListener('input', (e) => {
      renderFotoPreview(e.target.value.trim());
    });
      
      // ✅ Abrir cropper antes de subir
      /*openImageCropper(file, async (croppedFile) => {
        *setLoading(true);
        *try {
         * const url = await uploadAlumnoFoto(data.id, croppedFile);
          *body.querySelector('#m_foto_url').value = url;
        *  renderFotoPreview(url);
        *  toast('Foto recortada y actualizada correctamente.');
        *} catch (err) {
        *  toast('ERROR al subir foto: ' + err.message, 'err');
        *  e.target.value = '';
        *} finally {
        *  setLoading(false);
        *}
      *});*/

    async function refreshCarrerasSel() {
      const fid = body.querySelector('#m_facultad').value;
      const cid = data.carrera_id || '';
      let opts = `<option value="">(Sin carrera)</option>`;
      if (fid) {
        const carreras = await loadCarreras(fid);
        opts += carreras.map(c => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`).join('');
      }
      body.querySelector('#m_carrera').innerHTML = opts;
      body.querySelector('#m_carrera').value = String(cid || '');
    }
    body.querySelector('#m_facultad').addEventListener('change', async () => {
      data.carrera_id = null;
      await refreshCarrerasSel();
    });
    await refreshCarrerasSel();

    const alumnoId = data.id;
    let materiasCassIds = [];
    try {
      const matRes = await api('/materias?activo=1');
      const materiasList = (matRes && matRes.items) ? matRes.items : [];
      let inscritasRes = { materia_ids: [] };
      try {
        inscritasRes = await api('/asistencia/alumnos/' + alumnoId + '/materias');
      } catch (_) {}
      materiasCassIds = inscritasRes.materia_ids || [];
      const cassDiv = body.querySelector('#m_materias_cass');
      if (cassDiv && materiasList.length) {
        cassDiv.innerHTML = materiasList.map(m => {
          const checked = (materiasCassIds || []).indexOf(m.id) !== -1;
          return `<label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" data-materia-id="${m.id}" ${checked ? 'checked' : ''} />${escapeHtml(m.nombre)}</label>`;
        }).join('');
      }
    } catch (_) {}

    // Guardar el ID del alumno para usarlo en el event listener

    const btnCancel = el('button', { class: 'nc-btn secondary' }, 'Cancelar');
    const btnSave = el('button', { class: 'nc-btn primary' }, 'Guardar');

    const modal = openModal('Editar alumno', body, [btnCancel, btnSave]);
    
    // Asegurar que el event listener del file input esté activo después de abrir el modal
    setTimeout(() => {
      const fotoFileInput = body.querySelector('#m_foto_file');
      if (fotoFileInput && !fotoFileInput.hasAttribute('data-listener-added')) {
        fotoFileInput.setAttribute('data-listener-added', 'true');
        fotoFileInput.addEventListener('change', async (e) => {
          const file = e.target.files && e.target.files[0];
          if (!file) {
            e.target.value = '';
            return;
          }

          setLoading(true);
          try {
            const url = await uploadAlumnoFoto(alumnoId, file);
            const fotoUrlInput = body.querySelector('#m_foto_url');
            if (fotoUrlInput) {
              fotoUrlInput.value = url;
            }
            renderFotoPreview(url);
            toast('Foto actualizada correctamente.');
          } catch (err) {
            console.error('Error al subir foto:', err);
            toast('ERROR al subir foto: ' + err.message, 'err');
            e.target.value = '';
          } finally {
            setLoading(false);
          }
        });
      }
    }, 100);
    btnCancel.onclick = () => modal.close();
    btnSave.onclick = withButtonLock(btnSave, async () => {
      const fotoInput = body.querySelector('#m_foto_url');
      const fotoVal = (fotoInput && fotoInput.value) ? String(fotoInput.value).trim() : '';
      const subgrupoVal = body.querySelector('#m_subgrupo') ? body.querySelector('#m_subgrupo').value.trim() : '';
      const payload = {
        nombres: body.querySelector('#m_nombres').value.trim(),
        apellidos: body.querySelector('#m_apellidos').value.trim(),
        ci: body.querySelector('#m_ci').value.trim(),
        foto_url: fotoVal || null,
        curso_id: Number(body.querySelector('#m_curso').value || 0) || null,
        aula_id: Number(body.querySelector('#m_aula').value || 0) || null,
        facultad_id: Number(body.querySelector('#m_facultad').value || 0) || null,
        carrera_id: Number(body.querySelector('#m_carrera').value || 0) || null,
      };
      if (subgrupoVal !== '' || body.querySelector('#m_subgrupo')) payload.subgrupo = subgrupoVal || null;

      if (!payload.nombres || !payload.apellidos || !payload.ci) {
        toast('Nombres, apellidos y CI son obligatorios.', 'err');
        return;
      }

      setLoading(true);
      try {
        await api('/alumnos/' + data.id, { method: 'PUT', body: JSON.stringify(payload) });
        const cassCheckboxes = body.querySelectorAll('#m_materias_cass input[type="checkbox"][data-materia-id]');
        const materiaIdsFromForm = cassCheckboxes
          ? Array.from(cassCheckboxes).filter(cb => cb.checked).map(cb => Number(cb.getAttribute('data-materia-id')))
          : [];
        let materiaIds = materiaIdsFromForm;
        // El PUT ya autoinscribe (p. ej. grupo Nu/N). Si volvemos a POST solo lo del formulario,
        // pisamos la DB con checkboxes viejos. Unimos con lo que devolvió el servidor solo para Nu/N.
        const aulaSel = body.querySelector('#m_aula');
        if (isNuGrupoFromAulaSelect(aulaSel)) {
          try {
            const after = await api('/asistencia/alumnos/' + data.id + '/materias');
            const serverIds = (after && Array.isArray(after.materia_ids)) ? after.materia_ids.map(Number) : [];
            materiaIds = [...new Set([...materiaIdsFromForm, ...serverIds].filter((id) => Number.isFinite(id) && id > 0))];
          } catch (_) {}
        }
        try {
          await api('/asistencia/alumnos/' + data.id + '/materias', { method: 'POST', body: JSON.stringify({ materia_ids: materiaIds }) });
        } catch (_) {}
        toast('Alumno actualizado.');
        modal.close();
        if (onSaved) onSaved();
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    });
  }

  async function openAlumnoViewModal(alumno, navOptions) {
      trackModal({
        type: 'alumno_view',
        alumnoId: Number(alumno.id),
        index: navOptions && typeof navOptions.index === 'number' ? navOptions.index : -1,
        listIds: (navOptions && navOptions.list) ? navOptions.list.map(r => Number(r.id)).filter(Boolean) : [],
      });
      setLoading(true);
      let data = alumno;
      try {
        data = await api('/alumnos/' + alumno.id);
      } catch (e) {
        toast('No se pudo cargar el alumno.', 'err');
        setLoading(false);
        return;
      }
      setLoading(false);
      const body = el('div');
      const fotoUrl = normalizeFotoUrl(data.foto_url || '');
      const list = (navOptions && navOptions.list) || [];
      const idx = (navOptions && typeof navOptions.index === 'number') ? navOptions.index : -1;
      const hasNav = list.length > 1 && idx >= 0 && idx < list.length;
      const navHtml = hasNav ? `
        <div class="nc-row" style="align-items:center;justify-content:space-between;margin-bottom:14px;padding:10px;background:#f5f5f5;border-radius:8px;flex-wrap:wrap;gap:8px">
          <button type="button" class="nc-btn secondary small" id="nc_nav_prev" ${idx <= 0 ? 'disabled' : ''}>‹ Anterior</button>
          <span style="font-size:13px;font-weight:600">Estudiante ${idx + 1} de ${list.length}</span>
          <button type="button" class="nc-btn secondary small" id="nc_nav_next" ${idx >= list.length - 1 ? 'disabled' : ''}>Siguiente ›</button>
        </div>
      ` : '';
      body.innerHTML = `
        ${navHtml}
        <div class="nc-row" style="align-items:flex-start;gap:14px">
          <div style="min-width:140px">
            <div style="font-size:12px;color:#555;margin-bottom:6px">Foto</div>
            ${fotoUrl ? `<img class="nc-photo" src="${escapeAttr(fotoUrl)}" alt="Foto" />` : '<div class="nc-photo-missing">SIN FOTO</div>'}
          </div>
          <div style="flex:1;min-width:280px">
            <div style="font-size:18px;font-weight:700;margin-bottom:6px">${escapeHtml(alumnoDisplay(data) || 'Alumno')}</div>
            <div class="nc-mini">CI: <b>${escapeHtml(data.ci || '')}</b></div>
            <div class="nc-mini">Curso: <b>${escapeHtml(data.curso_nombre || '')}</b></div>
            <div class="nc-mini">Grupo: <b>${escapeHtml(data.aula_nombre || '')}</b></div>
            <div class="nc-mini">Facultad: <b>${escapeHtml(data.facultad_nombre || '')}</b></div>
            <div class="nc-mini">Carrera: <b>${escapeHtml(data.carrera_nombre || '')}</b></div>
          </div>
        </div>
    
        <hr style="margin:16px 0;">
    
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
          <h4 style="margin:0;">Historial de conductas</h4>
          <div style="font-size:12px;opacity:.7;">(más reciente arriba)</div>
        </div>

        <div class="nc-row" style="margin-top:10px;align-items:flex-end;flex-wrap:wrap;">
          <div class="nc-field">
            <label>Desde</label>
            <input id="nc_h_from" type="date" />
          </div>
          <div class="nc-field">
            <label>Hasta</label>
            <input id="nc_h_to" type="date" />
          </div>
          <div class="nc-field">
            <label>Tipo</label>
            <select id="nc_h_tipo">
              <option value="" selected>Todos</option>
              <option value="individual">Individual</option>
              <option value="grupal">Grupal</option>
            </select>
          </div>
          ${userPermissions.isAdmin() ? `
          <div class="nc-field" style="flex:2;min-width:220px">
            <label>Registró (usuario)</label>
            <input id="nc_h_eval" placeholder="Ej: David" />
          </div>
          ` : ''}
          <button class="nc-btn" id="nc_h_btn">Buscar</button>
          <button class="nc-btn secondary" id="nc_h_clear">Limpiar</button>
          ${userPermissions.isAdmin() ? `<button class="nc-btn secondary" id="nc_h_csv">Exportar CSV</button>
          <button class="nc-btn secondary" id="nc_h_pdf">Exportar PDF</button>` : ''}
        </div>

        <div id="nc_historial_conducta" style="margin-top:10px;max-height:220px;overflow:auto;">
          <div style="opacity:.7;">Cargando historial...</div>
        </div>
        <div style="margin-top:14px">
          <h4 style="margin:0 0 8px">Progreso de conducta</h4>
          <div id="nc_chart_conducta" style="height:180px;background:#fafafa;border-radius:10px;padding:8px"></div>
        </div>
      `;
    
      const modalActions = [];
      if (userPermissions.canManageStudents()) {
        modalActions.push(
          { label: 'Editar', className: 'nc-btn', onClick: (m) => { m.close(); openAlumnoEditModal(data); } },
          { label: 'Eliminar', className: 'nc-btn danger', onClick: async (m) => {
            if (!confirm('¿Eliminar a ' + (alumnoDisplay(data) || 'este alumno') + '? Esta acción no se puede deshacer.')) return;
            setLoading(true);
            try {
              await api('/alumnos/' + data.id, { method: 'DELETE' });
              toast('Alumno eliminado.');
              m.close();
              openTab('alumnos');
            } catch (e) {
              toast('ERROR: ' + e.message, 'err');
            } finally {
              setLoading(false);
            }
          }}
        );
      }
      modalActions.push(
        { label: 'Registrar conducta', className: 'nc-btn', onClick: () => { openConductaAlumnoModal(data); } },
        { label: 'Cerrar', className: 'secondary', onClick: (m) => m.close() }
      );
      const modal = openModal('Alumno', body, modalActions);
      if (hasNav) {
        const prevBtn = body.querySelector('#nc_nav_prev');
        const nextBtn = body.querySelector('#nc_nav_next');
        if (prevBtn) prevBtn.onclick = () => { modal.close(); openAlumnoViewModal(list[idx - 1], { list, index: idx - 1 }); };
        if (nextBtn) nextBtn.onclick = () => { modal.close(); openAlumnoViewModal(list[idx + 1], { list, index: idx + 1 }); };
      }
      const alumnoIdForHistorial = data.id;
      // cargar historial dentro del modal
      (function(){
      const run = () => {
        const from = document.getElementById('nc_h_from')?.value || '';
        const to = document.getElementById('nc_h_to')?.value || '';
        const tipo = document.getElementById('nc_h_tipo')?.value || '';
        const evaluador = document.getElementById('nc_h_eval')?.value?.trim() || '';
        loadConductaHistorial(alumnoIdForHistorial, { from, to, tipo, evaluador }, (rows) => {
          renderConductaLineChart(document.getElementById('nc_chart_conducta'), rows);
        });
      };
      const btn = document.getElementById('nc_h_btn');
      const clr = document.getElementById('nc_h_clear');
      if (btn) btn.onclick = run;
      if (clr) clr.onclick = () => {
        const f = document.getElementById('nc_h_from'); if (f) f.value = '';
        const t = document.getElementById('nc_h_to'); if (t) t.value = '';
        const tp = document.getElementById('nc_h_tipo'); if (tp) tp.value = '';
        const e = document.getElementById('nc_h_eval'); if (e) e.value = '';
        run();
      };
      const csvBtn = document.getElementById('nc_h_csv');
      const pdfBtn = document.getElementById('nc_h_pdf');

      async function doExport(fmt) {
        const from = document.getElementById('nc_h_from')?.value || '';
        const to = document.getElementById('nc_h_to')?.value || '';
        const tipo = document.getElementById('nc_h_tipo')?.value || '';
        const evaluador = (document.getElementById('nc_h_eval')?.value || '').trim();

        const qs = new URLSearchParams();
        if (from) qs.set('from', from);
        if (to) qs.set('to', to);
        if (tipo && tipo !== 'todos') qs.set('tipo', tipo);
        if (evaluador) qs.set('evaluador', evaluador);
        qs.set('format', fmt === 'csv' ? 'csv' : 'html');

        setLoading(true);
        try {
          const url = `${API}/alumnos/${alumnoIdForHistorial}/conducta/export?${qs.toString()}&nc_ts=${Date.now()}`;
          const res = await fetch(url, { credentials: 'same-origin', headers: NC_APP.nonce ? { 'X-WP-Nonce': NC_APP.nonce } : {} });
          if (!res.ok) throw new Error(res.statusText || 'Error al exportar');
          const ct = res.headers.get('content-type') || '';
          const raw = await res.text();
          if (fmt === 'csv') {
            const blob = new Blob([raw], { type: 'text/csv;charset=utf-8' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            const stamp = new Date().toISOString().slice(0,19).replace(/[:T]/g,'-');
            a.download = `conducta_${stamp}.csv`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(a.href), 5000);
          } else {
            const w = window.open('', '_blank');
            if (!w) throw new Error('Bloqueado por el navegador. Permití popups para exportar PDF.');
            w.document.open();
            w.document.write(ct.includes('html') ? raw : '<pre>' + escapeHtml(raw) + '</pre>');
            w.document.close();
            w.focus();
          }
        } catch (e) {
          toast('ERROR: ' + e.message, 'err');
        } finally {
          setLoading(false);
        }
      }

      if (csvBtn) csvBtn.onclick = () => doExport('csv');
      if (pdfBtn) pdfBtn.onclick = () => doExport('html');

      const ev = document.getElementById('nc_h_eval');
      if (ev) ev.addEventListener('keydown', (e) => { if (e.key === 'Enter') run(); });
      run();
    })();
    
      return modal;
    }
    
  async function loadConductaHistorial(alumnoId, filters = {}, onData) {
      const cont = document.getElementById('nc_historial_conducta');
      if (!cont) return;
    
      cont.innerHTML = `<div style="opacity:.7;">Cargando...</div>`;
    
      try {
        const qs = new URLSearchParams();
        if (filters.from) qs.set('from', filters.from);
        if (filters.to) qs.set('to', filters.to);
        if (filters.tipo) qs.set('tipo', filters.tipo);
        if (filters.evaluador) qs.set('evaluador', filters.evaluador);
        const url = `/alumnos/${alumnoId}/conducta` + (qs.toString() ? `?${qs}` : '');
        const data = await api(url);
    
        if (!Array.isArray(data) || data.length === 0) {
          cont.innerHTML = `<div style="opacity:.7;">Sin registros.</div>`;
          if (typeof onData === 'function') onData([]);
          return;
        }
        if (typeof onData === 'function') onData(data);
    
        const currentUserId = (NC_APP && NC_APP.currentUserId != null) ? Number(NC_APP.currentUserId) : null;
        cont.innerHTML = data.map(r => {
          const evalName = r.evaluador_nombre
            ? r.evaluador_nombre
            : (r.evaluador_user_id ? `ID ${r.evaluador_user_id}` : '—');
          const canEdit = currentUserId != null && Number(r.evaluador_user_id) === currentUserId;
          const obsG = r.observacion_general ? `<div><b>Obs. general:</b> ${escapeHtml(r.observacion_general)}</div>` : '';
          const obsI = r.observacion_item ? `<div><b>Obs. alumno:</b> ${escapeHtml(r.observacion_item)}</div>` : '';
          const editBtn = canEdit ? `<button type="button" class="nc-btn secondary nc-edit-conducta" style="margin-left:auto;font-size:12px;padding:4px 10px;" 
            data-item-id="${r.item_id != null ? r.item_id : ''}" 
            data-legacy-id="${r.item_id == null ? (r.evaluacion_id || '') : ''}" 
            data-fecha="${escapeHtml(r.fecha || '')}" 
            data-obs="${escapeHtml((r.observacion_item || '').replace(/"/g, '&quot;'))}" 
            data-score-resp-acad="${r.responsabilidad_academica ?? 0}"
            data-score-resp-conv="${r.respeto_convivencia ?? 0}"
            data-score-part-act="${r.participacion_actitud ?? 0}"
            data-score-autocont="${r.autocontrol_disciplina ?? 0}"
            data-score-auton-comp="${r.autonomia_compromiso ?? 0}"
            data-score-pres-ord="${r.presentacion_orden ?? 0}">Editar</button>` : '';
          return `
            <div class="nc-conducta-card" style="padding:10px;border:1px solid #eee;border-radius:10px;margin-bottom:10px;">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <div>
                    <b>${escapeHtml(r.fecha || '')}</b> 
                    ${userPermissions.canViewEvaluator() ? `<span style="opacity:.7;"> — ${escapeHtml(evalName)}</span>` : ''}
                  </div>
                ${(() => {
                  const t = (r.tipo || '').toUpperCase();
                  const badge = t ? t : ((Number(r.items_count||0) > 1) ? 'GRUPAL' : 'INDIVIDUAL');
                  return `<span class="nc-pill" style="padding:2px 8px;font-size:11px">${escapeHtml(badge)}</span>`;
                })()}
                ${editBtn}
              </div>
              ${obsG}
              ${obsI}
              <div style="margin-top:6px;opacity:.85;font-size:12px;">
                Resp.Acad.: <b>${r.responsabilidad_academica ?? 0}</b> · Respeto/Conv.: <b>${r.respeto_convivencia ?? 0}</b> · Part.Act.: <b>${r.participacion_actitud ?? 0}</b>
                · Autocont.: <b>${r.autocontrol_disciplina ?? 0}</b> · Auton.Comp.: <b>${r.autonomia_compromiso ?? 0}</b> · Pres.Orden: <b>${r.presentacion_orden ?? 0}</b>
              </div>
            </div>
          `;
        }).join('');
        cont.querySelectorAll('.nc-edit-conducta').forEach(btn => {
          btn.onclick = () => {
            const itemId = btn.getAttribute('data-item-id');
            const legacyId = btn.getAttribute('data-legacy-id');
            const fecha = btn.getAttribute('data-fecha') || '';
            const obs = (btn.getAttribute('data-obs') || '').replace(/&quot;/g, '"');
            const scores = {
              responsabilidad_academica: Number(btn.getAttribute('data-score-resp-acad') || 0),
              respeto_convivencia: Number(btn.getAttribute('data-score-resp-conv') || 0),
              participacion_actitud: Number(btn.getAttribute('data-score-part-act') || 0),
              autocontrol_disciplina: Number(btn.getAttribute('data-score-autocont') || 0),
              autonomia_compromiso: Number(btn.getAttribute('data-score-auton-comp') || 0),
              presentacion_orden: Number(btn.getAttribute('data-score-pres-ord') || 0)
            };
            openEditConductaModal({ itemId: itemId || null, legacyId: legacyId || null, fecha, observacion_item: obs, scores }, () => loadConductaHistorial(alumnoId));
          };
        });
      } catch (e) {
        console.error(e);
        cont.innerHTML = `<div style="color:#b00;">Error al cargar historial.</div>`;
      }
    }


    
    
  function openEditConductaModal(recordData, onSuccess) {
      const body = el('div');
      const fechaVal = recordData.fecha || new Date().toISOString().slice(0, 10);
      body.innerHTML = `
        <div class="nc-row">
          <div class="nc-field">
            <label>Fecha</label>
            <input id="nc_edit_fecha" type="date" value="${escapeHtml(fechaVal)}" />
          </div>
          <div class="nc-field" style="flex:2;">
            <label>Observación (opcional)</label>
            <input id="nc_edit_obs" type="text" placeholder="Ej: Interrumpió varias veces..." />
          </div>
        </div>
        <div class="nc-row" style="margin-top:10px;flex-wrap:wrap;">
          ${scoreSelect('responsabilidad_academica','Responsabilidad Académica')}
          ${scoreSelect('respeto_convivencia','Respeto y Convivencia')}
          ${scoreSelect('participacion_actitud','Participación y Actitud en Clase')}
          ${scoreSelect('autocontrol_disciplina','Autocontrol y Disciplina')}
          ${scoreSelect('autonomia_compromiso','Autonomía y Compromiso Personal')}
          ${scoreSelect('presentacion_orden','Presentación y Orden')}
        </div>
        <div style="margin-top:8px;font-size:12px;opacity:.7;">0 = Inaceptable, 5 = Excelente</div>
      `;
      const modal = openModal('Editar registro de conducta', body, [
        { label: 'Cancelar', className: 'secondary', onClick: (m) => m.close() },
        {
          label: 'Guardar',
          className: 'primary',
          onClick: async (m) => {
            const scores = {
              responsabilidad_academica: +document.getElementById('nc_s_responsabilidad_academica').value,
              respeto_convivencia: +document.getElementById('nc_s_respeto_convivencia').value,
              participacion_actitud: +document.getElementById('nc_s_participacion_actitud').value,
              autocontrol_disciplina: +document.getElementById('nc_s_autocontrol_disciplina').value,
              autonomia_compromiso: +document.getElementById('nc_s_autonomia_compromiso').value,
              presentacion_orden: +document.getElementById('nc_s_presentacion_orden').value,
            };
            const payload = { fecha: document.getElementById('nc_edit_fecha').value, observacion_item: document.getElementById('nc_edit_obs').value || '', scores };
            const url = recordData.itemId ? `/conducta-items/${recordData.itemId}` : `/conducta-legacy/${recordData.legacyId}`;
            setLoading(true);
            try {
              await api(url, { method: 'PATCH', body: JSON.stringify(payload) });
              toast('Registro actualizado.');
              m.close();
              if (typeof onSuccess === 'function') onSuccess();
            } catch (e) {
              toast('Error: ' + e.message, 'err');
            } finally {
              setLoading(false);
            }
          },
        },
      ]);
      // Establecer valores después de que el modal esté completamente renderizado
      setTimeout(() => {
        const obsEl = document.getElementById('nc_edit_obs');
        if (obsEl) {
          obsEl.value = recordData.observacion_item || '';
        }
        const scores = recordData.scores || {};
        ['responsabilidad_academica','respeto_convivencia','participacion_actitud','autocontrol_disciplina','autonomia_compromiso','presentacion_orden'].forEach(k => {
          const sel = document.getElementById('nc_s_' + k);
          if (sel) {
            const val = scores[k];
            if (val !== undefined && val !== null) {
              const numVal = Number(val);
              if (!isNaN(numVal) && numVal >= 0 && numVal <= 5) {
                sel.value = String(numVal);
              }
            }
          }
        });
      }, 100);
    }

  function openConductaAlumnoModal(alumno) {
      const body = el('div');
      const today = new Date().toISOString().slice(0, 10);
    
      body.innerHTML = `
        <div style="opacity:.85;margin-bottom:10px;">
          Alumno: <b>${escapeHtml(alumnoDisplay(alumno))}</b>
        </div>
    
        <div class="nc-row">
          <div class="nc-field">
            <label>Fecha</label>
            <input id="nc_c_fecha" type="date" value="${today}" />
          </div>
          <div class="nc-field" style="flex:2;">
            <label>Observación (opcional)</label>
            <input id="nc_c_obs" type="text" placeholder="Ej: Interrumpió varias veces..." />
          </div>
        </div>
    
        <div class="nc-row" style="margin-top:10px;flex-wrap:wrap;">
          ${scoreSelect('responsabilidad_academica','Responsabilidad Académica')}
          ${scoreSelect('respeto_convivencia','Respeto y Convivencia')}
          ${scoreSelect('participacion_actitud','Participación y Actitud en Clase')}
          ${scoreSelect('autocontrol_disciplina','Autocontrol y Disciplina')}
          ${scoreSelect('autonomia_compromiso','Autonomía y Compromiso Personal')}
          ${scoreSelect('presentacion_orden','Presentación y Orden')}
        </div>
    
        <div style="margin-top:8px;font-size:12px;opacity:.7;">
          0 = Inaceptable, 5 = Excelente
        </div>
      `;
    
      const modal = openModal('Registrar conducta', body, [
        { label: 'Cancelar', className: 'secondary', onClick: (m) => m.close() },
        {
          label: 'Guardar',
          className: 'primary',
          loadingText: 'Guardando...',
          onClick: async (m) => {
            const payload = {
              fecha: document.getElementById('nc_c_fecha').value,
              observacion_item: document.getElementById('nc_c_obs').value || '',
              curso_id: alumno.curso_id || null,
              aula_id: alumno.aula_id || null,
              scores: {
                responsabilidad_academica: +document.getElementById('nc_s_responsabilidad_academica').value,
                respeto_convivencia: +document.getElementById('nc_s_respeto_convivencia').value,
                participacion_actitud: +document.getElementById('nc_s_participacion_actitud').value,
                autocontrol_disciplina: +document.getElementById('nc_s_autocontrol_disciplina').value,
                autonomia_compromiso: +document.getElementById('nc_s_autonomia_compromiso').value,
                presentacion_orden: +document.getElementById('nc_s_presentacion_orden').value,
              }
            };
    
            setLoading(true);
                try {
                  await api(`/alumnos/${alumno.id}/conducta`, {
                    method: 'POST',
                    body: JSON.stringify(payload)
                  });
                  toast('Conducta registrada.');
                  m.close();
                  (function(){
      const run = () => {
        const from = document.getElementById('nc_h_from')?.value || '';
        const to = document.getElementById('nc_h_to')?.value || '';
        const tipo = document.getElementById('nc_h_tipo')?.value || '';
        const evaluador = document.getElementById('nc_h_eval')?.value?.trim() || '';
        loadConductaHistorial(alumno.id, { from, to, tipo, evaluador });
      };
      const btn = document.getElementById('nc_h_btn');
      const clr = document.getElementById('nc_h_clear');
      if (btn) btn.onclick = run;
      if (clr) clr.onclick = () => {
        const f = document.getElementById('nc_h_from'); if (f) f.value = '';
        const t = document.getElementById('nc_h_to'); if (t) t.value = '';
        const tp = document.getElementById('nc_h_tipo'); if (tp) tp.value = '';
        const e = document.getElementById('nc_h_eval'); if (e) e.value = '';
        run();
      };
      const ev = document.getElementById('nc_h_eval');
      if (ev) ev.addEventListener('keydown', (e) => { if (e.key === 'Enter') run(); });
      run();
    })();
                } catch (e) {
                  console.error(e);
                  alert(e.message || 'Error al registrar conducta.');
                } finally {
                  setLoading(false);
                }

          }
        }
      ]);
    
      return modal;
    }
    
  // Opciones de conducta: 5 = excelente, 0 = inaceptable
  const CONDUCTA_OPCIONES = [
    { value: 0, label: '0 - Inaceptable' },
    { value: 1, label: '1' },
    { value: 2, label: '2' },
    { value: 3, label: '3' },
    { value: 4, label: '4' },
    { value: 5, label: '5 - Excelente' },
  ];

  function scoreSelect(key, label) {
      const opts = CONDUCTA_OPCIONES.map(o => `<option value="${o.value}" ${o.value === 0 ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
      return `
        <div class="nc-field" style="min-width:180px;flex:1;">
          <label>${label}</label>
          <select id="nc_s_${key}">
            ${opts}
          </select>
        </div>
      `;
    }

  function renderConductaLineChart(container, rows) {
    if (!container || !Array.isArray(rows) || rows.length === 0) {
      if (container) container.innerHTML = '<div style="padding:20px;text-align:center;color:#666;font-size:13px">Sin datos para graficar</div>';
      return;
    }
    const pad = { top: 10, right: 10, bottom: 28, left: 32 };
    const w = Math.max(200, (container.offsetWidth || 280) - pad.left - pad.right);
    const h = Math.max(120, (container.offsetHeight || 180) - pad.top - pad.bottom);
    const sorted = [...rows].sort((a, b) => (a.fecha || '').localeCompare(b.fecha || ''));
    const dates = sorted.map(r => r.fecha || '');
    const avg = (r) => {
      const h = Number(r.responsabilidad_academica ?? 0), i = Number(r.respeto_convivencia ?? 0), m = Number(r.participacion_actitud ?? 0);
      const v = Number(r.autocontrol_disciplina ?? 0), d = Number(r.autonomia_compromiso ?? 0), p = Number(r.presentacion_orden ?? 0);
      return (h + i + m + v + d + p) / 6;
    };
    const values = sorted.map(avg);
    const minY = 0, maxY = 5;
    const xScale = (i) => pad.left + (dates.length > 1 ? (i / (dates.length - 1)) * w : 0);
    const yScale = (v) => pad.top + h - (Number(v) / (maxY - minY)) * h;
    const pathD = values.map((v, i) => (i === 0 ? 'M' : 'L') + xScale(i).toFixed(1) + ',' + yScale(v).toFixed(1)).join(' ');
    const labels = dates.map((d, i) => `<text x="${xScale(i)}" y="${h + pad.bottom - 6}" font-size="10" fill="#555" text-anchor="middle">${(d || '').slice(5)}</text>`).join('');
    const yTicks = [0, 1, 2, 3, 4, 5].map(v => `<line x1="${pad.left}" y1="${yScale(v)}" x2="${pad.left + w}" y2="${yScale(v)}" stroke="#eee" stroke-width="1"/>`);
    container.innerHTML = `<svg width="100%" height="100%" viewBox="0 0 ${pad.left + w + pad.right} ${pad.top + h + pad.bottom}" preserveAspectRatio="xMidYMid meet">
      ${yTicks.join('')}
      <path d="${pathD}" fill="none" stroke="#1a73e8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      ${labels}
      <text x="${pad.left - 8}" y="${pad.top + h/2}" font-size="10" fill="#555" text-anchor="middle" transform="rotate(-90 ${pad.left - 8} ${pad.top + h/2})">Promedio 0-5</text>
    </svg>`;
  }

  // State
  let cursos = [];
  let aulas = [];
  let facultades = [];
  // carreras cacheadas: { [facultad_id]: carreras[] }
  let carrerasByFac = {};
  let baseLoaded = false;

  // -------- Layout base
  root.innerHTML = '';
  root.appendChild(el('style', {}, `
    .nc-wrap{padding:16px;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;max-width:1150px}
    .nc-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .nc-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0;padding:4px 0;align-items:center}
    .nc-tab{padding:10px 16px;border-radius:12px;border:1px solid #ddd;background:#f8f9fa;cursor:pointer;font-size:14px;font-weight:500;transition:all .2s ease;color:#333}
    .nc-tab:hover{background:#e9ecef;border-color:#adb5bd}
    .nc-tab[data-active="1"]{border-color:#0d6efd;background:linear-gradient(135deg,#0d6efd,#0a58ca);color:#fff;box-shadow:0 2px 8px rgba(13,110,253,.3)}
    .nc-tab[data-active="1"]:hover{background:linear-gradient(135deg,#0a58ca,#084298);color:#fff}
    .nc-card{border:1px solid #e6e6e6;border-radius:14px;background:#fff;padding:14px;margin:10px 0}
    .nc-row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
    .nc-field{display:flex;flex-direction:column;gap:6px}
    .nc-field label{font-size:12px;color:#555}
    .nc-field input,.nc-field select,.nc-field textarea{padding:10px;border-radius:10px;border:1px solid #ddd;min-width:220px}
    .nc-field textarea{min-width:320px;min-height:90px}
    .nc-btn{padding:10px 12px;border-radius:10px;border:1px solid #111;background:#111;color:#fff;cursor:pointer}
    .nc-btn.primary{background:#2e7d32;border-color:#2e7d32;color:#fff}
    .nc-btn.primary:hover{background:#1b5e20;border-color:#1b5e20}
    .nc-btn.secondary{background:#fff;color:#333;border:1px solid #666;font-weight:500}
    .nc-btn.secondary:hover{background:#f5f5f5;border-color:#333;color:#000}
    .nc-btn.danger{background:#b00020;border-color:#b00020}
    .nc-btn:disabled{opacity:.5;cursor:not-allowed}
    table{border-collapse:collapse;width:100%}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;font-size:14px;vertical-align:top}
    th{font-size:12px;color:#555;text-transform:uppercase;letter-spacing:.04em}
    .nc-pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#f3f3f3;font-size:12px}
    #nc-toast{position:fixed;right:16px;bottom:16px;background:#111;color:#fff;padding:12px 14px;border-radius:12px;display:none;max-width:420px;z-index:999999}
    #nc-toast[data-type="err"]{background:#b00020}
    .nc-scores select{min-width:70px}
    .nc-mini{font-size:12px;color:#666}
    .nc-inline{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .nc-input-sm{min-width:200px}
    .nc-actions{display:flex;gap:8px;flex-wrap:wrap}
    .nc-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;overflow-y:auto}
    .nc-modal{background:#fff;border-radius:16px;max-width:700px;width:100%;max-height:90vh;box-shadow:0 10px 40px rgba(0,0,0,.25);border:1px solid #e6e6e6;display:flex;flex-direction:column;overflow:hidden}
    .nc-modal-h{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid #eee;flex-shrink:0}
    .nc-modal-b{padding:16px;overflow-y:auto;flex:1;min-height:0}
    .nc-modal-f{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;padding:14px 16px;border-top:1px solid #eee;flex-shrink:0}
    .nc-modal-x{background:#fff;border:2px solid #333;border-radius:10px;cursor:pointer;padding:8px 12px;font-size:24px;line-height:1;color:#333;font-weight:bold;min-width:36px;min-height:36px;display:flex;align-items:center;justify-content:center;transition:all 0.2s}
    .nc-modal-x:hover{background:#f5f5f5;border-color:#000;color:#000}
    .nc-link{background:transparent;border:0;padding:0;margin:0;color:#0b5fff;cursor:pointer;text-decoration:underline;font:inherit}
    .nc-photo{width:110px;height:110px;border-radius:14px;border:1px solid #eee;object-fit:cover;background:#fafafa}
    .nc-photo-missing,.nc-photo-badge{width:110px;height:110px;border-radius:14px;border:1px dashed #ddd;display:flex;align-items:center;justify-content:center;background:#fafafa;font-size:12px;color:#555}
    .nc-badge{display:inline-block;padding:6px 10px;border-radius:999px;background:#f3f3f3;font-size:12px;color:#444}
            /* Estilos de paginación */
    .nc-pagination {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 12px 16px;
      background: #f8f9fa;
      border-radius: 8px;
      margin: 16px 0;
      flex-wrap: wrap;
    }
    .nc-pagination-info {
      font-size: 14px;
      color: #495057;
    }
    .nc-pagination-controls {
      display: flex;
      gap: 8px;
      align-items: center;
    }
    .nc-pagination-page {
      padding: 0 12px;
      font-weight: 500;
      color: #212529;
    }
    .nc-pagination-jump {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 14px;
    }
    .nc-pagination-jump label {
      margin: 0;
      color: #495057;
    }
    .nc-btn.small {
      padding: 6px 12px;
      font-size: 13px;
    }
    /* Selección múltiple */
    .nc-bulk-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      background: #e7f3ff;
      border: 1px solid #b3d9ff;
      border-radius: 8px;
      margin: 12px 0;
      flex-wrap: wrap;
    }
    .nc-bulk-info {
      flex: 1;
      font-size: 14px;
      color: #004085;
    }
    .nc-bulk-info strong {
      font-weight: 600;
    }
    .nc-bulk-buttons {
      display: flex;
      gap: 8px;
    }
    .nc-checkbox-cell {
      width: 40px;
      text-align: center;
    }
    .nc-checkbox-cell input[type="checkbox"] {
      width: 18px;
      height: 18px;
      cursor: pointer;
    }
    tr.selected {
      background: #e7f3ff;
    }
    @media (max-width: 768px) {
      .nc-wrap {
        padding: 12px;
      }
      .nc-card {
        padding: 12px;
        margin: 8px 0;
      }
      .nc-row {
        flex-direction: column;
        gap: 12px;
      }
      .nc-field {
        width: 100%;
      }
      .nc-field input, .nc-field select, .nc-field textarea {
        min-width: 100%;
        width: 100%;
      }
      .nc-field textarea {
        min-width: 100%;
      }
      .nc-tabs {
        flex-direction: row;
        gap: 6px;
        overflow-x: auto;
        padding-bottom: 4px;
        -webkit-overflow-scrolling: touch;
      }
      .nc-tab {
        flex-shrink: 0;
        padding: 8px 12px;
        font-size: 13px;
      }
      table {
        font-size: 12px;
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }
      th, td {
        padding: 8px 6px;
        font-size: 12px;
        white-space: nowrap;
      }
      .nc-btn {
        width: 100%;
        text-align: center;
        padding: 12px;
      }
      .nc-btn.small {
        padding: 8px 12px;
      }
      .nc-pagination {
        flex-direction: column;
        align-items: stretch;
        padding: 10px;
      }
      .nc-pagination-controls {
        justify-content: center;
        flex-wrap: wrap;
        gap: 6px;
      }
      .nc-pagination-jump {
        flex-direction: column;
        width: 100%;
      }
      .nc-bulk-actions {
        flex-direction: column;
        align-items: stretch;
        padding: 10px;
      }
      .nc-bulk-buttons {
        flex-direction: column;
        width: 100%;
      }
      .nc-bulk-buttons button {
        width: 100%;
      }
      .nc-modal {
        max-width: calc(100% - 32px);
        max-height: 85vh;
        margin: 16px;
      }
      .nc-modal-b {
        padding: 12px;
        overflow-y: auto;
      }
      .nc-modal-backdrop {
        padding: 8px;
        align-items: flex-start;
        padding-top: 20px;
      }
      .nc-head {
        flex-direction: column;
        align-items: flex-start;
      }
      #nc_dash_stats {
        grid-template-columns: 1fr;
      }
      .nc-scores select {
        min-width: 60px;
        font-size: 12px;
        padding: 6px;
      }
      .nc-checkbox-cell {
        width: 32px;
      }
      .nc-checkbox-cell input[type="checkbox"] {
        width: 16px;
        height: 16px;
      }
    }
    @media (max-width: 480px) {
      th, td {
        padding: 6px 4px;
        font-size: 11px;
      }
      .nc-card {
        padding: 10px;
      }
      .nc-field input, .nc-field select, .nc-field textarea {
        padding: 8px;
        font-size: 14px;
      }
      .nc-btn {
        padding: 10px;
        font-size: 14px;
      }
    }
  `));

  const wrap = el('div', { class: 'nc-wrap' });
  wrap.appendChild(el('div', { id: 'nc-toast' }));
  wrap.appendChild(el('div', { class: 'nc-head' }, `
    <div>
      <h2 style="margin:0">Newton Conducta</h2>
      <div id="nc-status" style="margin-top:4px;color:#666;font-size:13px"></div>
    </div>
  `));

  const tabs = el('div', { class: 'nc-tabs' });
  const tabBtns = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'conducta-main', label: 'Conducta' },
    { id: 'asistencia-main', label: 'Asistencia' },
    { id: 'puntajes-main', label: 'Puntajes' },
    { id: 'reportes-main', label: 'Reportes' },
    { id: 'admin-main', label: 'Administrador' },
  ].map(t => {
    const b = el('button', { class: 'nc-tab', 'data-tab': t.id }, t.label);
    b.onclick = () => openTab(t.id);
    tabs.appendChild(b);
    return b;
  });

  wrap.appendChild(tabs);
  
  (async function() {
    await userPermissions.load();
    
    // Ocultar tabs según permisos
    const tabsToHide = [];
    
    if (!userPermissions.canViewReports()) {
      tabsToHide.push('reportes-main');
    }

    // Ocultar Puntajes / exámenes para roles no admin (Docente, Funcionario de Oficina, etc.)
    if (!userPermissions.isAdmin()) {
      tabsToHide.push('puntajes-main');
    }

    if (!userPermissions.canManageStudents() && !userPermissions.canManageCourses() && !userPermissions.canManageAulas() && !userPermissions.canManageFacultades()) {
      tabsToHide.push('admin-main');
    }

    // Ocultar los tabs
    tabBtns.forEach(btn => {
      if (tabsToHide.includes(btn.dataset.tab)) {
        btn.style.display = 'none';
      }
    });
  })();

  const view = el('div', { id: 'nc-view' });
  wrap.appendChild(view);
  root.appendChild(wrap);

  function setActiveTab(id) {
    tabBtns.forEach(b => b.dataset.active = (b.dataset.tab === id ? '1' : '0'));
  }

  function renderSectionHub(title, desc, actions) {
    view.innerHTML = '';
    const card = el('div', { class: 'nc-card' }, `
      <h3 style="margin:0 0 8px">${escapeHtml(title)}</h3>
      <p style="margin:0 0 14px;color:#666;font-size:13px">${escapeHtml(desc || '')}</p>
      <div id="nc-section-actions" class="nc-row nc-row-wrap" style="gap:10px"></div>
    `);
    view.appendChild(card);
    const wrapActions = card.querySelector('#nc-section-actions');
    (actions || []).forEach(a => {
      if (a && a.visible === false) return;
      const btn = el('button', { class: 'nc-btn secondary' }, a.label || 'Abrir');
      btn.onclick = () => {
        if (typeof a.onClick === 'function') a.onClick();
      };
      wrapActions.appendChild(btn);
    });
  }

  // ---------- Loaders
  async function loadBase(force = false) {
    if (baseLoaded && !force) return;
    setLoading(true);
    try {
      cursos = await api('/cursos');
      aulas = await api('/aulas');
      // facultades/carreras solo para pantallas que lo necesiten, pero lo precargamos
      facultades = await api('/facultades');
      carrerasByFac = {};
      baseLoaded = true;
    } finally {
      setLoading(false);
    }
  }

  async function loadCarreras(facultad_id) {
    const fid = Number(facultad_id || 0);
    if (!fid) return [];
    if (carrerasByFac[fid]) return carrerasByFac[fid];
    const rows = await api(`/carreras?facultad_id=${encodeURIComponent(fid)}`);
    carrerasByFac[fid] = rows || [];
    return carrerasByFac[fid];
  }

  function cursosOptions(includeAll = false, selected = '') {
    const opts = [];
    if (includeAll) opts.push(`<option value="">Todos</option>`);
    if (!includeAll) opts.push(`<option value="">(Sin curso)</option>`);
    for (const c of cursos) {
      const sel = String(c.id) === String(selected) ? 'selected' : '';
      opts.push(`<option value="${c.id}" ${sel}>${escapeHtml(c.nombre)}</option>`);
    }
    return opts.join('');
  }

  function aulasOptions(includeAll = false, selected = '') {
    const opts = [];
    if (includeAll) opts.push(`<option value="">Todos</option>`);
    if (!includeAll) opts.push(`<option value="">(Sin grupo)</option>`);
    for (const a of aulas) {
      const sel = String(a.id) === String(selected) ? 'selected' : '';
      opts.push(`<option value="${a.id}" ${sel}>${escapeHtml(a.nombre)}</option>`);
    }
    return opts.join('');
  }

  function facultadesOptions(includeAll = false, selected = '') {
    const opts = [];
    if (includeAll) opts.push(`<option value="">Todas</option>`);
    if (!includeAll) opts.push(`<option value="">(Sin facultad)</option>`);
    for (const f of facultades) {
      const sel = String(f.id) === String(selected) ? 'selected' : '';
      opts.push(`<option value="${f.id}" ${sel}>${escapeHtml(f.nombre)}</option>`);
    }
    return opts.join('');
  }

  // ---------- Navegación persistente (sessionStorage vía NC_AppState)
  const SCREEN_TO_MAIN = {
    grupos: 'conducta-main',
    listado: 'conducta-main',
    alumnos: 'conducta-main',
    conducta: 'conducta-main',
    'mis-registros': 'conducta-main',
    'asignacion-masiva': 'admin-main',
    nuevo: 'admin-main',
    importar: 'admin-main',
    cursos: 'admin-main',
    aulas: 'admin-main',
    facultades: 'admin-main',
    docentes: 'admin-main',
    'reportes-fecha': 'reportes-main',
    reportes: 'reportes-main',
    asistencia: 'asistencia-main',
    puntajes: 'puntajes-main',
  };

  function runScreen(screenId) {
    const map = {
      grupos: renderGrupos,
      listado: renderListado,
      alumnos: renderListado,
      conducta: renderConducta,
      'mis-registros': renderMisRegistros,
      'asignacion-masiva': renderAsignacionMasiva,
      nuevo: renderNuevoAlumno,
      importar: renderImportarAlumnos,
      cursos: renderCursosCrud,
      aulas: renderAulasCrud,
      facultades: renderFacultadesCarrerasCrud,
      docentes: renderDocentes,
      'reportes-fecha': renderReportesFecha,
      reportes: renderReportesFecha,
    };
    const fn = map[screenId];
    if (fn) fn();
  }

  function navigateScreen(screenId) {
    if (window.NC_AppState) window.NC_AppState.persistRoute({ screen: screenId });
    runScreen(screenId);
  }

  function resolveOpenTabRoute(id, opts) {
    const legacyScreen = SCREEN_TO_MAIN[id] ? id : null;
    const mainTab = legacyScreen ? SCREEN_TO_MAIN[id] : id;
    let screen = opts.screen;
    if (screen === undefined) {
      if (legacyScreen) screen = legacyScreen;
      else if (window.NC_AppState) {
        const st = window.NC_AppState.load();
        screen = st.mainTab === mainTab ? st.screen : null;
      } else {
        screen = null;
      }
    }
    return { mainTab, screen, legacyScreen };
  }

  // ---------- Tabs router
  async function openTab(id, opts = {}) {
    opts = opts || {};
    const route = resolveOpenTabRoute(id, opts);
    setActiveTab(route.mainTab);
    await loadBase(false);

    if (!opts.skipPersist && window.NC_AppState) {
      const patch = { mainTab: route.mainTab, screen: route.screen };
      if (route.mainTab === 'dashboard') patch.screen = null;
      if (opts.asistenciaSub !== undefined) patch.asistenciaSub = opts.asistenciaSub;
      if (opts.examenesSub !== undefined) patch.examenesSub = opts.examenesSub;
      window.NC_AppState.persistRoute(patch);
    }

    if (id === 'dashboard' || route.mainTab === 'dashboard') renderDashboard();

    if (id === 'conducta-main' || route.mainTab === 'conducta-main') {
      if (route.screen && runScreen) {
        runScreen(route.screen);
      } else {
        renderSectionHub(
          'Conducta',
          'Registro y seguimiento de conducta individual y grupal.',
          [
            { label: 'Ver grupos', onClick: () => navigateScreen('grupos') },
            { label: 'Registrar Conducta Individual', onClick: () => navigateScreen('listado') },
            { label: 'Registrar Conducta Grupal', onClick: () => navigateScreen('conducta') },
            { label: 'Mis Registros de Conducta', onClick: () => navigateScreen('mis-registros') },
          ]
        );
      }
    }

    if (id === 'asistencia-main' || id === 'asistencia') {
      const sub = opts.asistenciaSub !== undefined
        ? opts.asistenciaSub
        : (window.NC_AppState ? window.NC_AppState.load().asistenciaSub : null);
      if (window.NC_Asistencia && typeof window.NC_Asistencia.render === 'function') {
        await window.NC_Asistencia.render(view, { initialSub: sub });
      } else {
        view.innerHTML = '<div class="nc-card"><p>Módulo de asistencia no disponible. Compruebe que asistencia.js esté cargado.</p></div>';
      }
    }

    if (id === 'puntajes-main' || id === 'puntajes') {
      const sub = opts.examenesSub !== undefined
        ? opts.examenesSub
        : (window.NC_AppState ? window.NC_AppState.load().examenesSub : null);
      if (window.NC_Examenes && typeof window.NC_Examenes.render === 'function') {
        await window.NC_Examenes.render(view, { initialSub: sub });
      } else {
        view.innerHTML = '<div class="nc-card"><p>Módulo de puntajes/exámenes no disponible. Compruebe que examenes.js esté cargado.</p></div>';
      }
    }

    if (id === 'reportes-main' || route.mainTab === 'reportes-main') {
      if (route.screen === 'reportes-fecha' || route.screen === 'reportes') {
        renderReportesFecha();
      } else {
        renderSectionHub(
          'Reportes',
          'Reportes de conducta, asistencia, puntajes y reporte general integrado.',
          [
            { label: 'Reportes por fecha', onClick: () => navigateScreen('reportes-fecha'), visible: userPermissions.canViewReports() },
            { label: 'Reporte General (backend preparado)', onClick: () => navigateScreen('reportes-fecha'), visible: userPermissions.canViewReports() },
          ]
        );
      }
    }

    if (id === 'admin-main' || route.mainTab === 'admin-main') {
      if (route.screen && ['asignacion-masiva', 'nuevo', 'importar', 'cursos', 'aulas', 'facultades', 'docentes'].includes(route.screen)) {
        runScreen(route.screen);
      } else {
        renderSectionHub(
          'Administrador',
          'Gestión masiva, alumnos y catálogos académicos.',
          [
            { label: 'Asignación masiva', onClick: () => navigateScreen('asignacion-masiva'), visible: userPermissions.canManageStudents() },
            { label: 'Nuevo Alumno', onClick: () => navigateScreen('nuevo'), visible: userPermissions.canManageStudents() },
            { label: 'Importar Alumnos', onClick: () => navigateScreen('importar'), visible: userPermissions.canManageStudents() },
            { label: 'Cursos (CRUD)', onClick: () => navigateScreen('cursos'), visible: userPermissions.canManageCourses() },
            { label: 'Grupos (CRUD)', onClick: () => navigateScreen('aulas'), visible: userPermissions.canManageAulas() },
            { label: 'Facultades / Carreras', onClick: () => navigateScreen('facultades'), visible: userPermissions.canManageFacultades() },
            { label: 'Docentes', onClick: () => navigateScreen('docentes'), visible: userPermissions.canManageStudents() },
          ]
        );
      }
    }

    // Compatibilidad: rutas antiguas (ya cubiertas por route.screen vía resolveOpenTabRoute)
    if (route.legacyScreen && route.mainTab !== 'conducta-main' && route.mainTab !== 'admin-main' && route.mainTab !== 'reportes-main') {
      runScreen(route.legacyScreen);
    }
  }

  // ===================== DASHBOARD =====================
  function renderDashboard() {
    const today = new Date().toISOString().slice(0, 10);
    const from30 = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    view.innerHTML = '';
    const card = el('div', { class: 'nc-card' }, `
      <h3 style="margin:0 0 14px">Asistencia</h3>
      <div id="nc_dash_asistencia_loading" style="opacity:.7">Cargando...</div>
      <div id="nc_dash_asistencia_content" style="display:none">
        <div id="nc_dash_asistencia_stats" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px"></div>
        <h4 style="margin:16px 0 8px">Promedio por grupo aula (mes actual)</h4>
        <div id="nc_dash_asistencia_aulas" style="overflow:auto;margin-bottom:12px"></div>
        <a href="#" id="nc_dash_asistencia_ver_mas" class="nc-btn" style="display:inline-block;text-decoration:none;color:#fff;background:#2e7d32;padding:8px 16px;border-radius:6px;font-size:13px">Ver dashboard de asistencia completo</a>
      </div>
      <div id="nc_dash_asistencia_error" style="display:none;color:#888;font-size:13px">Módulo de asistencia no disponible.</div>

      <h3 style="margin:24px 0 14px">Resumen general</h3>
      <div class="nc-row nc-row-wrap" style="gap:8px;margin:0 0 12px">
        <button class="nc-btn secondary small" id="nc_dash_go_conducta">Ir a Conducta</button>
        <button class="nc-btn secondary small" id="nc_dash_go_asistencia">Ir a Asistencia</button>
        <button class="nc-btn secondary small" id="nc_dash_go_puntajes">Ir a Puntajes</button>
        <button class="nc-btn secondary small" id="nc_dash_go_reportes">Ir a Reportes</button>
        <button class="nc-btn secondary small" id="nc_dash_go_admin">Ir a Administrador</button>
      </div>
      <div id="nc_dash_stats" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px">
        <div style="opacity:.7">Cargando...</div>
      </div>
      <h4 style="margin:20px 0 10px">Por grupo</h4>
      <div id="nc_dash_aulas" style="overflow:auto">
        <div style="opacity:.7">Cargando...</div>
      </div>
      <h4 style="margin:24px 0 10px">Distribución de conducta por grupo (0=inaceptable, 5=excelente)</h4>
      <div class="nc-row" style="margin-bottom:12px">
        <div class="nc-field"><label>Desde</label><input id="nc_dash_from" type="date" value="${from30}" /></div>
        <div class="nc-field"><label>Hasta</label><input id="nc_dash_to" type="date" value="${today}" /></div>
        <button class="nc-btn" id="nc_dash_btn_chart">Actualizar gráficos</button>
      </div>
      <div id="nc_dash_charts"></div>
    `);
    view.appendChild(card);
    const dashGoConducta = document.getElementById('nc_dash_go_conducta');
    const dashGoAsistencia = document.getElementById('nc_dash_go_asistencia');
    const dashGoPuntajes = document.getElementById('nc_dash_go_puntajes');
    const dashGoReportes = document.getElementById('nc_dash_go_reportes');
    const dashGoAdmin = document.getElementById('nc_dash_go_admin');
    if (dashGoConducta) dashGoConducta.onclick = () => openTab('conducta-main');
    if (dashGoAsistencia) dashGoAsistencia.onclick = () => openTab('asistencia-main');
    if (dashGoPuntajes) dashGoPuntajes.onclick = () => openTab('puntajes-main');
    if (dashGoReportes) dashGoReportes.onclick = () => openTab('reportes-main');
    if (dashGoAdmin) dashGoAdmin.onclick = () => openTab('admin-main');
    if (!userPermissions.isAdmin() && dashGoPuntajes) dashGoPuntajes.style.display = 'none';
    if (!userPermissions.canViewReports() && dashGoReportes) dashGoReportes.style.display = 'none';
    if (!userPermissions.canManageStudents() && !userPermissions.canManageCourses() && !userPermissions.canManageAulas() && !userPermissions.canManageFacultades() && dashGoAdmin) dashGoAdmin.style.display = 'none';

    // ---------- Config. subgrupos: manejos básicos de UI ----------
    const tipoSel = document.getElementById('am_sgc_tipo');
    const cursoWrap = document.getElementById('am_sgc_curso_wrap');
    const carreraWrap = document.getElementById('am_sgc_carrera_wrap');
    const carreraSel = document.getElementById('am_sgc_carrera');

    if (tipoSel && cursoWrap && carreraWrap && carreraSel) {
      tipoSel.addEventListener('change', () => {
        const v = tipoSel.value || 'curso';
        if (v === 'curso') {
          cursoWrap.style.display = '';
          carreraWrap.style.display = 'none';
        } else {
          cursoWrap.style.display = 'none';
          carreraWrap.style.display = '';
          // Cargar todas las carreras si aún no se cargaron
          if (!carreraSel.dataset.loaded) {
            (async () => {
              try {
                const rows = await api('/carreras');
                let opts = '<option value=\"\">Seleccione carrera</option>';
                if (Array.isArray(rows)) {
                  opts += rows.map(c => `<option value=\"${c.id}\">${escapeHtml(c.nombre)}</option>`).join('');
                } else if (rows && Array.isArray(rows.items)) {
                  opts += rows.items.map(c => `<option value=\"${c.id}\">${escapeHtml(c.nombre)}</option>`).join('');
                }
                carreraSel.innerHTML = opts;
                carreraSel.dataset.loaded = '1';
              } catch (e) {
                toast('No se pudieron cargar las carreras.', 'err');
              }
            })();
          }
        }
      });
    }

    (async () => {
      try {
        const fromParam = document.getElementById('nc_dash_from')?.value || from30;
        const toParam = document.getElementById('nc_dash_to')?.value || today;
        const data = await api('/dashboard/stats?from=' + encodeURIComponent(fromParam) + '&to=' + encodeURIComponent(toParam));
        const stats = document.getElementById('nc_dash_stats');
        const alumnosEvaluados = data.alumnos_evaluados_mes ?? 0;
        stats.innerHTML = `
          <div class="nc-card" style="padding:14px;background:#f0f7ff;border:1px solid #cce;cursor:pointer;transition:all 0.2s;" id="nc_dash_alumnos_evaluados" onclick="if(window.openAlumnosEvaluadosModal) window.openAlumnosEvaluadosModal()" onmouseover="this.style.background='#e0efff';this.style.transform='scale(1.02)'" onmouseout="this.style.background='#f0f7ff';this.style.transform='scale(1)'">
            <div style="font-size:12px;color:#555">Alumnos evaluados (${new Date().toLocaleDateString('es-ES', {month: 'long', year: 'numeric'})})</div>
            <div style="font-size:24px;font-weight:700">${alumnosEvaluados}</div>
            <div style="font-size:11px;color:#888;margin-top:4px">Click para ver detalles</div>
          </div>
          <div class="nc-card" style="padding:14px;background:#f0fff0;border:1px solid #cec">
            <div style="font-size:12px;color:#555">Total aulas</div>
            <div style="font-size:24px;font-weight:700">${data.total_aulas ?? 0}</div>
          </div>
          <div class="nc-card" style="padding:14px;background:#fff8f0;border:1px solid #eec">
            <div style="font-size:12px;color:#555">Registros conducta</div>
            <div style="font-size:24px;font-weight:700">${data.total_registros_conducta ?? data.total_evaluaciones ?? 0}</div>
          </div>
        `;
        const aulasDiv = document.getElementById('nc_dash_aulas');
        const porAula = data.por_aula || [];
        if (!porAula.length) {
          aulasDiv.innerHTML = '<div style="opacity:.7">No hay datos por grupo.</div>';
        } else {
          const showAlumnosCol = userPermissions.isAdmin();
          aulasDiv.innerHTML = `
            <table style="width:100%">
              <thead><tr><th>Grupo</th>${showAlumnosCol ? '<th>Alumnos</th>' : ''}<th>Evaluaciones</th></tr></thead>
              <tbody>
                ${porAula.map(a => `
                  <tr>
                    <td>${escapeHtml(a.aula_nombre || '')}</td>${showAlumnosCol ? `<td>${a.alumnos ?? 0}</td>` : ''}<td>${a.evaluaciones ?? 0}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          `;
        }
        await loadDashboardCharts();
        (function () {
          const chartBtn = document.getElementById('nc_dash_btn_chart');
          if (chartBtn) chartBtn.onclick = withButtonLock(chartBtn, async () => {
            await loadDashboardCharts();
            const fromVal = document.getElementById('nc_dash_from')?.value || from30;
            const toVal = document.getElementById('nc_dash_to')?.value || today;
            const data2 = await api('/dashboard/stats?from=' + encodeURIComponent(fromVal) + '&to=' + encodeURIComponent(toVal));
            const porAula2 = data2.por_aula || [];
            const aulasDiv2 = document.getElementById('nc_dash_aulas');
            if (aulasDiv2 && porAula2.length) {
              const showAlumnosCol2 = userPermissions.isAdmin();
              aulasDiv2.innerHTML = `
              <table style="width:100%">
                <thead><tr><th>Grupo</th>${showAlumnosCol2 ? '<th>Alumnos</th>' : ''}<th>Evaluaciones</th></tr></thead>
                <tbody>
                  ${porAula2.map(a => `
                    <tr>
                      <td>${escapeHtml(a.aula_nombre || '')}</td>${showAlumnosCol2 ? `<td>${a.alumnos ?? 0}</td>` : ''}<td>${a.evaluaciones ?? 0}</td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            `;
            }
          }, { loadingText: 'Cargando...' });
        })();
      } catch (e) {
        const statsEl = document.getElementById('nc_dash_stats');
        const aulasEl = document.getElementById('nc_dash_aulas');
        if (statsEl) statsEl.innerHTML = '<div style="color:#b00">Error al cargar estadísticas.</div>';
        if (aulasEl) aulasEl.innerHTML = '';
      }
    })();

    (async function loadDashboardAsistencia() {
      const loading = document.getElementById('nc_dash_asistencia_loading');
      const content = document.getElementById('nc_dash_asistencia_content');
      const error = document.getElementById('nc_dash_asistencia_error');
      const mesActual = new Date().toISOString().slice(0, 7);
      try {
        const data = await api('/asistencia/dashboard?mes=' + encodeURIComponent(mesActual));
        if (!loading || !content || !error) return;
        loading.style.display = 'none';
        error.style.display = 'none';
        content.style.display = 'block';
        const pctActual = Number(data.promedio_actual ?? 0);
        const pctAnt = Number(data.promedio_anterior ?? 0);
        const diff = Math.round((pctActual - pctAnt) * 100) / 100;
        const fmt = (n) => Number(n).toFixed(2);
        document.getElementById('nc_dash_asistencia_stats').innerHTML = `
          <div class="nc-card" style="padding:14px;background:#e8f5e9;border:1px solid #a5d6a7">
            <div style="font-size:12px;color:#555">Promedio mes actual</div>
            <div style="font-size:22px;font-weight:700">${fmt(pctActual)}%</div>
          </div>
          <div class="nc-card" style="padding:14px;background:#fff3e0;border:1px solid #ffcc80">
            <div style="font-size:12px;color:#555">Promedio mes anterior</div>
            <div style="font-size:22px;font-weight:700">${fmt(pctAnt)}%</div>
          </div>
          <div class="nc-card" style="padding:14px;background:#e3f2fd;border:1px solid #90caf9">
            <div style="font-size:12px;color:#555">Variación</div>
            <div style="font-size:22px;font-weight:700">${diff >= 0 ? '+' : ''}${fmt(diff)}%</div>
          </div>
          <div class="nc-card" style="padding:14px;background:#fce4ec;border:1px solid #f48fb1">
            <div style="font-size:12px;color:#555">Sesiones mes actual</div>
            <div style="font-size:22px;font-weight:700">${data.sesiones_mes_actual ?? 0}</div>
          </div>
        `;
        const porAula = data.por_aula || [];
        const aulasDiv = document.getElementById('nc_dash_asistencia_aulas');
        const showPresentesCol = userPermissions.isAdmin();
        if (aulasDiv) {
          if (!porAula.length) {
            aulasDiv.innerHTML = '<p style="opacity:.7">No hay datos por grupo aula este mes.</p>';
          } else {
            aulasDiv.innerHTML = `
              <table style="width:100%">
                <thead><tr><th>Grupo Aula</th><th>Sesiones</th>${showPresentesCol ? '<th>Presentes / Total</th>' : ''}<th>Porcentaje</th></tr></thead>
                <tbody>
                  ${porAula.map(a => {
                    const pct = Number(a.porcentaje ?? 0);
                    const pctStr = Number.isFinite(pct) ? Number(pct).toFixed(2) : '0.00';
                    return `
                    <tr>
                      <td>${escapeHtml(a.aula_nombre || '')}</td>
                      <td>${a.sesiones ?? 0}</td>
                      ${showPresentesCol ? `<td>${a.total_presentes ?? 0} / ${a.total_registros ?? 0}</td>` : ''}
                      <td>${pctStr}%</td>
                    </tr>
                  `;}).join('')}
                </tbody>
              </table>
            `;
          }
        }
        const verMas = document.getElementById('nc_dash_asistencia_ver_mas');
        if (verMas) {
          verMas.onclick = (e) => { e.preventDefault(); if (typeof openTab === 'function') openTab('asistencia'); };
        }
      } catch (e) {
        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'none';
        if (error) error.style.display = 'block';
      }
    })();

    async function loadDashboardCharts() {
      const from = document.getElementById('nc_dash_from')?.value || from30;
      const to = document.getElementById('nc_dash_to')?.value || today;
      const cont = document.getElementById('nc_dash_charts');
      if (!cont) return;
      try {
        const res = await api('/dashboard/conducta-por-aula?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to));
        const porAula = res.por_aula || [];
        if (!porAula.length) {
          cont.innerHTML = '<div style="opacity:.7;padding:16px">No hay datos de conducta en el rango.</div>';
          return;
        }
        const colors = ['#b71c1c', '#f44336', '#ff9800', '#ffc107', '#66bb6a', '#2e7d32']; // 0=rojo (inaceptable), 5=verde (excelente)
        cont.innerHTML = porAula.map(a => {
          const dist = a.distribucion || { 0: 0, 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };
          const total = [0, 1, 2, 3, 4, 5].reduce((s, i) => s + (dist[i] || 0), 0);
          const maxVal = Math.max(1, ...Object.values(dist));
          const bars = [0, 1, 2, 3, 4, 5].map(i => {
            const n = dist[i] || 0;
            const pct = total ? (n / maxVal) * 100 : 0;
            return `<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
              <span style="width:24px;font-size:12px;font-weight:700">${i}</span>
              <div style="flex:1;height:24px;background:#eee;border-radius:6px;overflow:hidden">
                <div style="width:${pct}%;height:100%;background:${colors[i]};transition:width .3s"></div>
              </div>
              <span style="font-size:12px;min-width:32px">${n}</span>
            </div>`;
          }).join('');
          return `<div class="nc-card" style="margin-bottom:14px;padding:14px">
            <h5 style="margin:0 0 10px">${escapeHtml(a.aula_nombre || 'Grupo ' + a.aula_id)}</h5>
            ${bars}
            <div style="font-size:11px;color:#666;margin-top:6px">Total registros: ${total}</div>
          </div>`;
        }).join('');
      } catch (e) {
        cont.innerHTML = '<div style="color:#b00">Error al cargar gráficos.</div>';
      }
    }
  }

  // ===================== MODAL ALUMNOS EVALUADOS =====================
  let alumnosEvaluadosModalInstance = null;
  let alumnosEvaluadosLoadFn = null;

  window.openAlumnosEvaluadosModal = async function() {
    if (window._ncOpenAlumnosEvaluadosPending || alumnosEvaluadosModalInstance) return;
    window._ncOpenAlumnosEvaluadosPending = true;
    try {
    const body = el('div');
    body.innerHTML = `
      <div style="margin-bottom:16px">
        <div class="nc-row" style="margin-bottom:12px;flex-wrap:wrap;gap:10px">
          <div class="nc-field" style="min-width:200px">
            <label>Buscar (nombre, apellido o CI)</label>
            <input type="text" id="nc_ae_search" placeholder="Ej: García, 1234567" style="padding:8px;width:100%" />
          </div>
          <div class="nc-field">
            <label>Filtro</label>
            <select id="nc_ae_filter" style="min-width:150px">
              <option value="evaluados" selected>Solo evaluados</option>
              <option value="no_evaluados">Solo no evaluados</option>
              <option value="todos">Todos</option>
            </select>
          </div>
          <div class="nc-field">
            <label>Curso</label>
            <select id="nc_ae_curso">${cursosOptions(true)}</select>
          </div>
          <div class="nc-field">
            <label>Grupo</label>
            <select id="nc_ae_aula">${aulasOptions(true)}</select>
          </div>
          <button class="nc-btn" id="nc_ae_btn_buscar" style="align-self:flex-end">Buscar</button>
        </div>
        <div style="font-size:12px;color:#666;margin-top:8px">
          Mes actual: <b>${new Date().toLocaleDateString('es-ES', {month: 'long', year: 'numeric'})}</b>
        </div>
      </div>
      <div id="nc_ae_list" style="max-height:500px;overflow:auto;border:1px solid #ddd;border-radius:8px;padding:8px">
        <div style="opacity:.7;text-align:center;padding:20px">Cargando...</div>
      </div>
    `;

    const modal = openModal('Alumnos evaluados este mes', body, [
      { label: 'Cerrar', className: 'secondary', onClick: (m) => { alumnosEvaluadosModalInstance = null; alumnosEvaluadosLoadFn = null; m.close(); } }
    ]);
    
    alumnosEvaluadosModalInstance = modal;

    const loadAlumnos = async () => {
      const filter = document.getElementById('nc_ae_filter').value;
      const search = (document.getElementById('nc_ae_search') || {}).value || '';
      const curso_id = document.getElementById('nc_ae_curso').value || null;
      const aula_id = document.getElementById('nc_ae_aula').value || null;
      const qs = new URLSearchParams({ filter });
      if (search.trim()) qs.set('search', search.trim());
      if (curso_id) qs.set('curso_id', curso_id);
      if (aula_id) qs.set('aula_id', aula_id);
      
      const listEl = document.getElementById('nc_ae_list');
      listEl.innerHTML = '<div style="opacity:.7;text-align:center;padding:20px">Cargando...</div>';
      
      try {
        const res = await api('/dashboard/alumnos-evaluados?' + qs.toString());
        const alumnos = res.alumnos || [];
        
        if (alumnos.length === 0) {
          listEl.innerHTML = '<div style="opacity:.7;text-align:center;padding:20px">No hay alumnos que coincidan con los filtros.</div>';
          return;
        }

        const showTotalLine = userPermissions.isAdmin();
        listEl.innerHTML = `
          ${showTotalLine ? `<div style="margin-bottom:12px;padding:8px;background:#f5f5f5;border-radius:6px;font-size:12px">
            <strong>Total:</strong> ${alumnos.length} alumnos | 
            <span style="color:#2e7d32"><strong>Evaluados:</strong> ${res.total_evaluados ?? 0}</span> | 
            <span style="color:#d32f2f"><strong>No evaluados:</strong> ${res.total_no_evaluados ?? 0}</span>
          </div>` : ''}
          <table style="width:100%;border-collapse:collapse">
            <thead>
              <tr style="background:#f9f9f9;border-bottom:2px solid #ddd">
                <th style="padding:8px;text-align:left;font-size:12px">Estado</th>
                <th style="padding:8px;text-align:left;font-size:12px">Alumno</th>
                <th style="padding:8px;text-align:left;font-size:12px">CI</th>
                <th style="padding:8px;text-align:left;font-size:12px">Curso</th>
                <th style="padding:8px;text-align:left;font-size:12px">Grupo</th>
                <th style="padding:8px;text-align:center;font-size:12px">Acción</th>
              </tr>
            </thead>
            <tbody>
              ${alumnos.map(a => {
                const evaluado = a.evaluado_mes;
                const nombreCompleto = `${escapeHtml((a.apellidos || '') + ', ' + (a.nombres || ''))}`;
                return `
                  <tr style="border-bottom:1px solid #eee;${evaluado ? 'background:#e8f5e9;' : 'background:#fff3e0;'}" class="nc-ae-row" data-alumno-id="${a.id}">
                    <td style="padding:8px">
                      <span class="nc-pill" style="padding:4px 8px;font-size:11px;background:${evaluado ? '#4caf50' : '#ff9800'};color:#fff">
                        ${evaluado ? '✓ Evaluado' : '○ No evaluado'}
                      </span>
                    </td>
                    <td style="padding:8px;font-weight:${evaluado ? 'normal' : '600'}">${nombreCompleto}</td>
                    <td style="padding:8px">${escapeHtml(a.ci || '')}</td>
                    <td style="padding:8px">${escapeHtml(a.curso_nombre || '')}</td>
                    <td style="padding:8px">${escapeHtml(a.aula_nombre || '')}</td>
                    <td style="padding:8px;text-align:center">
                      ${!evaluado ? `<button class="nc-btn" style="padding:4px 12px;font-size:12px" onclick="registrarConductaDesdeModal(${a.id}, '${escapeHtml(nombreCompleto)}', ${a.curso_id || 'null'}, ${a.aula_id || 'null'})">Registrar</button>` : '<span style="color:#666;font-size:11px">Ya evaluado</span>'}
                    </td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        `;
      } catch (e) {
        listEl.innerHTML = `<div style="color:#b00;text-align:center;padding:20px">Error: ${escapeHtml(e.message)}</div>`;
      }
    };

    (function () {
      const btn = document.getElementById('nc_ae_btn_buscar');
      if (btn) btn.onclick = withButtonLock(btn, loadAlumnos, { loadingText: 'Buscando...' });
    })();
    const ncAeSearch = document.getElementById('nc_ae_search');
    if (ncAeSearch) ncAeSearch.addEventListener('keypress', (e) => { if (e.key === 'Enter') loadAlumnos(); });
    document.getElementById('nc_ae_filter').onchange = loadAlumnos;
    document.getElementById('nc_ae_curso').onchange = loadAlumnos;
    document.getElementById('nc_ae_aula').onchange = loadAlumnos;
    
    alumnosEvaluadosLoadFn = loadAlumnos;
    await loadAlumnos();
    } finally {
      window._ncOpenAlumnosEvaluadosPending = false;
    }
  }

  // Función auxiliar para registrar conducta desde el modal (protegida contra doble clic)
  window.registrarConductaDesdeModal = async function(alumnoId, nombreCompleto, cursoId, aulaId) {
    if (window._ncRegistrarConductaPending) return;
    window._ncRegistrarConductaPending = true;
    try {
    // Parsear nombre completo
    const partes = nombreCompleto.split(', ');
    const apellidos = partes[0] || '';
    const nombres = partes[1] || '';
    
    // Crear objeto alumno con los datos disponibles
    const alumno = {
      id: alumnoId,
      nombres: nombres,
      apellidos: apellidos,
      nombre: nombres,
      apellido: apellidos,
      curso_id: cursoId || null,
      aula_id: aulaId || null
    };
    
    // Guardar referencia al modal de alumnos evaluados antes de cerrarlo
    const modalAE = alumnosEvaluadosModalInstance;
    const reloadAE = alumnosEvaluadosLoadFn;
    
    // Cerrar el modal de alumnos evaluados temporalmente
    if (modalAE) {
      modalAE.close();
      alumnosEvaluadosModalInstance = null;
      alumnosEvaluadosLoadFn = null;
    }
    
    // Abrir el modal de registrar conducta
    const conductaModal = openConductaAlumnoModal(alumno);
    
    // Interceptar el cierre del modal de conducta para recargar el de alumnos evaluados
    const originalClose = conductaModal?.close;
    if (conductaModal && reloadAE) {
      conductaModal.close = function() {
        if (originalClose) originalClose.call(this);
        // Después de cerrar, reabrir y recargar el modal de alumnos evaluados
        setTimeout(() => {
          if (window.openAlumnosEvaluadosModal) {
            window.openAlumnosEvaluadosModal().then(() => {
              setTimeout(() => {
                if (alumnosEvaluadosLoadFn) alumnosEvaluadosLoadFn();
              }, 100);
            });
          }
        }, 300);
      };
    }
    } finally {
      window._ncRegistrarConductaPending = false;
    }
  };

  // ===================== VER GRUPOS (listado con filtros por aula, facultad, carrera) =====================
  // ===================== VER GRUPOS (con paginación y selección múltiple) =====================
  function renderGrupos() {
    if (window.NC_AppState) window.NC_AppState.persistRoute({ mainTab: 'conducta-main', screen: 'grupos' });
    view.innerHTML = '';
    const card = el('div', { class: 'nc-card' });
    card.innerHTML = `
      <h3 style="margin:0 0 10px">Ver grupos — estudiantes por grupo o búsqueda general</h3>
      <div class="nc-row">
        <div class="nc-field">
          <label>Buscar (nombre o CI)</label>
          <input id="g_search" placeholder="Ej: Juan / 1234567" />
        </div>
        <div class="nc-field">
          <label>Curso</label>
          <select id="g_curso">${cursosOptions(true)}</select>
        </div>
        <div class="nc-field">
          <label>Grupo</label>
          <select id="g_aula">${aulasOptions(true)}</select>
        </div>
        <div class="nc-field">
          <label>Facultad</label>
          <select id="g_facultad">${facultadesOptions(true)}</select>
        </div>
        <div class="nc-field">
          <label>Carrera</label>
          <select id="g_carrera"><option value="">Todas</option></select>
        </div>
        <div class="nc-field">
          <label>Ordenar</label>
          <select id="g_sortby">
            <option value="apellidos" selected>Apellido</option>
            <option value="nombres">Nombre</option>
            <option value="ci">CI</option>
          </select>
        </div>
        <button class="nc-btn" id="g_btn_buscar">Buscar</button>
        <button class="nc-btn secondary" id="g_btn_limpiar">Limpiar</button>
      </div>
      
      <div id="g_bulk_actions" style="display:none"></div>
      
      <div style="overflow:auto;margin-top:14px">
        <table>
          <thead>
            <tr id="g_thead_row">
              <th>Nombre</th><th>CI</th><th>Grupo</th><th>Curso</th><th>Carrera</th><th>Facultad</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody id="g_tbody">
            <tr><td colspan="7" style="color:#666">Usá filtros y presioná Buscar.</td></tr>
          </tbody>
        </table>
      </div>
      
      <div id="g_pagination"></div>
    `;
    view.appendChild(card);

    // Sin paginación: mostrar todos los registros
    const gruposPaginator = new Paginator({
      itemsPerPage: 99999,
      onPageChange: () => renderGruposTable()
    });

    const gruposSelector = new BulkSelector({
      onSelectionChange: () => {
        gruposSelector.renderActionBar('g_bulk_actions', [
          {
            label: 'Eliminar seleccionados',
            className: 'nc-btn danger small',
            onClick: async (ids) => {
              try {
                setLoading(true);
                await bulkDeleteAlumnos(ids);
                toast('Alumnos eliminados correctamente.');
                gruposSelector.clear();
                runGruposSearch();
              } catch (error) {
                toast(error.message, 'err');
              } finally {
                setLoading(false);
              }
            }
          }
        ]);
      }
    });

    let currentRows = [];

    const selFac = document.getElementById('g_facultad');
    const selCar = document.getElementById('g_carrera');
    selFac.onchange = async () => {
      const fid = selFac.value;
      selCar.innerHTML = '<option value="">Todas</option>';
      if (!fid) return;
      const cars = await loadCarreras(fid);
      selCar.innerHTML = '<option value="">Todas</option>' + (cars.map(c => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`).join(''));
    };

    function renderGruposTable() {
      const tbody = document.getElementById('g_tbody');
      const theadRow = document.getElementById('g_thead_row');
      
      if (!currentRows || currentRows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="color:#666">No hay alumnos con esos filtros.</td></tr>';
        gruposPaginator.renderControls('g_pagination');
        return;
      }

      const pageItems = gruposPaginator.getCurrentPageItems();
      const allPageIds = pageItems.map(r => r.id);
      const byId = {};
      pageItems.forEach(r => { byId[String(r.id)] = r; });

      // Encabezado de la tabla
      theadRow.innerHTML = `
        <th>Nombre</th><th>CI</th><th>Grupo</th><th>Curso</th><th>Carrera</th><th>Facultad</th><th>Acciones</th>
      `;

      tbody.innerHTML = pageItems.map(r => {
        return `
          <tr>
            <td>
              <button class="nc-link" data-act="view" data-id="${r.id}">${escapeHtml((r.nombres || r.nombre || '') + ' ' + (r.apellidos || r.apellido || ''))}</button>
            </td>
            <td>${escapeHtml(r.ci || '')}</td>
            <td>${escapeHtml(r.aula_nombre || '')}</td>
            <td>${escapeHtml(r.curso_nombre || '')}</td>
            <td>${escapeHtml(r.carrera_nombre || '')}</td>
            <td>${escapeHtml(r.facultad_nombre || '')}</td>
            <td>
              ${userPermissions.canManageStudents() ? `
                <button class="nc-btn secondary" data-act="edit" data-id="${r.id}" style="padding:6px 8px">Editar</button>
                <button class="nc-btn danger" data-act="del" data-id="${r.id}" style="padding:6px 8px;margin-left:4px">Eliminar</button>
              ` : ''}
            </td>
          </tr>
        `;
      }).join('');


      // Event listeners para acciones
      tbody.onclick = async (ev) => {
        const btn = ev.target.closest('button[data-act]');
        if (!btn) return;
        const id = btn.getAttribute('data-id');
        const act = btn.getAttribute('data-act');
        const row = byId[String(id)];
        if (!row) return;
        if (act === 'view') {
          const idx = currentRows.findIndex(r => String(r.id) === String(id));
          openAlumnoViewModal(row, (currentRows.length > 1 && idx >= 0) ? { list: currentRows, index: idx } : null);
          return;
        }
        if (act === 'edit') { openAlumnoEditModal(row, runGruposSearch); return; }
        if (act === 'del') {
          if (!confirm('¿Eliminar a ' + (row.nombres || '') + ' ' + (row.apellidos || '') + '?')) return;
          setLoading(true);
          try {
            await api('/alumnos/' + id, { method: 'DELETE' });
            toast('Alumno eliminado.');
            runGruposSearch();
          } catch (e) { toast('ERROR: ' + e.message, 'err'); }
          finally { setLoading(false); }
        }
      };

      gruposPaginator.renderControls('g_pagination');
    }

    async function runGruposSearch() {
      const search = document.getElementById('g_search').value.trim();
      const curso_id = document.getElementById('g_curso').value;
      const aula_id = document.getElementById('g_aula').value;
      const facultad_id = document.getElementById('g_facultad').value;
      const carrera_id = document.getElementById('g_carrera').value;
      const sort_by = document.getElementById('g_sortby').value;
      const qs = new URLSearchParams();
      if (search) qs.set('search', search);
      if (curso_id) qs.set('curso_id', curso_id);
      if (aula_id) qs.set('aula_id', aula_id);
      if (facultad_id) qs.set('facultad_id', facultad_id);
      if (carrera_id) qs.set('carrera_id', carrera_id);
      qs.set('order_by', sort_by);
      qs.set('order', 'ASC');
      setLoading(true);
      try {
        const rows = await api('/alumnos' + (qs.toString() ? '?' + qs.toString() : ''));
        currentRows = rows;
        // Limpiar selección antes de establecer nuevos items
        gruposSelector.clear();
        gruposPaginator.setItems(rows);
        // Asegurar que el renderizado refleje el estado limpio
        renderGruposTable();
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    (function () {
      const btn = document.getElementById('g_btn_buscar');
      if (btn) btn.onclick = withButtonLock(btn, runGruposSearch, { loadingText: 'Buscando...' });
    })();
    document.getElementById('g_search').addEventListener('keypress', (e) => {
      if (e.key === 'Enter') runGruposSearch();
    });
    document.getElementById('g_btn_limpiar').onclick = () => {
      document.getElementById('g_search').value = '';
      document.getElementById('g_curso').value = '';
      document.getElementById('g_aula').value = '';
      document.getElementById('g_facultad').value = '';
      document.getElementById('g_carrera').innerHTML = '<option value="">Todas</option>';
      runGruposSearch();
    };
  }

  // ===================== ASIGNACIÓN MASIVA (subgrupos y materias CASS) =====================
  function renderAsignacionMasiva() {
    view.innerHTML = '';
    const card = el('div', { class: 'nc-card' });
    card.innerHTML = `
      <h3 style="margin:0 0 6px">Asignación masiva</h3>
      <p style="margin:0 0 20px;color:#666;font-size:14px">Configurar materias por curso (auto-inscripción) e inscribir estudiantes en materias (casos especiales). También podés agregar alumnos a grupos.</p>
      <div class="nc-tabs" style="margin-bottom:16px">
        <button type="button" class="nc-tab" data-am-sub="curso-materias" data-active="1">Materias por curso</button>
        <button type="button" class="nc-tab" data-am-sub="subgrupos" data-active="0">Agregar a grupos</button>
        ${''}
        <button type="button" class="nc-tab" data-am-sub="materias" data-active="0">Inscribir en Materias</button>
        <button type="button" class="nc-tab" data-am-sub="materias-grupo" data-active="0">Materias por grupo</button>
        ${userPermissions.isAdmin() ? '<button type="button" class="nc-tab" data-am-sub="cursos" data-active="0">Asignar cursos</button>' : ''}
      </div>
      <div id="am_curso_materias_panel" class="nc-card" style="margin-top:0;padding:16px">
        <h4 style="margin:0 0 12px">Materias por curso</h4>
        <p style="margin:0 0 16px;color:#666;font-size:13px">Asigná las materias que corresponden a cada curso. Los alumnos que registres o edites con ese curso quedarán inscritos automáticamente en esas materias.</p>
        <div class="nc-row" style="flex-wrap:wrap;gap:12px;margin-bottom:16px">
          <div class="nc-field"><label>Curso</label><select id="am_cm_curso">${cursosOptions(true)}</select></div>
          <button class="nc-btn" id="am_cm_cargar" style="align-self:flex-end">Cargar materias</button>
        </div>
        <div id="am_cm_materias_wrap" style="display:none;margin-bottom:16px">
          <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px">
            <span style="font-weight:600;width:100%">Materias del curso:</span>
            <div id="am_cm_materias" style="display:flex;flex-wrap:wrap;gap:8px"></div>
          </div>
          <div class="nc-row" style="gap:8px;flex-wrap:wrap">
            <button class="nc-btn" id="am_cm_guardar">Guardar materias</button>
            <button class="nc-btn secondary" id="am_cm_aplicar">Aplicar a alumnos existentes</button>
          </div>
        </div>
        <p id="am_cm_msg" style="margin:12px 0 0;color:#666;font-size:13px">Seleccioná un curso y presioná Cargar materias para ver o editar las materias asignadas.</p>
      </div>
      <div id="am_subgrupos_panel" class="nc-card" style="margin-top:0;padding:16px;display:none">
        <h4 style="margin:0 0 12px">Agregar alumnos a un grupo (sin quitar otros)</h4>
        <div class="nc-row" style="flex-wrap:wrap;gap:12px;margin-bottom:12px">
          <div class="nc-field"><label>Grupo destino</label><select id="am_sg_aula">${aulasOptions(true)}</select></div>
          <button class="nc-btn" id="am_sg_cargar" style="align-self:flex-end">Cargar alumnos</button>
        </div>
        <div class="nc-row" style="flex-wrap:wrap;gap:12px;margin-bottom:8px">
          <div class="nc-field" style="flex:1;min-width:220px">
            <label>Buscar (nombre o CI)</label>
            <input id="am_sg_search" placeholder="Ej: Juan / 1234567" />
          </div>
        </div>
        <div id="am_sg_bulk" style="display:none;margin-bottom:12px"></div>
        <div style="overflow:auto;max-height:320px">
          <table><thead><tr><th><input type="checkbox" id="am_sg_select_all" title="Seleccionar todos" /></th><th>Nombre</th><th>CI</th><th>Grupo (actual)</th><th>Curso</th><th>Carrera</th><th>Facultad</th></tr></thead><tbody id="am_sg_tbody"></tbody></table>
        </div>
        <p id="am_sg_msg" style="margin:12px 0 0;color:#666;font-size:13px">Elegí el grupo destino, escribí el criterio de búsqueda y presioná Cargar alumnos.</p>
      </div>
      ${userPermissions.isAdmin() ? `
      <div id="am_subgrupos_config_panel" class="nc-card" style="margin-top:0;padding:16px;display:none">
        <h4 style="margin:0 0 12px">Configuración de subgrupos (por curso o por carrera)</h4>
        <p style="margin:0 0 16px;color:#666;font-size:13px">Definí los subgrupos que se usarán en Marcar asistencia. Podés crear una configuración para un curso entero o para una carrera. Ej: 1,2,3 o A,B,C.</p>
        <div class="nc-row" style="flex-wrap:wrap;gap:12px;margin-bottom:16px">
          <div class="nc-field"><label>Tipo</label><select id="am_sgc_tipo"><option value="curso">Por curso</option><option value="carrera">Por carrera</option></select></div>
          <div class="nc-field" id="am_sgc_facultad_wrap"><label>Facultad</label><select id="am_sgc_facultad">${facultadesOptions(true)}</select></div>
          <div class="nc-field" id="am_sgc_curso_wrap"><label>Curso</label><select id="am_sgc_curso"><option value="">Seleccione curso</option></select></div>
          <div class="nc-field" id="am_sgc_carrera_wrap" style="display:none"><label>Carrera</label><select id="am_sgc_carrera"><option value="">Seleccione carrera</option></select></div>
          <div class="nc-field"><label>Subgrupos (ej: 1,2,3 o A,B,C)</label><input type="text" id="am_sgc_subgrupos" placeholder="1,2,3" /></div>
          <button class="nc-btn" id="am_sgc_crear" style="align-self:flex-end">Crear</button>
        </div>
        <div style="overflow:auto;max-height:280px">
          <table><thead><tr><th>Tipo</th><th>Curso / Carrera</th><th>Subgrupos</th><th></th></tr></thead><tbody id="am_sgc_tbody"></tbody></table>
        </div>
      </div>
      ` : ''}
      ${userPermissions.isAdmin() ? `
      <div id="am_cursos_panel" class="nc-card" style="margin-top:0;padding:16px;display:none">
        <h4 style="margin:0 0 12px">Asignar cursos a estudiantes</h4>
        <p style="margin:0 0 12px;color:#666;font-size:13px">Seleccioná un curso destino y aplicalo a varios estudiantes a la vez. Si necesitás cambiar facultad o carrera, usá las opciones de destino y el botón separado.</p>
        <div class="nc-row" style="flex-wrap:wrap;gap:12px;margin-bottom:12px">
          <div class="nc-field">
            <label>Curso destino</label>
            <select id="am_cur_curso">${cursosOptions(false)}</select>
          </div>
          <div class="nc-field">
            <label>Grupo (opcional)</label>
            <select id="am_cur_grupo">${aulasOptions(true)}</select>
          </div>
          <div class="nc-field">
            <label>Facultad (opcional)</label>
            <select id="am_cur_facultad">${facultadesOptions(true)}</select>
          </div>
          <button class="nc-btn" id="am_cur_cargar" style="align-self:flex-end">Cargar alumnos</button>
        </div>
        <div class="nc-row" style="flex-wrap:wrap;gap:12px;margin-bottom:12px">
          <div class="nc-field">
            <label>Facultad destino (opcional)</label>
            <select id="am_cur_facultad_dest">${facultadesOptions(true)}</select>
          </div>
          <div class="nc-field">
            <label>Carrera destino (opcional)</label>
            <select id="am_cur_carrera_dest"><option value="">Seleccione carrera</option></select>
          </div>
        </div>
        <div class="nc-row" style="flex-wrap:wrap;gap:12px;margin-bottom:8px">
          <div class="nc-field" style="flex:1;min-width:220px">
            <label>Buscar (nombre o CI)</label>
            <input id="am_cur_search" placeholder="Ej: Juan / 1234567" />
          </div>
        </div>
        <div id="am_cur_bulk" style="display:none;margin-bottom:12px"></div>
        <div style="overflow:auto;max-height:320px">
          <table><thead><tr><th><input type="checkbox" id="am_cur_select_all" title="Seleccionar todos" /></th><th>Nombre</th><th>CI</th><th>Grupo</th><th>Curso actual</th><th>Carrera</th><th>Facultad</th></tr></thead><tbody id="am_cur_tbody"></tbody></table>
        </div>
        <p id="am_cur_msg" style="margin:12px 0 0;color:#666;font-size:13px">Seleccioná curso destino y filtros, luego Cargar alumnos.</p>
      </div>
      ` : ''}
      <div id="am_materias_panel" class="nc-card" style="margin-top:0;padding:16px;display:none">
        <h4 style="margin:0 0 12px">Inscribir o quitar estudiantes de una materia</h4>
        <div class="nc-row" style="flex-wrap:wrap;gap:12px;margin-bottom:12px">
          <div class="nc-field"><label>Materia</label><select id="am_mat_materia"><option value="">Seleccione materia</option></select></div>
          <div class="nc-field"><label>Grupo (opcional)</label><select id="am_mat_aula">${aulasOptions(true)}</select></div>
          <button class="nc-btn" id="am_mat_cargar" style="align-self:flex-end">Cargar alumnos</button>
        </div>
        <div class="nc-row" style="flex-wrap:wrap;gap:12px;margin-bottom:8px">
          <div class="nc-field" style="flex:1;min-width:220px">
            <label>Buscar (nombre o CI)</label>
            <input id="am_mat_search" placeholder="Ej: Juan / 1234567" />
          </div>
        </div>
        <div id="am_mat_bulk" style="display:none;margin-bottom:12px"></div>
        <div style="overflow:auto;max-height:320px">
          <table><thead><tr><th><input type="checkbox" id="am_mat_select_all" title="Seleccionar todos" /></th><th>Nombre</th><th>CI</th><th>Grupo</th><th>Curso</th><th>Carrera</th><th>Facultad</th><th>Inscrito en esta materia</th></tr></thead><tbody id="am_mat_tbody"></tbody></table>
        </div>
        <p id="am_mat_msg" style="margin:12px 0 0;color:#666;font-size:13px">Seleccioná materia y presioná Cargar alumnos. Marcá a quienes querés inscribir o quitar.</p>
      </div>

      <div id="am_materias_grupo_panel" class="nc-card" style="margin-top:0;padding:16px;display:none">
        <h4 style="margin:0 0 12px">Materias por grupo</h4>
        <p style="margin:0 0 16px;color:#666;font-size:13px">
          Configurá las materias de un grupo completo. Podés ver qué materias ya tiene ese grupo y guardar cambios.
        </p>
        <div class="nc-row" style="flex-wrap:wrap;gap:12px;margin-bottom:16px">
          <div class="nc-field"><label>Grupo</label><select id="am_mg_aula">${aulasOptions(true)}</select></div>
          <button class="nc-btn" id="am_mg_cargar" style="align-self:flex-end" type="button">Cargar materias</button>
        </div>
        <div id="am_mg_materias_wrap" style="display:none;margin-bottom:16px">
          <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px">
            <span style="font-weight:600;width:100%">Materias del grupo:</span>
            <div id="am_mg_materias" style="display:flex;flex-wrap:wrap;gap:8px"></div>
          </div>
          <div class="nc-row" style="gap:8px;flex-wrap:wrap">
            <button class="nc-btn" id="am_mg_guardar" type="button">Guardar materias</button>
          </div>
        </div>
        <p id="am_mg_msg" style="margin:12px 0 0;color:#666;font-size:13px">Seleccioná un grupo y presioná Cargar materias para ver o editar sus materias inscritas.</p>
      </div>
    `;
    view.appendChild(card);

    // ---------- Config. subgrupos: manejos básicos de UI ----------
    const tipoSel = document.getElementById('am_sgc_tipo');
    const cursoWrap = document.getElementById('am_sgc_curso_wrap');
    const carreraWrap = document.getElementById('am_sgc_carrera_wrap');
    const carreraSel = document.getElementById('am_sgc_carrera');
    const facWrap = document.getElementById('am_sgc_facultad_wrap');
    const facSel = document.getElementById('am_sgc_facultad');

    async function loadAllCarrerasIntoSelect(selectEl) {
      try {
        const rows = await api('/carreras');
        let opts = '<option value="">Seleccione carrera</option>';
        if (Array.isArray(rows)) {
          opts += rows.map(c => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`).join('');
        } else if (rows && Array.isArray(rows.items)) {
          opts += rows.items.map(c => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`).join('');
        }
        selectEl.innerHTML = opts;
        selectEl.dataset.loaded = '1';
      } catch (e) {
        toast('No se pudieron cargar las carreras.', 'err');
      }
    }

    function rebuildAmSgcCursoOptions() {
      if (!facSel) return;
      const fid = Number(facSel.value || 0);
      const sel = document.getElementById('am_sgc_curso');
      if (!sel) return;
      let opts = '<option value="">Seleccione curso</option>';
      for (const c of cursos) {
        const cFac = c.facultad_id != null ? Number(c.facultad_id) : 0;
        // Si la facultad está seleccionada, filtramos SOLO cuando el curso ya tiene facultad asignada diferente.
        // Los cursos sin facultad quedan visibles para poder configurarlos.
        if (fid && cFac && cFac !== fid) continue;
        opts += `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`;
      }
      sel.innerHTML = opts;
    }

    if (facSel) {
      facSel.addEventListener('change', () => {
        rebuildAmSgcCursoOptions();
      });
    }

    if (tipoSel && cursoWrap && carreraWrap && carreraSel && facWrap && facSel) {
      tipoSel.addEventListener('change', () => {
        const v = tipoSel.value || 'curso';
        if (v === 'curso') {
          facWrap.style.display = '';
          cursoWrap.style.display = '';
          carreraWrap.style.display = 'none';
          rebuildAmSgcCursoOptions();
        } else {
          facWrap.style.display = 'none';
          cursoWrap.style.display = 'none';
          carreraWrap.style.display = '';
          if (!carreraSel.dataset.loaded) {
            loadAllCarrerasIntoSelect(carreraSel);
          }
        }
      });
      // estado inicial
      if (tipoSel.value === 'carrera') {
        facWrap.style.display = 'none';
        cursoWrap.style.display = 'none';
        carreraWrap.style.display = '';
        if (!carreraSel.dataset.loaded) {
          loadAllCarrerasIntoSelect(carreraSel);
        }
      } else {
        facWrap.style.display = '';
        cursoWrap.style.display = '';
        carreraWrap.style.display = 'none';
        rebuildAmSgcCursoOptions();
      }
    }

    // --------- Config. subgrupos: CRUD básico ----------
    const amSgcTbody = document.getElementById('am_sgc_tbody');
    const amSgcCrearBtn = document.getElementById('am_sgc_crear');
    const amSgcSubInput = document.getElementById('am_sgc_subgrupos');
    const amSgcCursoSel = document.getElementById('am_sgc_curso');

    async function loadSubgruposConfigTable() {
      if (!amSgcTbody) return;
      try {
        const rows = await api('/subgrupos-config');
        const list = Array.isArray(rows) ? rows : (rows && Array.isArray(rows.items) ? rows.items : []);
        if (!list.length) {
          amSgcTbody.innerHTML = '<tr><td colspan="3" style="color:#666">Todavía no hay configuraciones de subgrupos.</td></tr>';
          return;
        }
        amSgcTbody.innerHTML = list.map(r => {
          const tipoLabel = r.tipo === 'carrera' ? 'Por carrera' : 'Por curso';
          const nombreConf = r.tipo === 'carrera'
            ? (r.carrera_nombre || `Carrera #${r.carrera_id}`)
            : (r.curso_nombre || `Curso #${r.curso_id}`);
          const safeId = Number(r.id);
          return `<tr>
            <td>${escapeHtml(tipoLabel)}</td>
            <td>${escapeHtml(nombreConf || '')}</td>
            <td>${escapeHtml(r.subgrupos || '')}</td>
            <td><button class="nc-btn secondary" data-sg-del="${safeId}">Eliminar</button></td>
          </tr>`;
        }).join('');

        amSgcTbody.querySelectorAll('[data-sg-del]').forEach(btn => {
          btn.addEventListener('click', async () => {
            const id = Number(btn.getAttribute('data-sg-del'));
            if (!id) return;
            if (!confirm('¿Eliminar esta configuración de subgrupos?')) return;
            try {
              await api(`/subgrupos-config/${id}`, { method: 'DELETE' });
              toast('Configuración eliminada.');
              await loadSubgruposConfigTable();
            } catch (e) {
              toast('No se pudo eliminar la configuración.', 'err');
            }
          });
        });
      } catch (e) {
        amSgcTbody.innerHTML = '<tr><td colspan="3" style="color:#a00">Error al cargar las configuraciones.</td></tr>';
      }
    }

    if (amSgcCrearBtn && tipoSel && amSgcSubInput && amSgcCursoSel && carreraSel && facSel) {
      amSgcCrearBtn.addEventListener('click', async () => {
        const tipo = tipoSel.value || 'curso';
        const sub = (amSgcSubInput.value || '').trim();
        if (!sub) {
          toast('Ingresá la lista de subgrupos (ej: 1,2,3 o A,B,C).', 'err');
          return;
        }
        let payload = { tipo, subgrupos: sub };
        if (tipo === 'curso') {
          const fid = facSel.value;
          const cid = amSgcCursoSel.value;
          if (!fid) {
            toast('Seleccioná una facultad.', 'err');
            return;
          }
          if (!cid) {
            toast('Seleccioná un curso.', 'err');
            return;
          }
          payload.curso_id = Number(cid);
        } else {
          const carid = carreraSel.value;
          if (!carid) {
            toast('Seleccioná una carrera.', 'err');
            return;
          }
          payload.carrera_id = Number(carid);
        }
        try {
          await api('/subgrupos-config', {
            method: 'POST',
            body: JSON.stringify(payload),
          });
          toast('Configuración de subgrupos creada.');
          amSgcSubInput.value = '';
          await loadSubgruposConfigTable();
        } catch (e) {
          toast('No se pudo crear la configuración.', 'err');
        }
      });
      // cargar tabla al entrar si el panel existe
      loadSubgruposConfigTable();
    }

    let amMateriasList = [];
    (async () => {
      try {
        const res = await api('/materias?activo=1');
        amMateriasList = (res && res.items) ? res.items : [];
        const sel = document.getElementById('am_mat_materia');
        if (sel) sel.innerHTML = '<option value="">Seleccione materia</option>' + (amMateriasList.map(m => `<option value="${m.id}">${escapeHtml(m.nombre)}</option>`).join(''));
      } catch (_) {}
    })();

    card.querySelectorAll('[data-am-sub]').forEach(btn => {
      btn.onclick = () => {
        const sub = btn.getAttribute('data-am-sub');
        card.querySelectorAll('[data-am-sub]').forEach(b => b.dataset.active = (b.getAttribute('data-am-sub') === sub ? '1' : '0'));
        document.getElementById('am_curso_materias_panel').style.display = sub === 'curso-materias' ? 'block' : 'none';
        document.getElementById('am_subgrupos_panel').style.display = sub === 'subgrupos' ? 'block' : 'none';
        const cfgPanel = document.getElementById('am_subgrupos_config_panel');
        if (cfgPanel) cfgPanel.style.display = sub === 'subgrupos-config' ? 'block' : 'none';
        document.getElementById('am_materias_panel').style.display = sub === 'materias' ? 'block' : 'none';
        const mgPanel = document.getElementById('am_materias_grupo_panel');
        if (mgPanel) mgPanel.style.display = sub === 'materias-grupo' ? 'block' : 'none';
        const curPanel = document.getElementById('am_cursos_panel');
        if (curPanel) curPanel.style.display = sub === 'cursos' ? 'block' : 'none';
      };
    });

    let amCmMateriasList = [];
    let amCmMateriaIds = [];
    async function loadAmCmMaterias() {
      const cursoId = document.getElementById('am_cm_curso').value;
      if (!cursoId) return;
      try {
        const [matRes, curRes] = await Promise.all([
          api('/materias?activo=1'),
          api('/asistencia/cursos/' + cursoId + '/materias')
        ]);
        amCmMateriasList = (matRes && matRes.items) ? matRes.items : [];
        amCmMateriaIds = (curRes && curRes.materia_ids) ? curRes.materia_ids : [];
        const wrap = document.getElementById('am_cm_materias_wrap');
        const div = document.getElementById('am_cm_materias');
        if (!div) return;
        div.innerHTML = amCmMateriasList.map(m => {
          const checked = amCmMateriaIds.indexOf(m.id) !== -1;
          return `<label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" class="am_cm_cb" data-materia-id="${m.id}" ${checked ? 'checked' : ''} />${escapeHtml(m.nombre)}</label>`;
        }).join('');
        wrap.style.display = 'block';
      } catch (e) {
        toast('Error: ' + (e.message || 'No se pudo cargar'), 'err');
      }
    }
    document.getElementById('am_cm_cargar').onclick = () => loadAmCmMaterias();
    document.getElementById('am_cm_guardar').onclick = async () => {
      const cursoId = document.getElementById('am_cm_curso').value;
      if (!cursoId) { toast('Seleccioná un curso.', 'err'); return; }
      const cbs = document.querySelectorAll('#am_cm_materias .am_cm_cb:checked');
      const materiaIds = Array.from(cbs).map(cb => Number(cb.getAttribute('data-materia-id')));
      try {
        setLoading(true);
        await api('/asistencia/cursos/' + cursoId + '/materias', { method: 'POST', body: JSON.stringify({ materia_ids: materiaIds }) });
        toast('Materias guardadas. Los nuevos alumnos de este curso se inscribirán automáticamente.');
      } catch (e) {
        toast('Error: ' + (e.message || 'No se pudo guardar'), 'err');
      } finally {
        setLoading(false);
      }
    };
    document.getElementById('am_cm_aplicar').onclick = async () => {
      const cursoId = document.getElementById('am_cm_curso').value;
      if (!cursoId) { toast('Seleccioná un curso.', 'err'); return; }
      if (!confirm('¿Inscribir a todos los alumnos existentes de este curso en las materias configuradas?')) return;
      try {
        setLoading(true);
        const res = await api('/asistencia/cursos/' + cursoId + '/materias/aplicar', { method: 'POST' });
        toast('Inscripciones aplicadas: ' + (res.inscritos ?? 0) + ' para ' + (res.alumnos ?? 0) + ' alumnos.');
      } catch (e) {
        toast('Error: ' + (e.message || 'No se pudo aplicar'), 'err');
      } finally {
        setLoading(false);
      }
    };

    let amSgRows = [];
    let amMatRows = [];
    let amCurRows = [];
    let amMatInscritos = {};
    let amSgSelected = new Set();
    let amCurSelected = new Set();
    let amMatSelected = new Set();
    let amMatMateriaSet = new Set();

    function renderAmSgTable() {
      const tbody = document.getElementById('am_sg_tbody');
      if (!tbody) return;
      const searchEl = document.getElementById('am_sg_search');
      const msgEl = document.getElementById('am_sg_msg');
      const wrapDiv = tbody.closest('div');
      const term = (searchEl && searchEl.value ? searchEl.value : '').toLowerCase().trim();
      let rows = amSgRows || [];
      if (term) {
        rows = rows.filter(a => {
          const nombre = ((a.nombres || '') + ' ' + (a.apellidos || '')).toLowerCase();
          const ci = String(a.ci || '').toLowerCase();
          return nombre.includes(term) || ci.includes(term);
        });
      }
      tbody.innerHTML = rows.length ? rows.map(a => {
        const id = Number(a.id);
        const checked = amSgSelected.has(id) ? ' checked' : '';
        return `<tr><td class="nc-checkbox-cell"><input type="checkbox" class="am_sg_cb" data-id="${id}"${checked} /></td>
        <td>${escapeHtml((a.nombres || '') + ' ' + (a.apellidos || ''))}</td><td>${escapeHtml(a.ci || '')}</td>
        <td>${escapeHtml(a.aula_nombre || '')}</td><td>${escapeHtml(a.curso_nombre || '')}</td><td>${escapeHtml(a.carrera_nombre || '')}</td><td>${escapeHtml(a.facultad_nombre || '')}</td></tr>`;
      }).join('') : '<tr><td colspan="7" style="color:#666">No hay alumnos que coincidan con la búsqueda.</td></tr>';
      if (msgEl) msgEl.style.display = amSgRows.length ? 'none' : 'block';
      const bulk = document.getElementById('am_sg_bulk');
      if (bulk) { bulk.style.display = amSgSelected.size ? 'block' : 'none'; }
      const selAll = document.getElementById('am_sg_select_all');
      if (selAll && rows.length) {
        const allIds = rows.map(a => Number(a.id));
        selAll.checked = allIds.length > 0 && allIds.every(id => amSgSelected.has(id));
      }
      if (wrapDiv) wrapDiv.style.display = amSgRows.length ? 'block' : 'none';
    }

    function renderAmMatTable() {
      const tbody = document.getElementById('am_mat_tbody');
      if (!tbody) return;
      const searchEl = document.getElementById('am_mat_search');
      const msgEl = document.getElementById('am_mat_msg');
      const wrapDiv = tbody.closest('div');
      const term = (searchEl && searchEl.value ? searchEl.value : '').toLowerCase().trim();
      let rows = amMatRows || [];
      if (term) {
        rows = rows.filter(a => {
          const nombre = ((a.nombres || '') + ' ' + (a.apellidos || '')).toLowerCase();
          const ci = String(a.ci || '').toLowerCase();
          return nombre.includes(term) || ci.includes(term);
        });
      }
      tbody.innerHTML = rows.length ? rows.map(a => {
        const id = Number(a.id);
        const inscrito = amMatMateriaSet.has(id);
        const checked = amMatSelected.has(id) ? ' checked' : '';
        return `<tr><td class="nc-checkbox-cell"><input type="checkbox" class="am_mat_cb" data-id="${id}" data-inscrito="${inscrito ? '1' : '0'}"${checked} /></td>
        <td>${escapeHtml((a.nombres || '') + ' ' + (a.apellidos || ''))}</td><td>${escapeHtml(a.ci || '')}</td>
        <td>${escapeHtml(a.aula_nombre || '')}</td><td>${escapeHtml(a.curso_nombre || '')}</td><td>${escapeHtml(a.carrera_nombre || '')}</td><td>${escapeHtml(a.facultad_nombre || '')}</td>
        <td>${inscrito ? 'Sí' : 'No'}</td></tr>`;
      }).join('') : '<tr><td colspan="8" style="color:#666">No hay alumnos que coincidan con la búsqueda.</td></tr>';
      if (msgEl) msgEl.style.display = amMatRows.length ? 'none' : 'block';
      const bulk = document.getElementById('am_mat_bulk');
      if (bulk) { bulk.style.display = amMatSelected.size ? 'block' : 'none'; }
      const selAll = document.getElementById('am_mat_select_all');
      if (selAll && rows.length) {
        const allIds = rows.map(a => Number(a.id));
        selAll.checked = allIds.length > 0 && allIds.every(id => amMatSelected.has(id));
      }
      if (wrapDiv) wrapDiv.style.display = amMatRows.length ? 'block' : 'none';
    }

    function renderAmCurTable() {
      const tbody = document.getElementById('am_cur_tbody');
      if (!tbody) return;
      const searchEl = document.getElementById('am_cur_search');
      const msgEl = document.getElementById('am_cur_msg');
      const wrapDiv = tbody.closest('div');
      const term = (searchEl && searchEl.value ? searchEl.value : '').toLowerCase().trim();
      let rows = amCurRows || [];
      if (term) {
        rows = rows.filter(a => {
          const nombre = ((a.nombres || '') + ' ' + (a.apellidos || '')).toLowerCase();
          const ci = String(a.ci || '').toLowerCase();
          return nombre.includes(term) || ci.includes(term);
        });
      }
      tbody.innerHTML = rows.length ? rows.map(a => {
        const id = Number(a.id);
        const checked = amCurSelected.has(id) ? ' checked' : '';
        return `<tr><td class="nc-checkbox-cell"><input type="checkbox" class="am_cur_cb" data-id="${id}"${checked} /></td>
        <td>${escapeHtml((a.nombres || '') + ' ' + (a.apellidos || ''))}</td><td>${escapeHtml(a.ci || '')}</td>
        <td>${escapeHtml(a.aula_nombre || '')}</td><td>${escapeHtml(a.curso_nombre || '')}</td><td>${escapeHtml(a.carrera_nombre || '')}</td><td>${escapeHtml(a.facultad_nombre || '')}</td></tr>`;
      }).join('') : '<tr><td colspan="7" style="color:#666">No hay alumnos que coincidan con la búsqueda.</td></tr>';
      if (msgEl) msgEl.style.display = amCurRows.length ? 'none' : 'block';
      const bulk = document.getElementById('am_cur_bulk');
      if (bulk) { bulk.style.display = amCurSelected.size ? 'block' : 'none'; }
      const selAll = document.getElementById('am_cur_select_all');
      if (selAll && rows.length) {
        const allIds = rows.map(a => Number(a.id));
        selAll.checked = allIds.length > 0 && allIds.every(id => amCurSelected.has(id));
      }
      if (wrapDiv) wrapDiv.style.display = amCurRows.length ? 'block' : 'none';
    }

    const amSgSearch = document.getElementById('am_sg_search');
    if (amSgSearch) amSgSearch.addEventListener('input', () => renderAmSgTable());
    const amMatSearch = document.getElementById('am_mat_search');
    if (amMatSearch) amMatSearch.addEventListener('input', () => renderAmMatTable());

    document.getElementById('am_sg_cargar').onclick = withButtonLock(document.getElementById('am_sg_cargar'), async () => {
      const aula_id = document.getElementById('am_sg_aula').value;
      const term = document.getElementById('am_sg_search').value.trim();
      if (!aula_id) { toast('Seleccioná el grupo destino.', 'err'); return; }
      setLoading(true);
      try {
        let url = '/alumnos?order_by=apellidos&order=ASC';
        if (term.length >= 2) {
          url += '&search=' + encodeURIComponent(term);
        }
        const rows = await api(url);
        amSgRows = Array.isArray(rows) ? rows : (rows && rows.items ? rows.items : []);
        amSgSelected = new Set();
        renderAmSgTable();
        if (!amSgRows.length) {
          toast('No se encontraron alumnos con ese criterio.', 'err');
        }
      } catch (e) {
        toast('Error: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    });

    document.getElementById('am_sg_select_all').onchange = function() {
      card.querySelectorAll('.am_sg_cb').forEach(cb => {
        cb.checked = this.checked;
        const id = Number(cb.getAttribute('data-id'));
        if (this.checked) amSgSelected.add(id); else amSgSelected.delete(id);
      });
      toggleAmSgBulk();
    };
    function toggleAmSgBulk() {
      const n = amSgSelected.size;
      const bulk = document.getElementById('am_sg_bulk');
      bulk.style.display = n ? 'block' : 'none';
      bulk.innerHTML = n ? `<button type="button" class="nc-btn" id="am_sg_aplicar">Agregar al grupo a ${n} seleccionado(s)</button>` : '';
      const aplic = document.getElementById('am_sg_aplicar');
      if (aplic) aplic.onclick = withButtonLock(aplic, async () => {
        const aula_id = document.getElementById('am_sg_aula').value;
        const ids = Array.from(amSgSelected);
        if (!ids.length) return;
        if (!aula_id) { toast('Seleccioná el grupo destino.', 'err'); return; }
        setLoading(true);
        try {
          await api('/alumnos/bulk-aula-add', {
            method: 'POST',
            body: JSON.stringify({ aula_id: Number(aula_id), alumno_ids: ids }),
          });
          toast(ids.length + ' alumno(s) agregados al grupo.');
        } catch (e) {
          toast('Error al agregar al grupo: ' + (e.message || e), 'err');
        } finally {
          setLoading(false);
        }
        document.getElementById('am_sg_cargar').click();
      }, { loadingText: 'Guardando...' });
    }

    // --------- Asignar cursos masivamente ----------
    const amCurSearch = document.getElementById('am_cur_search');
    if (amCurSearch) amCurSearch.addEventListener('input', () => renderAmCurTable());
    const facDestSel = document.getElementById('am_cur_facultad_dest');
    const carDestSel = document.getElementById('am_cur_carrera_dest');
    if (facDestSel && carDestSel) {
      facDestSel.addEventListener('change', async () => {
        const fid = facDestSel.value;
        if (!fid) {
          carDestSel.innerHTML = '<option value="">Seleccione carrera</option>';
          return;
        }
        try {
          const rows = await api('/carreras?facultad_id=' + encodeURIComponent(fid));
          const list = Array.isArray(rows) ? rows : (rows && Array.isArray(rows.items) ? rows.items : []);
          carDestSel.innerHTML = '<option value="">Seleccione carrera</option>' + list.map(c => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`).join('');
        } catch (_) {
          carDestSel.innerHTML = '<option value="">Error al cargar</option>';
        }
      });
    }
    const amCurCargarBtn = document.getElementById('am_cur_cargar');
    if (amCurCargarBtn) {
      amCurCargarBtn.addEventListener('click', async () => {
        const cursoSel = document.getElementById('am_cur_curso');
        const grupoSel = document.getElementById('am_cur_grupo');
        const facSel2 = document.getElementById('am_cur_facultad');
        const msgEl = document.getElementById('am_cur_msg');
        const tbody = document.getElementById('am_cur_tbody');
        amCurRows = [];
        amCurSelected = new Set();
        if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="color:#666">Cargando alumnos...</td></tr>';
        let q = '/alumnos?';
        const params = [];
        if (grupoSel && grupoSel.value) params.push('aula_id=' + encodeURIComponent(grupoSel.value));
        if (facSel2 && facSel2.value) params.push('facultad_id=' + encodeURIComponent(facSel2.value));
        if (params.length) q += params.join('&');
        try {
          const res = await api(q);
          amCurRows = (res && res.items) ? res.items : (Array.isArray(res) ? res : []);
          renderAmCurTable();
        } catch (e) {
          if (msgEl) msgEl.textContent = 'Error al cargar alumnos: ' + (e.message || e);
        }
      });
    }
    const amCurSelectAll = document.getElementById('am_cur_select_all');
    if (amCurSelectAll) {
      amCurSelectAll.addEventListener('change', () => {
        const tbody = document.getElementById('am_cur_tbody');
        if (!tbody) return;
        const checks = tbody.querySelectorAll('.am_cur_cb');
        amCurSelected = new Set();
        checks.forEach(cb => {
          cb.checked = amCurSelectAll.checked;
          if (amCurSelectAll.checked) {
            const id = Number(cb.getAttribute('data-id'));
            if (id) amCurSelected.add(id);
          }
        });
        renderAmCurTable();
      });
    }
    const amCurTbody = document.getElementById('am_cur_tbody');
    if (amCurTbody) {
      amCurTbody.addEventListener('change', (e) => {
        const t = e.target;
        if (t && t.classList.contains('am_cur_cb')) {
          const id = Number(t.getAttribute('data-id'));
          if (!id) return;
          if (t.checked) amCurSelected.add(id); else amCurSelected.delete(id);
          renderAmCurTable();
        }
      });
    }
    const amCurBulk = document.getElementById('am_cur_bulk');
    if (amCurBulk) {
      amCurBulk.innerHTML = '<div class="nc-bulk-actions"><div class="nc-bulk-info">Alumnos seleccionados: <strong id="am_cur_count">0</strong></div><div class="nc-bulk-buttons"><button type="button" class="nc-btn primary" id="am_cur_aplicar">Asignar curso</button><button type="button" class="nc-btn secondary" id="am_cur_aplicar_fac">Asignar facultad/carrera</button></div></div>';
      const countEl = amCurBulk.querySelector('#am_cur_count');
      function updateAmCurCount() {
        if (countEl) countEl.textContent = String(amCurSelected.size);
      }
      updateAmCurCount();
      const btnAplicar = amCurBulk.querySelector('#am_cur_aplicar');
      if (btnAplicar) {
        btnAplicar.addEventListener('click', async () => {
          const cursoSel = document.getElementById('am_cur_curso');
          if (!cursoSel || !cursoSel.value) {
            toast('Seleccioná un curso destino.', 'err');
            return;
          }
          if (!amCurSelected.size) {
            toast('Seleccioná al menos un alumno.', 'err');
            return;
          }
          if (!confirm('¿Asignar el curso seleccionado a los alumnos marcados?')) return;
          try {
            setLoading(true);
            await api('/alumnos/bulk-curso', {
              method: 'POST',
              body: JSON.stringify({
                curso_id: Number(cursoSel.value),
                alumno_ids: Array.from(amCurSelected),
              }),
            });
            toast('Curso asignado correctamente a los alumnos seleccionados.');
          } catch (e) {
            toast('Error al asignar curso: ' + (e.message || e), 'err');
          } finally {
            setLoading(false);
          }
        });
      }
      const btnAplicarFac = amCurBulk.querySelector('#am_cur_aplicar_fac');
      if (btnAplicarFac) {
        btnAplicarFac.addEventListener('click', async () => {
          const facDestSel = document.getElementById('am_cur_facultad_dest');
          const carDestSel = document.getElementById('am_cur_carrera_dest');
          const facultadId = facDestSel && facDestSel.value ? Number(facDestSel.value) : null;
          const carreraId  = carDestSel && carDestSel.value ? Number(carDestSel.value) : null;
          if (!facultadId && !carreraId) {
            toast('Seleccioná al menos una facultad destino o una carrera destino.', 'err');
            return;
          }
          if (!amCurSelected.size) {
            toast('Seleccioná al menos un alumno.', 'err');
            return;
          }
          if (!confirm('¿Asignar la facultad/carrera seleccionada a los alumnos marcados?')) return;
          try {
            setLoading(true);
            await api('/alumnos/bulk-facultad-carrera', {
              method: 'POST',
              body: JSON.stringify({
                facultad_id: facultadId,
                carrera_id: carreraId,
                alumno_ids: Array.from(amCurSelected),
              }),
            });
            toast('Facultad/carrera asignadas correctamente a los alumnos seleccionados.');
          } catch (e) {
            toast('Error al asignar facultad/carrera: ' + (e.message || e), 'err');
          } finally {
            setLoading(false);
          }
        });
      }
      // Actualizar contador cuando cambie la selección
      const origRenderAmCurTable = renderAmCurTable;
      renderAmCurTable = function() {
        origRenderAmCurTable();
        updateAmCurCount();
      };
    }
    card.addEventListener('change', (e) => {
      if (e.target && e.target.classList.contains('am_sg_cb')) {
        const id = Number(e.target.getAttribute('data-id'));
        if (e.target.checked) amSgSelected.add(id); else amSgSelected.delete(id);
        toggleAmSgBulk();
      }
    });

    document.getElementById('am_mat_cargar').onclick = withButtonLock(document.getElementById('am_mat_cargar'), async () => {
      const materia_id = document.getElementById('am_mat_materia').value;
      if (!materia_id) { toast('Seleccioná una materia.', 'err'); return; }
      const aula_id = document.getElementById('am_mat_aula').value;
      setLoading(true);
      try {
        let url = '/alumnos';
        if (aula_id) url += '?aula_id=' + encodeURIComponent(aula_id);
        const rows = await api(url);
        amMatRows = Array.isArray(rows) ? rows : (rows && rows.items ? rows.items : []);
        amMatSelected = new Set();
        amMatMateriaSet = new Set();
        try {
          const resIds = await api('/asistencia/materias/' + encodeURIComponent(materia_id) + '/alumnos');
          const idsArr = (resIds && resIds.alumno_ids) ? resIds.alumno_ids : [];
          idsArr.forEach(id => { amMatMateriaSet.add(Number(id)); });
        } catch (_) {}
        renderAmMatTable();
      } catch (e) {
        toast('Error: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    });

    document.getElementById('am_mat_select_all').onchange = function() {
      card.querySelectorAll('.am_mat_cb').forEach(cb => {
        cb.checked = this.checked;
        const id = Number(cb.getAttribute('data-id'));
        if (this.checked) amMatSelected.add(id); else amMatSelected.delete(id);
      });
      toggleAmMatBulk();
    };
    function toggleAmMatBulk() {
      const n = amMatSelected.size;
      const bulk = document.getElementById('am_mat_bulk');
      bulk.style.display = n ? 'block' : 'none';
      bulk.innerHTML = n ? `<button type="button" class="nc-btn" id="am_mat_inscribir">Inscribir ${n} en esta materia</button> <button type="button" class="nc-btn secondary" id="am_mat_quitar">Quitar ${n} de esta materia</button>` : '';
      const inscribir = document.getElementById('am_mat_inscribir');
      const quitar = document.getElementById('am_mat_quitar');
      if (inscribir) inscribir.onclick = withButtonLock(inscribir, applyAmMatBulk.bind(null, true), { loadingText: 'Guardando...' });
      if (quitar) quitar.onclick = withButtonLock(quitar, applyAmMatBulk.bind(null, false), { loadingText: 'Guardando...' });
    }
    async function applyAmMatBulk(inscribir) {
      const materia_id = Number(document.getElementById('am_mat_materia').value);
      if (!materia_id) return;
      const ids = Array.from(amMatSelected);
      if (!ids.length) return;
      setLoading(true);
      try {
        const path = inscribir ? '/asistencia/materias/' + materia_id + '/inscribir' : '/asistencia/materias/' + materia_id + '/quitar';
        const res = await api(path, { method: 'POST', body: JSON.stringify({ alumno_ids: ids }) });
        const n = inscribir ? (res.inscritos ?? 0) : (res.quitados ?? 0);
        toast((inscribir ? 'Inscritos' : 'Quitados') + ': ' + n);
      } catch (e) {
        toast('Error: ' + (e.message || 'No se pudo completar'), 'err');
      }
      setLoading(false);
      document.getElementById('am_mat_cargar').click();
    }
    card.addEventListener('change', (e) => {
      if (e.target && e.target.classList.contains('am_mat_cb')) {
        const id = Number(e.target.getAttribute('data-id'));
        if (e.target.checked) amMatSelected.add(id); else amMatSelected.delete(id);
        toggleAmMatBulk();
      }
    });

    // --------- Materias por grupo (estilo materias por curso) ----------
    let amMgMateriasList = [];
    let amMgMateriaIds = [];

    async function getAlumnoIdsByAula(aulaId) {
      const rows = await api('/alumnos?aula_id=' + encodeURIComponent(aulaId) + '&order_by=apellidos&order=ASC');
      const list = (rows && rows.items) ? rows.items : (Array.isArray(rows) ? rows : []);
      return list.map(x => Number(x.id)).filter(id => id > 0);
    }

    async function loadAmMgMaterias() {
      const aulaEl = document.getElementById('am_mg_aula');
      const aulaId = aulaEl ? Number(aulaEl.value || 0) : 0;
      if (!aulaId) { toast('Seleccioná un grupo.', 'err'); return; }
      setLoading(true);
      try {
        const matRes = await api('/materias?activo=1');
        amMgMateriasList = (matRes && matRes.items) ? matRes.items : [];
        const checks = await Promise.all(amMgMateriasList.map(async (m) => {
          try {
            const res = await api('/asistencia/materias/' + encodeURIComponent(m.id) + '/grupos');
            const groups = (res && res.items) ? res.items : [];
            return groups.some(g => Number(g.id) === aulaId) ? Number(m.id) : null;
          } catch (_) {
            return null;
          }
        }));
        amMgMateriaIds = checks.filter(v => v !== null);
        const wrap = document.getElementById('am_mg_materias_wrap');
        const div = document.getElementById('am_mg_materias');
        if (!div || !wrap) return;
        div.innerHTML = amMgMateriasList.map(m => {
          const checked = amMgMateriaIds.indexOf(Number(m.id)) !== -1;
          return `<label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" class="am_mg_cb" data-materia-id="${m.id}" ${checked ? 'checked' : ''} />${escapeHtml(m.nombre)}</label>`;
        }).join('');
        wrap.style.display = 'block';
        const msg = document.getElementById('am_mg_msg');
        if (msg) msg.textContent = 'Materias cargadas. Podés ajustar checks y guardar.';
      } catch (e) {
        toast('Error: ' + (e.message || 'No se pudo cargar'), 'err');
      } finally {
        setLoading(false);
      }
    }

    const amMgCargar = document.getElementById('am_mg_cargar');
    if (amMgCargar) amMgCargar.onclick = withButtonLock(amMgCargar, loadAmMgMaterias, { loadingText: 'Cargando...' });

    const amMgGuardar = document.getElementById('am_mg_guardar');
    if (amMgGuardar) {
      amMgGuardar.onclick = withButtonLock(amMgGuardar, async () => {
        const aulaEl = document.getElementById('am_mg_aula');
        const aulaId = aulaEl ? Number(aulaEl.value || 0) : 0;
        if (!aulaId) { toast('Seleccioná un grupo.', 'err'); return; }

        const cbs = document.querySelectorAll('#am_mg_materias .am_mg_cb:checked');
        const selectedMateriaIds = Array.from(cbs).map(cb => Number(cb.getAttribute('data-materia-id')));
        const currentSet = new Set((amMgMateriaIds || []).map(Number));
        const selectedSet = new Set((selectedMateriaIds || []).map(Number));
        const toAdd = Array.from(selectedSet).filter(id => !currentSet.has(id));
        const toRemove = Array.from(currentSet).filter(id => !selectedSet.has(id));

        if (!toAdd.length && !toRemove.length) {
          toast('No hay cambios para guardar.');
          return;
        }

        setLoading(true);
        try {
          const alumno_ids = await getAlumnoIdsByAula(aulaId);
          if (!alumno_ids.length) {
            toast('El grupo seleccionado no tiene alumnos activos.', 'err');
            return;
          }

          let totalIns = 0;
          let totalQui = 0;
          for (const materiaId of toAdd) {
            const res = await api('/asistencia/materias/' + encodeURIComponent(materiaId) + '/inscribir', {
              method: 'POST',
              body: JSON.stringify({ alumno_ids }),
            });
            totalIns += Number(res && res.inscritos ? res.inscritos : 0);
          }
          for (const materiaId of toRemove) {
            const res = await api('/asistencia/materias/' + encodeURIComponent(materiaId) + '/quitar', {
              method: 'POST',
              body: JSON.stringify({ alumno_ids }),
            });
            totalQui += Number(res && res.quitados ? res.quitados : 0);
          }

          toast('Materias por grupo actualizadas. Inscritos: ' + totalIns + '. Quitados: ' + totalQui + '.');
          await loadAmMgMaterias();
        } catch (e) {
          toast('Error: ' + (e.message || 'No se pudo guardar'), 'err');
        } finally {
          setLoading(false);
        }
      }, { loadingText: 'Guardando...' });
    }
  }

  // ===================== MIS REGISTROS DE CONDUCTA =====================
  function renderMisRegistros() {
    if (window.NC_AppState) window.NC_AppState.persistRoute({ mainTab: 'conducta-main', screen: 'mis-registros' });
    view.innerHTML = '';
    const card = el('div', { class: 'nc-card' });
    const subTabs = [
      { id: 'mr-individual', label: 'Individuales' },
      { id: 'mr-grupal', label: 'Grupales' },
    ];
    const today = new Date().toISOString().slice(0, 10);
    const from30 = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    card.innerHTML = `
      <h3 style="margin:0 0 14px">Mis Registros de Conducta</h3>
      <div class="nc-row nc-tabs" style="margin-bottom:14px" id="mr_subtabs">
        ${subTabs.map(t => `<button type="button" class="nc-tab" data-mr-sub="${t.id}">${t.label}</button>`).join('')}
      </div>
      <div class="nc-row" style="flex-wrap:wrap;gap:10px;margin-bottom:14px">
        <div class="nc-field"><label>Buscar (alumno o CI)</label><input type="text" id="mr_search" placeholder="Nombre, apellido o CI" style="min-width:180px;padding:8px" /></div>
        <div class="nc-field"><label>Curso</label><select id="mr_curso">${cursosOptions(true)}</select></div>
        <div class="nc-field"><label>Grupo</label><select id="mr_aula">${aulasOptions(true)}</select></div>
        <div class="nc-field"><label>Desde</label><input type="date" id="mr_from" value="${from30}" /></div>
        <div class="nc-field"><label>Hasta</label><input type="date" id="mr_to" value="${today}" /></div>
        <button class="nc-btn" id="mr_btn_buscar" style="align-self:flex-end">Buscar</button>
      </div>
      <div id="mr_content" style="overflow:auto;min-height:200px">
        <p style="opacity:.7">Seleccioná Individuales o Grupales y presioná Buscar.</p>
      </div>
    `;
    view.appendChild(card);

    let currentSub = 'mr-individual';
    let lastIndividual = [];
    let lastGrupales = [];

    function setSub(id) {
      currentSub = id;
      card.querySelectorAll('[data-mr-sub]').forEach(b => { b.dataset.active = (b.dataset.mrSub === id ? '1' : '0'); });
      renderMrContent();
    }

    async function loadMisRegistros() {
      const qs = new URLSearchParams();
      qs.set('tipo', currentSub === 'mr-grupal' ? 'grupal' : 'individual');
      const search = document.getElementById('mr_search').value.trim();
      if (search) qs.set('search', search);
      const curso_id = document.getElementById('mr_curso').value;
      if (curso_id) qs.set('curso_id', curso_id);
      const aula_id = document.getElementById('mr_aula').value;
      if (aula_id) qs.set('aula_id', aula_id);
      const from = document.getElementById('mr_from').value;
      if (from) qs.set('from', from);
      const to = document.getElementById('mr_to').value;
      if (to) qs.set('to', to);
      setLoading(true);
      try {
        const res = await api('/conducta/mis-registros?' + qs.toString());
        const items = res.items || [];
        if (currentSub === 'mr-grupal') lastGrupales = items;
        else lastIndividual = items;
        renderMrContent();
      } catch (e) {
        toast('Error: ' + e.message, 'err');
        document.getElementById('mr_content').innerHTML = '<p style="color:#b00">Error al cargar.</p>';
      } finally {
        setLoading(false);
      }
    }

    function renderMrContent() {
      const cont = document.getElementById('mr_content');
      if (!cont) return;
      if (currentSub === 'mr-individual') {
        const items = lastIndividual;
        if (items.length === 0) {
          cont.innerHTML = '<p style="opacity:.7">No hay registros individuales con esos filtros.</p>';
          return;
        }
        cont.innerHTML = `
          <p style="margin-bottom:12px;font-weight:600;font-size:14px">Total: <strong>${items.length}</strong> registro${items.length !== 1 ? 's' : ''}</p>
          <table class="nc-table" style="width:100%;border-collapse:collapse;font-size:13px">
            <thead><tr style="background:#f5f5f5">
              <th style="padding:8px">Alumno</th><th style="padding:8px">CI</th><th style="padding:8px">Fecha</th><th style="padding:8px">Grupo</th><th style="padding:8px">Curso</th>
              <th style="padding:8px">Resp.Acad.</th><th style="padding:8px">Respeto</th><th style="padding:8px">Part.Act.</th><th style="padding:8px">Autocont.</th><th style="padding:8px">Auton.Comp.</th><th style="padding:8px">Pres.Orden</th>
              <th style="padding:8px">Acción</th>
            </tr></thead>
            <tbody>
              ${items.map(r => {
                const nombre = escapeHtml((r.nombres || '') + ' ' + (r.apellidos || ''));
                const obs = (r.observacion_item || '').replace(/"/g, '&quot;');
                return `<tr>
                  <td style="padding:8px">${nombre}</td>
                  <td style="padding:8px">${escapeHtml(r.ci || '')}</td>
                  <td style="padding:8px">${escapeHtml(r.fecha || '')}</td>
                  <td style="padding:8px">${escapeHtml(r.aula_nombre || '')}</td>
                  <td style="padding:8px">${escapeHtml(r.curso_nombre || '')}</td>
                  <td style="padding:8px">${r.responsabilidad_academica ?? 0}</td>
                  <td style="padding:8px">${r.respeto_convivencia ?? 0}</td>
                  <td style="padding:8px">${r.participacion_actitud ?? 0}</td>
                  <td style="padding:8px">${r.autocontrol_disciplina ?? 0}</td>
                  <td style="padding:8px">${r.autonomia_compromiso ?? 0}</td>
                  <td style="padding:8px">${r.presentacion_orden ?? 0}</td>
                  <td style="padding:8px">
                    <button type="button" class="nc-btn secondary small nc-mr-edit" data-item-id="${r.item_id || ''}" data-legacy-id="${r.es_legacy ? r.id : ''}" data-fecha="${escapeHtml(r.fecha || '')}" data-obs="${obs}"
                      data-score-resp-acad="${r.responsabilidad_academica ?? 0}" data-score-resp-conv="${r.respeto_convivencia ?? 0}" data-score-part-act="${r.participacion_actitud ?? 0}"
                      data-score-autocont="${r.autocontrol_disciplina ?? 0}" data-score-auton-comp="${r.autonomia_compromiso ?? 0}" data-score-pres-ord="${r.presentacion_orden ?? 0}">Editar</button>
                  </td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        `;
        cont.querySelectorAll('.nc-mr-edit').forEach(btn => {
          btn.onclick = () => {
            const itemId = btn.getAttribute('data-item-id') || null;
            const legacyId = btn.getAttribute('data-legacy-id') || null;
            openEditConductaModal({
              itemId: itemId || null,
              legacyId: legacyId || null,
              fecha: btn.getAttribute('data-fecha') || '',
              observacion_item: (btn.getAttribute('data-obs') || '').replace(/&quot;/g, '"'),
              scores: {
                responsabilidad_academica: Number(btn.getAttribute('data-score-resp-acad') || 0),
                respeto_convivencia: Number(btn.getAttribute('data-score-resp-conv') || 0),
                participacion_actitud: Number(btn.getAttribute('data-score-part-act') || 0),
                autocontrol_disciplina: Number(btn.getAttribute('data-score-autocont') || 0),
                autonomia_compromiso: Number(btn.getAttribute('data-score-auton-comp') || 0),
                presentacion_orden: Number(btn.getAttribute('data-score-pres-ord') || 0)
              }
            }, () => loadMisRegistros());
          };
        });
      } else {
        const items = lastGrupales;
        if (items.length === 0) {
          cont.innerHTML = '<p style="opacity:.7">No hay registros grupales con esos filtros.</p>';
          return;
        }
        cont.innerHTML = `
          <p style="margin-bottom:12px;font-weight:600;font-size:14px">Total: <strong>${items.length}</strong> registro${items.length !== 1 ? 's' : ''}</p>
          <table class="nc-table" style="width:100%;border-collapse:collapse;font-size:13px">
            <thead><tr style="background:#f5f5f5">
              <th style="padding:8px">Fecha</th><th style="padding:8px">Grupo</th><th style="padding:8px">Curso</th><th style="padding:8px">Alumnos</th><th style="padding:8px">Ver</th>
            </tr></thead>
            <tbody>
              ${items.map(r => `
                <tr>
                  <td style="padding:8px">${escapeHtml(r.fecha || '')}</td>
                  <td style="padding:8px">${escapeHtml(r.aula_nombre || '')}</td>
                  <td style="padding:8px">${escapeHtml(r.curso_nombre || '')}</td>
                  <td style="padding:8px">${r.cantidad_alumnos ?? 0}</td>
                  <td style="padding:8px"><button type="button" class="nc-btn secondary small nc-mr-ver-grupal" data-eid="${r.evaluacion_id}">Ver detalle</button></td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        `;
        cont.querySelectorAll('.nc-mr-ver-grupal').forEach(btn => {
          btn.onclick = async () => {
            const eid = btn.getAttribute('data-eid');
            setLoading(true);
            try {
              const res = await api('/conducta/mis-registros/' + eid);
              const h = res.header || {};
              const list = res.items || [];
              const body = el('div');
              body.innerHTML = `
                <div style="margin-bottom:12px">
                  <p><strong>Fecha:</strong> ${escapeHtml(h.fecha || '')} · <strong>Grupo:</strong> ${escapeHtml(h.aula_nombre || '')} · <strong>Curso:</strong> ${escapeHtml(h.curso_nombre || '')}</p>
                  ${h.observacion_general ? `<p style="opacity:.9">Obs. general: ${escapeHtml(h.observacion_general)}</p>` : ''}
                </div>
                <div style="max-height:400px;overflow:auto">
                  <table class="nc-table" style="width:100%;font-size:12px;border-collapse:collapse">
                    <thead><tr style="background:#f0f0f0">
                      <th style="padding:6px">Alumno</th><th style="padding:6px">CI</th>
                      <th style="padding:6px">R.Acad</th><th style="padding:6px">Respeto</th><th style="padding:6px">Part.</th><th style="padding:6px">Autoc.</th><th style="padding:6px">Auton.</th><th style="padding:6px">Pres.</th><th style="padding:6px">Obs.</th>
                    </tr></thead>
                    <tbody>
                      ${list.map(it => `
                        <tr style="border-bottom:1px solid #eee">
                          <td style="padding:6px">${escapeHtml((it.nombres || '') + ' ' + (it.apellidos || ''))}</td>
                          <td style="padding:6px">${escapeHtml(it.ci || '')}</td>
                          <td style="padding:6px">${it.responsabilidad_academica ?? 0}</td>
                          <td style="padding:6px">${it.respeto_convivencia ?? 0}</td>
                          <td style="padding:6px">${it.participacion_actitud ?? 0}</td>
                          <td style="padding:6px">${it.autocontrol_disciplina ?? 0}</td>
                          <td style="padding:6px">${it.autonomia_compromiso ?? 0}</td>
                          <td style="padding:6px">${it.presentacion_orden ?? 0}</td>
                          <td style="padding:6px;max-width:150px">${escapeHtml((it.observacion_item || '').slice(0, 50))}${(it.observacion_item || '').length > 50 ? '…' : ''}</td>
                        </tr>
                      `).join('')}
                    </tbody>
                  </table>
                </div>
              `;
              openModal('Registro de conducta grupal', body, [{ label: 'Cerrar', className: 'secondary', onClick: (m) => m.close() }]);
            } catch (e) {
              toast('Error: ' + e.message, 'err');
            } finally {
              setLoading(false);
            }
          };
        });
      }
    }

    card.querySelectorAll('[data-mr-sub]').forEach(b => {
      b.onclick = () => setSub(b.dataset.mrSub);
    });
    (function () {
      const btn = document.getElementById('mr_btn_buscar');
      if (btn) btn.onclick = withButtonLock(btn, loadMisRegistros, { loadingText: 'Buscando...' });
    })();
    document.getElementById('mr_search').addEventListener('keypress', (e) => { if (e.key === 'Enter') loadMisRegistros(); });
    setSub('mr-individual');
  }

  // ===================== REPORTES POR FECHA =====================
  function renderReportesFecha() {
    view.innerHTML = '';
    const today = new Date().toISOString().slice(0, 10);
    const showRegistroFilter = userPermissions.canViewEvaluator();
    const card = el('div', { class: 'nc-card' });
    card.innerHTML = `
      <h3 style="margin:0 0 10px">Reporte visual por fecha</h3>
      <div class="nc-row">
        <div class="nc-field"><label>Desde</label><input id="r_from" type="date" value="${today}" /></div>
        <div class="nc-field"><label>Hasta</label><input id="r_to" type="date" value="${today}" /></div>
        <div class="nc-field"><label>Grupo</label><select id="r_aula">${aulasOptions(true)}</select></div>
        ${showRegistroFilter ? '<div class="nc-field"><label>Registró</label><select id="r_registro"><option value="">Todas</option></select></div>' : ''}
        <button class="nc-btn" id="r_btn_buscar">Buscar</button>
        <button class="nc-btn secondary" id="r_export_excel">Exportar Excel (CSV)</button>
        <button class="nc-btn secondary" id="r_export_pdf">Exportar PDF</button>
      </div>
      <div style="margin-top:14px;overflow:auto">
        <table>
          <thead>
            <tr>
              <th>Fecha</th><th>Tipo</th><th>Alumno</th><th>CI</th><th>Grupo</th>
                ${userPermissions.canViewEvaluator() ? '<th>Registró</th>' : ''}
              <th>Resp.Acad.</th><th>Respeto/Conv.</th><th>Part.Act.</th><th>Autocont.</th><th>Auton.Comp.</th><th>Pres.Orden</th><th>Obs.</th>
            </tr>
          </thead>
          <tbody id="r_tbody">
            <tr><td colspan="13" style="color:#666">Elegí rango y presioná Buscar.</td></tr>
          </tbody>
        </table>
      </div>
    `;
    view.appendChild(card);

    if (showRegistroFilter) {
      const sel = document.getElementById('r_registro');
      if (sel) {
        api('/reportes/usuarios-registro').then(res => {
          const items = (res && res.items) ? res.items : [];
          items.forEach(u => {
            const opt = document.createElement('option');
            opt.value = u.id;
            opt.textContent = escapeHtml(u.display_name || ('Usuario ' + u.id));
            sel.appendChild(opt);
          });
        }).catch(() => {});
      }
    }

    function labelConducta(v) { const n = Number(v); if (n === 0) return '0 (Inaceptable)'; if (n === 5) return '5 (Excelente)'; return String(n); }

    function getReportesQueryParams() {
      const from = document.getElementById('r_from').value;
      const to = document.getElementById('r_to').value;
      const aula_id = document.getElementById('r_aula').value;
      const qs = new URLSearchParams({ from, to });
      if (aula_id) qs.set('aula_id', aula_id);
      if (showRegistroFilter) {
        const registroEl = document.getElementById('r_registro');
        if (registroEl && registroEl.value) qs.set('evaluador_user_id', registroEl.value);
      }
      return qs;
    }

    async function runReportesSearch() {
      const from = document.getElementById('r_from').value;
      const to = document.getElementById('r_to').value;
      if (!from || !to) { toast('Elegí desde y hasta.', 'err'); return; }
      const qs = getReportesQueryParams();
      setLoading(true);
      try {
        const rows = await api('/reportes/fecha?' + qs.toString());
        const tbody = document.getElementById('r_tbody');
        if (!rows.length) {
          tbody.innerHTML = '<tr><td colspan="13" style="color:#666">No hay registros en ese rango.</td></tr>';
          return;
        }
        tbody.innerHTML = rows.map(r => {
          const nombre = ((r.nombres || '') + ' ' + (r.apellidos || '')).trim();
          return `<tr>
            <td>${escapeHtml(r.fecha || '')}</td>
            <td>${escapeHtml(r.tipo || '')}</td>
            <td>${escapeHtml(nombre)}</td>
            <td>${escapeHtml(r.ci || '')}</td>
            <td>${escapeHtml(r.aula_nombre || '')}</td>
            ${userPermissions.canViewEvaluator() ? `<td>${escapeHtml(r.evaluador_nombre || '')}</td>` : ''}
            <td>${labelConducta(r.responsabilidad_academica)}</td>
            <td>${labelConducta(r.respeto_convivencia)}</td>
            <td>${labelConducta(r.participacion_actitud)}</td>
            <td>${labelConducta(r.autocontrol_disciplina)}</td>
            <td>${labelConducta(r.autonomia_compromiso)}</td>
            <td>${labelConducta(r.presentacion_orden)}</td>
            <td>${escapeHtml((r.observacion || r.observacion_item || '').slice(0, 50))}</td>
          </tr>`;
        }).join('');
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    (function () {
      const btn = document.getElementById('r_btn_buscar');
      if (btn) btn.onclick = withButtonLock(btn, runReportesSearch, { loadingText: 'Buscando...' });
    })();

    const rExportExcelBtn = document.getElementById('r_export_excel');
    if (rExportExcelBtn) {
      rExportExcelBtn.onclick = withButtonLock(rExportExcelBtn, async () => {
        const from = document.getElementById('r_from').value;
        const to = document.getElementById('r_to').value;
        if (!from || !to) { toast('Elegí desde y hasta.', 'err'); return; }
        const qs = getReportesQueryParams();
        qs.set('format', 'csv');
        setLoading(true);
        try {
          const url = `${API}/reportes/export?${qs.toString()}&nc_ts=${Date.now()}`;
          const res = await fetch(url, { credentials: 'same-origin', headers: NC_APP.nonce ? { 'X-WP-Nonce': NC_APP.nonce } : {} });
          const blob = await res.blob();
          const a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = 'reporte-conducta-' + from + '-' + to + '.csv';
          document.body.appendChild(a);
          a.click();
          a.remove();
          setTimeout(() => URL.revokeObjectURL(a.href), 5000);
          toast('Exportado (Excel/CSV).');
        } catch (e) { toast('ERROR: ' + e.message, 'err'); }
        finally { setLoading(false); }
      }, { loadingText: 'Exportando...' });
    }

    const rExportPdfBtn = document.getElementById('r_export_pdf');
    if (rExportPdfBtn) {
      rExportPdfBtn.onclick = withButtonLock(rExportPdfBtn, async () => {
        const from = document.getElementById('r_from').value;
        const to = document.getElementById('r_to').value;
        if (!from || !to) { toast('Elegí desde y hasta.', 'err'); return; }
        const qs = getReportesQueryParams();
        qs.set('format', 'html');
        setLoading(true);
        try {
          const url = `${API}/reportes/export?${qs.toString()}&nc_ts=${Date.now()}`;
          const res = await fetch(url, { credentials: 'same-origin', headers: NC_APP.nonce ? { 'X-WP-Nonce': NC_APP.nonce } : {} });
          if (!res.ok) throw new Error(res.statusText || 'Error al exportar');
          const html = await res.text();
          const w = window.open('', '_blank');
          if (!w) throw new Error('Permití popups para exportar PDF.');
          w.document.open();
          w.document.write(html);
          w.document.close();
          w.focus();
          toast('Abrí la ventana. Usá Imprimir > Guardar como PDF.');
        } catch (e) { toast('ERROR: ' + e.message, 'err'); }
        finally { setLoading(false); }
      }, { loadingText: 'Exportando...' });
    }
  }

  // ===================== LISTADO ALUMNOS (con orden por apellido/nombre) =====================
  function renderListado() {
    if (window.NC_AppState) window.NC_AppState.persistRoute({ mainTab: 'conducta-main', screen: 'listado' });
    view.innerHTML = '';
    const card = el('div', { class: 'nc-card' });
    card.innerHTML = `
      <h3 style="margin:0 0 10px">Listado de alumnos</h3>
      <div class="nc-row">
        <div class="nc-field">
          <label>Buscar (nombre o CI)</label>
          <input id="f_search" placeholder="Ej: Juan / 1234567" />
        </div>
        <div class="nc-field">
          <label>Curso</label>
          <select id="f_curso">${cursosOptions(true)}</select>
        </div>
        <div class="nc-field">
          <label>Grupo</label>
          <select id="f_aula">${aulasOptions(true)}</select>
        </div>

        <div class="nc-field">
          <label>Ordenar por</label>
          <select id="f_sortby">
            <option value="apellido" selected>Apellido</option>
            <option value="nombre">Nombre</option>
          </select>
        </div>
        <div class="nc-field">
          <label>Orden</label>
          <select id="f_order">
            <option value="ASC" selected>A→Z</option>
            <option value="DESC">Z→A</option>
          </select>
        </div>

        <button class="nc-btn" id="btn_buscar">Buscar</button>
        <button class="nc-btn secondary" id="btn_limpiar">Limpiar</button>
      </div>
      <div class="nc-mini" style="margin-top:10px">
        Tip: al crear un alumno, volvés acá y apretás Buscar para refrescar (o podés usar “Ver listado”).
      </div>
    `;

    const out = el('div', { class: 'nc-card' }, `
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <h3 style="margin:0">Resultados</h3>
        <div id="count" class="nc-pill">0</div>
      </div>
      
      <div id="f_bulk_actions" style="display:none"></div>
      
      <div style="overflow:auto;margin-top:10px">
        <table>
          <thead>
            <tr id="f_thead_row">
              <th>Nombres</th>
              <th>Apellidos</th>
              <th>CI</th>
              <th>Curso</th>
              <th>Grupo</th>
              <th>Carrera</th>
              <th>Facultad</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody id="tbody">
            <tr><td colspan="8" style="color:#666">Usá filtros y presioná Buscar.</td></tr>
          </tbody>
        </table>
      </div>
      
      <div id="f_pagination"></div>
    `);

    view.appendChild(card);
    view.appendChild(out);

    // Sin paginación: mostrar todos los registros
    const listadoPaginator = new Paginator({
      itemsPerPage: 99999,
      onPageChange: () => renderListadoTable()
    });

    const listadoSelector = new BulkSelector({
      onSelectionChange: () => {
        listadoSelector.renderActionBar('f_bulk_actions', [
          {
            label: 'Eliminar seleccionados',
            className: 'nc-btn danger small',
            onClick: async (ids) => {
              try {
                setLoading(true);
                await bulkDeleteAlumnos(ids);
                toast('Alumnos eliminados correctamente.');
                listadoSelector.clear();
                runListadoSearch();
              } catch (error) {
                toast(error.message, 'err');
              } finally {
                setLoading(false);
              }
            }
          }
        ]);
      }
    });

    let currentRows = [];

    const btnBuscar = document.getElementById('btn_buscar');
    const btnLimpiar = document.getElementById('btn_limpiar');

    btnBuscar.onclick = withButtonLock(btnBuscar, runListadoSearch, { loadingText: 'Buscando...' });
    document.getElementById('f_search').addEventListener('keypress', (e) => {
      if (e.key === 'Enter') runListadoSearch();
    });
    btnLimpiar.onclick = () => {
      document.getElementById('f_search').value = '';
      document.getElementById('f_curso').value = '';
      document.getElementById('f_aula').value = '';
      document.getElementById('f_sortby').value = 'apellido';
      document.getElementById('f_order').value = 'ASC';
      runListadoSearch();
    };

    function renderListadoTable() {
      const tbody = document.getElementById('tbody');
      const theadRow = document.getElementById('f_thead_row');
      const count = document.getElementById('count');
      
      if (!currentRows || currentRows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="color:#666">No hay alumnos con esos filtros.</td></tr>';
        count.textContent = '0';
        listadoPaginator.renderControls('f_pagination');
        return;
      }

      const pageItems = listadoPaginator.getCurrentPageItems();
      const allPageIds = pageItems.map(r => r.id);
      const byId = {};
      pageItems.forEach(r => { byId[String(r.id)] = r; });

      count.textContent = String(currentRows.length);

      // Encabezado de la tabla
      theadRow.innerHTML = `
        <th>Nombres</th>
        <th>Apellidos</th>
        <th>CI</th>
        <th>Curso</th>
        <th>Grupo</th>
        <th>Carrera</th>
        <th>Facultad</th>
        <th>Acciones</th>
      `;

      tbody.innerHTML = pageItems.map(r => {
        return `
          <tr>
            <td>
              ${(() => {
                const nm = (r.nombres || r.nombre || '').trim();
                return nm
                  ? `<button class="nc-link" data-act="view" data-id="${r.id}">${escapeHtml(nm)}</button>`
                  : '';
              })()}
            </td>
            <td>${escapeHtml(r.apellidos || r.apellido || '')}</td>
            <td>${escapeHtml(r.ci)}</td>
            <td>${escapeHtml(r.curso_nombre || '')}</td>
            <td>${escapeHtml(r.aula_nombre || '')}</td>
            <td>${escapeHtml(r.carrera_nombre || r.carrera || '')}</td>
            <td>${escapeHtml(r.facultad_nombre || r.facultad || '')}</td>
            <td>
              ${userPermissions.canManageStudents() ? `
                <button class="nc-btn secondary" data-act="edit" data-id="${r.id}" style="padding:6px 8px">Editar</button>
                <button class="nc-btn danger" data-act="del" data-id="${r.id}" style="padding:6px 8px;margin-left:4px">Eliminar</button>
              ` : ''}
            </td>
          </tr>
        `;
      }).join('');


      // Event listeners para acciones
      tbody.onclick = async (ev) => {
        const btn = ev.target.closest('button[data-act]');
        if (!btn) return;
        const id = btn.getAttribute('data-id');
        const act = btn.getAttribute('data-act');
        const row = byId[String(id)];
        if (!row) return;

        if (act === 'view') {
          const idx = currentRows.findIndex(r => String(r.id) === String(id));
          openAlumnoViewModal(row, (currentRows.length > 1 && idx >= 0) ? { list: currentRows, index: idx } : null);
          return;
        }

        if (act === 'del') {
          if (!confirm(`Eliminar alumno: ${row.nombres} ${row.apellidos} (CI ${row.ci})?`)) return;
          setLoading(true);
          try {
            await api('/alumnos/' + id, { method: 'DELETE' });
            toast('Alumno eliminado.');
            runListadoSearch();
          } catch (e) {
            toast('ERROR: ' + e.message, 'err');
          } finally {
            setLoading(false);
          }
          return;
        }

        if (act === 'edit') {
          openAlumnoEditModal(row, runListadoSearch);
        }
      };

      listadoPaginator.renderControls('f_pagination');
    }

    async function runListadoSearch() {
      const search = document.getElementById('f_search').value.trim();
      const curso_id = document.getElementById('f_curso').value;
      const aula_id = document.getElementById('f_aula').value;
      const sort_by = document.getElementById('f_sortby').value;
      const order = document.getElementById('f_order').value;

      const qs = new URLSearchParams();
      if (search) qs.set('search', search);
      if (curso_id) qs.set('curso_id', curso_id);
      if (aula_id) qs.set('aula_id', aula_id);
      if (sort_by) qs.set('sort_by', sort_by);
      if (order) qs.set('order', order);

      setLoading(true);
      try {
        const rows = await api('/alumnos' + (qs.toString() ? `?${qs}` : ''));
        currentRows = rows;
        listadoSelector.clear();
        listadoPaginator.setItems(rows);
        renderListadoTable();
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    runListadoSearch();
  }

  // ===================== NUEVO ALUMNO (facultad/carrera con selects) =====================
  function renderNuevoAlumno() {
    view.innerHTML = '';

    const card = el('div', { class: 'nc-card' });
    card.innerHTML = `
      <h3 style="margin:0 0 10px">Agregar alumno</h3>
      <div class="nc-row">
        <div class="nc-field">
          <label>Nombres *</label>
          <input id="n_nombres" placeholder="Ej: Juan Carlos" />
        </div>
        <div class="nc-field">
          <label>Apellidos *</label>
          <input id="n_apellidos" placeholder="Ej: Pérez Gómez" />
        </div>
        <div class="nc-field">
          <label>CI *</label>
          <input id="n_ci" placeholder="Ej: 1234567" />
        </div>
        <div class="nc-field">
          <label>Curso</label>
          <select id="n_curso">${cursosOptions(false)}</select>
        </div>
        <div class="nc-field">
          <label>Grupo</label>
          <select id="n_aula">${aulasOptions(false)}</select>
        </div>
        <div class="nc-field">
          <label>Facultad</label>
          <select id="n_facultad">${facultadesOptions(false)}</select>
        </div>
        <div class="nc-field">
          <label>Carrera</label>
          <select id="n_carrera">
            <option value="">(Sin carrera)</option>
          </select>
        </div>
      </div>

      <div class="nc-row" style="margin-top:10px;align-items:flex-start;gap:14px">
        <div style="min-width:140px">
          <div style="font-size:12px;color:#555;margin-bottom:6px">Foto</div>
          <div id="n_foto_box">
            <div class="nc-photo-badge">SIN FOTO</div>
          </div>
        </div>
        <div class="nc-field" style="flex:1">
          <label>URL de foto (opcional)</label>
          <input id="n_foto_url" placeholder="https://..." />
          <div style="margin-top:8px">
            <label style="font-size:12px;color:#555">O subir una imagen</label>
            <input id="n_foto_file" type="file" accept="image/*,.heic,.heif" />
            <div style="font-size:12px;color:#666;margin-top:6px">* No es obligatorio. Si subís, se guarda en la Biblioteca de Medios de WordPress.</div>
          </div>
        </div>
      </div>

      <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="nc-btn primary" id="btn_guardar">Guardar</button>
        <button class="nc-btn secondary" id="btn_ir_listado">Ver listado</button>
      </div>

      <div style="margin-top:10px;color:#666;font-size:13px">
        * Obligatorio
      </div>
    `;

    view.appendChild(card);

    const selFac = document.getElementById('n_facultad');
    const selCar = document.getElementById('n_carrera');

    selFac.onchange = async () => {
      const fid = selFac.value;
      selCar.innerHTML = `<option value="">(Sin carrera)</option>`;
      if (!fid) return;
      setLoading(true);
      try {
        const cars = await loadCarreras(fid);
        selCar.innerHTML = [`<option value="">(Sin carrera)</option>`]
          .concat(cars.map(c => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`))
          .join('');
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    };

    // Foto opcional (URL o subida)
    const nFotoBox = document.getElementById('n_foto_box');
    const nFotoUrl = document.getElementById('n_foto_url');
    const nFotoFile = document.getElementById('n_foto_file');

    function renderNewFoto() {
      const url = normalizeFotoUrl(nFotoUrl.value || '');
      if (!url) {
        nFotoBox.innerHTML = `<div class="nc-photo-badge">SIN FOTO</div>`;
        return;
      }
      nFotoBox.innerHTML = `<img class="nc-photo" alt="Foto" src="${escapeAttr(url)}" />`;
    }

    nFotoUrl.oninput = renderNewFoto;
    nFotoFile.addEventListener('change', async (e) => {
      const file = e.target.files && e.target.files[0];
      if (!file) {
        delete window.__pendingFotoFile;
        return;
      }
      setLoading(true);
      let fileToUse;
      try {
        fileToUse = await ensureFotoFileForUpload(file);
      } catch (err) {
        toast('Error al procesar la imagen: ' + err.message, 'err');
        e.target.value = '';
        delete window.__pendingFotoFile;
        setLoading(false);
        return;
      }
      const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
      if (!allowedTypes.includes(fileToUse.type)) {
        toast('Tipo de archivo no permitido. Usa JPG, PNG, GIF, WEBP o HEIC.', 'err');
        e.target.value = '';
        delete window.__pendingFotoFile;
        setLoading(false);
        return;
      }
      const maxSize = 5 * 1024 * 1024; // 5MB
      if (fileToUse.size > maxSize) {
        toast('El archivo es demasiado grande. Máximo 5MB.', 'err');
        e.target.value = '';
        delete window.__pendingFotoFile;
        setLoading(false);
        return;
      }
      try {
        const url = await uploadToMediaLibrary(fileToUse);
        nFotoUrl.value = url;
        toast('Foto cargada. Se vinculará al crear el alumno.', 'ok');
      } catch (err) {
        toast('ERROR al cargar foto: ' + err.message, 'err');
        e.target.value = '';
      } finally {
        setLoading(false);
      }
      
      // ✅ Abrir cropper antes de subir
      /*openImageCropper(file, async (croppedFile) => {
        *setLoading(true);
        try {
          const url = await uploadToMediaLibrary(croppedFile);
          nFotoUrl.value = url;
          renderNewFoto();
          toast('Foto recortada y cargada correctamente.', 'ok');
        } catch (err) {
          toast('ERROR al cargar foto: ' + err.message, 'err');
          e.target.value = '';
        } finally {
          setLoading(false);
        }
      });*/
    });
    
    // 2. En el botón de "Crear Alumno" (después de la creación exitosa):
    // Buscar donde se crea el alumno y agregar esto después:
    /*
    const nuevoAlumno = await api('/alumnos', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    
    const nuevoAlumnoId = nuevoAlumno.id;
    
    // Si hay foto pendiente (OPCIÓN B), subirla ahora
    if (window.__pendingFotoFile && nuevoAlumnoId) {
      try {
        setLoading(true);
        const fotoUrl = await uploadAlumnoFoto(nuevoAlumnoId, window.__pendingFotoFile);
        console.log('Foto subida exitosamente:', fotoUrl);
        toast('Alumno creado con foto correctamente.');
      } catch (err) {
        console.error('Error subiendo foto:', err);
        toast('Alumno creado, pero hubo un error al subir la foto: ' + err.message, 'err');
      } finally {
        setLoading(false);
        delete window.__pendingFotoFile;
      }
    }
    */
    
    // ============================================================================
    // FUNCIÓN AUXILIAR: Preview de imagen antes de subir
    // ============================================================================
    
    
    function previewImageFile(file, previewElement) {
      if (!file || !previewElement) return;
      
      const reader = new FileReader();
      reader.onload = (e) => {
        previewElement.innerHTML = `<img src="${e.target.result}" alt="Preview" style="max-width: 100%; height: auto;" />`;
      };
      reader.onerror = () => {
        console.error('Error leyendo archivo para preview');
      };
      reader.readAsDataURL(file);
    }
    
    // USO EJEMPLO:
    /*
    const fileInput = document.getElementById('m_foto_file');
    const previewBox = document.getElementById('m_foto_box');
    
    fileInput.addEventListener('change', (e) => {
      const file = e.target.files[0];
      if (file) {
        previewImageFile(file, previewBox);
      }
    });
    */
    
    // ============================================================================
    // DEBUGGING: Función para verificar FormData
    // ============================================================================
    
    /**
     * Muestra en consola el contenido de un FormData (útil para debugging)
     * @param {FormData} formData 
     */
    function debugFormData(formData) {
      console.log('=== FormData Contents ===');
      for (let pair of formData.entries()) {
        console.log(pair[0] + ':', pair[1]);
      }
      console.log('========================');
    }
    
    // USO:
    /*
    const formData = new FormData();
    formData.append('foto', file);
    debugFormData(formData); // Ver en consola qué se está enviando
    */
    
    // ============================================================================
    // VALIDACIÓN COMPLETA DE IMÁGENES (Opcional pero recomendada)
    // ============================================================================
    
    /**
     * Valida un archivo de imagen antes de subirlo (usar después de ensureFotoFileForUpload si puede ser HEIC).
     */
    function validateImageFile(file) {
      if (!file) {
        return { valid: false, error: 'No se seleccionó ningún archivo' };
      }
      const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
      if (!allowedTypes.includes(file.type)) {
        return { valid: false, error: 'Tipo de archivo no permitido. Usa JPG, PNG, GIF, WEBP o HEIC.' };
      }
    
      // Validar tamaño (5MB)
      const maxSize = 5 * 1024 * 1024;
      if (file.size > maxSize) {
        const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
        return { 
          valid: false, 
          error: `El archivo es demasiado grande (${sizeMB}MB). Máximo 5MB.` 
        };
      }
    
      // Validar nombre de archivo (opcional)
      const invalidChars = /[<>:"/\\|?*\x00-\x1F]/g;
      if (invalidChars.test(file.name)) {
        return { 
          valid: false, 
          error: 'El nombre del archivo contiene caracteres no permitidos.' 
        };
      }
    
      return { valid: true, error: null };
    }
    
    // USO:
    /*
    const file = e.target.files[0];
    const validation = validateImageFile(file);
    
    if (!validation.valid) {
      toast(validation.error, 'err');
      e.target.value = ''; // Limpiar input
      return;
    }
    
    // Proceder con la subida...
    */
    
    // ============================================================================
    // ELIMINACIÓN DE FOTO (Usar endpoint DELETE)
    // ============================================================================
    
    /**
     * Elimina la foto de un alumno
     */
    async function deleteAlumnoFoto(alumnoId) {
      if (!alumnoId) {
        throw new Error('ID de alumno es requerido');
      }
    
      const url = `${API}/alumnos/${alumnoId}/foto`;
      
      const res = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-WP-Nonce': NC_APP.nonce
        }
      });
    
      const contentType = res.headers.get('content-type') || '';
      const data = contentType.includes('application/json')
        ? await res.json().catch(() => ({}))
        : await res.text();
    
      if (!res.ok) {
        const msg = (data && data.message) 
          ? data.message 
          : (typeof data === 'string' ? data : res.statusText);
        throw new Error(msg);
      }
    
      return data;
    }
    
    // USO:
    /*
    // Agregar botón "Eliminar foto" en el modal
    const btnEliminarFoto = el('button', { 
      type: 'button',
      class: 'nc-btn-danger'
    }, 'Eliminar foto');
    
    btnEliminarFoto.addEventListener('click', async () => {
      if (!confirm('¿Eliminar la foto actual?')) return;
      
      setLoading(true);
      try {
        await deleteAlumnoFoto(alumnoId);
        body.querySelector('#m_foto_url').value = '';
        renderFotoPreview('');
        toast('Foto eliminada correctamente.');
      } catch (err) {
        toast('ERROR al eliminar foto: ' + err.message, 'err');
      } finally {
        setLoading(false);
      }
    });
    */


    nFotoFile.onchange = async () => {
      const file = nFotoFile.files && nFotoFile.files[0];
      if (!file) return;
      setLoading(true);
      try {
        const url = await uploadToMediaLibrary(file);
        nFotoUrl.value = url;
        renderNewFoto();
        toast('Foto subida correctamente.');
      } catch (e) {
        toast('ERROR al subir foto: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    };
    

    renderNewFoto();

    document.getElementById('btn_ir_listado').onclick = () => openTab('alumnos');

    const btnGuardarNuevo = document.getElementById('btn_guardar');
    btnGuardarNuevo.onclick = withButtonLock(btnGuardarNuevo, async () => {
      const nombres = document.getElementById('n_nombres').value.trim();
      const apellidos = document.getElementById('n_apellidos').value.trim();
      const ci = document.getElementById('n_ci').value.trim();

      // Si seleccionó un archivo pero aún no se subió, lo subimos ahora
      const pendingFile = nFotoFile.files && nFotoFile.files[0];
      if (pendingFile && !((nFotoUrl.value || '').trim())) {
        try {
          setLoading(true);
          const url = await uploadToMediaLibrary(pendingFile);
          nFotoUrl.value = url;
          renderNewFoto();
        } catch (e) {
          toast('ERROR al subir foto: ' + e.message, 'err');
          setLoading(false);
          return;
        } finally {
          setLoading(false);
        }
      }

      const nFotoVal = document.getElementById('n_foto_url');
      const fotoUrlVal = (nFotoVal && nFotoVal.value) ? String(nFotoVal.value).trim() : '';
      const payload = {
        nombres,
        apellidos,
        ci,
        curso_id: Number(document.getElementById('n_curso').value || 0) || null,
        aula_id: Number(document.getElementById('n_aula').value || 0) || null,
        facultad_id: Number(document.getElementById('n_facultad').value || 0) || null,
        carrera_id: Number(document.getElementById('n_carrera').value || 0) || null,
        foto_url: fotoUrlVal || null
      };

      if (!nombres || !apellidos || !ci) {
        toast('Nombres, apellidos y CI son obligatorios.', 'err');
        return;
      }

      setLoading(true);
      try {
        const r = await api('/alumnos', { method: 'POST', body: JSON.stringify(payload) });
        toast('Alumno creado (id: ' + r.id + ').');
        document.getElementById('n_nombres').value = '';
        document.getElementById('n_apellidos').value = '';
        document.getElementById('n_ci').value = '';
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    }, { loadingText: 'Guardando...' });
  }

  // ===================== IMPORTAR ALUMNOS =====================
  function renderImportarAlumnos() {
    view.innerHTML = '';
    const card = el('div', { class: 'nc-card' });
    card.innerHTML = `
      <h3 style="margin:0 0 10px">Importar Alumnos desde Excel</h3>
      <div style="margin-bottom:20px;padding:15px;background:#f5f5f5;border-radius:8px;">
        <p style="margin:0 0 10px;"><strong>Instrucciones:</strong></p>
        <ul style="margin:0;padding-left:20px;color:#666;">
          <li>El archivo Excel debe tener las siguientes columnas: <strong>Nombres</strong>, <strong>Apellidos</strong>, <strong>CI</strong></li>
          <li>Columnas opcionales: <strong>Facultad</strong>, <strong>Carrera</strong>, <strong>Curso</strong>, <strong>Aula</strong></li>
          <li>Si las facultades, carreras, cursos o aulas no existen, se crearán automáticamente</li>
          <li>Los alumnos con CI duplicado se omitirán (a menos que actives "Actualizar existentes")</li>
        </ul>
      </div>
      <div class="nc-row">
        <div class="nc-field" style="flex:1">
          <label>Archivo Excel (.xlsx, .xls) *</label>
          <input id="imp_file" type="file" accept=".xlsx,.xls,.xlsm" />
          <div style="font-size:12px;color:#666;margin-top:6px">Formatos soportados: .xlsx, .xls, .xlsm</div>
        </div>
      </div>
      <div class="nc-row" style="margin-top:15px">
        <div class="nc-field">
          <label>Curso por defecto (opcional)</label>
          <select id="imp_curso">${cursosOptions(false)}</select>
        </div>
        <div class="nc-field">
          <label>Aula por defecto (opcional)</label>
          <select id="imp_aula">${aulasOptions(false)}</select>
        </div>
      </div>
      <div class="nc-row" style="margin-top:15px">
        <div class="nc-field">
          <label>
            <input id="imp_skip_duplicates" type="checkbox" checked style="width:auto;min-width:auto" />
            Omitir CIs duplicados
          </label>
        </div>
        <div class="nc-field">
          <label>
            <input id="imp_update_existing" type="checkbox" style="width:auto;min-width:auto" />
            Actualizar alumnos existentes (si el CI ya existe)
          </label>
        </div>
      </div>
      <div style="margin-top:20px;display:flex;gap:10px">
        <button class="nc-btn" id="imp_btn_importar">Importar</button>
        <button class="nc-btn secondary" id="imp_btn_limpiar">Limpiar</button>
      </div>
      <div id="imp_results" style="margin-top:20px;display:none"></div>
    `;
    view.appendChild(card);

    document.getElementById('imp_btn_limpiar').onclick = () => {
      document.getElementById('imp_file').value = '';
      document.getElementById('imp_curso').value = '';
      document.getElementById('imp_aula').value = '';
      document.getElementById('imp_skip_duplicates').checked = true;
      document.getElementById('imp_update_existing').checked = false;
      document.getElementById('imp_results').style.display = 'none';
    };

    const btnImportar = document.getElementById('imp_btn_importar');
    btnImportar.onclick = withButtonLock(btnImportar, async () => {
      const fileInput = document.getElementById('imp_file');
      const file = fileInput.files && fileInput.files[0];
      if (!file) {
        toast('Seleccioná un archivo Excel.', 'err');
        return;
      }

      const curso_id = document.getElementById('imp_curso').value;
      const aula_id = document.getElementById('imp_aula').value;
      const skip_duplicates = document.getElementById('imp_skip_duplicates').checked;
      const update_existing = document.getElementById('imp_update_existing').checked;

      setLoading(true);
      const resultsDiv = document.getElementById('imp_results');
      resultsDiv.style.display = 'none';

      try {
        const formData = new FormData();
        formData.append('excel', file);
        if (curso_id) formData.append('curso_id', curso_id);
        if (aula_id) formData.append('aula_id', aula_id);
        formData.append('skip_duplicates', skip_duplicates ? '1' : '0');
        formData.append('update_existing', update_existing ? '1' : '0');

        const res = await fetch(`${API}/alumnos/import?nc_ts=${Date.now()}`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: NC_APP.nonce ? { 'X-WP-Nonce': NC_APP.nonce } : {},
          body: formData
        });

        const data = await res.json();
        if (!res.ok) {
          throw new Error(data.message || 'Error al importar');
        }

        const results = data;
        let html = '<div style="padding:15px;background:#f0f7ff;border-radius:8px;border:1px solid #1a73e8;">';
        html += '<h4 style="margin:0 0 10px;color:#1a73e8;">Resultados de la importación</h4>';
        html += `<p style="margin:5px 0;"><strong>Total de filas procesadas:</strong> ${results.total_rows || 0}</p>`;
        html += `<p style="margin:5px 0;color:#0f5132;"><strong>✓ Importados:</strong> ${results.imported || 0}</p>`;
        html += `<p style="margin:5px 0;color:#084298;"><strong>↻ Actualizados:</strong> ${results.updated || 0}</p>`;
        html += `<p style="margin:5px 0;color:#856404;"><strong>⊘ Omitidos:</strong> ${results.skipped || 0}</p>`;
        
        if (results.errors && results.errors.length > 0) {
          html += '<div style="margin-top:15px;"><strong style="color:#b00020;">Errores encontrados:</strong><ul style="margin:5px 0;padding-left:20px;">';
          results.errors.slice(0, 10).forEach(err => {
            html += `<li style="color:#666;">Línea ${err.line}: ${escapeHtml(err.message)}</li>`;
          });
          if (results.errors.length > 10) {
            html += `<li style="color:#666;">... y ${results.errors.length - 10} errores más</li>`;
          }
          html += '</ul></div>';
        }
        html += '</div>';

        resultsDiv.innerHTML = html;
        resultsDiv.style.display = 'block';
        toast(`Importación completada: ${results.imported || 0} importados, ${results.updated || 0} actualizados`);
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
        resultsDiv.innerHTML = `<div style="padding:15px;background:#ffe0e0;border-radius:8px;border:1px solid #b00020;color:#b00020;">Error: ${escapeHtml(e.message)}</div>`;
        resultsDiv.style.display = 'block';
      } finally {
        setLoading(false);
      }
    }, { loadingText: 'Importando...' });
  }

  // ===================== CONDUCTA =====================
  function renderConducta() {
    if (window.NC_AppState) window.NC_AppState.persistRoute({ mainTab: 'conducta-main', screen: 'conducta' });
    view.innerHTML = '';

    const card = el('div', { class: 'nc-card' });
    card.innerHTML = `
      <h3 style="margin:0 0 10px">Registrar conducta</h3>

      <div class="nc-row">
        <div class="nc-field">
          <label>Fecha *</label>
          <input id="c_fecha" type="date" />
        </div>
        <div class="nc-field">
          <label>Curso *</label>
          <select id="c_curso">${cursosOptions(false)}</select>
        </div>
        <div class="nc-field">
          <label>Aula *</label>
          <select id="c_aula">${aulasOptions(false)}</select>
        </div>
        <button class="nc-btn" id="btn_cargar_alumnos">Cargar alumnos</button>
      </div>

      <div class="nc-row" style="margin-top:10px">
        <div class="nc-field" style="flex:1;min-width:320px">
          <label>Observación (opcional)</label>
          <textarea id="c_obs" placeholder="Notas generales..."></textarea>
        </div>
      </div>
    `;

    const groupOpts = CONDUCTA_OPCIONES.map(o => `<option value="${o.value}" ${o.value === 0 ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
    const card2 = el('div', { class: 'nc-card' }, `
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px">
        <h3 style="margin:0">Valor para todo el grupo</h3>
        <button class="nc-btn secondary" id="btn_aplicar_todos" disabled>Aplicar a todos</button>
      </div>
      <div class="nc-row" style="flex-wrap:wrap;margin-bottom:14px;padding:10px;background:#f8f9fa;border-radius:10px">
        <div class="nc-field" style="min-width:160px"><label>Responsabilidad Académica</label><select id="nc_g_responsabilidad_academica">${groupOpts}</select></div>
        <div class="nc-field" style="min-width:160px"><label>Respeto y Convivencia</label><select id="nc_g_respeto_convivencia">${groupOpts}</select></div>
        <div class="nc-field" style="min-width:160px"><label>Participación y Actitud</label><select id="nc_g_participacion_actitud">${groupOpts}</select></div>
        <div class="nc-field" style="min-width:160px"><label>Autocontrol y Disciplina</label><select id="nc_g_autocontrol_disciplina">${groupOpts}</select></div>
        <div class="nc-field" style="min-width:160px"><label>Autonomía y Compromiso</label><select id="nc_g_autonomia_compromiso">${groupOpts}</select></div>
        <div class="nc-field" style="min-width:160px"><label>Presentación y Orden</label><select id="nc_g_presentacion_orden">${groupOpts}</select></div>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <h3 style="margin:0">Puntuación por alumno</h3>
        <div id="c_count" class="nc-pill">0</div>
      </div>
      
      <div id="c_bulk_actions" style="display:none"></div>
      
      <div style="overflow:auto;margin-top:10px">
        <table>
          <thead>
            <tr id="c_thead_row">
              <th>Alumno</th>
              <th>CI</th>
              <th>Resp.Acad.</th><th>Respeto/Conv.</th><th>Part.Act.</th><th>Autocont.</th><th>Auton.Comp.</th><th>Pres.Orden</th>
            </tr>
          </thead>
          <tbody id="c_tbody">
            <tr><td colspan="8" style="color:#666">Cargá alumnos para puntuar.</td></tr>
          </tbody>
        </table>
      </div>
      
      <div id="c_pagination"></div>
      
      <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="nc-btn primary" id="btn_guardar_eval" disabled>Guardar evaluación</button>
      </div>
    `);

    view.appendChild(card);
    view.appendChild(card2);

    // Paginador y selector
    const conductaPaginator = new Paginator({
      itemsPerPage: 20,
      onPageChange: () => renderConductaTable()
    });

    const conductaSelector = new BulkSelector({
      onSelectionChange: () => {
        conductaSelector.renderActionBar('c_bulk_actions', []);
      }
    });

    let currentRows = [];
    /** Valores "valor para todo el grupo" (persisten al cambiar de página) */
    let conductaGroupValues = {};
    /** Overrides por alumno cuando se cambia individualmente */
    let conductaIndividualScores = {};
    let conductaDraftTimer = null;

    function scheduleConductaDraftSave() {
      if (!window.NC_AppState) return;
      if (conductaDraftTimer) clearTimeout(conductaDraftTimer);
      conductaDraftTimer = setTimeout(() => {
        conductaDraftTimer = null;
        if (!currentRows.length) return;
        window.NC_AppState.setConductaDraft({
          fecha: document.getElementById('c_fecha')?.value || '',
          curso_id: document.getElementById('c_curso')?.value || '',
          aula_id: document.getElementById('c_aula')?.value || '',
          obs: document.getElementById('c_obs')?.value || '',
          groupValues: Object.assign({}, conductaGroupValues),
          individualScores: JSON.parse(JSON.stringify(conductaIndividualScores)),
          alumnoIds: currentRows.map(r => Number(r.id)),
        });
      }, 400);
    }

    function getConductaScore(alumnoId, key) {
      const k = String(key);
      if (conductaIndividualScores[alumnoId] != null && conductaIndividualScores[alumnoId][k] !== undefined)
        return Number(conductaIndividualScores[alumnoId][k]);
      if (conductaGroupValues[k] !== undefined) return Number(conductaGroupValues[k]);
      return 5;
    }

    // default fecha hoy
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    document.getElementById('c_fecha').value = `${yyyy}-${mm}-${dd}`;

    (function () {
      const btn = document.getElementById('btn_cargar_alumnos');
      if (btn) btn.onclick = withButtonLock(btn, loadAlumnosForEval, { loadingText: 'Cargando...' });
    })();

    function renderConductaTable() {
      const tbody = document.getElementById('c_tbody');
      const theadRow = document.getElementById('c_thead_row');
      const count = document.getElementById('c_count');
      
      if (!currentRows || currentRows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" style="color:#666">No hay alumnos para ese curso/aula.</td></tr>`;
        count.textContent = '0';
        document.getElementById('btn_guardar_eval').disabled = true;
        conductaPaginator.renderControls('c_pagination');
        return;
      }

      const pageItems = conductaPaginator.getCurrentPageItems();
      const allPageIds = pageItems.map(r => r.id);
      const byId = {};
      pageItems.forEach(r => { byId[String(r.id)] = r; });

      count.textContent = String(currentRows.length);

      // Actualizar encabezado con checkbox si es admin
      if (userPermissions.canManageStudents()) {
        theadRow.innerHTML = `
          <th class="nc-checkbox-cell">
            <input type="checkbox" id="c_select_all" ${conductaSelector.isAllSelected(allPageIds) ? 'checked="checked"' : ''}>
          </th>
          <th>Alumno</th>
          <th>CI</th>
          <th>Resp.Acad.</th><th>Respeto/Conv.</th><th>Part.Act.</th><th>Autocont.</th><th>Auton.Comp.</th><th>Pres.Orden</th>
        `;
      } else {
        theadRow.innerHTML = `
          <th>Alumno</th>
          <th>CI</th>
          <th>Resp.Acad.</th><th>Respeto/Conv.</th><th>Part.Act.</th><th>Autocont.</th><th>Auton.Comp.</th><th>Pres.Orden</th>
        `;
      }

      const scoreSel = (name, alumnoId, value) => {
        const val = value !== undefined ? Number(value) : 5;
        const opts = CONDUCTA_OPCIONES.map(o => `<option value="${o.value}" ${o.value === val ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
        return `<select data-alumno="${alumnoId}" data-k="${name}">${opts}</select>`;
      };

      tbody.innerHTML = pageItems.map(r => {
        const isSelected = conductaSelector.isSelected(r.id);
        return `
          <tr class="nc-scores ${isSelected ? 'selected' : ''}" data-alumno="${r.id}">
            ${userPermissions.canManageStudents() ? `
              <td class="nc-checkbox-cell">
                <input type="checkbox" class="c-checkbox" data-id="${r.id}" ${isSelected ? 'checked="checked"' : ''}>
              </td>
            ` : ''}
            <td>
              <button class="nc-link" data-act="view" data-id="${r.id}">
                ${escapeHtml(((r.apellidos || r.apellido) ? ((r.apellidos || r.apellido) + ', ') : '') + (r.nombres || r.nombre || ''))}
              </button>
            </td>
            <td>${escapeHtml(r.ci)}</td>
            <td>${scoreSel('responsabilidad_academica', r.id, getConductaScore(r.id, 'responsabilidad_academica'))}</td>
            <td>${scoreSel('respeto_convivencia', r.id, getConductaScore(r.id, 'respeto_convivencia'))}</td>
            <td>${scoreSel('participacion_actitud', r.id, getConductaScore(r.id, 'participacion_actitud'))}</td>
            <td>${scoreSel('autocontrol_disciplina', r.id, getConductaScore(r.id, 'autocontrol_disciplina'))}</td>
            <td>${scoreSel('autonomia_compromiso', r.id, getConductaScore(r.id, 'autonomia_compromiso'))}</td>
            <td>${scoreSel('presentacion_orden', r.id, getConductaScore(r.id, 'presentacion_orden'))}</td>
          </tr>
        `;
      }).join('');

      const btnGuardarEval = document.getElementById('btn_guardar_eval');
      btnGuardarEval.disabled = false;
      btnGuardarEval.onclick = withButtonLock(btnGuardarEval, saveEval, { loadingText: 'Guardando...' });

      // Event listener para checkbox maestro
      if (userPermissions.canManageStudents()) {
        const selectAllCheckbox = document.getElementById('c_select_all');
        if (selectAllCheckbox) {
          // Remover listener anterior si existe
          selectAllCheckbox.onchange = null;
          selectAllCheckbox.onchange = (e) => {
            e.stopPropagation();
            const wantsAllSelected = e.target.checked;
            const allCurrentlySelected = conductaSelector.isAllSelected(allPageIds);
            
            // Solo actualizar si hay discrepancia
            if (wantsAllSelected !== allCurrentlySelected) {
              conductaSelector.toggleAll(allPageIds);
            }
            // Re-renderizar para sincronizar visualmente
            renderConductaTable();
          };
        }

        // Event listeners para checkboxes individuales
        document.querySelectorAll('.c-checkbox').forEach(checkbox => {
          // Remover listeners anteriores si existen
          checkbox.onchange = null;
          checkbox.onclick = null;
          checkbox.onchange = (e) => {
            e.stopPropagation();
            const id = parseInt(e.target.dataset.id);
            const wantsChecked = e.target.checked;
            
            // Actualizar el estado interno según lo que el usuario quiere
            if (wantsChecked) {
              // Usuario quiere seleccionar -> agregar al selector
              conductaSelector.selectedIds.add(id);
            } else {
              // Usuario quiere deseleccionar -> quitar del selector
              conductaSelector.selectedIds.delete(id);
            }
            // Notificar el cambio de selección
            conductaSelector.onSelectionChange(conductaSelector.getSelectedIds());
            // Re-renderizar para sincronizar visualmente
            renderConductaTable();
          };
        });
      }

      // Event listeners para acciones
      tbody.onclick = (ev) => {
        const btn = ev.target.closest('button[data-act="view"]');
        if (!btn) return;
        const id = btn.getAttribute('data-id');
        const row = byId[String(id)];
        if (row) openAlumnoViewModal(row);
      };

      // Botón aplicar a todos: guarda valores de grupo y aplica a todos los alumnos (todas las páginas)
      const btnAplicar = document.getElementById('btn_aplicar_todos');
      if (btnAplicar) {
        btnAplicar.disabled = false;
        btnAplicar.onclick = () => {
          const v1 = document.getElementById('nc_g_responsabilidad_academica').value;
          const v2 = document.getElementById('nc_g_respeto_convivencia').value;
          const v3 = document.getElementById('nc_g_participacion_actitud').value;
          const v4 = document.getElementById('nc_g_autocontrol_disciplina').value;
          const v5 = document.getElementById('nc_g_autonomia_compromiso').value;
          const v6 = document.getElementById('nc_g_presentacion_orden').value;
          conductaGroupValues = { responsabilidad_academica: v1, respeto_convivencia: v2, participacion_actitud: v3, autocontrol_disciplina: v4, autonomia_compromiso: v5, presentacion_orden: v6 };
          renderConductaTable();
          scheduleConductaDraftSave();
          toast('Valores aplicados a todos los alumnos (todas las páginas). Podés modificar cada alumno individualmente.');
        };
      }

      // Al cambiar un select individual, guardar override de ese alumno
      tbody.querySelectorAll('select[data-alumno][data-k]').forEach(sel => {
        sel.onchange = () => {
          const alumnoId = Number(sel.getAttribute('data-alumno'));
          const k = sel.getAttribute('data-k');
          if (!conductaIndividualScores[alumnoId]) conductaIndividualScores[alumnoId] = {};
          conductaIndividualScores[alumnoId][k] = sel.value;
          scheduleConductaDraftSave();
        };
      });

      conductaPaginator.renderControls('c_pagination');
    }

    async function loadAlumnosForEval(opts) {
      const mergeDraft = opts && opts.mergeDraft ? opts.mergeDraft : null;
      const fecha = document.getElementById('c_fecha').value;
      const curso_id = document.getElementById('c_curso').value;
      const aula_id  = document.getElementById('c_aula').value;

      if (!fecha || !curso_id || !aula_id) {
        toast('Fecha, curso y aula son obligatorios.', 'err');
        return;
      }

      const qs = new URLSearchParams({ curso_id, aula_id });

      setLoading(true);
      try {
        const rows = await api('/alumnos?' + qs.toString());
        currentRows = rows;
        if (mergeDraft) {
          conductaGroupValues = mergeDraft.groupValues ? Object.assign({}, mergeDraft.groupValues) : {};
          conductaIndividualScores = mergeDraft.individualScores ? JSON.parse(JSON.stringify(mergeDraft.individualScores)) : {};
        } else {
          conductaGroupValues = {};
          conductaIndividualScores = {};
        }
        conductaSelector.clear();
        conductaPaginator.setItems(rows);
        renderConductaTable();
        scheduleConductaDraftSave();
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    async function saveEval() {
      const fecha = document.getElementById('c_fecha').value;
      const curso_id = Number(document.getElementById('c_curso').value);
      const aula_id  = Number(document.getElementById('c_aula').value);
      const observacion = document.getElementById('c_obs').value.trim();

      const items = [];
      // Recopilar datos de todos los alumnos desde el estado en memoria (persiste al cambiar de página)
      for (const row of currentRows) {
        const alumno_id = Number(row.id);
        items.push({
          alumno_id,
          responsabilidad_academica: getConductaScore(alumno_id, 'responsabilidad_academica'),
          respeto_convivencia: getConductaScore(alumno_id, 'respeto_convivencia'),
          participacion_actitud: getConductaScore(alumno_id, 'participacion_actitud'),
          autocontrol_disciplina: getConductaScore(alumno_id, 'autocontrol_disciplina'),
          autonomia_compromiso: getConductaScore(alumno_id, 'autonomia_compromiso'),
          presentacion_orden: getConductaScore(alumno_id, 'presentacion_orden'),
        });
      }

      const payload = { fecha, curso_id, aula_id, observacion, items };

      setLoading(true);
      try {
        const r = await api('/evaluaciones', { method: 'POST', body: JSON.stringify(payload) });
        toast('Evaluación guardada. ID: ' + r.id);
        document.getElementById('c_obs').value = '';
        if (window.NC_AppState) window.NC_AppState.setConductaDraft(null);
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    ['c_fecha', 'c_curso', 'c_aula', 'c_obs'].forEach(fid => {
      const inp = document.getElementById(fid);
      if (inp) inp.addEventListener('change', scheduleConductaDraftSave);
      if (inp && inp.tagName === 'TEXTAREA') inp.addEventListener('input', scheduleConductaDraftSave);
    });

    (async function restoreConductaDraftIfAny() {
      if (!window.NC_AppState) return;
      const d = window.NC_AppState.load().conductaDraft;
      if (!d || !d.aula_id || !d.curso_id) return;
      const fechaEl = document.getElementById('c_fecha');
      const cursoEl = document.getElementById('c_curso');
      const aulaEl = document.getElementById('c_aula');
      const obsEl = document.getElementById('c_obs');
      if (d.fecha && fechaEl) fechaEl.value = d.fecha;
      if (d.curso_id && cursoEl) cursoEl.value = String(d.curso_id);
      if (d.aula_id && aulaEl) aulaEl.value = String(d.aula_id);
      if (d.obs != null && obsEl) obsEl.value = d.obs;
      await loadAlumnosForEval({
        mergeDraft: {
          groupValues: d.groupValues || {},
          individualScores: d.individualScores || {},
        },
      });
    })();
  }

  // ===================== CRUD: CURSOS =====================
  function renderCursosCrud() {
    view.innerHTML = '';

    const card = el('div', { class: 'nc-card' });
    card.innerHTML = `
      <h3 style="margin:0 0 10px">Cursos (crear / editar / eliminar)</h3>
      <div class="nc-row">
        <div class="nc-field">
          <label>Nuevo curso</label>
          <input id="curso_new" placeholder="Ej: Curso de Medicina" />
        </div>
        <button class="nc-btn" id="curso_add">Agregar</button>
        <button class="nc-btn secondary" id="curso_reload">Recargar</button>
      </div>
    `;

    const list = el('div', { class: 'nc-card' }, `
      <div class="nc-inline" style="justify-content:space-between">
        <h3 style="margin:0">Listado</h3>
        <div class="nc-pill" id="curso_count">0</div>
      </div>
      <div style="overflow:auto;margin-top:10px">
        <table>
          <thead><tr><th>ID</th><th>Nombre</th><th>Activo</th><th>Acciones</th></tr></thead>
          <tbody id="curso_tbody"></tbody>
        </table>
      </div>
    `);

    view.appendChild(card);
    view.appendChild(list);

    let cursosCrudList = [];
    async function loadCursosCrud() {
      try {
        const res = await api('/cursos?activo=all');
        cursosCrudList = Array.isArray(res) ? res : (res && res.items ? res.items : []);
      } catch (_) {
        cursosCrudList = [];
      }
      paint();
    }

    (function () {
      const addBtn = document.getElementById('curso_add');
      const reloadBtn = document.getElementById('curso_reload');
      if (addBtn) addBtn.onclick = withButtonLock(addBtn, addCurso, { loadingText: 'Creando...' });
      if (reloadBtn) reloadBtn.onclick = withButtonLock(reloadBtn, async () => {
        baseLoaded = false;
        await loadBase(true);
        await loadCursosCrud();
        toast('Cursos recargados.');
      }, { loadingText: 'Recargando...' });
    })();

    loadCursosCrud();

    function paint() {
      const tbody = document.getElementById('curso_tbody');
      if (!tbody) return;
      document.getElementById('curso_count').textContent = String(cursosCrudList.length);

      if (!cursosCrudList.length) {
        tbody.innerHTML = `<tr><td colspan="4" style="color:#666">No hay cursos.</td></tr>`;
        return;
      }

      tbody.innerHTML = cursosCrudList.map(c => {
        const activo = Number(c.activo) !== 0;
        return `
        <tr data-id="${c.id}" data-activo="${activo ? '1' : '0'}">
          <td>${c.id}</td>
          <td>
            <input class="nc-input-sm" value="${escapeHtml(c.nombre)}" data-k="nombre" />
          </td>
          <td>
            <label class="nc-toggle-switch" title="${activo ? 'Desactivar curso' : 'Activar curso'}" style="position:relative;display:inline-block;width:44px;height:24px;cursor:pointer;vertical-align:middle">
              <input type="checkbox" role="switch" data-act="toggle" ${activo ? 'checked' : ''} style="opacity:0;width:0;height:0;position:absolute;margin:0" />
              <span class="nc-toggle-track" style="position:absolute;inset:0;background-color:${activo ? '#2e7d32' : '#ccc'};border-radius:24px;transition:background-color .2s"></span>
              <span class="nc-toggle-thumb" style="position:absolute;height:18px;width:18px;left:3px;top:3px;background:#fff;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .2s;transform:translateX(${activo ? '20px' : '0'})"></span>
            </label>
          </td>
          <td class="nc-actions">
            <button class="nc-btn secondary" data-act="save">Guardar</button>
            <button class="nc-btn danger" data-act="del">Eliminar</button>
          </td>
        </tr>
      `;
      }).join('');

      tbody.querySelectorAll('button[data-act="save"]').forEach(btn => { btn.onclick = withButtonLock(btn, saveCurso); });
      tbody.querySelectorAll('button[data-act="del"]').forEach(btn => { btn.onclick = withButtonLock(btn, delCurso); });
      tbody.querySelectorAll('input[data-act="toggle"]').forEach(inp => {
        const label = inp.closest('label');
        const track = label ? label.querySelector('.nc-toggle-track') : null;
        const thumb = label ? label.querySelector('.nc-toggle-thumb') : null;
        function syncSwitchVisual() {
          const on = inp.checked;
          if (track) track.style.backgroundColor = on ? '#2e7d32' : '#ccc';
          if (thumb) thumb.style.transform = on ? 'translateX(20px)' : 'translateX(0)';
        }
        inp.onchange = (e) => {
          syncSwitchVisual();
          const tr = e.target.closest('tr');
          if (!tr) return;
          // Mantener en sync el estado del row para otras acciones (Guardar / siguiente toggle)
          tr.dataset.activo = inp.checked ? '1' : '0';
          toggleCursoActivo(e, tr);
        };
      });
    }

    async function toggleCursoActivo(e, tr) {
      const row = tr || (e && e.target && e.target.closest('tr'));
      if (!row) return;
      const id = row.dataset.id;
      const nombre = row.querySelector('input[data-k="nombre"]').value.trim();
      const inp = row.querySelector('input[data-act="toggle"]');
      const activoPrevio = row.dataset.activo === '1';
      // Usar el estado real del switch (no el dataset), y persistirlo como 0/1
      const nuevoActivo = (inp && inp.checked) ? 1 : 0;
      setLoading(true);
      try {
        await api(`/cursos/${id}`, { method: 'PUT', body: JSON.stringify({ nombre, activo: nuevoActivo }) });
        await loadCursosCrud();
        baseLoaded = false;
        await loadBase(true);
        toast(nuevoActivo ? 'Curso activado.' : 'Curso desactivado.');
      } catch (err) {
        if (inp) {
          inp.checked = activoPrevio;
          const label = inp.closest('label');
          const track = label && label.querySelector('.nc-toggle-track');
          const thumb = label && label.querySelector('.nc-toggle-thumb');
          if (track) track.style.backgroundColor = activoPrevio ? '#2e7d32' : '#ccc';
          if (thumb) thumb.style.transform = activoPrevio ? 'translateX(20px)' : 'translateX(0)';
          row.dataset.activo = activoPrevio ? '1' : '0';
        }
        toast('ERROR: ' + err.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    async function addCurso() {
      const nombre = document.getElementById('curso_new').value.trim();
      if (!nombre) return toast('Escribí un nombre de curso.', 'err');

      setLoading(true);
      try {
        await api('/cursos', { method: 'POST', body: JSON.stringify({ nombre }) });
        document.getElementById('curso_new').value = '';
        baseLoaded = false;
        await loadBase(true);
        await loadCursosCrud();
        toast('Curso creado.');
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    async function saveCurso(e) {
      const tr = e.target.closest('tr');
      const id = tr.dataset.id;
      const nombre = tr.querySelector('input[data-k="nombre"]').value.trim();
      if (!nombre) return toast('Nombre inválido.', 'err');
      const activo = tr.dataset.activo === '1' ? 1 : 0;

      setLoading(true);
      try {
        await api(`/cursos/${id}`, { method: 'PUT', body: JSON.stringify({ nombre, activo }) });
        baseLoaded = false;
        await loadBase(true);
        await loadCursosCrud();
        toast('Curso actualizado.');
      } catch (err) {
        toast('ERROR: ' + err.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    async function delCurso(e) {
      const tr = e.target.closest('tr');
      const id = tr.dataset.id;
      if (!confirm('¿Eliminar este curso?')) return;

      setLoading(true);
      try {
        await api(`/cursos/${id}`, { method: 'DELETE' });
        baseLoaded = false;
        await loadBase(true);
        await loadCursosCrud();
        toast('Curso eliminado.');
      } catch (err) {
        toast('ERROR: ' + err.message, 'err');
      } finally {
        setLoading(false);
      }
    }
  }

  // ===================== CRUD: AULAS =====================
  function renderAulasCrud() {
    view.innerHTML = '';

    const card = el('div', { class: 'nc-card' });
    card.innerHTML = `
      <h3 style="margin:0 0 10px">Aulas (crear / editar / eliminar)</h3>
      <div class="nc-row">
        <div class="nc-field">
          <label>Nombre aula</label>
          <input id="aula_new_nombre" placeholder="Ej: 1ro A" />
        </div>
        <div class="nc-field">
          <label>Curso</label>
          <select id="aula_new_curso">${cursosOptions(false)}</select>
        </div>
        <div class="nc-field">
          <label>Facultad</label>
          <select id="aula_new_fac">${facultadesOptions(false)}</select>
        </div>
        <div class="nc-field">
          <label>Carrera</label>
          <select id="aula_new_car">
            <option value="">(Sin carrera)</option>
          </select>
        </div>
        <div class="nc-field">
          <label>Turno</label>
          <!-- Turno eliminado -->
        </div>

        <button class="nc-btn" id="aula_add">Agregar</button>
        <button class="nc-btn secondary" id="aula_reload">Recargar</button>
      </div>
    `;

    const list = el('div', { class: 'nc-card' }, `
      <div class="nc-inline" style="justify-content:space-between">
        <h3 style="margin:0">Listado</h3>
        <div class="nc-pill" id="aula_count">0</div>
      </div>
      <div style="overflow:auto;margin-top:10px">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Nombre</th><th>Curso</th><th>Facultad</th><th>Carrera</th><th>Turno</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody id="aula_tbody"></tbody>
        </table>
      </div>
    `);

    view.appendChild(card);
    view.appendChild(list);

    const selFac = document.getElementById('aula_new_fac');
    const selCar = document.getElementById('aula_new_car');
    selFac.onchange = async () => {
      selCar.innerHTML = `<option value="">(Sin carrera)</option>`;
      if (!selFac.value) return;
      setLoading(true);
      try {
        const cars = await loadCarreras(selFac.value);
        selCar.innerHTML = [`<option value="">(Sin carrera)</option>`]
          .concat(cars.map(c => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`))
          .join('');
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    };

    (function () {
      const addBtn = document.getElementById('aula_add');
      const reloadBtn = document.getElementById('aula_reload');
      if (addBtn) addBtn.onclick = withButtonLock(addBtn, addAula, { loadingText: 'Creando...' });
      if (reloadBtn) reloadBtn.onclick = withButtonLock(reloadBtn, async () => {
        baseLoaded = false;
        await loadBase(true);
        paint();
        toast('Aulas recargadas.');
      }, { loadingText: 'Recargando...' });
    })();

    paint();

    async function paint() {
      const tbody = document.getElementById('aula_tbody');
      document.getElementById('aula_count').textContent = String(aulas.length);

      if (!aulas.length) {
        tbody.innerHTML = `<tr><td colspan="7" style="color:#666">No hay aulas.</td></tr>`;
        return;
      }

      // Para que aparezcan nombres aunque vengan ids
      const cursoMap = Object.fromEntries(cursos.map(c => [String(c.id), c.nombre]));
      const facMap = Object.fromEntries(facultades.map(f => [String(f.id), f.nombre]));

      tbody.innerHTML = (aulas || []).map(a => `
        <tr data-id="${a.id}">
          <td>${a.id}</td>
          <td><input class="nc-input-sm" value="${escapeHtml(a.nombre)}" data-k="nombre" /></td>
          <td>
            <select data-k="curso_id">${cursosOptions(false, a.curso_id || '')}</select>
          </td>
          <td>
            <select data-k="facultad_id">${facultadesOptions(false, a.facultad_id || '')}</select>
          </td>
          <td>
            <select data-k="carrera_id">
              <option value="">(Sin carrera)</option>
            </select>
            <div class="nc-mini">Actualizá carrera luego de elegir facultad</div>
          </td>
          <td>
            <!-- Turno eliminado -->
          </td>
          <td class="nc-actions">
            <button class="nc-btn secondary" data-act="save">Guardar</button>
            <button class="nc-btn danger" data-act="del">Eliminar</button>
          </td>
        </tr>
      `).join('');

      // Cargar carreras por fila según la facultad actual
      for (const tr of tbody.querySelectorAll('tr[data-id]')) {
        const facSel = tr.querySelector('select[data-k="facultad_id"]');
        const carSel = tr.querySelector('select[data-k="carrera_id"]');
        const aulaId = tr.dataset.id;

        // cuando cambia facultad, refresca carreras
        facSel.onchange = async () => {
          carSel.innerHTML = `<option value="">(Sin carrera)</option>`;
          const fid = facSel.value;
          if (!fid) return;
          setLoading(true);
          try {
            const cars = await loadCarreras(fid);
            carSel.innerHTML = [`<option value="">(Sin carrera)</option>`]
              .concat(cars.map(c => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`))
              .join('');
          } catch (e) {
            toast('ERROR: ' + e.message, 'err');
          } finally {
            setLoading(false);
          }
        };

        // precargar si ya hay facultad
        const currentFid = facSel.value;
        if (currentFid) {
          const cars = await loadCarreras(currentFid);
          carSel.innerHTML = [`<option value="">(Sin carrera)</option>`]
            .concat(cars.map(c => `<option value="${c.id}" ${String(c.id)===String((aulas.find(x=>String(x.id)===String(aulaId))||{}).carrera_id||'')?'selected':''}>${escapeHtml(c.nombre)}</option>`))
            .join('');
        }
      }

      tbody.querySelectorAll('button[data-act="save"]').forEach(btn => { btn.onclick = withButtonLock(btn, saveAula); });
      tbody.querySelectorAll('button[data-act="del"]').forEach(btn => { btn.onclick = withButtonLock(btn, delAula); });
    }

    async function addAula() {
      const nombre = document.getElementById('aula_new_nombre').value.trim();
      if (!nombre) return toast('Escribí un nombre de aula.', 'err');

      const payload = {
        nombre,
        curso_id: Number(document.getElementById('aula_new_curso').value || 0) || null,
        facultad_id: Number(document.getElementById('aula_new_fac').value || 0) || null,
        carrera_id: Number(document.getElementById('aula_new_car').value || 0) || null,
        // turno eliminado
      };

      setLoading(true);
      try {
        await api('/aulas', { method: 'POST', body: JSON.stringify(payload) });
        document.getElementById('aula_new_nombre').value = '';
        baseLoaded = false;
        await loadBase(true);
        await paint();
        toast('Aula creada.');
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    async function saveAula(e) {
      const tr = e.target.closest('tr');
      const id = tr.dataset.id;

      const payload = {
        nombre: tr.querySelector('input[data-k="nombre"]').value.trim(),
        curso_id: Number(tr.querySelector('select[data-k="curso_id"]').value || 0) || null,
        facultad_id: Number(tr.querySelector('select[data-k="facultad_id"]').value || 0) || null,
        carrera_id: Number(tr.querySelector('select[data-k="carrera_id"]').value || 0) || null,
        // turno eliminado
      };

      if (!payload.nombre) return toast('Nombre inválido.', 'err');

      setLoading(true);
      try {
        await api(`/aulas/${id}`, { method: 'PUT', body: JSON.stringify(payload) });
        baseLoaded = false;
        await loadBase(true);
        await paint();
        toast('Aula actualizada.');
      } catch (err) {
        toast('ERROR: ' + err.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    async function delAula(e) {
      const tr = e.target.closest('tr');
      const id = tr.dataset.id;
      if (!confirm('¿Eliminar esta aula?')) return;

      setLoading(true);
      try {
        await api(`/aulas/${id}`, { method: 'DELETE' });
        baseLoaded = false;
        await loadBase(true);
        await paint();
        toast('Aula eliminada.');
      } catch (err) {
        toast('ERROR: ' + err.message, 'err');
      } finally {
        setLoading(false);
      }
    }
  }

  // ===================== CRUD: FACULTADES + CARRERAS =====================
  function renderFacultadesCarrerasCrud() {
    view.innerHTML = '';

    const cardF = el('div', { class: 'nc-card' });
    cardF.innerHTML = `
      <h3 style="margin:0 0 10px">Facultades (CRUD)</h3>
      <div class="nc-row">
        <div class="nc-field">
          <label>Nueva facultad</label>
          <input id="fac_new" placeholder="Ej: Newton" />
        </div>
        <button class="nc-btn" id="fac_add">Agregar</button>
        <button class="nc-btn secondary" id="fac_reload">Recargar</button>
      </div>

      <div style="overflow:auto;margin-top:12px">
        <table>
          <thead><tr><th>ID</th><th>Nombre</th><th>Acciones</th></tr></thead>
          <tbody id="fac_tbody"></tbody>
        </table>
      </div>
    `;

    const cardC = el('div', { class: 'nc-card' });
    cardC.innerHTML = `
      <h3 style="margin:0 0 10px">Carreras dentro de una Facultad</h3>
      <div class="nc-row">
        <div class="nc-field">
          <label>Facultad</label>
          <select id="car_fac">${facultadesOptions(false)}</select>
        </div>
        <div class="nc-field">
          <label>Nueva carrera</label>
          <input id="car_new" placeholder="Ej: Medicina" />
        </div>
        <button class="nc-btn" id="car_add">Agregar</button>
        <button class="nc-btn secondary" id="car_reload">Recargar</button>
      </div>

      <div style="overflow:auto;margin-top:12px">
        <table>
          <thead><tr><th>ID</th><th>Carrera</th><th>Acciones</th></tr></thead>
          <tbody id="car_tbody"></tbody>
        </table>
      </div>
    `;

    view.appendChild(cardF);
    view.appendChild(cardC);

    (function () {
      const addBtn = document.getElementById('fac_add');
      const reloadBtn = document.getElementById('fac_reload');
      if (addBtn) addBtn.onclick = withButtonLock(addBtn, addFac, { loadingText: 'Creando...' });
      if (reloadBtn) reloadBtn.onclick = withButtonLock(reloadBtn, async () => {
        baseLoaded = false;
        await loadBase(true);
        paintFac();
        paintCarreras(); // refresca select
        toast('Facultades recargadas.');
      }, { loadingText: 'Recargando...' });
    })();

    document.getElementById('car_fac').onchange = paintCarreras;
    (function () {
      const addBtn = document.getElementById('car_add');
      const reloadBtn = document.getElementById('car_reload');
      if (addBtn) addBtn.onclick = withButtonLock(addBtn, addCarrera, { loadingText: 'Creando...' });
      if (reloadBtn) reloadBtn.onclick = withButtonLock(reloadBtn, async () => {
        const fid = document.getElementById('car_fac').value;
        if (fid) delete carrerasByFac[Number(fid)];
        await paintCarreras();
        toast('Carreras recargadas.');
      }, { loadingText: 'Recargando...' });
    })();

    paintFac();
    paintCarreras();

    function paintFac() {
      const tbody = document.getElementById('fac_tbody');
      if (!facultades.length) {
        tbody.innerHTML = `<tr><td colspan="3" style="color:#666">No hay facultades.</td></tr>`;
        return;
      }
      tbody.innerHTML = facultades.map(f => `
        <tr data-id="${f.id}">
          <td>${f.id}</td>
          <td><input class="nc-input-sm" value="${escapeHtml(f.nombre)}" data-k="nombre" /></td>
          <td class="nc-actions">
            <button class="nc-btn secondary" data-act="save">Guardar</button>
            <button class="nc-btn danger" data-act="del">Eliminar</button>
          </td>
        </tr>
      `).join('');

      tbody.querySelectorAll('button[data-act="save"]').forEach(btn => { btn.onclick = withButtonLock(btn, saveFac); });
      tbody.querySelectorAll('button[data-act="del"]').forEach(btn => { btn.onclick = withButtonLock(btn, delFac); });

      // refresca el select de carreras
      document.getElementById('car_fac').innerHTML = facultadesOptions(false, document.getElementById('car_fac').value);
    }

    async function addFac() {
      const nombre = document.getElementById('fac_new').value.trim();
      if (!nombre) return toast('Escribí un nombre de facultad.', 'err');

      setLoading(true);
      try {
        await api('/facultades', { method: 'POST', body: JSON.stringify({ nombre }) });
        document.getElementById('fac_new').value = '';
        baseLoaded = false;
        await loadBase(true);
        paintFac();
        await paintCarreras();
        toast('Facultad creada.');
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    async function saveFac(e) {
      const tr = e.target.closest('tr');
      const id = tr.dataset.id;
      const nombre = tr.querySelector('input[data-k="nombre"]').value.trim();
      if (!nombre) return toast('Nombre inválido.', 'err');

      setLoading(true);
      try {
        await api(`/facultades/${id}`, { method: 'PUT', body: JSON.stringify({ nombre }) });
        baseLoaded = false;
        await loadBase(true);
        paintFac();
        await paintCarreras();
        toast('Facultad actualizada.');
      } catch (err) {
        toast('ERROR: ' + err.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    async function delFac(e) {
      const tr = e.target.closest('tr');
      const id = tr.dataset.id;
      if (!confirm('¿Eliminar esta facultad? (se inactivan sus carreras)')) return;

      setLoading(true);
      try {
        await api(`/facultades/${id}`, { method: 'DELETE' });
        baseLoaded = false;
        await loadBase(true);
        paintFac();
        await paintCarreras();
        toast('Facultad eliminada.');
      } catch (err) {
        toast('ERROR: ' + err.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    async function paintCarreras() {
      const fid = document.getElementById('car_fac').value;
      const tbody = document.getElementById('car_tbody');

      if (!fid) {
        tbody.innerHTML = `<tr><td colspan="3" style="color:#666">Elegí una facultad para ver sus carreras.</td></tr>`;
        return;
      }

      setLoading(true);
      try {
        const cars = await loadCarreras(fid);
        if (!cars.length) {
          tbody.innerHTML = `<tr><td colspan="3" style="color:#666">No hay carreras para esta facultad.</td></tr>`;
          return;
        }

        tbody.innerHTML = cars.map(c => `
          <tr data-id="${c.id}">
            <td>${c.id}</td>
            <td><input class="nc-input-sm" value="${escapeHtml(c.nombre)}" data-k="nombre" /></td>
            <td class="nc-actions">
              <button class="nc-btn secondary" data-act="save">Guardar</button>
              <button class="nc-btn danger" data-act="del">Eliminar</button>
            </td>
          </tr>
        `).join('');

        tbody.querySelectorAll('button[data-act="save"]').forEach(btn => { btn.onclick = withButtonLock(btn, saveCarrera); });
        tbody.querySelectorAll('button[data-act="del"]').forEach(btn => { btn.onclick = withButtonLock(btn, delCarrera); });

      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    async function addCarrera() {
      const fid = document.getElementById('car_fac').value;
      const nombre = document.getElementById('car_new').value.trim();
      if (!fid) return toast('Elegí una facultad.', 'err');
      if (!nombre) return toast('Escribí el nombre de la carrera.', 'err');

      setLoading(true);
      try {
        await api('/carreras', { method: 'POST', body: JSON.stringify({ facultad_id: Number(fid), nombre }) });
        document.getElementById('car_new').value = '';
        delete carrerasByFac[Number(fid)];
        await paintCarreras();
        toast('Carrera creada.');
      } catch (e) {
        toast('ERROR: ' + e.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    async function saveCarrera(e) {
      const tr = e.target.closest('tr');
      const id = tr.dataset.id;
      const fid = document.getElementById('car_fac').value;
      const nombre = tr.querySelector('input[data-k="nombre"]').value.trim();
      if (!nombre) return toast('Nombre inválido.', 'err');

      setLoading(true);
      try {
        await api(`/carreras/${id}`, { method: 'PUT', body: JSON.stringify({ nombre }) });
        delete carrerasByFac[Number(fid)];
        await paintCarreras();
        toast('Carrera actualizada.');
      } catch (err) {
        toast('ERROR: ' + err.message, 'err');
      } finally {
        setLoading(false);
      }
    }

    async function delCarrera(e) {
      const tr = e.target.closest('tr');
      const id = tr.dataset.id;
      const fid = document.getElementById('car_fac').value;
      if (!confirm('¿Eliminar esta carrera?')) return;

      setLoading(true);
      try {
        await api(`/carreras/${id}`, { method: 'DELETE' });
        delete carrerasByFac[Number(fid)];
        await paintCarreras();
        toast('Carrera eliminada.');
      } catch (err) {
        toast('ERROR: ' + err.message, 'err');
      } finally {
        setLoading(false);
      }
    }
  }

  function renderDocentes() {
    view.innerHTML = '';
    const card = el('div', { class: 'nc-card' });
    card.innerHTML = `
      <h3 style="margin:0 0 14px">Docentes</h3>
      <p style="color:#555;margin-bottom:16px;font-size:14px">Crear nuevos docentes o asignar el rol docente a usuarios existentes de tu sitio.</p>
      <div class="nc-row" style="gap:12px;margin-bottom:20px;flex-wrap:wrap">
        <button class="nc-btn" id="nc_doc_crear">Crear docente nuevo</button>
        <button class="nc-btn secondary" id="nc_doc_asignar">Asignar docente (usuario existente)</button>
      </div>
      <h4 style="margin:16px 0 8px">Usuarios con rol docente</h4>
      <div id="nc_doc_list" style="overflow:auto">Cargando...</div>
    `;
    view.appendChild(card);

    async function loadDocentes() {
      const listEl = document.getElementById('nc_doc_list');
      if (!listEl) return;
      try {
        const res = await api('/docentes');
        const items = (res && res.items) ? res.items : [];
        if (!items.length) {
          listEl.innerHTML = '<p style="opacity:.8">No hay usuarios con rol docente.</p>';
          return;
        }
        listEl.innerHTML = `
          <table class="nc-table" style="width:100%">
            <thead><tr><th>Nombre</th><th>Correo</th><th>Usuario</th></tr></thead>
            <tbody>
              ${items.map(d => `
                <tr>
                  <td>${escapeHtml(d.display_name || '')}</td>
                  <td>${escapeHtml(d.user_email || '')}</td>
                  <td>${escapeHtml(d.user_login || '')}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        `;
      } catch (e) {
        listEl.innerHTML = '<p style="color:#b00">Error: ' + escapeHtml(e.message) + '</p>';
      }
    }

    (function () {
      const btn = document.getElementById('nc_doc_crear');
      if (btn) btn.onclick = withButtonLock(btn, async () => {
        const email = prompt('Correo electrónico del nuevo docente:');
        if (!email) return;
        const display_name = prompt('Nombre para mostrar (opcional):', '');
        const password = prompt('Contraseña (opcional, se genera una si se deja vacío):', '');
        try {
          await api('/docentes', { method: 'POST', body: JSON.stringify({ email: email.trim(), display_name: (display_name || '').trim(), password: (password || '').trim() }) });
          toast('Docente creado correctamente.');
          loadDocentes();
        } catch (e) {
          toast('Error: ' + e.message, 'err');
        }
      }, { loadingText: 'Creando...' });
    })();

    (function () {
      const btn = document.getElementById('nc_doc_asignar');
      if (btn) btn.onclick = withButtonLock(btn, async () => {
        try {
          const [docRes, usrRes] = await Promise.all([api('/docentes'), api('/usuarios')]);
          const docentes = (docRes && docRes.items) ? docRes.items : [];
          const usuarios = (usrRes && usrRes.items) ? usrRes.items : [];
          const docentesIds = new Set(docentes.map(d => d.id));
          const noDocentes = usuarios.filter(u => !docentesIds.has(u.id));
          if (!noDocentes.length) { toast('Todos los usuarios ya son docentes o no hay usuarios.', 'err'); return; }
          const modal = el('div', { class: 'nc-modal-backdrop', id: 'nc_doc_modal' });
          modal.innerHTML = '<div class="nc-modal" style="max-width:400px"><div class="nc-modal-h"><h3>Asignar docente</h3><button class="nc-modal-x" id="nc_doc_modal_close">&times;</button></div><div class="nc-modal-b"><label style="display:block;margin-bottom:8px">Seleccione el usuario:</label><select id="nc_doc_user_sel" style="width:100%;padding:10px;margin-bottom:12px;border-radius:8px;border:1px solid #ddd">' + noDocentes.map(u => '<option value="' + u.id + '">' + escapeHtml(u.display_name || u.user_email) + ' (' + escapeHtml(u.user_email) + ')</option>').join('') + '</select><button class="nc-btn" id="nc_doc_confirm_asignar">Asignar rol docente</button></div></div></div>';
          modal.onclick = (ev) => { if (ev.target === modal) modal.remove(); };
          modal.querySelector('#nc_doc_modal_close').onclick = () => modal.remove();
          const confirmBtn = modal.querySelector('#nc_doc_confirm_asignar');
          if (confirmBtn) confirmBtn.onclick = withButtonLock(confirmBtn, async () => {
            const uid = parseInt(modal.querySelector('#nc_doc_user_sel').value, 10);
            await api('/docentes/asignar', { method: 'POST', body: JSON.stringify({ user_id: uid }) });
            toast('Rol docente asignado.');
            modal.remove();
            loadDocentes();
          }, { loadingText: 'Asignando...' });
          document.body.appendChild(modal);
        } catch (e) {
          toast('Error: ' + e.message, 'err');
        }
      }, { loadingText: 'Cargando...' });
    })();

    loadDocentes();
  }

  window.NC_ModalRestoreHandlers = Object.assign(window.NC_ModalRestoreHandlers || {}, {
    alumno_view: async (m) => {
      const a = await api('/alumnos/' + Number(m.alumnoId));
      let navOptions = null;
      if (m.listIds && m.listIds.length && m.index >= 0) {
        const list = [];
        for (const id of m.listIds) {
          try {
            list.push(await api('/alumnos/' + Number(id)));
          } catch (_) { /* skip */ }
        }
        const idx = list.findIndex(r => Number(r.id) === Number(m.alumnoId));
        if (idx >= 0) navOptions = { list, index: idx };
      }
      await openAlumnoViewModal(a, navOptions || undefined);
    },
    alumno_edit: async (m) => {
      const a = await api('/alumnos/' + Number(m.alumnoId));
      await openAlumnoEditModal(a);
    },
  });

  // Start — restaurar navegación y modal tras recarga
  await userPermissions.load();
  if (window.NC_AppState) window.NC_AppState.migrateLegacyAsistenciaSub();
  const bootState = window.NC_AppState ? window.NC_AppState.load() : { mainTab: 'dashboard' };
  let bootTab = bootState.mainTab || 'dashboard';
  const hiddenTabs = [];
  if (!userPermissions.canViewReports()) hiddenTabs.push('reportes-main');
  if (!userPermissions.isAdmin()) hiddenTabs.push('puntajes-main');
  if (!userPermissions.canManageStudents() && !userPermissions.canManageCourses() && !userPermissions.canManageAulas() && !userPermissions.canManageFacultades()) {
    hiddenTabs.push('admin-main');
  }
  if (hiddenTabs.includes(bootTab)) bootTab = 'dashboard';

  await openTab(bootTab, {
    skipPersist: true,
    screen: bootState.screen,
    asistenciaSub: bootState.asistenciaSub,
    examenesSub: bootState.examenesSub,
  });

  if (window.NC_AppState) {
    await window.NC_AppState.tryRestoreModal();
  }

})();
