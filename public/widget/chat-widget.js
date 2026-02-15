(function () {
  "use strict";

  var script = document.currentScript;
  if (!script) {
    var scripts = document.querySelectorAll("script[data-widget-key]");
    script = scripts.length > 0 ? scripts[scripts.length - 1] : null;
  }

  if (!script) {
    return;
  }

  var widgetKey = (script.getAttribute("data-widget-key") || "").trim();
  if (!widgetKey) {
    return;
  }

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

  var state = {
    sessionId: loadOrCreateSessionId(),
    isOpen: localStorage.getItem(storageOpenStateKey) === "1",
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

  var ui = createUi();
  bindUi();
  applyConfigToUi();
  togglePanel(state.isOpen);

  bootstrap();

  function bootstrap() {
    if (state.bootstrapDone) {
      return;
    }

    state.bootstrapDone = true;

    fetchConfig()
      .then(function () {
        return fetchMessages();
      })
      .catch(function (error) {
        console.warn("Widget init failed", error);
      })
      .finally(function () {
        startPolling();
      });
  }

  function loadOrCreateSessionId() {
    var existing = (localStorage.getItem(storageSessionKey) || "").trim();
    if (existing) {
      return existing;
    }

    var generated = "ws_" + randomId(28);
    localStorage.setItem(storageSessionKey, generated);
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
      ".texhub-widget-wrap{position:fixed;z-index:2147483000;display:flex;flex-direction:column;align-items:flex-end;gap:12px;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}",
      ".texhub-widget-wrap.left{left:20px;right:auto;align-items:flex-start;}",
      ".texhub-widget-wrap.right{right:20px;left:auto;align-items:flex-end;}",
      ".texhub-widget-launcher{height:56px;min-width:56px;padding:0 16px;border:none;border-radius:999px;color:#fff;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 16px 32px rgba(15,23,42,.2);}",
      ".texhub-widget-launcher:disabled{opacity:.7;cursor:not-allowed;}",
      ".texhub-widget-panel{width:min(360px,calc(100vw - 24px));height:min(560px,calc(100vh - 100px));border-radius:18px;overflow:hidden;display:flex;flex-direction:column;border:1px solid rgba(148,163,184,.35);box-shadow:0 24px 60px rgba(15,23,42,.24);background:#fff;}",
      ".texhub-widget-panel.hidden{display:none;}",
      ".texhub-widget-header{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:8px;border-bottom:1px solid rgba(148,163,184,.25);}",
      ".texhub-widget-header-title{font-size:16px;font-weight:700;line-height:1.3;margin:0;color:#0f172a;}",
      ".texhub-widget-header-status{font-size:12px;color:#64748b;margin:2px 0 0;}",
      ".texhub-widget-close{border:none;background:transparent;color:#64748b;font-size:22px;line-height:1;cursor:pointer;padding:2px 4px;border-radius:8px;}",
      ".texhub-widget-close:hover{background:rgba(148,163,184,.18);}",
      ".texhub-widget-body{flex:1;overflow:auto;padding:14px 12px;display:flex;flex-direction:column;gap:10px;background:#f8fafc;}",
      ".texhub-widget-empty{font-size:13px;color:#64748b;padding:8px 6px;}",
      ".texhub-widget-msg{max-width:86%;border-radius:14px;padding:10px 12px;font-size:14px;line-height:1.35;word-break:break-word;}",
      ".texhub-widget-msg.in{align-self:flex-start;background:#fff;color:#0f172a;border:1px solid rgba(148,163,184,.28);}",
      ".texhub-widget-msg.out{align-self:flex-end;background:#dbeafe;color:#0f172a;}",
      ".texhub-widget-msg img{max-width:100%;display:block;border-radius:10px;}",
      ".texhub-widget-meta{display:block;font-size:11px;color:#64748b;margin-top:6px;}",
      ".texhub-widget-footer{border-top:1px solid rgba(148,163,184,.25);padding:10px;display:flex;flex-direction:column;gap:8px;background:#fff;}",
      ".texhub-widget-input{width:100%;min-height:42px;max-height:120px;resize:vertical;border:1px solid rgba(148,163,184,.4);border-radius:12px;padding:10px 12px;font-size:14px;line-height:1.35;color:#0f172a;outline:none;background:#fff;}",
      ".texhub-widget-input:focus{border-color:#1677FF;box-shadow:0 0 0 3px rgba(22,119,255,.12);}",
      ".texhub-widget-actions{display:flex;align-items:center;gap:8px;}",
      ".texhub-widget-file{position:relative;overflow:hidden;border:1px solid rgba(148,163,184,.4);border-radius:10px;padding:8px 10px;font-size:12px;color:#334155;background:#fff;cursor:pointer;white-space:nowrap;}",
      ".texhub-widget-file input{position:absolute;inset:0;opacity:0;cursor:pointer;}",
      ".texhub-widget-send{flex:1;border:none;border-radius:10px;color:#fff;font-size:14px;font-weight:600;height:38px;cursor:pointer;}",
      ".texhub-widget-send:disabled{opacity:.65;cursor:not-allowed;}",
      ".texhub-widget-file-name{font-size:12px;color:#64748b;padding:0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}",
      "@media (max-width:640px){.texhub-widget-wrap{left:12px!important;right:12px!important;align-items:stretch!important}.texhub-widget-panel{width:100%;height:min(72vh,560px)}.texhub-widget-launcher{align-self:flex-end}}"
    ].join("");

    var wrapper = document.createElement("div");
    wrapper.className = "texhub-widget-wrap right";

    var panel = document.createElement("div");
    panel.className = "texhub-widget-panel hidden";

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
      empty: empty,
      input: input,
      fileInput: fileInput,
      fileName: fileName,
      send: send
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

    if (state.isOpen) {
      ui.panel.classList.remove("hidden");
      ui.launcher.textContent = "−";
      localStorage.setItem(storageOpenStateKey, "1");
      ui.input.focus();
      fetchMessages();
    } else {
      ui.panel.classList.add("hidden");
      ui.launcher.textContent = state.config.settings.launcher_label;
      localStorage.setItem(storageOpenStateKey, "0");
    }
  }

  function fetchConfig() {
    return fetch(apiBase + "/" + encodeURIComponent(widgetKey) + "/config", {
      method: "GET",
      mode: "cors"
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error("Config request failed");
        }

        return response.json();
      })
      .then(function (data) {
        if (!data || !data.widget) {
          return;
        }

        state.config = data.widget;
        if (!state.config.settings) {
          state.config.settings = {};
        }

        applyConfigToUi();
      })
      .catch(function (error) {
        console.warn("Widget config load failed", error);
      });
  }

  function applyConfigToUi() {
    var settings = state.config.settings || {};
    var position = settings.position === "bottom-left" ? "left" : "right";
    var theme = settings.theme === "dark" ? "dark" : "light";
    var color = normalizeColor(settings.primary_color) || "#1677FF";

    ui.wrapper.classList.remove("left", "right");
    ui.wrapper.classList.add(position);

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
          throw new Error("Messages request failed");
        }

        return response.json();
      })
      .then(function (data) {
        var items = Array.isArray(data && data.messages) ? data.messages : [];
        if (items.length > 0) {
          pushMessages(items);
        }
      })
      .catch(function (error) {
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

    fetch(apiBase + "/" + encodeURIComponent(widgetKey) + "/messages", {
      method: "POST",
      mode: "cors",
      body: formData
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error("Send message failed");
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
      })
      .catch(function (error) {
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

    state.pollTimer = window.setInterval(function () {
      fetchMessages();
    }, 3500);
  }
})();
