/**
 * Estado de navegación y modales del plugin (sessionStorage, por usuario).
 */
(function (global) {
  'use strict';

  const KEY = 'nc_app_state_v1';
  const MAX_AGE_MS = 48 * 60 * 60 * 1000;
  const VERSION = 1;

  function getUserId() {
    const id = global.NC_APP && global.NC_APP.currentUserId;
    return id != null ? Number(id) : 0;
  }

  function defaultState() {
    return {
      v: VERSION,
      userId: getUserId(),
      savedAt: Date.now(),
      mainTab: 'dashboard',
      screen: null,
      asistenciaSub: null,
      examenesSub: null,
      modal: null,
      conductaDraft: null,
      examenesCargarDraft: null,
    };
  }

  function load() {
    try {
      const raw = sessionStorage.getItem(KEY);
      if (!raw) return defaultState();
      const s = JSON.parse(raw);
      if (!s || Number(s.userId) !== getUserId()) return defaultState();
      if (s.savedAt && Date.now() - Number(s.savedAt) > MAX_AGE_MS) {
        sessionStorage.removeItem(KEY);
        return defaultState();
      }
      return Object.assign(defaultState(), s, { userId: getUserId() });
    } catch (_) {
      return defaultState();
    }
  }

  function save(partial) {
    try {
      const cur = load();
      const next = Object.assign({}, cur, partial || {}, {
        userId: getUserId(),
        savedAt: Date.now(),
        v: VERSION,
      });
      sessionStorage.setItem(KEY, JSON.stringify(next));
      return next;
    } catch (_) {
      return load();
    }
  }

  function persistRoute(fields) {
    const p = {};
    if (fields.mainTab !== undefined) p.mainTab = fields.mainTab;
    if (fields.screen !== undefined) p.screen = fields.screen;
    if (fields.asistenciaSub !== undefined) p.asistenciaSub = fields.asistenciaSub;
    if (fields.examenesSub !== undefined) p.examenesSub = fields.examenesSub;
    return save(p);
  }

  function setModal(modal) {
    return save({ modal: modal || null });
  }

  function clearModal() {
    return save({ modal: null });
  }

  function setConductaDraft(draft) {
    return save({ conductaDraft: draft || null });
  }

  function setExamenesCargarDraft(draft) {
    return save({ examenesCargarDraft: draft || null });
  }

  /** Migra clave antigua de asistencia si existe. */
  function migrateLegacyAsistenciaSub() {
    try {
      const leg = sessionStorage.getItem('nc_asistencia_active_sub');
      if (!leg) return null;
      const st = load();
      if (!st.asistenciaSub) persistRoute({ asistenciaSub: leg });
      return leg;
    } catch (_) {
      return null;
    }
  }

  async function tryRestoreModal() {
    const m = load().modal;
    if (!m || !m.type) return false;
    const handlers = global.NC_ModalRestoreHandlers || {};
    const fn = handlers[m.type];
    if (typeof fn !== 'function') {
      clearModal();
      return false;
    }
    try {
      await fn(m);
      return true;
    } catch (e) {
      console.warn('[NC_AppState] No se pudo restaurar modal:', m.type, e);
      clearModal();
      return false;
    }
  }

  global.NC_AppState = {
    KEY,
    MAX_AGE_MS,
    load,
    save,
    persistRoute,
    setModal,
    clearModal,
    setConductaDraft,
    setExamenesCargarDraft,
    migrateLegacyAsistenciaSub,
    tryRestoreModal,
    getUserId,
  };
})(typeof window !== 'undefined' ? window : globalThis);
