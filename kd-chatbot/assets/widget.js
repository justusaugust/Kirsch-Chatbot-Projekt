(function () {
  'use strict';

  var cfg = window.KDCB_CONFIG || {};
  if (!cfg.enabled) {
    return;
  }

  var MAX_MESSAGES = Number(cfg.max_messages || 12);
  var STORAGE_SESSION = 'kdcb_session_id_v1';
  var STORAGE_MESSAGES = 'kdcb_messages_v1';

  var state = {
    sessionId: getOrCreateSessionId(),
    messages: loadMessages(),
    waiting: false,
  };

  var ui = buildWidgetUI();
  renderMessages();

  function getOrCreateSessionId() {
    var existing = localStorage.getItem(STORAGE_SESSION);
    if (existing) {
      return existing;
    }

    var id = '';
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      id = window.crypto.randomUUID();
    } else {
      id = 'kdcb-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    localStorage.setItem(STORAGE_SESSION, id);
    return id;
  }

  function loadMessages() {
    try {
      var raw = localStorage.getItem(STORAGE_MESSAGES);
      if (!raw) {
        return [];
      }

      var parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) {
        return [];
      }

      return parsed
        .filter(function (m) {
          return m && (m.role === 'user' || m.role === 'assistant') && typeof m.content === 'string';
        })
        .slice(-MAX_MESSAGES);
    } catch (e) {
      return [];
    }
  }

  function persistMessages() {
    state.messages = state.messages.slice(-MAX_MESSAGES);
    localStorage.setItem(STORAGE_MESSAGES, JSON.stringify(state.messages));
  }

  function sanitizeText(value, maxLen) {
    var text = String(value || '').replace(/<[^>]*>/g, ' ');
    text = text.replace(/\s+/g, ' ').trim();
    if (maxLen && text.length > maxLen) {
      text = text.slice(0, maxLen);
    }
    return text;
  }

  function buildWidgetUI() {
    var root = document.createElement('div');
    root.className = 'kdcb-widget';

    var panel = document.createElement('section');
    panel.className = 'kdcb-panel';

    var header = document.createElement('div');
    header.className = 'kdcb-header';
    var title = document.createElement('h3');
    title.textContent = (cfg.strings && cfg.strings.title) || 'K&D Hausbau Chat';
    var closeBtn = document.createElement('button');
    closeBtn.className = 'kdcb-close';
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Schliessen');
    closeBtn.textContent = '×';
    header.appendChild(title);
    header.appendChild(closeBtn);

    var messages = document.createElement('div');
    messages.className = 'kdcb-messages';

    var actions = document.createElement('div');
    actions.className = 'kdcb-actions';

    var inputRow = document.createElement('div');
    inputRow.className = 'kdcb-input-row';
    var input = document.createElement('textarea');
    input.className = 'kdcb-input';
    input.placeholder = (cfg.strings && cfg.strings.placeholder) || 'Ihre Nachricht ...';
    input.maxLength = 1500;

    var sendBtn = document.createElement('button');
    sendBtn.type = 'button';
    sendBtn.className = 'kdcb-send';
    sendBtn.textContent = (cfg.strings && cfg.strings.send) || 'Senden';

    inputRow.appendChild(input);
    inputRow.appendChild(sendBtn);

    var defectBtn = document.createElement('button');
    defectBtn.type = 'button';
    defectBtn.className = 'kdcb-defect-open';
    defectBtn.textContent = (cfg.strings && cfg.strings.open_defect) || 'Maengel melden';

    actions.appendChild(inputRow);
    actions.appendChild(defectBtn);

    var defectWrap = document.createElement('div');
    defectWrap.className = 'kdcb-defect-wrap';
    defectWrap.hidden = true;

    panel.appendChild(header);
    panel.appendChild(messages);
    panel.appendChild(actions);
    panel.appendChild(defectWrap);

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'kdcb-toggle';
    toggle.setAttribute('aria-label', (cfg.strings && cfg.strings.toggle_label) || 'K&D Chat');
    toggle.textContent = 'K&D';

    root.appendChild(panel);
    root.appendChild(toggle);
    document.body.appendChild(root);

    toggle.addEventListener('click', function () {
      panel.classList.toggle('kdcb-open');
      if (panel.classList.contains('kdcb-open')) {
        input.focus();
        scrollMessagesToBottom(messages);
      }
    });

    closeBtn.addEventListener('click', function () {
      panel.classList.remove('kdcb-open');
    });

    sendBtn.addEventListener('click', function () {
      handleSendMessage(input, sendBtn, messages, defectWrap);
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        handleSendMessage(input, sendBtn, messages, defectWrap);
      }
    });

    defectBtn.addEventListener('click', function () {
      renderDefectForm(defectWrap, messages);
      sendStatusPing('[DEFECT_FORM_OPENED]');
    });

    return {
      panel: panel,
      messages: messages,
      input: input,
      sendBtn: sendBtn,
      defectWrap: defectWrap,
    };
  }

  function scrollMessagesToBottom(container) {
    container.scrollTop = container.scrollHeight;
  }

  function addMessage(role, text, sources) {
    var clean = sanitizeText(text, role === 'user' ? 1500 : 2500);
    if (!clean) {
      return;
    }

    state.messages.push({ role: role, content: clean });
    persistMessages();

    if (!ui.panel.classList.contains('kdcb-open')) {
      ui.panel.classList.add('kdcb-open');
    }

    renderMessages(sources || null);
  }

  function renderMessages(lastSources) {
    ui.messages.innerHTML = '';

    if (!state.messages.length) {
      var starter = document.createElement('div');
      starter.className = 'kdcb-msg kdcb-msg-assistant';
      starter.textContent = 'Willkommen bei K&D Hausbau. Wie kann ich helfen?';
      ui.messages.appendChild(starter);
      return;
    }

    state.messages.forEach(function (message, index) {
      var item = document.createElement('div');
      item.className = 'kdcb-msg ' + (message.role === 'user' ? 'kdcb-msg-user' : 'kdcb-msg-assistant');
      item.textContent = message.content;

      if (
        message.role === 'assistant' &&
        Array.isArray(lastSources) &&
        lastSources.length > 0 &&
        index === state.messages.length - 1
      ) {
        var srcWrap = document.createElement('div');
        srcWrap.className = 'kdcb-sources';

        lastSources.forEach(function (source) {
          if (!source || !source.url || !source.title) {
            return;
          }
          var link = document.createElement('a');
          link.href = source.url;
          link.textContent = source.title;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          srcWrap.appendChild(link);
        });

        if (srcWrap.childNodes.length > 0) {
          item.appendChild(srcWrap);
        }
      }

      ui.messages.appendChild(item);
    });

    scrollMessagesToBottom(ui.messages);
  }

  function setSending(isSending) {
    state.waiting = isSending;
    ui.sendBtn.disabled = !!isSending;
    ui.input.disabled = !!isSending;
    ui.sendBtn.textContent = isSending
      ? ((cfg.strings && cfg.strings.loading) || 'Antwort wird geladen ...')
      : ((cfg.strings && cfg.strings.send) || 'Senden');
  }

  async function postChat(payload) {
    var res = await fetch(cfg.chat_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
    });

    if (!res.ok) {
      throw new Error('chat_http_' + res.status);
    }

    return res.json();
  }

  async function handleSendMessage(input, sendBtn, messagesNode, defectWrap) {
    if (state.waiting) {
      return;
    }

    var userText = sanitizeText(input.value, 1500);
    if (!userText) {
      return;
    }

    input.value = '';
    addMessage('user', userText);

    setSending(true);

    try {
      var payload = {
        session_id: state.sessionId,
        page_url: window.location.href,
        page_title: document.title,
        messages: state.messages.slice(-MAX_MESSAGES),
      };

      var data = await postChat(payload);
      var reply = sanitizeText(data && data.reply ? data.reply : '', 2500);

      if (!reply) {
        reply = (cfg.strings && cfg.strings.error) || 'Der Chat ist aktuell nicht erreichbar.';
      }

      addMessage('assistant', reply, Array.isArray(data.sources) ? data.sources : []);

      if (data && data.action && data.action.type === 'show_defect_form') {
        renderDefectForm(defectWrap, messagesNode);
      }
    } catch (err) {
      addMessage('assistant', (cfg.strings && cfg.strings.error) || 'Der Chat ist aktuell nicht erreichbar.');
    } finally {
      setSending(false);
      scrollMessagesToBottom(messagesNode);
      sendBtn.disabled = false;
    }
  }

  function renderDefectForm(defectWrap, messagesNode) {
    defectWrap.hidden = false;
    defectWrap.innerHTML = '';

    var schema = cfg.defect_schema || {};
    var trades = Array.isArray(schema.trades) && schema.trades.length ? schema.trades : ['Dach', 'Fenster', 'Sanitaer', 'Elektro', 'Fassade', 'Innenausbau', 'Sonstiges'];
    var urgencies = Array.isArray(schema.urgencies) && schema.urgencies.length ? schema.urgencies : ['niedrig', 'mittel', 'hoch'];

    var form = document.createElement('form');
    form.className = 'kdcb-defect-form';
    form.innerHTML =
      '<div class="kdcb-step" data-step="1">' +
        fieldHtml('Vor- und Nachname*', 'full_name', 'text', true, 120) +
        fieldHtml('E-Mail*', 'email', 'email', true, 120) +
        fieldHtml('Telefonnummer', 'phone', 'text', false, 80) +
        fieldHtml('Adresse des Bauvorhabens / Objektadresse*', 'object_address', 'text', true, 220) +
        '<div class="kdcb-form-nav"><button class="kdcb-next" type="button">Weiter</button></div>' +
      '</div>' +
      '<div class="kdcb-step" data-step="2" hidden>' +
        selectHtml('Gewerk / Bereich*', 'trade', trades) +
        fieldHtml('Ort des Mangels (Raum/Etage)*', 'defect_location', 'text', true, 120) +
        textareaHtml('Beschreibung des Mangels*', 'defect_description', true, 2000) +
        selectHtml('Dringlichkeit*', 'urgency', urgencies) +
        '<label class="kdcb-checkbox"><input type="checkbox" name="callback_requested" value="1" /> Rueckruf erwuenscht</label>' +
        fieldHtml('Seite/URL, von der gemeldet wurde', 'page_url_view', 'text', false, 500, window.location.href, true) +
        '<div class="kdcb-form-nav"><button class="kdcb-prev" type="button">Zurueck</button><button class="kdcb-submit" type="submit">Maengelmeldung senden</button></div>' +
      '</div>' +
      '<div class="kdcb-status" aria-live="polite"></div>';

    defectWrap.appendChild(form);

    var step1 = form.querySelector('[data-step="1"]');
    var step2 = form.querySelector('[data-step="2"]');
    var statusNode = form.querySelector('.kdcb-status');
    var nextBtn = form.querySelector('.kdcb-next');
    var prevBtn = form.querySelector('.kdcb-prev');

    nextBtn.addEventListener('click', function () {
      clearStatus(statusNode);
      var step1Required = ['full_name', 'email', 'object_address'];
      var validation = validateFormFields(form, step1Required);
      if (!validation.ok) {
        setStatus(statusNode, validation.message, true);
        return;
      }

      step1.hidden = true;
      step2.hidden = false;
      scrollMessagesToBottom(messagesNode);
    });

    prevBtn.addEventListener('click', function () {
      clearStatus(statusNode);
      step2.hidden = true;
      step1.hidden = false;
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      clearStatus(statusNode);

      var required = ['full_name', 'email', 'object_address', 'trade', 'defect_location', 'defect_description', 'urgency'];
      var validation = validateFormFields(form, required);
      if (!validation.ok) {
        setStatus(statusNode, validation.message, true);
        return;
      }

      var description = form.elements.defect_description.value || '';
      if (description.length > 2000) {
        setStatus(statusNode, 'Die Beschreibung darf maximal 2000 Zeichen enthalten.', true);
        return;
      }

      submitDefectForm(form, statusNode);
    });

    scrollMessagesToBottom(messagesNode);
  }

  function fieldHtml(label, name, type, required, maxLength, value, readonly) {
    var req = required ? 'required' : '';
    var max = maxLength ? 'maxlength="' + Number(maxLength) + '"' : '';
    var val = typeof value === 'string' ? value.replace(/"/g, '&quot;') : '';
    var ro = readonly ? 'readonly' : '';

    return (
      '<div class="kdcb-field">' +
        '<label>' + label + '</label>' +
        '<input type="' + type + '" name="' + name + '" ' + req + ' ' + max + ' value="' + val + '" ' + ro + ' />' +
      '</div>'
    );
  }

  function textareaHtml(label, name, required, maxLength) {
    var req = required ? 'required' : '';
    var max = maxLength ? 'maxlength="' + Number(maxLength) + '"' : '';

    return (
      '<div class="kdcb-field">' +
        '<label>' + label + '</label>' +
        '<textarea name="' + name + '" ' + req + ' ' + max + '></textarea>' +
      '</div>'
    );
  }

  function selectHtml(label, name, options) {
    var html = '<div class="kdcb-field"><label>' + label + '</label><select name="' + name + '" required>';
    html += '<option value="">Bitte waehlen</option>';

    options.forEach(function (option) {
      var safe = String(option).replace(/"/g, '&quot;');
      html += '<option value="' + safe + '">' + safe + '</option>';
    });

    html += '</select></div>';
    return html;
  }

  function validateFormFields(form, requiredNames) {
    for (var i = 0; i < requiredNames.length; i += 1) {
      var key = requiredNames[i];
      var el = form.elements[key];
      if (!el) {
        continue;
      }

      var value = sanitizeText(el.value || '', key === 'defect_description' ? 2000 : 220);
      if (!value) {
        return { ok: false, message: 'Bitte fuellen Sie alle Pflichtfelder aus.' };
      }

      if (key === 'email') {
        var isValidEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        if (!isValidEmail) {
          return { ok: false, message: 'Bitte geben Sie eine gueltige E-Mail ein.' };
        }
      }
    }

    return { ok: true };
  }

  async function submitDefectForm(form, statusNode) {
    setStatus(statusNode, 'Maengelmeldung wird gesendet ...', false);

    var payload = {
      session_id: state.sessionId,
      page_url: window.location.href,
      full_name: sanitizeText(form.elements.full_name.value, 120),
      email: sanitizeText(form.elements.email.value, 120),
      phone: sanitizeText(form.elements.phone.value, 80),
      object_address: sanitizeText(form.elements.object_address.value, 220),
      trade: sanitizeText(form.elements.trade.value, 80),
      defect_location: sanitizeText(form.elements.defect_location.value, 120),
      defect_description: sanitizeText(form.elements.defect_description.value, 2000),
      urgency: sanitizeText(form.elements.urgency.value, 20),
      callback_requested: !!(form.elements.callback_requested && form.elements.callback_requested.checked),
    };

    try {
      var res = await fetch(cfg.defect_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: 'same-origin',
      });

      if (!res.ok) {
        throw new Error('defect_http_' + res.status);
      }

      var data = await res.json();
      if (!data || data.ok !== true) {
        throw new Error('defect_invalid_response');
      }

      setStatus(statusNode, 'Vielen Dank. Ihre Maengelmeldung wurde versendet.', false, true);
      addMessage('assistant', 'Vielen Dank. Ihre Maengelmeldung wurde an K&D gesendet.');
      sendStatusPing('[DEFECT_FORM_SUBMITTED]');
      form.reset();
      form.elements.page_url_view.value = window.location.href;
    } catch (err) {
      setStatus(statusNode, 'Versand fehlgeschlagen. Bitte versuchen Sie es spaeter erneut.', true);
    }
  }

  function setStatus(node, text, isError, isOk) {
    node.textContent = text;
    node.className = 'kdcb-status';
    if (isError) {
      node.classList.add('kdcb-error');
    }
    if (isOk) {
      node.classList.add('kdcb-ok');
    }
  }

  function clearStatus(node) {
    node.textContent = '';
    node.className = 'kdcb-status';
  }

  function sendStatusPing(statusText) {
    var message = sanitizeText(statusText, 120);
    if (!message) {
      return;
    }

    var pingMessages = state.messages.slice(-MAX_MESSAGES);
    pingMessages.push({ role: 'user', content: message });

    fetch(cfg.chat_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        session_id: state.sessionId,
        page_url: window.location.href,
        page_title: document.title,
        messages: pingMessages,
      }),
      credentials: 'same-origin',
    }).catch(function () {
      // Intentionally swallow ping errors.
    });
  }
})();
