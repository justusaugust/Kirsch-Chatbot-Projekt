(function () {
  'use strict';

  var cfg = window.KDCB_CONFIG || {};
  if (!cfg.enabled) {
    return;
  }

  var MAX_MESSAGES = Number(cfg.max_messages || 12);
  var STORAGE_SESSION = 'kdcb_session_id_v2';
  var STORAGE_MESSAGES = 'kdcb_messages_v2';
  var STORAGE_DEFECT_DRAFT = 'kdcb_defect_draft_v2';
  var STORAGE_PRIVACY_DISMISSED = 'kdcb_privacy_notice_dismissed_v1';
  var DEFECT_STEP1_REQUIRED_FIELDS = ['full_name', 'email', 'object_address'];
  var DEFECT_STEP2_REQUIRED_FIELDS = ['trade', 'defect_location', 'defect_description', 'urgency'];

  var state = {
    sessionId: getOrCreateSessionId(),
    messages: loadMessages(),
    defectDraft: loadDefectDraft(),
    privacyDismissed: isPrivacyNoticeDismissed(),
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

  function loadDefectDraft() {
    try {
      var raw = localStorage.getItem(STORAGE_DEFECT_DRAFT);
      if (!raw) {
        return null;
      }

      var parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') {
        return null;
      }

      return parsed;
    } catch (e) {
      return null;
    }
  }

  function persistDefectDraft(draft) {
    if (!draft || typeof draft !== 'object') {
      return;
    }

    localStorage.setItem(STORAGE_DEFECT_DRAFT, JSON.stringify(draft));
    state.defectDraft = draft;
  }

  function clearDefectDraft() {
    localStorage.removeItem(STORAGE_DEFECT_DRAFT);
    state.defectDraft = null;
  }

  function isPrivacyNoticeDismissed() {
    try {
      return localStorage.getItem(STORAGE_PRIVACY_DISMISSED) === '1';
    } catch (e) {
      return false;
    }
  }

  function dismissPrivacyNotice() {
    state.privacyDismissed = true;
    try {
      localStorage.setItem(STORAGE_PRIVACY_DISMISSED, '1');
    } catch (e) {
      // Ignore storage errors in private mode.
    }

    if (ui && ui.privacyBanner) {
      ui.privacyBanner.hidden = true;
    }
  }

  function sanitizeText(value, maxLen) {
    var text = String(value || '').replace(/<[^>]*>/g, ' ');
    text = text.replace(/\s+/g, ' ').trim();
    if (maxLen && text.length > maxLen) {
      text = text.slice(0, maxLen);
    }
    return text;
  }

  function sanitizeAssistantText(value, maxLen) {
    var text = String(value || '').replace(/<[^>]*>/g, '');
    text = text.replace(/\r\n?/g, '\n');
    text = text.replace(/[ \t]+\n/g, '\n');
    text = text.replace(/\n{3,}/g, '\n\n');
    text = text.trim();
    if (maxLen && text.length > maxLen) {
      text = text.slice(0, maxLen);
    }
    return text;
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function renderInlineMarkdown(value) {
    var escaped = escapeHtml(value);
    var codeChunks = [];

    escaped = escaped.replace(/`([^`]+)`/g, function (_match, code) {
      var token = '%%KDCB_INLINE_CODE_' + codeChunks.length + '%%';
      codeChunks.push('<code>' + code + '</code>');
      return token;
    });

    escaped = escaped.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, function (_match, label, url) {
      var normalizedUrl = String(url || '').replace(/&amp;/g, '&');
      try {
        var parsed = new URL(normalizedUrl, window.location.origin);
        if (!/^https?:$/i.test(parsed.protocol)) {
          return label;
        }
        normalizedUrl = parsed.toString();
      } catch (error) {
        return label;
      }

      return '<a href="' + escapeHtml(normalizedUrl) + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
    });

    escaped = escaped.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    escaped = escaped.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');

    escaped = escaped.replace(/%%KDCB_INLINE_CODE_(\d+)%%/g, function (_match, index) {
      return codeChunks[Number(index)] || '';
    });

    return escaped;
  }

  function renderAssistantMarkdown(value) {
    var text = sanitizeAssistantText(value, 2500);
    if (!text) {
      return '';
    }

    var codeBlocks = [];
    text = text.replace(/```([a-zA-Z0-9_-]+)?\n([\s\S]*?)```/g, function (_match, lang, code) {
      var token = '%%KDCB_BLOCK_CODE_' + codeBlocks.length + '%%';
      var langClass = lang ? ' class="language-' + escapeHtml(lang) + '"' : '';
      codeBlocks.push('<pre class="kdcb-code"><code' + langClass + '>' + escapeHtml(code) + '</code></pre>');
      return token;
    });

    var lines = text.split('\n');
    var htmlParts = [];
    var paragraphLines = [];
    var listType = null;

    function flushParagraph() {
      if (!paragraphLines.length) {
        return;
      }
      htmlParts.push('<p>' + renderInlineMarkdown(paragraphLines.join('\n')).replace(/\n/g, '<br>') + '</p>');
      paragraphLines = [];
    }

    function closeList() {
      if (listType === 'ul') {
        htmlParts.push('</ul>');
      } else if (listType === 'ol') {
        htmlParts.push('</ol>');
      }
      listType = null;
    }

    lines.forEach(function (line) {
      var trimmed = String(line || '').trim();
      var ulMatch = /^[-*]\s+(.+)$/.exec(trimmed);
      var olMatch = /^(\d+)\.\s+(.+)$/.exec(trimmed);

      if (!trimmed) {
        flushParagraph();
        closeList();
        return;
      }

      if (ulMatch) {
        flushParagraph();
        if (listType !== 'ul') {
          closeList();
          listType = 'ul';
          htmlParts.push('<ul>');
        }
        htmlParts.push('<li>' + renderInlineMarkdown(ulMatch[1]) + '</li>');
        return;
      }

      if (olMatch) {
        flushParagraph();
        if (listType !== 'ol') {
          closeList();
          listType = 'ol';
          htmlParts.push('<ol>');
        }
        htmlParts.push('<li>' + renderInlineMarkdown(olMatch[2]) + '</li>');
        return;
      }

      if (listType) {
        closeList();
      }
      paragraphLines.push(line);
    });

    flushParagraph();
    closeList();

    var html = htmlParts.join('');
    html = html.replace(/%%KDCB_BLOCK_CODE_(\d+)%%/g, function (_match, index) {
      return codeBlocks[Number(index)] || '';
    });

    return html;
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

    var headerControls = document.createElement('div');
    headerControls.className = 'kdcb-header-controls';

    var resetBtn = document.createElement('button');
    resetBtn.className = 'kdcb-reset';
    resetBtn.type = 'button';
    resetBtn.setAttribute('aria-label', 'Chat zurücksetzen');
    resetBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M12 6V3L8 7l4 4V8c2.76 0 5 2.24 5 5a5 5 0 0 1-5 5c-2.21 0-4.1-1.43-4.78-3.42l-1.9.65A7 7 0 1 0 12 6z"/></svg>';

    var closeBtn = document.createElement('button');
    closeBtn.className = 'kdcb-close';
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Schließen');
    closeBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';

    header.appendChild(title);
    headerControls.appendChild(resetBtn);
    headerControls.appendChild(closeBtn);
    header.appendChild(headerControls);

    var privacyBanner = document.createElement('div');
    privacyBanner.className = 'kdcb-privacy-banner';
    privacyBanner.hidden = !!state.privacyDismissed;
    privacyBanner.innerHTML =
      '<div class="kdcb-privacy-copy">' +
        '<strong class="kdcb-privacy-title">Hinweis: KI-Chat</strong>' +
        '<p class="kdcb-privacy-text">Dieser Chat nutzt KI. Chat-Nachrichten werden nicht in WordPress gespeichert. Bitte keine sensiblen persönlichen Daten eingeben (z. B. Konto-, Ausweis- oder Gesundheitsdaten). Angaben aus dem Mängelformular werden nicht an die KI gesendet.</p>' +
      '</div>' +
      '<button type="button" class="kdcb-privacy-close" aria-label="Datenschutzhinweis schließen">×</button>';

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
    input.rows = 1;

    var sendBtn = document.createElement('button');
    sendBtn.type = 'button';
    sendBtn.className = 'kdcb-send';
    sendBtn.setAttribute('aria-label', (cfg.strings && cfg.strings.send) || 'Senden');
    sendBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';

    inputRow.appendChild(input);
    inputRow.appendChild(sendBtn);

    var defectBtn = document.createElement('button');
    defectBtn.type = 'button';
    defectBtn.className = 'kdcb-defect-open';
    defectBtn.textContent = (cfg.strings && cfg.strings.open_defect) || 'Mängel melden';

    actions.appendChild(inputRow);
    actions.appendChild(defectBtn);

    var defectWrap = document.createElement('div');
    defectWrap.className = 'kdcb-defect-wrap';
    defectWrap.hidden = true;

    panel.appendChild(header);
    panel.appendChild(privacyBanner);
    panel.appendChild(messages);
    panel.appendChild(actions);
    panel.appendChild(defectWrap);

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'kdcb-toggle';
    toggle.setAttribute('aria-label', (cfg.strings && cfg.strings.toggle_label) || 'K&D Chat');
    toggle.innerHTML = '<svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>';

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

    // Auto-resize textarea
    input.addEventListener('input', function() {
      this.style.height = 'auto';
      this.style.height = (this.scrollHeight) + 'px';
    });

    closeBtn.addEventListener('click', function () {
      panel.classList.remove('kdcb-open');
    });

    var privacyCloseBtn = privacyBanner.querySelector('.kdcb-privacy-close');
    if (privacyCloseBtn) {
      privacyCloseBtn.addEventListener('click', function () {
        dismissPrivacyNotice();
      });
    }

    resetBtn.addEventListener('click', function () {
      resetChatState();
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
      resetBtn: resetBtn,
      privacyBanner: privacyBanner,
    };
  }

  function resetChatState() {
    state.messages = [];
    clearDefectDraft();
    localStorage.removeItem(STORAGE_MESSAGES);
    localStorage.removeItem(STORAGE_DEFECT_DRAFT);
    localStorage.removeItem(STORAGE_SESSION);
    state.sessionId = getOrCreateSessionId();

    if (ui && ui.input) {
      ui.input.value = '';
      ui.input.style.height = '';
      ui.input.disabled = false;
    }

    if (ui && ui.sendBtn) {
      ui.sendBtn.disabled = false;
    }

    if (ui && ui.defectWrap) {
      ui.defectWrap.hidden = true;
      ui.defectWrap.innerHTML = '';
    }

    setSending(false);
    renderMessages();
  }

  function scrollMessagesToBottom(container) {
    container.scrollTop = container.scrollHeight;
  }

  function addMessage(role, text, sources) {
    var clean = role === 'assistant'
      ? sanitizeAssistantText(text, 2500)
      : sanitizeText(text, 1500);
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

      var content = document.createElement('div');
      content.className = 'kdcb-msg-content';
      if (message.role === 'assistant') {
        content.innerHTML = renderAssistantMarkdown(message.content);
      } else {
        content.textContent = message.content;
      }
      item.appendChild(content);

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
    
    if (isSending) {
      var typing = document.createElement('div');
      typing.className = 'kdcb-msg kdcb-msg-assistant kdcb-typing';
      typing.id = 'kdcb-typing';
      typing.innerHTML = '<span></span><span></span><span></span>';
      ui.messages.appendChild(typing);
      scrollMessagesToBottom(ui.messages);
    } else {
      var typing = document.getElementById('kdcb-typing');
      if (typing) {
        typing.remove();
      }
    }
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
      var reply = sanitizeAssistantText(data && data.reply ? data.reply : '', 2500);

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

    var existingForm = defectWrap.querySelector('.kdcb-defect-form');
    if (existingForm) {
      syncCallbackPhoneState(existingForm);
      updateDefectSubmitState(existingForm);
      scrollMessagesToBottom(messagesNode);
      return;
    }

    defectWrap.innerHTML = '';

    var schema = cfg.defect_schema || {};
    var trades = Array.isArray(schema.trades) && schema.trades.length ? schema.trades : ['Dach', 'Fenster', 'Sanitär', 'Elektro', 'Fassade', 'Innenausbau', 'Sonstiges'];
    var urgencies = Array.isArray(schema.urgencies) && schema.urgencies.length ? schema.urgencies : ['niedrig', 'mittel', 'hoch'];

    var head = document.createElement('div');
    head.className = 'kdcb-defect-head';
    head.innerHTML =
      '<strong class="kdcb-defect-title">Mängelformular</strong>' +
      '<button class="kdcb-defect-close" type="button" aria-label="Mängelformular schließen">×</button>';
    defectWrap.appendChild(head);

    var form = document.createElement('form');
    form.className = 'kdcb-defect-form';
    form.innerHTML =
      '<div class="kdcb-step" data-step="1">' +
        '<p class="kdcb-step-intro">Bitte geben Sie zuerst Ihre Kontaktdaten und die Objektadresse an.</p>' +
        fieldHtml('Vor- und Nachname*', 'full_name', 'text', true, 120) +
        fieldHtml('E-Mail*', 'email', 'email', true, 120) +
        fieldHtml('Adresse des Bauvorhabens / Objektadresse*', 'object_address', 'text', true, 220) +
        '<div class="kdcb-form-nav"><button class="kdcb-next" type="button">Weiter</button></div>' +
      '</div>' +
      '<div class="kdcb-step" data-step="2" hidden>' +
        '<p class="kdcb-step-intro">Beschreiben Sie den Mangel möglichst konkret, damit K&D schnell reagieren kann.</p>' +
        selectHtml('Gewerk / Bereich*', 'trade', trades) +
        fieldHtml('Ort des Mangels (Raum/Etage)*', 'defect_location', 'text', true, 120) +
        textareaHtml('Beschreibung des Mangels*', 'defect_description', true, 2000) +
        selectHtml('Dringlichkeit*', 'urgency', urgencies) +
        '<div class="kdcb-callback-card">' +
          '<label class="kdcb-checkbox"><input type="checkbox" name="callback_requested" value="1" />' +
            '<span>Rückruf erwünscht</span>' +
          '</label>' +
          '<p class="kdcb-callback-hint">Wenn Sie einen Rückruf möchten, benötigen wir eine Telefonnummer.</p>' +
          '<div class="kdcb-callback-phone" data-callback-phone hidden>' +
            fieldHtml('Telefonnummer für Rückruf*', 'phone', 'tel', false, 80) +
          '</div>' +
        '</div>' +
        '<div class="kdcb-form-nav"><button class="kdcb-prev" type="button">Zurück</button><button class="kdcb-submit" type="submit">Mängelmeldung senden</button></div>' +
      '</div>' +
      '<div class="kdcb-status" aria-live="polite"></div>';

    defectWrap.appendChild(form);

    if (state.defectDraft) {
      applyDefectDraft(form, state.defectDraft);
    }

    var step1 = form.querySelector('[data-step="1"]');
    var step2 = form.querySelector('[data-step="2"]');
    var statusNode = form.querySelector('.kdcb-status');
    var nextBtn = form.querySelector('.kdcb-next');
    var prevBtn = form.querySelector('.kdcb-prev');
    var closeBtn = defectWrap.querySelector('.kdcb-defect-close');

    closeBtn.addEventListener('click', function () {
      persistDefectDraftFromForm(form);
      defectWrap.hidden = true;
      clearStatus(statusNode);
    });

    nextBtn.addEventListener('click', function () {
      clearStatus(statusNode);
      var validation = validateFormFields(form, DEFECT_STEP1_REQUIRED_FIELDS);
      if (!validation.ok) {
        setStatus(statusNode, validation.message, true);
        return;
      }

      setDefectStep(form, 2);
      persistDefectDraftFromForm(form);
      updateDefectSubmitState(form);
      scrollMessagesToBottom(messagesNode);
    });

    prevBtn.addEventListener('click', function () {
      clearStatus(statusNode);
      setDefectStep(form, 1);
      persistDefectDraftFromForm(form);
      updateDefectSubmitState(form);
    });

    form.addEventListener('input', function () {
      syncCallbackPhoneState(form);
      persistDefectDraftFromForm(form);
      updateDefectSubmitState(form);
    });

    form.addEventListener('change', function (event) {
      if (
        event &&
        event.target &&
        event.target.name === 'callback_requested'
      ) {
        syncCallbackPhoneState(form);
      }
      persistDefectDraftFromForm(form);
      updateDefectSubmitState(form);
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      clearStatus(statusNode);

      syncCallbackPhoneState(form);
      var validation = validateFormFields(form, getDefectSubmitRequiredFields(form));
      if (!validation.ok) {
        setStatus(statusNode, validation.message, true);
        return;
      }

      var description = form.elements.defect_description.value || '';
      if (description.length > 2000) {
        setStatus(statusNode, 'Die Beschreibung darf maximal 2000 Zeichen enthalten.', true);
        return;
      }

      persistDefectDraftFromForm(form);
      submitDefectForm(form, statusNode);
    });

    if (step1 && step2 && !step1.hidden && !step2.hidden) {
      setDefectStep(form, 1);
    }

    syncCallbackPhoneState(form);
    updateDefectSubmitState(form);
    scrollMessagesToBottom(messagesNode);
  }

  function getDefectStep(form) {
    var step1 = form.querySelector('[data-step="1"]');
    if (step1 && !step1.hidden) {
      return 1;
    }
    return 2;
  }

  function setDefectStep(form, stepNumber) {
    var step1 = form.querySelector('[data-step="1"]');
    var step2 = form.querySelector('[data-step="2"]');
    if (!step1 || !step2) {
      return;
    }

    var toStep = Number(stepNumber) === 2 ? 2 : 1;
    step1.hidden = toStep !== 1;
    step2.hidden = toStep !== 2;
    form.setAttribute('data-active-step', String(toStep));
  }

  function getDefectSubmitRequiredFields(form) {
    var required = DEFECT_STEP1_REQUIRED_FIELDS.concat(DEFECT_STEP2_REQUIRED_FIELDS);
    if (form.elements.callback_requested && form.elements.callback_requested.checked) {
      required.push('phone');
    }
    return required;
  }

  function syncCallbackPhoneState(form) {
    var callbackInput = form.elements.callback_requested;
    var phoneWrap = form.querySelector('[data-callback-phone]');
    var phoneInput = form.elements.phone;
    var callbackEnabled = !!(callbackInput && callbackInput.checked);

    if (phoneWrap) {
      phoneWrap.hidden = !callbackEnabled;
    }

    if (phoneInput) {
      phoneInput.required = callbackEnabled;
      if (callbackEnabled) {
        phoneInput.setAttribute('aria-required', 'true');
      } else {
        phoneInput.removeAttribute('aria-required');
      }
    }
  }

  function collectDefectDraft(form) {
    var draft = {
      step: getDefectStep(form),
      page_url: window.location.href,
      full_name: sanitizeText(form.elements.full_name ? form.elements.full_name.value : '', 120),
      email: sanitizeText(form.elements.email ? form.elements.email.value : '', 120),
      phone: sanitizeText(form.elements.phone ? form.elements.phone.value : '', 80),
      object_address: sanitizeText(form.elements.object_address ? form.elements.object_address.value : '', 220),
      trade: sanitizeText(form.elements.trade ? form.elements.trade.value : '', 80),
      defect_location: sanitizeText(form.elements.defect_location ? form.elements.defect_location.value : '', 120),
      defect_description: sanitizeText(form.elements.defect_description ? form.elements.defect_description.value : '', 2000),
      urgency: sanitizeText(form.elements.urgency ? form.elements.urgency.value : '', 20),
      callback_requested: !!(form.elements.callback_requested && form.elements.callback_requested.checked),
    };

    return draft;
  }

  function applyDefectDraft(form, draft) {
    if (!draft || typeof draft !== 'object') {
      return;
    }

    var textFields = ['full_name', 'email', 'phone', 'object_address', 'trade', 'defect_location', 'defect_description', 'urgency'];
    textFields.forEach(function (field) {
      if (!form.elements[field]) {
        return;
      }
      if (typeof draft[field] === 'string') {
        form.elements[field].value = draft[field];
      }
    });

    if (form.elements.callback_requested) {
      form.elements.callback_requested.checked = !!draft.callback_requested;
    }
    setDefectStep(form, draft.step);
    syncCallbackPhoneState(form);
  }

  function persistDefectDraftFromForm(form) {
    persistDefectDraft(collectDefectDraft(form));
  }

  function updateDefectSubmitState(form) {
    var submitBtn = form.querySelector('.kdcb-submit');
    if (!submitBtn) {
      return;
    }

    var validation = validateFormFields(form, getDefectSubmitRequiredFields(form));
    var descriptionLength = form.elements.defect_description && typeof form.elements.defect_description.value === 'string'
      ? form.elements.defect_description.value.length
      : 0;
    var canSubmit = validation.ok && descriptionLength <= 2000;

    submitBtn.disabled = !canSubmit;
    submitBtn.setAttribute('aria-disabled', canSubmit ? 'false' : 'true');
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
    html += '<option value="">Bitte wählen</option>';

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
        if (key === 'phone') {
          return { ok: false, message: 'Bitte geben Sie eine Telefonnummer für den Rückruf an.' };
        }
        return { ok: false, message: 'Bitte füllen Sie alle Pflichtfelder aus.' };
      }

      if (key === 'email') {
        var isValidEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        if (!isValidEmail) {
          return { ok: false, message: 'Bitte geben Sie eine gültige E-Mail ein.' };
        }
      }
    }

    return { ok: true };
  }

  async function submitDefectForm(form, statusNode) {
    setStatus(statusNode, 'Mängelmeldung wird gesendet ...', false);
    var submitBtn = form.querySelector('.kdcb-submit');
    if (submitBtn) {
      submitBtn.disabled = true;
    }

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

      setStatus(statusNode, 'Vielen Dank. Ihre Mängelmeldung wurde versendet.', false, true);
      addMessage('assistant', 'Vielen Dank. Ihre Mängelmeldung wurde an K&D gesendet.');
      sendStatusPing('[DEFECT_FORM_SUBMITTED]');
      window.setTimeout(function () {
        form.reset();
        setDefectStep(form, 1);
        syncCallbackPhoneState(form);
        clearStatus(statusNode);
        clearDefectDraft();
        if (ui.defectWrap) {
          ui.defectWrap.hidden = true;
        }
      }, 1200);
    } catch (err) {
      setStatus(statusNode, 'Versand fehlgeschlagen. Bitte versuchen Sie es später erneut.', true);
    } finally {
      updateDefectSubmitState(form);
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
