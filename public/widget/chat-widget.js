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
    isOpen: safeStorageGet(storageOpenStateKey) === "1",
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
    pollTimer: null,
    fileToSend: null,
    bootstrapDone: false
  };

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
      ".texhub-widget-wrap{position:fixed !important;bottom:16px !important;z-index:2147483647 !important;display:flex !important;flex-direction:column !important;align-items:flex-end !important;gap:12px !important;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif !important;}",
      ".texhub-widget-wrap.left{left:16px !important;right:auto !important;align-items:flex-start !important;}",
      ".texhub-widget-wrap.right{right:16px !important;left:auto !important;align-items:flex-end !important;}",
      ".texhub-widget-launcher{height:56px;min-width:56px;padding:0 18px;border:none;border-radius:999px;color:#fff;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 16px 32px rgba(15,23,42,.2);}",
      ".texhub-widget-launcher:disabled{opacity:.7;cursor:not-allowed;}",
      ".texhub-widget-panel{width:min(420px,calc(100vw - 24px));height:min(760px,calc(100vh - 24px));border-radius:24px;overflow:hidden;display:flex;flex-direction:column;border:1px solid rgba(148,163,184,.35);box-shadow:0 24px 60px rgba(15,23,42,.24);background:#fff;}",
      ".texhub-widget-panel.hidden{display:none;}",
      ".texhub-widget-header{padding:22px 24px;display:flex;align-items:flex-start;justify-content:space-between;gap:8px;border-bottom:1px solid rgba(148,163,184,.25);background:#fff;}",
      ".texhub-widget-header-title{font-size:22px;font-weight:800;line-height:1.2;margin:0;color:#0f172a;}",
      ".texhub-widget-header-status{font-size:18px;color:#64748b;font-weight:600;margin:4px 0 0;}",
      ".texhub-widget-close{border:none;background:transparent;color:#64748b;font-size:42px;line-height:1;cursor:pointer;padding:0 2px;border-radius:8px;}",
      ".texhub-widget-close:hover{background:rgba(148,163,184,.18);}",
      ".texhub-widget-body{flex:1;overflow:auto;padding:20px;display:flex;flex-direction:column;gap:12px;background:#F8FAFC;}",
      ".texhub-widget-empty{font-size:42px;line-height:1.3;color:#64748b;padding:14px 18px;}",
      ".texhub-widget-msg{max-width:92%;border-radius:24px;padding:16px 18px;font-size:18px;line-height:1.45;word-break:break-word;}",
      ".texhub-widget-msg.in{align-self:flex-start;background:#fff;color:#0f172a;border:1px solid rgba(148,163,184,.28);}",
      ".texhub-widget-msg.out{align-self:flex-end;background:#dbeafe;color:#0f172a;}",
      ".texhub-widget-msg img{max-width:100%;display:block;border-radius:14px;}",
      ".texhub-widget-meta{display:block;font-size:14px;color:#64748b;margin-top:8px;}",
      ".texhub-widget-footer{border-top:1px solid rgba(148,163,184,.25);padding:12px;display:flex;flex-direction:column;gap:8px;background:#fff;}",
      ".texhub-widget-input{width:100%;min-height:74px;max-height:200px;resize:vertical;border:2px solid rgba(148,163,184,.4);border-radius:22px;padding:16px 18px;font-size:44px;line-height:1.35;color:#0f172a;outline:none;background:#fff;}",
      ".texhub-widget-input:focus{border-color:#1677FF;box-shadow:0 0 0 3px rgba(22,119,255,.12);}",
      ".texhub-widget-actions{display:flex;align-items:center;gap:8px;}",
      ".texhub-widget-file{position:relative;overflow:hidden;border:2px solid rgba(148,163,184,.4);border-radius:18px;padding:10px 18px;font-size:18px;font-weight:600;color:#334155;background:#fff;cursor:pointer;white-space:nowrap;}",
      ".texhub-widget-file input{position:absolute;inset:0;opacity:0;cursor:pointer;}",
      ".texhub-widget-send{flex:1;border:none;border-radius:18px;color:#fff;font-size:20px;font-weight:700;height:54px;cursor:pointer;}",
      ".texhub-widget-send:disabled{opacity:.65;cursor:not-allowed;}",
      ".texhub-widget-file-name{font-size:14px;color:#64748b;padding:0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}",
      ".texhub-widget-error{display:none;font-size:13px;line-height:1.35;color:#B42318;background:#FEE4E2;border:1px solid #FDA29B;padding:8px 10px;border-radius:12px;}",
      "@media (max-width:640px){.texhub-widget-wrap{left:12px!important;right:12px!important;bottom:12px!important;align-items:stretch!important}.texhub-widget-panel{width:100%;height:min(82vh,760px)}.texhub-widget-header{padding:16px}.texhub-widget-header-title{font-size:20px}.texhub-widget-header-status{font-size:16px}.texhub-widget-body{padding:14px}.texhub-widget-empty{font-size:18px;padding:8px}.texhub-widget-msg{font-size:17px;padding:14px 16px}.texhub-widget-input{font-size:16px;min-height:62px}.texhub-widget-send{height:50px;font-size:18px}}"
    ].join("");

    var wrapper = document.createElement("div");
    wrapper.className = "texhub-widget-wrap right";
    wrapper.style.position = "fixed";
    wrapper.style.bottom = "16px";
    wrapper.style.right = "16px";
    wrapper.style.left = "auto";
    wrapper.style.zIndex = "2147483000";
    wrapper.style.display = "flex";
    wrapper.style.flexDirection = "column";
    wrapper.style.alignItems = "flex-end";
    wrapper.style.gap = "12px";

    var panel = document.createElement("div");
    panel.className = "texhub-widget-panel hidden";
    panel.style.display = "none";
    panel.style.width = "min(360px,calc(100vw - 24px))";
    panel.style.height = "min(560px,calc(100vh - 100px))";
    panel.style.border = "1px solid rgba(148,163,184,.35)";
    panel.style.borderRadius = "18px";
    panel.style.overflow = "hidden";
    panel.style.boxShadow = "0 24px 60px rgba(15,23,42,.24)";
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

    var actions = document.createElement("div");
    actions.className = "texhub-widget-actions";

    var fileButton = document.createElement("label");
    fileButton.className = "texhub-widget-file";
    fileButton.textContent = "Фото";

    var fileInput = document.createElement("input");
    fileInput.type = "file";
    fileInput.accept = "image/*";

    fileButton.appendChild(fileInput);

    var send = document.createElement("button");
    send.type = "button";
    send.className = "texhub-widget-send";
    send.textContent = "Отправить";

    actions.appendChild(fileButton);
    actions.appendChild(send);

    footer.appendChild(input);
    footer.appendChild(fileName);
    footer.appendChild(actions);

    panel.appendChild(header);
    panel.appendChild(body);
    panel.appendChild(footer);

    var launcher = document.createElement("button");
    launcher.type = "button";
    launcher.className = "texhub-widget-launcher";
    launcher.textContent = state.config.settings.launcher_label;
    launcher.style.height = "56px";
    launcher.style.minWidth = "56px";
    launcher.style.padding = "0 16px";
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
    launcher.style.boxShadow = "0 16px 32px rgba(15,23,42,.2)";

    var error = document.createElement("div");
    error.className = "texhub-widget-error";

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
      error: error
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
      safeStorageSet(storageOpenStateKey, "1");
      ui.input.focus();
      fetchMessages();
    } else {
      ui.panel.classList.add("hidden");
      ui.panel.style.display = "none";
      ui.launcher.textContent = state.config.settings.launcher_label;
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
    if (!ui || !ui.wrapper || !ui.panel || !ui.launcher || !ui.send || !ui.title || !ui.status || !ui.input || !ui.body || !ui.empty || !ui.footer) {
      debugLog("apply_config_skipped_ui_not_ready");
      return;
    }

    var settings = state.config.settings || {};
    var position = settings.position === "bottom-left" ? "left" : "right";
    var theme = settings.theme === "dark" ? "dark" : "light";
    var color = normalizeColor(settings.primary_color) || "#1677FF";

    ui.wrapper.classList.remove("left", "right");
    ui.wrapper.classList.add(position);
    ui.wrapper.style.bottom = "20px";
    if (position === "left") {
      ui.wrapper.style.left = "20px";
      ui.wrapper.style.right = "auto";
      ui.wrapper.style.alignItems = "flex-start";
    } else {
      ui.wrapper.style.right = "20px";
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
      ui.input.style.background = "#111827";
      ui.input.style.color = "#F8FAFC";
      ui.input.style.borderColor = "rgba(148,163,184,.35)";
      ui.empty.style.color = "#CBD5E1";
      ui.empty.textContent = settings.welcome_message || "Здравствуйте! Напишите ваш вопрос.";
    } else {
      ui.panel.style.background = "#ffffff";
      ui.body.style.background = "#F8FAFC";
      ui.footer.style.background = "#ffffff";
      ui.title.style.color = "#0F172A";
      ui.status.style.color = "#64748B";
      ui.input.style.background = "#ffffff";
      ui.input.style.color = "#0F172A";
      ui.input.style.borderColor = "rgba(148,163,184,.4)";
      ui.empty.style.color = "#64748B";
      ui.empty.textContent = settings.welcome_message || "Здравствуйте! Напишите ваш вопрос.";
    }
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
          pushMessages(items);
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
      meta.textContent = formatTime(message.sent_at || message.created_at);
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

    if (!text && !file) {
      return;
    }

    var formData = new FormData();
    formData.append("session_id", state.sessionId);

    if (text) {
      formData.append("text", text);
    }

    if (file) {
      formData.append("file", file);
    }

    formData.append("client_message_id", "web_" + randomId(20));
    formData.append("page_url", window.location.href);

    state.sending = true;
    ui.send.disabled = true;
    debugLog("send_start", {
      has_text: text !== "",
      has_file: !!file
    });

    fetch(apiBase + "/" + encodeURIComponent(widgetKey) + "/messages", {
      method: "POST",
      mode: "cors",
      body: formData
    })
      .then(function (response) {
        if (!response.ok) {
          throw httpError("Send message failed", response.status);
        }

        return response.json();
      })
      .then(function (data) {
        if (data && data.chat_message) {
          pushMessages([data.chat_message]);
        }

        if (data && data.assistant_message) {
          pushMessages([data.assistant_message]);
        }

        ui.input.value = "";
        ui.fileInput.value = "";
        state.fileToSend = null;
        ui.fileName.style.display = "none";
        ui.fileName.textContent = "";

        debugLog("send_success", {
          has_chat_message: !!(data && data.chat_message),
          has_assistant_message: !!(data && data.assistant_message)
        });
      })
      .catch(function (error) {
        debugLog("send_failed", {
          error: serializeError(error)
        });
        console.warn("Widget send failed", error);
      })
      .finally(function () {
        state.sending = false;
        ui.send.disabled = false;
        fetchMessages();
      });
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
