(function () {
  "use strict";

  var script = document.currentScript;
  if (!script) {
    var scripts = document.querySelectorAll("script[data-widget-key], script[src*=\"/widget/chat-widget.js\"]");
    script = scripts.length > 0 ? scripts[scripts.length - 1] : null;
  }

  if (!script) {
    console.warn("[TexHub Widget] Script tag not found. Widget was not initialized.");
    return;
  }

  var widgetKey = (
    script.getAttribute("data-widget-key") ||
    script.getAttribute("data-widget-id") ||
    getWidgetKeyFromScriptUrl(script.getAttribute("src") || "") ||
    ""
  ).trim();

  if (!widgetKey) {
    console.warn("[TexHub Widget] Missing widget key. Add data-widget-key=\"...\" to script tag.");
    return;
  }

  var debugFromAttribute = parseBooleanFlag(script.getAttribute("data-debug"));
  var debugFromQuery = /(?:\?|&)widget_debug=1(?:&|$)/.test(window.location.search || "");
  var debugEnabled = debugFromAttribute || debugFromQuery;
  var diagnosticsStore = window.__texhubWidgetDiagnostics || (window.__texhubWidgetDiagnostics = {});
  var diagnostics = diagnosticsStore[widgetKey] || {};
  diagnosticsStore[widgetKey] = diagnostics;

  var customApiBase = (script.getAttribute("data-api-base") || "").trim();
  var scriptUrl = script.getAttribute("src") || "";
  var scriptOrigin = "";

  try {
    scriptOrigin = new URL(scriptUrl, window.location.href).origin;
  } catch (_error) {
    scriptOrigin = window.location.origin;
  }

  var apiBase = customApiBase || scriptOrigin + "/api/widget";
  var storageSessionKey = "texhub_widget_session_" + widgetKey;
  var storageOpenStateKey = "texhub_widget_open_" + widgetKey;
  document.documentElement.setAttribute("data-texhub-widget-loaded", "1");
  debugLog("script_loaded", {
    widget_key: widgetKey,
    script_origin: scriptOrigin,
    api_base: apiBase,
    debug_enabled: debugEnabled
  });

  var state = {
    sessionId: loadOrCreateSessionId(),
    isOpen: safeStorageGet(storageOpenStateKey) !== "0",
    loading: false,
    sending: false,
    config: {
      is_active: true,
      settings: {
        position: "bottom-right",
        theme: "light",
        primary_color: "#1677FF",
        title: "Онлайн чат",
        welcome_message: "Здравствуйте! Напишите ваш вопрос.",
        placeholder: "Введите сообщение...",
        launcher_label: "Чат"
      }
    },
    messages: [],
    messageIds: {},
    lastId: 0,
    nextLocalMessageId: -1,
    pendingLocalMessageIds: {},
    pollTimer: null,
    fileToSend: null,
    bootstrapDone: false
  };
  var visitorContext = buildVisitorContext();

  var ui = null;
  onDomReady(initWidget);

  function initWidget() {
    if (ui) {
      return;
    }

    if (document.getElementById("texhub-widget-root-" + widgetKey)) {
      debugLog("ui_skipped_already_exists");
      return;
    }

    ui = createUi();
    bindUi();
    applyConfigToUi();
    applyChannelAvailability();
    togglePanel(state.isOpen);
    debugLog("ui_ready", {
      is_open: state.isOpen,
      session_id: state.sessionId
    });

    bootstrap();
  }

  function bootstrap() {
    if (state.bootstrapDone) {
      return;
    }

    state.bootstrapDone = true;

    fetchConfig()
      .then(function () {
        debugLog("bootstrap_config_loaded");
        return fetchMessages();
      })
      .catch(function (error) {
        debugLog("bootstrap_failed", {
          error: serializeError(error)
        });
        console.warn("Widget init failed", error);
      })
      .finally(function () {
        startPolling();
      });
  }

  function loadOrCreateSessionId() {
    var existing = (safeStorageGet(storageSessionKey) || "").trim();
    if (existing) {
      return existing;
    }

    var generated = "ws_" + randomId(28);
    safeStorageSet(storageSessionKey, generated);
    debugLog("session_created", {
      session_id: generated
    });
    return generated;
  }

  function randomId(length) {
    var chars = "abcdefghijklmnopqrstuvwxyz0123456789";
    var out = "";

    for (var i = 0; i < length; i += 1) {
      out += chars.charAt(Math.floor(Math.random() * chars.length));
    }

    return out;
  }

  function createUi() {
    var root = document.createElement("div");
    root.id = "texhub-widget-root-" + widgetKey;

    var style = document.createElement("style");
    style.textContent = [
      ".texhub-widget-wrap{position:fixed !important;bottom:14px !important;z-index:2147483647 !important;display:flex !important;flex-direction:column !important;align-items:flex-end !important;gap:10px !important;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif !important;}",
      ".texhub-widget-wrap.left{left:14px !important;right:auto !important;align-items:flex-start !important;}",
      ".texhub-widget-wrap.right{right:14px !important;left:auto !important;align-items:flex-end !important;}",
      ".texhub-widget-launcher{height:52px;min-width:52px;padding:0 16px;border:none;border-radius:999px;color:#fff;font-size:15px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 12px 26px rgba(15,23,42,.2);}",
      ".texhub-widget-launcher:disabled{opacity:.7;cursor:not-allowed;}",
      ".texhub-widget-panel{width:min(360px,calc(100vw - 20px));height:min(560px,calc(100vh - 20px));border-radius:18px;overflow:hidden;display:flex;flex-direction:column;border:1px solid rgba(148,163,184,.35);box-shadow:0 18px 40px rgba(15,23,42,.2);background:#fff;}",
      ".texhub-widget-panel.hidden{display:none;}",
      ".texhub-widget-header{padding:12px 14px;display:flex;align-items:flex-start;justify-content:space-between;gap:8px;border-bottom:1px solid rgba(148,163,184,.25);background:#fff;}",
      ".texhub-widget-header-title{font-size:16px;font-weight:800;line-height:1.25;margin:0;color:#0f172a;}",
      ".texhub-widget-header-status{font-size:11px;color:#64748b;font-weight:600;margin:2px 0 0;}",
      ".texhub-widget-close{border:none;background:transparent;color:#64748b;font-size:30px;line-height:1;cursor:pointer;padding:0 2px;border-radius:8px;}",
      ".texhub-widget-close:hover{background:rgba(148,163,184,.18);}",
      ".texhub-widget-body{flex:1;overflow:auto;padding:10px;display:flex;flex-direction:column;gap:7px;background:#F8FAFC;}",
      ".texhub-widget-empty{font-size:14px;line-height:1.35;color:#64748b;padding:8px 10px;}",
      ".texhub-widget-msg{max-width:90%;border-radius:16px;padding:10px 12px;font-size:14px;line-height:1.35;word-break:break-word;}",
      ".texhub-widget-msg.in{align-self:flex-start;background:#fff;color:#0f172a;border:1px solid rgba(148,163,184,.28);}",
      ".texhub-widget-msg.out{align-self:flex-end;background:#dbeafe;color:#0f172a;}",
      ".texhub-widget-msg.pending{opacity:.78;}",
      ".texhub-widget-msg.failed{background:#FFF1F3;border-color:#FDA4AF;}",
      ".texhub-widget-msg img{max-width:100%;display:block;border-radius:14px;}",
      ".texhub-widget-meta{display:block;font-size:11px;color:#64748b;margin-top:5px;}",
      ".texhub-widget-footer{border-top:1px solid rgba(148,163,184,.25);padding:8px;display:flex;flex-direction:column;gap:6px;background:#fff;}",
      ".texhub-widget-composer{display:flex;align-items:center;gap:6px;border:1px solid rgba(148,163,184,.45);border-radius:14px;background:#fff;padding:5px 6px;}",
      ".texhub-widget-input{flex:1;min-height:34px;max-height:96px;resize:none;border:none;padding:7px 2px;font-size:14px;line-height:1.3;color:#0f172a;outline:none;background:transparent;}",
      ".texhub-widget-input:focus{box-shadow:none;}",
      ".texhub-widget-file{position:relative;overflow:hidden;border:none;border-radius:10px;width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;font-size:18px;color:#667085;background:#EEF2F7;cursor:pointer;flex:0 0 34px;}",
      ".texhub-widget-file input{position:absolute;inset:0;opacity:0;cursor:pointer;}",
      ".texhub-widget-send{border:none;border-radius:999px;color:#fff;font-size:18px;font-weight:700;width:34px;height:34px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;flex:0 0 34px;}",
      ".texhub-widget-send:disabled{opacity:.65;cursor:not-allowed;}",
      ".texhub-widget-file-name{font-size:11px;color:#64748b;padding:0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}",
      ".texhub-widget-error{display:none;font-size:12px;line-height:1.35;color:#B42318;background:#FEE4E2;border:1px solid #FDA29B;padding:7px 9px;border-radius:10px;}",
      "@media (max-width:640px){.texhub-widget-wrap{left:10px!important;right:10px!important;bottom:10px!important;align-items:stretch!important}.texhub-widget-panel{width:100%;height:min(74vh,520px)}.texhub-widget-header{padding:10px 12px}.texhub-widget-header-title{font-size:15px}.texhub-widget-header-status{font-size:11px}.texhub-widget-body{padding:8px}.texhub-widget-empty{font-size:13px;padding:6px}.texhub-widget-msg{font-size:13px;padding:9px 11px}.texhub-widget-input{font-size:14px;min-height:33px}.texhub-widget-send,.texhub-widget-file{width:32px;height:32px;flex-basis:32px}}"
    ].join("");

    var wrapper = document.createElement("div");
    wrapper.className = "texhub-widget-wrap right";
    wrapper.style.position = "fixed";
    wrapper.style.bottom = "14px";
    wrapper.style.right = "14px";
    wrapper.style.left = "auto";
    wrapper.style.zIndex = "2147483647";
    wrapper.style.display = "flex";
    wrapper.style.flexDirection = "column";
    wrapper.style.alignItems = "flex-end";
    wrapper.style.gap = "10px";

    var panel = document.createElement("div");
    panel.className = "texhub-widget-panel hidden";
    panel.style.display = "none";
    panel.style.width = "min(360px,calc(100vw - 20px))";
    panel.style.height = "min(560px,calc(100vh - 20px))";
    panel.style.border = "1px solid rgba(148,163,184,.35)";
    panel.style.borderRadius = "18px";
    panel.style.overflow = "hidden";
    panel.style.boxShadow = "0 18px 40px rgba(15,23,42,.2)";
    panel.style.background = "#fff";
    panel.style.flexDirection = "column";

    var header = document.createElement("div");
    header.className = "texhub-widget-header";

    var headerInfo = document.createElement("div");

    var title = document.createElement("p");
    title.className = "texhub-widget-header-title";
    title.textContent = state.config.settings.title;

    var status = document.createElement("p");
    status.className = "texhub-widget-header-status";
    status.textContent = "Онлайн";

    headerInfo.appendChild(title);
    headerInfo.appendChild(status);

    var close = document.createElement("button");
    close.type = "button";
    close.className = "texhub-widget-close";
    close.setAttribute("aria-label", "Close chat");
    close.textContent = "×";

    header.appendChild(headerInfo);
    header.appendChild(close);

    var body = document.createElement("div");
    body.className = "texhub-widget-body";

    var empty = document.createElement("div");
    empty.className = "texhub-widget-empty";
    empty.textContent = state.config.settings.welcome_message;
    body.appendChild(empty);

    var footer = document.createElement("div");
    footer.className = "texhub-widget-footer";

    var input = document.createElement("textarea");
    input.className = "texhub-widget-input";
    input.placeholder = state.config.settings.placeholder;

    var fileName = document.createElement("div");
    fileName.className = "texhub-widget-file-name";
    fileName.style.display = "none";

    var composer = document.createElement("div");
    composer.className = "texhub-widget-composer";

    var error = document.createElement("div");
    error.className = "texhub-widget-error";

    var fileButton = document.createElement("label");
    fileButton.className = "texhub-widget-file";
    fileButton.textContent = "📎";

    var fileInput = document.createElement("input");
    fileInput.type = "file";
    fileInput.accept = "image/*";

    fileButton.appendChild(fileInput);

    var send = document.createElement("button");
    send.type = "button";
    send.className = "texhub-widget-send";
    send.textContent = "↑";
    send.dataset.defaultLabel = "↑";

    composer.appendChild(fileButton);
    composer.appendChild(input);
    composer.appendChild(send);

    footer.appendChild(composer);
    footer.appendChild(fileName);
    footer.appendChild(error);

    panel.appendChild(header);
    panel.appendChild(body);
    panel.appendChild(footer);

    var launcher = document.createElement("button");
    launcher.type = "button";
    launcher.className = "texhub-widget-launcher";
    launcher.textContent = state.config.settings.launcher_label;
    launcher.style.height = "52px";
    launcher.style.minWidth = "52px";
    launcher.style.padding = "0 14px";
    launcher.style.border = "none";
    launcher.style.borderRadius = "999px";
    launcher.style.color = "#ffffff";
    launcher.style.background = "#1677FF";
    launcher.style.fontWeight = "600";
    launcher.style.cursor = "pointer";
    launcher.style.display = "inline-flex";
    launcher.style.alignItems = "center";
    launcher.style.justifyContent = "center";
    launcher.style.gap = "8px";
    launcher.style.boxShadow = "0 12px 26px rgba(15,23,42,.2)";

    wrapper.appendChild(panel);
    wrapper.appendChild(launcher);

    root.appendChild(style);
    root.appendChild(wrapper);
    document.body.appendChild(root);

    return {
      root: root,
      wrapper: wrapper,
      panel: panel,
      launcher: launcher,
      title: title,
      status: status,
      close: close,
      body: body,
      footer: footer,
      empty: empty,
      input: input,
      fileInput: fileInput,
      fileName: fileName,
      send: send,
      error: error,
      composer: composer,
      fileButton: fileButton
    };
  }

  function bindUi() {
    ui.launcher.addEventListener("click", function () {
      togglePanel(!state.isOpen);
    });

    ui.close.addEventListener("click", function () {
      togglePanel(false);
    });

    ui.send.addEventListener("click", function () {
      sendMessage();
    });

    ui.input.addEventListener("keydown", function (event) {
      if (event.key === "Enter" && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
      }
    });

    ui.fileInput.addEventListener("change", function () {
      var file = ui.fileInput.files && ui.fileInput.files[0] ? ui.fileInput.files[0] : null;
      state.fileToSend = file;

      if (file) {
        ui.fileName.style.display = "block";
        ui.fileName.textContent = "Файл: " + file.name;
      } else {
        ui.fileName.style.display = "none";
        ui.fileName.textContent = "";
      }
    });
  }

  function togglePanel(open) {
    state.isOpen = !!open;
    debugLog("panel_toggled", {
      is_open: state.isOpen
    });

    if (state.isOpen) {
      ui.panel.classList.remove("hidden");
      ui.panel.style.display = "flex";
      ui.launcher.textContent = "−";
      ui.launcher.setAttribute("aria-expanded", "true");
      safeStorageSet(storageOpenStateKey, "1");
      if (!ui.input.disabled) {
        ui.input.focus();
      }
      fetchMessages();
    } else {
      ui.panel.classList.add("hidden");
      ui.panel.style.display = "none";
      ui.launcher.textContent = state.config.settings.launcher_label;
      ui.launcher.setAttribute("aria-expanded", "false");
      safeStorageSet(storageOpenStateKey, "0");
    }
  }

  function fetchConfig() {
    debugLog("config_fetch_start");
    return fetch(apiBase + "/" + encodeURIComponent(widgetKey) + "/config", {
      method: "GET",
      mode: "cors"
    })
      .then(function (response) {
        if (!response.ok) {
          throw httpError("Config request failed", response.status);
        }

        return response.json();
      })
      .then(function (data) {
        if (!data || !data.widget) {
          debugLog("config_invalid_payload");
          return;
        }

        state.config = data.widget;
        if (!state.config.settings) {
          state.config.settings = {};
        }

        applyConfigToUi();
        applyChannelAvailability();
        debugLog("config_fetch_success", {
          is_active: !!state.config.is_active
        });
      })
      .catch(function (error) {
        debugLog("config_fetch_failed", {
          error: serializeError(error)
        });
        console.warn("Widget config load failed", error);
      });
  }

  function applyConfigToUi() {
    if (!ui || !ui.wrapper || !ui.panel || !ui.launcher || !ui.send || !ui.title || !ui.status || !ui.input || !ui.body || !ui.empty || !ui.footer || !ui.composer || !ui.fileButton) {
      debugLog("apply_config_skipped_ui_not_ready");
      return;
    }

    var settings = state.config.settings || {};
    var position = settings.position === "bottom-left" ? "left" : "right";
    var theme = settings.theme === "dark" ? "dark" : "light";
    var color = normalizeColor(settings.primary_color) || "#1677FF";

    ui.wrapper.classList.remove("left", "right");
    ui.wrapper.classList.add(position);
    ui.wrapper.style.bottom = "14px";
    if (position === "left") {
      ui.wrapper.style.left = "14px";
      ui.wrapper.style.right = "auto";
      ui.wrapper.style.alignItems = "flex-start";
    } else {
      ui.wrapper.style.right = "14px";
      ui.wrapper.style.left = "auto";
      ui.wrapper.style.alignItems = "flex-end";
    }

    ui.launcher.style.background = color;
    ui.send.style.background = color;

    ui.title.textContent = settings.title || "Онлайн чат";
    ui.input.placeholder = settings.placeholder || "Введите сообщение...";
    ui.launcher.textContent = state.isOpen ? "−" : (settings.launcher_label || "Чат");

    if (theme === "dark") {
      ui.panel.style.background = "#0F172A";
      ui.body.style.background = "#111827";
      ui.footer.style.background = "#0F172A";
      ui.title.style.color = "#F8FAFC";
      ui.status.style.color = "#94A3B8";
      ui.composer.style.background = "#111827";
      ui.composer.style.borderColor = "rgba(148,163,184,.35)";
      ui.fileButton.style.background = "#1F2937";
      ui.fileButton.style.color = "#CBD5E1";
      ui.input.style.background = "transparent";
      ui.input.style.color = "#F8FAFC";
      ui.empty.style.color = "#CBD5E1";
      ui.empty.textContent = settings.welcome_message || "Здравствуйте! Напишите ваш вопрос.";
    } else {
      ui.panel.style.background = "#ffffff";
      ui.body.style.background = "#F8FAFC";
      ui.footer.style.background = "#ffffff";
      ui.title.style.color = "#0F172A";
      ui.status.style.color = "#64748B";
      ui.composer.style.background = "#ffffff";
      ui.composer.style.borderColor = "rgba(148,163,184,.45)";
      ui.fileButton.style.background = "#EEF2F7";
      ui.fileButton.style.color = "#667085";
      ui.input.style.background = "transparent";
      ui.input.style.color = "#0F172A";
      ui.empty.style.color = "#64748B";
      ui.empty.textContent = settings.welcome_message || "Здравствуйте! Напишите ваш вопрос.";
    }

    if (!isWidgetActive()) {
      ui.status.style.color = theme === "dark" ? "#FCA5A5" : "#B42318";
    }
  }

  function isWidgetActive() {
    return !state.config || state.config.is_active !== false;
  }

  function applyChannelAvailability() {
    if (!ui || !ui.input || !ui.fileInput || !ui.send || !ui.status) {
      return;
    }

    var active = isWidgetActive();

    ui.status.textContent = active ? "Онлайн" : "Офлайн";
    ui.input.disabled = !active;
    ui.fileInput.disabled = !active;
    ui.send.disabled = !active || state.sending;

    if (!active) {
      ui.send.textContent = "×";
      ui.send.title = "Чат недоступен";
    } else if (!state.sending) {
      ui.send.textContent = ui.send.dataset.defaultLabel || "Отправить";
      ui.send.title = "Отправить";
    }

    if (!active) {
      setInlineError("Чат временно недоступен. Попробуйте позже.", true);
    } else if (ui.error && ui.error.dataset && ui.error.dataset.persistent === "1") {
      clearInlineError(true);
    }
  }

  function setInlineError(message, persistent) {
    if (!ui || !ui.error) {
      return;
    }

    var safeMessage = String(message || "").trim();

    if (safeMessage === "") {
      clearInlineError(true);
      return;
    }

    ui.error.textContent = safeMessage;
    ui.error.style.display = "block";
    ui.error.dataset.persistent = persistent ? "1" : "0";
  }

  function clearInlineError(force) {
    if (!ui || !ui.error) {
      return;
    }

    if (!force && ui.error.dataset && ui.error.dataset.persistent === "1") {
      return;
    }

    ui.error.textContent = "";
    ui.error.style.display = "none";
    ui.error.dataset.persistent = "0";
  }

  function normalizeColor(value) {
    var text = (value || "").trim().toUpperCase();
    return /^#[0-9A-F]{6}$/.test(text) ? text : null;
  }

  function fetchMessages() {
    if (state.loading) {
      return Promise.resolve();
    }

    state.loading = true;
    debugLog("messages_fetch_start", {
      after_id: state.lastId || 0
    });

    var url =
      apiBase +
      "/" +
      encodeURIComponent(widgetKey) +
      "/messages?session_id=" +
      encodeURIComponent(state.sessionId) +
      "&after_id=" +
      encodeURIComponent(String(state.lastId || 0)) +
      "&limit=80";

    return fetch(url, {
      method: "GET",
      mode: "cors"
    })
      .then(function (response) {
        if (!response.ok) {
          throw httpError("Messages request failed", response.status);
        }

        return response.json();
      })
      .then(function (data) {
        var items = Array.isArray(data && data.messages) ? data.messages : [];
        if (items.length > 0) {
          mergeServerMessages(items);
        }

        debugLog("messages_fetch_success", {
          count: items.length
        });
      })
      .catch(function (error) {
        debugLog("messages_fetch_failed", {
          error: serializeError(error)
        });
        console.warn("Widget messages load failed", error);
      })
      .finally(function () {
        state.loading = false;
      });
  }

  function getClientMessageId(message) {
    if (!message || typeof message !== "object") {
      return "";
    }

    if (message.payload && typeof message.payload === "object") {
      var fromPayload = String(message.payload.client_message_id || "").trim();
      if (fromPayload !== "") {
        return fromPayload;
      }
    }

    return String(message.channel_message_id || "").trim();
  }

  function mergeServerMessages(items) {
    if (!Array.isArray(items) || items.length === 0) {
      return;
    }

    for (var i = 0; i < items.length; i += 1) {
      var message = items[i];
      var clientMessageId = getClientMessageId(message);

      if (clientMessageId && state.pendingLocalMessageIds[clientMessageId]) {
        removeLocalPendingMessage(clientMessageId);
      }
    }

    pushMessages(items);
  }

  function removeLocalPendingMessage(clientMessageId) {
    var pendingId = state.pendingLocalMessageIds[clientMessageId];

    if (typeof pendingId !== "number") {
      return;
    }

    var nextMessages = [];
    for (var i = 0; i < state.messages.length; i += 1) {
      var current = state.messages[i];
      if (current && current.id === pendingId) {
        if (typeof current.media_url === "string" && current.media_url.indexOf("blob:") === 0 && typeof URL.revokeObjectURL === "function") {
          try {
            URL.revokeObjectURL(current.media_url);
          } catch (_error) {
            // noop
          }
        }
        continue;
      }
      nextMessages.push(current);
    }

    state.messages = nextMessages;
    delete state.pendingLocalMessageIds[clientMessageId];
    delete state.messageIds[pendingId];
  }

  function addLocalPendingMessage(text, file, clientMessageId) {
    var localId = state.nextLocalMessageId;
    state.nextLocalMessageId -= 1;

    var previewUrl = null;
    if (file && typeof URL.createObjectURL === "function") {
      try {
        previewUrl = URL.createObjectURL(file);
      } catch (_error) {
        previewUrl = null;
      }
    }

    var localMessage = {
      id: localId,
      sender_type: "customer",
      direction: "inbound",
      status: "sending",
      channel_message_id: clientMessageId,
      message_type: file ? "image" : "text",
      text: text || "",
      media_url: previewUrl,
      sent_at: new Date().toISOString(),
      payload: {
        client_message_id: clientMessageId,
        local_pending: true
      }
    };

    state.pendingLocalMessageIds[clientMessageId] = localId;
    pushMessages([localMessage]);
  }

  function markPendingMessageFailed(clientMessageId) {
    var pendingId = state.pendingLocalMessageIds[clientMessageId];
    if (typeof pendingId !== "number") {
      return;
    }

    for (var i = 0; i < state.messages.length; i += 1) {
      if (state.messages[i] && state.messages[i].id === pendingId) {
        state.messages[i].status = "failed";
        break;
      }
    }

    renderMessages();
  }

  function pushMessages(items) {
    for (var i = 0; i < items.length; i += 1) {
      var message = items[i];
      if (!message || typeof message.id !== "number") {
        continue;
      }

      if (state.messageIds[message.id]) {
        continue;
      }

      state.messageIds[message.id] = true;
      state.messages.push(message);
      if (message.id > state.lastId) {
        state.lastId = message.id;
      }
    }

    renderMessages();
  }

  function renderMessages() {
    state.messages.sort(function (a, b) {
      return (a.id || 0) - (b.id || 0);
    });

    while (ui.body.firstChild) {
      ui.body.removeChild(ui.body.firstChild);
    }

    if (state.messages.length === 0) {
      ui.empty.style.display = "block";
      ui.body.appendChild(ui.empty);
      return;
    }

    ui.empty.style.display = "none";

    for (var i = 0; i < state.messages.length; i += 1) {
      var message = state.messages[i];
      var bubble = document.createElement("div");
      var isOutbound =
        message.direction === "outbound" ||
        message.sender_type === "assistant" ||
        message.sender_type === "agent";

      bubble.className = "texhub-widget-msg " + (isOutbound ? "out" : "in");
      if (message.status === "sending") {
        bubble.className += " pending";
      } else if (message.status === "failed") {
        bubble.className += " failed";
      }

      if (message.message_type === "image" && message.media_url) {
        var image = document.createElement("img");
        image.src = message.media_url;
        image.alt = "Image";
        bubble.appendChild(image);

        if (message.text) {
          var caption = document.createElement("div");
          caption.textContent = String(message.text);
          caption.style.marginTop = "8px";
          bubble.appendChild(caption);
        }
      } else {
        bubble.textContent = String(message.text || "");
      }

      var meta = document.createElement("span");
      meta.className = "texhub-widget-meta";
      if (message.status === "sending") {
        meta.textContent = "Отправка...";
      } else if (message.status === "failed") {
        meta.textContent = "Не отправлено";
      } else {
        meta.textContent = formatTime(message.sent_at || message.created_at);
      }
      bubble.appendChild(meta);

      ui.body.appendChild(bubble);
    }

    ui.body.scrollTop = ui.body.scrollHeight;
  }

  function formatTime(value) {
    if (!value) {
      return "";
    }

    var date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return "";
    }

    return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  }

  function sendMessage() {
    if (state.sending) {
      return;
    }

    var text = (ui.input.value || "").trim();
    var file = state.fileToSend;

    if (!isWidgetActive()) {
      setInlineError("Чат временно недоступен. Попробуйте позже.", true);
      return;
    }

    if (!text && !file) {
      setInlineError("Введите сообщение или добавьте фото.", false);
      return;
    }

    clearInlineError(true);

    var clientMessageId = "web_" + randomId(20);
    var draftText = text;
    var draftFile = file;

    // Optimistic UX: show message and clear input immediately.
    addLocalPendingMessage(draftText, draftFile, clientMessageId);
    ui.input.value = "";
    ui.fileInput.value = "";
    state.fileToSend = null;
    ui.fileName.style.display = "none";
    ui.fileName.textContent = "";

    var formData = new FormData();
    formData.append("session_id", state.sessionId);

    if (draftText) {
      formData.append("text", draftText);
    }

    if (draftFile) {
      formData.append("file", draftFile);
    }

    formData.append("client_message_id", clientMessageId);
    formData.append("page_url", window.location.href);
    appendFormValue(formData, "visitor_name", visitorContext.visitor_name, 160);
    appendFormValue(formData, "visitor_identifier", visitorContext.visitor_identifier, 191);
    appendFormValue(formData, "visitor_city", visitorContext.visitor_city, 120);
    appendFormValue(formData, "visitor_country", visitorContext.visitor_country, 120);
    appendFormValue(formData, "visitor_address", visitorContext.visitor_address, 255);
    appendFormValue(formData, "visitor_page", window.location.pathname + window.location.search, 2048);
    appendFormValue(formData, "visitor_referrer", visitorContext.visitor_referrer, 2048);
    appendFormValue(formData, "visitor_language", visitorContext.visitor_language, 32);
    appendFormValue(formData, "visitor_timezone", visitorContext.visitor_timezone, 80);

    state.sending = true;
    ui.send.disabled = true;
    ui.send.textContent = "…";
    ui.send.title = "Отправка";
    debugLog("send_start", {
      has_text: draftText !== "",
      has_file: !!draftFile
    });

    fetch(apiBase + "/" + encodeURIComponent(widgetKey) + "/messages", {
      method: "POST",
      mode: "cors",
      body: formData
    })
      .then(function (response) {
        if (!response.ok) {
          return parseResponseError(response)
            .then(function (message) {
              throw httpError(message || "Send message failed", response.status);
            });
        }

        return response.json();
      })
      .then(function (data) {
        if (data && data.chat_message) {
          mergeServerMessages([data.chat_message]);
        } else {
          removeLocalPendingMessage(clientMessageId);
        }

        if (data && data.assistant_message) {
          mergeServerMessages([data.assistant_message]);
        }
        clearInlineError(true);

        debugLog("send_success", {
          has_chat_message: !!(data && data.chat_message),
          has_assistant_message: !!(data && data.assistant_message)
        });
      })
      .catch(function (error) {
        markPendingMessageFailed(clientMessageId);
        setInlineError(error && error.message ? error.message : "Не удалось отправить сообщение.", false);
        debugLog("send_failed", {
          error: serializeError(error)
        });
        console.warn("Widget send failed", error);
      })
      .finally(function () {
        state.sending = false;
        ui.send.textContent = ui.send.dataset.defaultLabel || "Отправить";
        ui.send.title = "Отправить";
        applyChannelAvailability();
        fetchMessages();
      });
  }

  function parseResponseError(response) {
    return response.text()
      .then(function (text) {
        var normalized = String(text || "").trim();
        if (!normalized) {
          return "";
        }

        try {
          var payload = JSON.parse(normalized);
          var validationDetail = "";

          if (payload && payload.errors && typeof payload.errors === "object") {
            var errorKeys = Object.keys(payload.errors);
            if (errorKeys.length > 0) {
              var firstKey = errorKeys[0];
              var firstFieldErrors = payload.errors[firstKey];
              if (Array.isArray(firstFieldErrors) && firstFieldErrors.length > 0) {
                validationDetail = String(firstFieldErrors[0] || "").trim();
              } else if (typeof firstFieldErrors === "string") {
                validationDetail = firstFieldErrors.trim();
              }
            }
          }

          if (payload && typeof payload.message === "string" && payload.message.trim() !== "") {
            var message = payload.message.trim();
            if (message.toLowerCase() === "validation failed." && validationDetail !== "") {
              return validationDetail;
            }
            return message;
          }

          if (validationDetail !== "") {
            return validationDetail;
          }
        } catch (_error) {
          // ignore parse errors and fallback to raw text
        }

        return normalized.length > 220 ? (normalized.slice(0, 220) + "...") : normalized;
      })
      .catch(function () {
        return "";
      });
  }

  function appendFormValue(formData, key, value, maxLength) {
    var normalized = String(value || "").trim();
    if (normalized === "") {
      return;
    }
    if (typeof maxLength === "number" && maxLength > 0 && normalized.length > maxLength) {
      normalized = normalized.slice(0, maxLength);
    }
    formData.append(key, normalized);
  }

  function buildVisitorContext() {
    var explicitName = String(script.getAttribute("data-visitor-name") || "").trim();
    var explicitId = String(script.getAttribute("data-visitor-id") || "").trim();
    var explicitCity = String(script.getAttribute("data-visitor-city") || "").trim();
    var explicitCountry = String(script.getAttribute("data-visitor-country") || "").trim();
    var explicitAddress = String(script.getAttribute("data-visitor-address") || "").trim();
    var fallbackId = String(state.sessionId || "").trim();
    var resolvedId = explicitId || fallbackId;

    var language = "";
    if (typeof navigator !== "undefined" && typeof navigator.language === "string") {
      language = navigator.language.trim();
    }

    var timezone = "";
    try {
      timezone = String(Intl.DateTimeFormat().resolvedOptions().timeZone || "").trim();
    } catch (_error) {
      timezone = "";
    }

    var resolvedName = explicitName;
    if (!resolvedName) {
      var shortId = resolvedId ? resolvedId.slice(-8).toUpperCase() : "";
      var nameParts = [];
      nameParts.push(shortId ? ("Visitor " + shortId) : "Website Visitor");

      var locationBits = [];
      if (explicitCity) {
        locationBits.push(explicitCity);
      }
      if (explicitCountry) {
        locationBits.push(explicitCountry);
      }
      if (locationBits.length > 0) {
        nameParts.push(locationBits.join(", "));
      }

      resolvedName = nameParts.join(" · ");
    }

    var referrer = "";
    if (typeof document !== "undefined" && typeof document.referrer === "string") {
      referrer = document.referrer.trim();
    }

    return {
      visitor_name: resolvedName,
      visitor_identifier: resolvedId,
      visitor_city: explicitCity,
      visitor_country: explicitCountry,
      visitor_address: explicitAddress,
      visitor_referrer: referrer,
      visitor_language: language,
      visitor_timezone: timezone
    };
  }

  function startPolling() {
    if (state.pollTimer) {
      return;
    }

    debugLog("polling_started");
    state.pollTimer = window.setInterval(function () {
      fetchMessages();
    }, 3500);
  }

  function parseBooleanFlag(value) {
    var normalized = String(value || "").trim().toLowerCase();
    return normalized === "1" || normalized === "true" || normalized === "yes" || normalized === "on";
  }

  function getWidgetKeyFromScriptUrl(src) {
    try {
      var parsed = new URL(String(src || ""), window.location.href);
      var fromSnake = (parsed.searchParams.get("widget_key") || "").trim();
      if (fromSnake) {
        return fromSnake;
      }

      var fromCamel = (parsed.searchParams.get("widgetKey") || "").trim();
      return fromCamel || "";
    } catch (_error) {
      return "";
    }
  }

  function onDomReady(callback) {
    if (document.readyState === "complete" || document.readyState === "interactive") {
      callback();
      return;
    }

    document.addEventListener("DOMContentLoaded", function handleReady() {
      callback();
    }, { once: true });
  }

  function safeStorageGet(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (_error) {
      return null;
    }
  }

  function safeStorageSet(key, value) {
    try {
      window.localStorage.setItem(key, value);
      return true;
    } catch (_error) {
      return false;
    }
  }

  function httpError(message, status) {
    var error = new Error(message);
    error.status = status;
    return error;
  }

  function serializeError(error) {
    if (!error) {
      return { message: "Unknown error" };
    }

    return {
      message: String(error.message || "Unknown error"),
      status: typeof error.status === "number" ? error.status : null
    };
  }

  function updateDiagnostics(stage, details) {
    diagnostics.widget_key = widgetKey;
    diagnostics.stage = stage;
    diagnostics.api_base = apiBase;
    diagnostics.script_origin = scriptOrigin;
    diagnostics.session_id = state && state.sessionId ? state.sessionId : null;
    diagnostics.timestamp = new Date().toISOString();

    if (details && typeof details === "object") {
      diagnostics.details = details;
    } else {
      diagnostics.details = null;
    }

    if (typeof window.CustomEvent === "function" && typeof window.dispatchEvent === "function") {
      try {
        window.dispatchEvent(
          new window.CustomEvent("texhub:widget:status", {
            detail: {
              widget_key: widgetKey,
              stage: stage,
              details: diagnostics.details
            }
          })
        );
      } catch (_error) {
        // ignore diagnostics event errors
      }
    }
  }

  function debugLog(stage, details) {
    updateDiagnostics(stage, details);

    if (!debugEnabled || !window.console || typeof window.console.log !== "function") {
      return;
    }

    if (typeof details === "undefined") {
      window.console.log("[TexHub Widget]", stage);
      return;
    }

    window.console.log("[TexHub Widget]", stage, details);
  }
})();
