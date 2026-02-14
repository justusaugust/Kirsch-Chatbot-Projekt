(function () {
  'use strict';

  var cfg = window.KDCB_CONFIG || {};
  if (!cfg.enabled) {
    return;
  }

  var MAX_MESSAGES = Number(cfg.max_messages || 12);

  var STORAGE_SESSION = 'kdcb_session_id_v3';
  var STORAGE_MESSAGES = 'kdcb_messages_v3';
  var STORAGE_DEFECT_DRAFT = 'kdcb_defect_draft_v3';
  var STORAGE_PRIVACY_DISMISSED = 'kdcb_privacy_notice_dismissed_v1';

  var STORAGE_SESSION_LEGACY = 'kdcb_session_id_v2';
  var STORAGE_MESSAGES_LEGACY = 'kdcb_messages_v2';
  var STORAGE_DEFECT_DRAFT_LEGACY = 'kdcb_defect_draft_v2';

  var DEFECT_STEP1_REQUIRED_FIELDS = ['full_name', 'email', 'object_address'];
  var DEFECT_STEP2_REQUIRED_FIELDS = ['trade', 'defect_location', 'defect_description', 'urgency'];

  var state = {
    sessionId: getOrCreateSessionId(),
    messages: loadMessages(),
    defectDraft: loadDefectDraft(),
    privacyDismissed: isPrivacyNoticeDismissed(),
    panelOpen: false,
    defectOpen: false,
    historyOpen: false,
    waiting: false,
    launcherHover: false,
    openFrameId: null,
    openDelayId: null,
    inputFadeProgress: 0,
    inputFadeTarget: 0,
    inputFadeFrameId: null,
    inputFadeLastTs: null,
  };

  var ui = buildWidgetUI();
  setCherryVisual('idle');
  renderAll();

  function safeGetItem(key) {
    try {
      return localStorage.getItem(key);
    } catch (error) {
      return null;
    }
  }

  function safeSetItem(key, value) {
    try {
      localStorage.setItem(key, value);
    } catch (error) {
      // Ignore private mode or quota errors.
    }
  }

  function safeRemoveItem(key) {
    try {
      localStorage.removeItem(key);
    } catch (error) {
      // Ignore private mode or quota errors.
    }
  }

  function firstAvailableStorage(keys) {
    for (var i = 0; i < keys.length; i += 1) {
      var value = safeGetItem(keys[i]);
      if (value) {
        return value;
      }
    }
    return null;
  }

  function getOrCreateSessionId() {
    var existing = firstAvailableStorage([STORAGE_SESSION, STORAGE_SESSION_LEGACY]);
    if (existing) {
      safeSetItem(STORAGE_SESSION, existing);
      return existing;
    }

    var id = '';
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      id = window.crypto.randomUUID();
    } else {
      id = 'kdcb-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    safeSetItem(STORAGE_SESSION, id);
    return id;
  }

  function normalizeSources(sources) {
    if (!Array.isArray(sources)) {
      return [];
    }

    var normalized = [];

    sources.forEach(function (source) {
      if (!source || typeof source !== 'object') {
        return;
      }

      var title = sanitizeText(source.title || '', 120);
      var url = normalizeHttpUrl(source.url || '');
      if (!title || !url) {
        return;
      }

      normalized.push({
        title: title,
        url: url,
      });
    });

    return normalized.slice(0, 6);
  }

  function loadMessages() {
    var raw = firstAvailableStorage([STORAGE_MESSAGES, STORAGE_MESSAGES_LEGACY]);
    if (!raw) {
      return [];
    }

    var parsed;
    try {
      parsed = JSON.parse(raw);
    } catch (error) {
      return [];
    }

    if (!Array.isArray(parsed)) {
      return [];
    }

    var messages = parsed
      .filter(function (message) {
        return message && typeof message === 'object';
      })
      .map(function (message) {
        var role = message.role === 'assistant' ? 'assistant' : 'user';
        var content = role === 'assistant'
          ? sanitizeAssistantText(message.content || '', 2500)
          : sanitizeText(message.content || '', 1500);

        if (!content) {
          return null;
        }

        var out = {
          role: role,
          content: content,
        };

        if (role === 'assistant') {
          out.sources = normalizeSources(message.sources || []);
        }

        return out;
      })
      .filter(Boolean)
      .slice(-MAX_MESSAGES);

    safeSetItem(STORAGE_MESSAGES, JSON.stringify(messages));
    return messages;
  }

  function persistMessages() {
    state.messages = state.messages.slice(-MAX_MESSAGES);
    safeSetItem(STORAGE_MESSAGES, JSON.stringify(state.messages));
  }

  function loadDefectDraft() {
    var raw = firstAvailableStorage([STORAGE_DEFECT_DRAFT, STORAGE_DEFECT_DRAFT_LEGACY]);
    if (!raw) {
      return null;
    }

    try {
      var parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') {
        return null;
      }
      safeSetItem(STORAGE_DEFECT_DRAFT, JSON.stringify(parsed));
      return parsed;
    } catch (error) {
      return null;
    }
  }

  function persistDefectDraft(draft) {
    if (!draft || typeof draft !== 'object') {
      return;
    }

    safeSetItem(STORAGE_DEFECT_DRAFT, JSON.stringify(draft));
    state.defectDraft = draft;
  }

  function clearDefectDraft() {
    safeRemoveItem(STORAGE_DEFECT_DRAFT);
    safeRemoveItem(STORAGE_DEFECT_DRAFT_LEGACY);
    state.defectDraft = null;
  }

  function isPrivacyNoticeDismissed() {
    return safeGetItem(STORAGE_PRIVACY_DISMISSED) === '1';
  }

  function dismissPrivacyNotice() {
    state.privacyDismissed = true;
    safeSetItem(STORAGE_PRIVACY_DISMISSED, '1');
    renderAll();
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
    text = text.replace(/\n{3,}/g, '\n\n').trim();
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

  function normalizeHttpUrl(url) {
    var clean = String(url || '').trim();
    if (!clean) {
      return '';
    }

    try {
      var parsed = new URL(clean, window.location.origin);
      if (!/^https?:$/i.test(parsed.protocol)) {
        return '';
      }
      return parsed.toString();
    } catch (error) {
      return '';
    }
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
      var normalizedUrl = normalizeHttpUrl(String(url || '').replace(/&amp;/g, '&'));
      if (!normalizedUrl) {
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

  function getCherryAsset(key) {
    var assets = cfg.cherry_assets || {};
    var fallbackMap = {
      idle_url: '',
      hover_url: assets.idle_url || '',
      open_idle_url: assets.idle_url || '',
      open_talk_url: assets.open_idle_url || assets.idle_url || '',
    };

    return String(assets[key] || fallbackMap[key] || '').trim();
  }

  function setCherryVisual(stateName) {
    if (!ui || !ui.cherryImage) {
      return;
    }

    var key = 'idle_url';
    if (stateName === 'hover') {
      key = 'hover_url';
    } else if (stateName === 'open_idle') {
      key = 'open_idle_url';
    } else if (stateName === 'open_talk') {
      key = 'open_talk_url';
    }

    var src = getCherryAsset(key);
    if (src) {
      ui.cherryImage.src = src;
    }
    ui.cherryImage.setAttribute('data-state', stateName);
  }

  function updateCherryVisualForContext() {
    if (state.panelOpen) {
      setCherryVisual('open_idle');
      return;
    }

    setCherryVisual(state.launcherHover ? 'hover' : 'idle');
  }

  function isMobileViewport() {
    return !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
  }

  function updateMobileSheetState(open) {
    if (!ui) {
      return;
    }

    var mobile = isMobileViewport();
    ui.sheetHeader.hidden = !(mobile && open);
    ui.backdrop.hidden = !(mobile && open);

    if (mobile && open) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
  }

  function getOpenMotionProfile() {
    var prefersReducedMotion = false;
    var isMobile = false;

    if (window.matchMedia) {
      prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      isMobile = window.matchMedia('(max-width: 768px)').matches;
    }

    if (prefersReducedMotion) {
      return {
        openDelayMs: 0,
        focusDelayMs: 120,
      };
    }

    if (isMobile) {
      return {
        openDelayMs: 34,
        focusDelayMs: 460,
      };
    }

    return {
      openDelayMs: 68,
      focusDelayMs: 700,
    };
  }

  function openPanel() {
    if (state.panelOpen) {
      return;
    }

    state.panelOpen = true;
    state.launcherHover = false;

    if (state.openFrameId) {
      window.cancelAnimationFrame(state.openFrameId);
      state.openFrameId = null;
    }
    if (state.openDelayId) {
      window.clearTimeout(state.openDelayId);
      state.openDelayId = null;
    }

    ui.root.classList.remove('kdcb-open');
    renderModeVisibility();
    renderHistoryDrawer();
    renderMainBubble();
    // Ensure browser commits initial style before transitioning to open.
    void ui.shell.offsetWidth;
    var motion = getOpenMotionProfile();

    updateMobileSheetState(true);

    // Trigger open transitions after a short delay so the lift-in feels deliberate.
    state.openFrameId = window.requestAnimationFrame(function () {
      state.openFrameId = window.requestAnimationFrame(function () {
        state.openFrameId = null;
        state.openDelayId = window.setTimeout(function () {
          ui.root.classList.add('kdcb-open');
          state.openDelayId = null;
          updateCherryVisualForContext();
        }, motion.openDelayMs);
      });
    });

    window.setTimeout(function () {
      if (state.panelOpen && !state.defectOpen) {
        ui.input.focus();
      }
    }, motion.focusDelayMs);
  }

  function closePanel() {
    if (!state.panelOpen) {
      return;
    }

    state.panelOpen = false;
    state.historyOpen = false;
    ui.root.classList.remove('kdcb-history-open');

    if (state.defectOpen) {
      closeDefectOverlay(true);
    }

    if (state.openFrameId) {
      window.cancelAnimationFrame(state.openFrameId);
      state.openFrameId = null;
    }
    if (state.openDelayId) {
      window.clearTimeout(state.openDelayId);
      state.openDelayId = null;
    }

    ui.root.classList.remove('kdcb-open');
    updateMobileSheetState(false);

    if (ui.historyDrawer) {
      ui.historyDrawer.hidden = true;
    }

    updateCherryVisualForContext();
  }

  function toggleHistoryDrawer() {
    state.historyOpen = !state.historyOpen;
    renderHistoryDrawer();
    updateInputPlaceholder();
  }

  function renderMainBubble() {
    if (isMobileViewport()) {
      renderMobileChatView();
      return;
    }
    renderDesktopBubble();
  }

  function renderDesktopBubble() {
    ui.bubble.innerHTML = '';

    if (!state.privacyDismissed) {
      var notice = document.createElement('div');
      notice.className = 'kdcb-privacy-notice kdcb-stagger';
      notice.innerHTML =
        '<strong>Hinweis: KI-Chat</strong>' +
        '<p>Dieser Chat nutzt KI. Chat-Nachrichten werden nicht in WordPress gespeichert. ' +
        'Bitte keine sensiblen persönlichen Daten eingeben. Angaben aus dem Mängelformular werden nicht an die KI gesendet.</p>';
      var acceptBtn = document.createElement('button');
      acceptBtn.type = 'button';
      acceptBtn.className = 'kdcb-privacy-accept';
      acceptBtn.textContent = 'Verstanden';
      acceptBtn.addEventListener('click', function () {
        dismissPrivacyNotice();
      });
      notice.appendChild(acceptBtn);
      ui.bubble.appendChild(notice);
      return;
    }

    if (state.waiting) {
      var typing = document.createElement('div');
      typing.className = 'kdcb-main-typing';
      typing.innerHTML = '<span></span><span></span><span></span>';
      ui.bubble.appendChild(typing);
      return;
    }

    var latestAssistant = getLatestAssistantMessage();
    if (latestAssistant) {
      var content = document.createElement('div');
      content.className = 'kdcb-main-content kdcb-stagger';
      content.innerHTML = renderAssistantMarkdown(latestAssistant.content);
      ui.bubble.appendChild(content);

      if (Array.isArray(latestAssistant.sources) && latestAssistant.sources.length) {
        var sourceWrap = document.createElement('div');
        sourceWrap.className = 'kdcb-main-sources';
        latestAssistant.sources.forEach(function (source) {
          if (!source || !source.title || !source.url) { return; }
          var link = document.createElement('a');
          link.href = source.url;
          link.textContent = source.title;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          sourceWrap.appendChild(link);
        });
        if (sourceWrap.childNodes.length) {
          ui.bubble.appendChild(sourceWrap);
        }
      }
      return;
    }

    var starter = document.createElement('div');
    starter.className = 'kdcb-main-content kdcb-stagger';
    starter.innerHTML =
      '<p><strong class="kdcb-welcome-heading">Willkommen. Wie können wir helfen?</strong></p>' +
      '<p>Ich unterstütze Sie direkt bei:</p>' +
      '<ul>' +
      '<li>Kauf- und Mietanfragen</li>' +
      '<li>Leistungen und Ansprechpartnern</li>' +
      '<li>Mängelmeldungen</li>' +
      '</ul>';
    ui.bubble.appendChild(starter);
  }

  function renderMobileChatView() {
    ui.bubble.innerHTML = '';

    if (!state.privacyDismissed) {
      var notice = document.createElement('div');
      notice.className = 'kdcb-privacy-notice kdcb-stagger';
      notice.innerHTML =
        '<strong>Hinweis: KI-Chat</strong>' +
        '<p>Dieser Chat nutzt KI. Chat-Nachrichten werden nicht in WordPress gespeichert. ' +
        'Bitte keine sensiblen persönlichen Daten eingeben. Angaben aus dem Mängelformular werden nicht an die KI gesendet.</p>';
      var acceptBtn = document.createElement('button');
      acceptBtn.type = 'button';
      acceptBtn.className = 'kdcb-privacy-accept';
      acceptBtn.textContent = 'Verstanden';
      acceptBtn.addEventListener('click', function () {
        dismissPrivacyNotice();
      });
      notice.appendChild(acceptBtn);
      ui.bubble.appendChild(notice);
      return;
    }

    var chatList = document.createElement('div');
    chatList.className = 'kdcb-chat-list';

    if (!state.messages.length) {
      var welcome = document.createElement('div');
      welcome.className = 'kdcb-chat-msg kdcb-chat-msg-assistant';
      welcome.innerHTML =
        '<p><strong class="kdcb-welcome-heading">Willkommen. Wie können wir helfen?</strong></p>' +
        '<p>Ich unterstütze Sie direkt bei:</p>' +
        '<ul>' +
        '<li>Kauf- und Mietanfragen</li>' +
        '<li>Leistungen und Ansprechpartnern</li>' +
        '<li>Mängelmeldungen</li>' +
        '</ul>';
      chatList.appendChild(welcome);
    }

    state.messages.forEach(function (message) {
      var msgEl = document.createElement('div');
      msgEl.className = 'kdcb-chat-msg kdcb-chat-msg-' + message.role;

      if (message.role === 'assistant') {
        msgEl.innerHTML = renderAssistantMarkdown(message.content);

        if (Array.isArray(message.sources) && message.sources.length) {
          var sourceWrap = document.createElement('div');
          sourceWrap.className = 'kdcb-main-sources';
          message.sources.forEach(function (source) {
            if (!source || !source.title || !source.url) { return; }
            var link = document.createElement('a');
            link.href = source.url;
            link.textContent = source.title;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            sourceWrap.appendChild(link);
          });
          if (sourceWrap.childNodes.length) {
            msgEl.appendChild(sourceWrap);
          }
        }
      } else {
        msgEl.textContent = message.content;
      }

      chatList.appendChild(msgEl);
    });

    if (state.waiting) {
      var typing = document.createElement('div');
      typing.className = 'kdcb-chat-msg kdcb-chat-msg-assistant';
      var dots = document.createElement('div');
      dots.className = 'kdcb-main-typing';
      dots.innerHTML = '<span></span><span></span><span></span>';
      typing.appendChild(dots);
      chatList.appendChild(typing);
    }

    ui.bubble.appendChild(chatList);

    // Auto-scroll to bottom after render
    window.setTimeout(function () {
      ui.bubble.scrollTop = ui.bubble.scrollHeight;
    }, 30);
  }

  function renderHistoryDrawer() {
    if (!ui.historyDrawer) {
      return;
    }

    ui.historyDrawer.innerHTML = '';

    if (!state.panelOpen || !state.historyOpen || state.defectOpen) {
      ui.historyDrawer.hidden = true;
      ui.root.classList.remove('kdcb-history-open');
      if (ui.historyToggle) {
        ui.historyToggle.setAttribute('aria-expanded', 'false');
      }
      updateInputPlaceholder();
      return;
    }

    ui.historyDrawer.hidden = false;
    ui.root.classList.add('kdcb-history-open');
    if (ui.historyToggle) {
      ui.historyToggle.setAttribute('aria-expanded', 'true');
    }

    var header = document.createElement('div');
    header.className = 'kdcb-history-head';

    var titleWrap = document.createElement('div');
    titleWrap.className = 'kdcb-history-head-copy';

    var title = document.createElement('strong');
    title.className = 'kdcb-history-head-title';
    title.textContent = 'Verlauf';

    var counter = document.createElement('span');
    counter.className = 'kdcb-history-head-count';
    counter.textContent = state.messages.length + ' ' + (state.messages.length === 1 ? 'Nachricht' : 'Nachrichten');

    titleWrap.appendChild(title);
    titleWrap.appendChild(counter);

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'kdcb-history-close';
    closeBtn.setAttribute('aria-label', 'Verlauf schließen');
    closeBtn.textContent = '×';
    closeBtn.addEventListener('click', function () {
      state.historyOpen = false;
      renderHistoryDrawer();
      updateInputPlaceholder();
    });

    header.appendChild(titleWrap);
    header.appendChild(closeBtn);
    ui.historyDrawer.appendChild(header);

    if (!state.messages.length) {
      var emptyState = document.createElement('div');
      emptyState.className = 'kdcb-history-empty-state';
      emptyState.innerHTML =
        '<p class="kdcb-history-empty-title">Noch kein Verlauf vorhanden.</p>' +
        '<p class="kdcb-history-empty">Die letzten 12 Nachrichten erscheinen hier automatisch.</p>';
      ui.historyDrawer.appendChild(emptyState);
      updateInputPlaceholder();
      return;
    }

    state.messages.forEach(function (message) {
      var item = document.createElement('article');
      item.className = 'kdcb-history-item kdcb-history-' + message.role;

      var role = document.createElement('div');
      role.className = 'kdcb-history-role';
      role.textContent = message.role === 'assistant' ? 'Assistent' : 'Sie';
      item.appendChild(role);

      var body = document.createElement('div');
      body.className = 'kdcb-history-body';
      if (message.role === 'assistant') {
        body.innerHTML = renderAssistantMarkdown(message.content);
      } else {
        body.textContent = message.content;
      }
      item.appendChild(body);

      if (message.role === 'assistant' && Array.isArray(message.sources) && message.sources.length) {
        var sourceDetails = document.createElement('details');
        sourceDetails.className = 'kdcb-history-sources-toggle';

        var summary = document.createElement('summary');
        summary.textContent = 'Quellen (' + message.sources.length + ')';
        sourceDetails.appendChild(summary);

        var sourceWrap = document.createElement('div');
        sourceWrap.className = 'kdcb-history-sources';

        message.sources.forEach(function (source) {
          if (!source || !source.title || !source.url) {
            return;
          }

          var link = document.createElement('a');
          link.href = source.url;
          link.textContent = source.title;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          sourceWrap.appendChild(link);
        });

        if (sourceWrap.childNodes.length) {
          sourceDetails.appendChild(sourceWrap);
          item.appendChild(sourceDetails);
        }
      }

      ui.historyDrawer.appendChild(item);
    });

    ui.historyDrawer.scrollTop = ui.historyDrawer.scrollHeight;
    updateInputPlaceholder();
  }

  function getSmartPlaceholder() {
    return (cfg.strings && cfg.strings.placeholder) || 'Ihre Nachricht ...';
  }

  function updateInputPlaceholder() {
    if (!ui || !ui.input) {
      return;
    }

    ui.input.placeholder = getSmartPlaceholder();
  }

  function renderInputState() {
    var waiting = !!state.waiting;
    var empty = !ui.input.value.trim();
    ui.input.disabled = waiting;
    ui.sendBtn.disabled = waiting || empty;
    ui.sendBtn.title = (!waiting && empty) ? 'Bitte Nachricht eingeben' : '';
    updateInputPlaceholder();
    updateInputOverflowState(true);
  }

  function applyInputFadeProgress(progress) {
    if (!ui || !ui.inputRow) {
      return;
    }

    var clamped = Math.max(0, Math.min(1, Number(progress) || 0));
    var easedOpacity = Math.pow(clamped, 1.45);
    var easedBlur = 1 - Math.pow(1 - clamped, 1.6);
    var reveal = clamped <= 0.001 ? 0 : (1 - Math.exp(-clamped * 10));
    var opacity = clamped <= 0.001 ? 0 : Math.min(0.9, (reveal * 0.12) + (easedOpacity * 0.78));
    var blur = clamped <= 0.001 ? 0 : ((reveal * 0.6) + (easedBlur * 4.8));
    var height = 12 + (clamped * 22);

    ui.inputRow.style.setProperty('--kdcb-input-fade-progress', clamped.toFixed(4));
    ui.inputRow.style.setProperty('--kdcb-input-fade-opacity', opacity.toFixed(4));
    ui.inputRow.style.setProperty('--kdcb-input-fade-blur', blur.toFixed(3) + 'px');
    ui.inputRow.style.setProperty('--kdcb-input-fade-height', height.toFixed(2) + 'px');
  }

  function animateInputFadeFrame(timestamp) {
    if (!state.inputFadeLastTs) {
      state.inputFadeLastTs = timestamp;
    }

    var deltaMs = Math.max(0, Math.min(64, timestamp - state.inputFadeLastTs));
    state.inputFadeLastTs = timestamp;

    var diff = state.inputFadeTarget - state.inputFadeProgress;
    if (Math.abs(diff) <= 0.0025) {
      state.inputFadeProgress = state.inputFadeTarget;
      applyInputFadeProgress(state.inputFadeProgress);
      state.inputFadeFrameId = null;
      state.inputFadeLastTs = null;
      return;
    }

    var tau = diff < 0 ? 64 : 105;
    var smoothing = 1 - Math.exp((-deltaMs || 16) / tau);
    state.inputFadeProgress += diff * smoothing;
    applyInputFadeProgress(state.inputFadeProgress);
    state.inputFadeFrameId = window.requestAnimationFrame(animateInputFadeFrame);
  }

  function updateInputOverflowState(immediate) {
    if (!ui || !ui.input || !ui.inputRow) {
      return;
    }

    var hiddenPx = Math.max(0, ui.input.scrollHeight - ui.input.clientHeight);
    var hasOverflow = hiddenPx > 1;
    var target = 0;

    if (hasOverflow) {
      var overflowBase = 0.34;
      var maxScroll = Math.max(0, ui.input.scrollHeight - ui.input.clientHeight);
      var fadeZone = Math.max(90, Math.min(540, maxScroll * 0.42));
      var rawProgress = fadeZone > 0 ? (ui.input.scrollTop / fadeZone) : 0;
      var normalized = Math.max(0, Math.min(1, rawProgress));
      var scrollProgress = normalized * normalized * (3 - (2 * normalized));

      target = overflowBase + ((1 - overflowBase) * scrollProgress);
    }

    state.inputFadeTarget = target;

    if (immediate) {
      if (state.inputFadeFrameId) {
        window.cancelAnimationFrame(state.inputFadeFrameId);
        state.inputFadeFrameId = null;
      }
      state.inputFadeLastTs = null;
      state.inputFadeProgress = target;
      applyInputFadeProgress(state.inputFadeProgress);
      return;
    }

    if (state.inputFadeFrameId) {
      return;
    }

    state.inputFadeLastTs = null;
    state.inputFadeFrameId = window.requestAnimationFrame(animateInputFadeFrame);
  }

  function renderModeVisibility() {
    var showDefectForm = !!state.defectOpen;
    var privacyPending = !state.privacyDismissed;
    if (showDefectForm && state.historyOpen) {
      state.historyOpen = false;
    }
    ui.bubble.hidden = showDefectForm;
    ui.actions.hidden = showDefectForm || privacyPending;
    ui.utilityPill.hidden = showDefectForm || privacyPending;
    ui.defectBtn.hidden = showDefectForm || privacyPending;
    ui.defectPanel.hidden = !showDefectForm;
    ui.root.classList.toggle('kdcb-defect-open', showDefectForm);
  }

  function renderAll() {
    renderMainBubble();
    renderHistoryDrawer();
    renderInputState();
    renderModeVisibility();
  }

  function getLatestAssistantMessage() {
    for (var i = state.messages.length - 1; i >= 0; i -= 1) {
      if (state.messages[i].role === 'assistant') {
        return state.messages[i];
      }
    }
    return null;
  }

  function setWaiting(isWaiting) {
    state.waiting = !!isWaiting;
    renderInputState();
    renderMainBubble();
  }

  function addMessage(role, text, sources) {
    var content = role === 'assistant'
      ? sanitizeAssistantText(text, 2500)
      : sanitizeText(text, 1500);

    if (!content) {
      return;
    }

    var message = {
      role: role === 'assistant' ? 'assistant' : 'user',
      content: content,
    };

    if (message.role === 'assistant') {
      message.sources = normalizeSources(sources || []);
    }

    state.messages.push(message);
    persistMessages();
    renderAll();
  }

  function getChatPayloadMessages() {
    return state.messages
      .map(function (message) {
        return {
          role: message.role,
          content: message.content,
        };
      })
      .slice(-MAX_MESSAGES);
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

  function normalizeInternalNavigationUrl(url) {
    if (typeof url !== 'string' || !url.trim()) {
      return '';
    }

    try {
      var parsed = new URL(url, window.location.origin);
      var protocol = String(parsed.protocol || '').toLowerCase();
      if ((protocol !== 'http:' && protocol !== 'https:') || parsed.origin !== window.location.origin) {
        return '';
      }

      return parsed.href;
    } catch (error) {
      return '';
    }
  }

  function handleServerAction(action) {
    if (!action || typeof action !== 'object') {
      return;
    }

    if (action.type === 'show_defect_form') {
      openDefectOverlay();
      return;
    }

    if (action.type === 'open_history') {
      openPanel();
      if (state.defectOpen) {
        closeDefectOverlay(true);
      }
      state.historyOpen = true;
      renderHistoryDrawer();
      updateInputPlaceholder();
      return;
    }

    if (action.type === 'navigate_internal') {
      var target = normalizeInternalNavigationUrl(action.url || '');
      if (!target) {
        return;
      }

      window.setTimeout(function () {
        window.location.assign(target);
      }, 140);
    }
  }

  async function handleSendMessage() {
    if (state.waiting) {
      return;
    }

    var userText = sanitizeText(ui.input.value, 1500);
    if (!userText) {
      return;
    }

    ui.input.value = '';
    ui.input.style.height = '';
    updateInputOverflowState(true);

    addMessage('user', userText);
    openPanel();
    ui.sendBtn.classList.remove('kdcb-pulse');
    void ui.sendBtn.offsetWidth;
    ui.sendBtn.classList.add('kdcb-pulse');
    setWaiting(true);

    try {
      var payload = {
        session_id: state.sessionId,
        page_url: window.location.href,
        page_title: document.title,
        messages: getChatPayloadMessages(),
      };

      var data = await postChat(payload);
      var reply = sanitizeAssistantText(data && data.reply ? data.reply : '', 2500);

      if (!reply) {
        reply = (cfg.strings && cfg.strings.error) || 'Der Chat ist aktuell nicht erreichbar.';
      }

      addMessage('assistant', reply, Array.isArray(data.sources) ? data.sources : []);
      handleServerAction(data && data.action ? data.action : null);
    } catch (error) {
      addMessage('assistant', (cfg.strings && cfg.strings.error) || 'Der Chat ist aktuell nicht erreichbar.');
    } finally {
      setWaiting(false);
      if (state.panelOpen && !state.defectOpen) {
        ui.input.focus();
      }
    }
  }

  function resetChatState() {
    state.messages = [];

    safeRemoveItem(STORAGE_MESSAGES);
    safeRemoveItem(STORAGE_MESSAGES_LEGACY);
    safeRemoveItem(STORAGE_SESSION);
    safeRemoveItem(STORAGE_SESSION_LEGACY);

    state.sessionId = getOrCreateSessionId();
    state.waiting = false;
    state.historyOpen = false;

    ui.input.value = '';
    ui.input.style.height = '';
    updateInputOverflowState(true);

    if (state.defectOpen) {
      closeDefectOverlay(true);
    }

    renderAll();
  }

  function buildWidgetUI() {
    var root = document.createElement('div');
    root.className = 'kdcb-widget';

    var launcher = document.createElement('button');
    launcher.type = 'button';
    launcher.className = 'kdcb-cherry-launcher';
    launcher.setAttribute('aria-label', (cfg.strings && cfg.strings.toggle_label) || 'K&D Chat');
    launcher.draggable = false;
    launcher.setAttribute('draggable', 'false');

    var cherryImage = document.createElement('img');
    cherryImage.alt = '';
    cherryImage.decoding = 'async';
    cherryImage.loading = 'lazy';
    cherryImage.draggable = false;
    cherryImage.setAttribute('draggable', 'false');
    cherryImage.style.pointerEvents = 'none';
    cherryImage.src = getCherryAsset('idle_url') || '';
    cherryImage.addEventListener('dragstart', function (event) {
      event.preventDefault();
    });

    launcher.appendChild(cherryImage);

    var shell = document.createElement('section');
    shell.className = 'kdcb-shell';


    var bubble = document.createElement('div');
    bubble.className = 'kdcb-main-bubble';

    var historyDrawer = document.createElement('aside');
    historyDrawer.className = 'kdcb-history-drawer';
    historyDrawer.hidden = true;

    var actions = document.createElement('div');
    actions.className = 'kdcb-actions';

    var composer = document.createElement('div');
    composer.className = 'kdcb-composer';

    var inputRow = document.createElement('div');
    inputRow.className = 'kdcb-input-row';

    var input = document.createElement('textarea');
    input.className = 'kdcb-input';
    input.placeholder = (cfg.strings && cfg.strings.placeholder) || 'Ihre Nachricht ...';
    input.maxLength = 1500;
    input.rows = 1;
    input.id = 'kdcb-input';

    var composerLabel = document.createElement('label');
    composerLabel.className = 'kdcb-composer-label';
    composerLabel.setAttribute('for', 'kdcb-input');
    composerLabel.textContent = 'Ihre Nachricht';

    var sendBtn = document.createElement('button');
    sendBtn.type = 'button';
    sendBtn.className = 'kdcb-send';
    sendBtn.setAttribute('aria-label', (cfg.strings && cfg.strings.send) || 'Senden');
    sendBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M12 20V4M5 11l7-7 7 7"/></svg>';

    inputRow.appendChild(input);
    inputRow.appendChild(sendBtn);
    composer.appendChild(composerLabel);
    composer.appendChild(inputRow);

    var utilityPill = document.createElement('div');
    utilityPill.className = 'kdcb-utility-pill';

    var historyToggle = document.createElement('button');
    historyToggle.type = 'button';
    historyToggle.className = 'kdcb-utility-btn kdcb-utility-history';
    historyToggle.setAttribute('aria-label', 'Verlauf anzeigen');
    historyToggle.setAttribute('aria-expanded', 'false');
    historyToggle.innerHTML =
      '<svg viewBox="0 0 24 24" aria-hidden="true">' +
      '<path d="M12 6a1 1 0 0 1 1 1v4.38l2.24 1.3a1 1 0 1 1-1 1.73l-2.74-1.59A1 1 0 0 1 11 12V7a1 1 0 0 1 1-1Zm0-4a10 10 0 1 1-8.96 5.56l.03-.05H1a1 1 0 1 1 0-2h4a1 1 0 0 1 1 1v4a1 1 0 1 1-2 0V8.54A8 8 0 1 0 12 4Z"/></svg>' +
      '<span>Verlauf</span>';

    var resetBtn = document.createElement('button');
    resetBtn.type = 'button';
    resetBtn.className = 'kdcb-utility-btn kdcb-utility-reset';
    resetBtn.setAttribute('aria-label', 'Chat zurücksetzen');
    resetBtn.innerHTML =
      '<svg viewBox="0 0 24 24" aria-hidden="true">' +
      '<path d="M12 5a7 7 0 1 1-6.95 7.89 1 1 0 1 1 1.99-.28A5 5 0 1 0 12 7h-.03l1.83 1.83a1 1 0 0 1-1.41 1.41l-3.54-3.54a1 1 0 0 1 0-1.41l3.54-3.54a1 1 0 0 1 1.41 1.41L11.97 5H12Z"/></svg>' +
      '<span>Reset</span>';

    var defectBtn = document.createElement('button');
    defectBtn.type = 'button';
    defectBtn.className = 'kdcb-defect-shortcut';
    defectBtn.innerHTML =
      '<svg viewBox="0 0 24 24" aria-hidden="true">' +
      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm4 18H6V4h7v5h5v11Z"/></svg>' +
      '<span>' + ((cfg.strings && cfg.strings.open_defect) || 'Mängel melden') + '</span>';

    utilityPill.appendChild(defectBtn);
    utilityPill.appendChild(historyToggle);
    utilityPill.appendChild(resetBtn);

    actions.appendChild(composer);

    var defectPanel = document.createElement('section');
    defectPanel.className = 'kdcb-defect-panel';
    defectPanel.hidden = true;

    var sheetHeader = document.createElement('div');
    sheetHeader.className = 'kdcb-sheet-header';
    sheetHeader.hidden = true;

    var sheetHandle = document.createElement('div');
    sheetHandle.className = 'kdcb-sheet-handle';

    var sheetTitle = document.createElement('span');
    sheetTitle.className = 'kdcb-sheet-title';
    sheetTitle.textContent = (cfg.strings && cfg.strings.sheet_title) || 'K&D Chat';

    var sheetClose = document.createElement('button');
    sheetClose.type = 'button';
    sheetClose.className = 'kdcb-sheet-close';
    sheetClose.setAttribute('aria-label', 'Chat schließen');
    sheetClose.textContent = '×';

    var sheetActions = document.createElement('div');
    sheetActions.className = 'kdcb-sheet-actions';

    var sheetDefectBtn = document.createElement('button');
    sheetDefectBtn.type = 'button';
    sheetDefectBtn.className = 'kdcb-sheet-action-btn';
    sheetDefectBtn.setAttribute('aria-label', (cfg.strings && cfg.strings.open_defect) || 'Mängel melden');
    sheetDefectBtn.innerHTML =
      '<svg viewBox="0 0 24 24" aria-hidden="true">' +
      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm4 18H6V4h7v5h5v11Z"/></svg>' +
      '<span>' + ((cfg.strings && cfg.strings.open_defect) || 'Mängel melden') + '</span>';

    var sheetResetBtn = document.createElement('button');
    sheetResetBtn.type = 'button';
    sheetResetBtn.className = 'kdcb-sheet-action-btn';
    sheetResetBtn.setAttribute('aria-label', 'Chat zurücksetzen');
    sheetResetBtn.innerHTML =
      '<svg viewBox="0 0 24 24" aria-hidden="true">' +
      '<path d="M12 5a7 7 0 1 1-6.95 7.89 1 1 0 1 1 1.99-.28A5 5 0 1 0 12 7h-.03l1.83 1.83a1 1 0 0 1-1.41 1.41l-3.54-3.54a1 1 0 0 1 0-1.41l3.54-3.54a1 1 0 0 1 1.41 1.41L11.97 5H12Z"/></svg>';

    sheetActions.appendChild(sheetDefectBtn);
    sheetActions.appendChild(sheetResetBtn);
    sheetActions.appendChild(sheetClose);

    sheetHeader.appendChild(sheetHandle);
    sheetHeader.appendChild(sheetTitle);
    sheetHeader.appendChild(sheetActions);

    var backdrop = document.createElement('div');
    backdrop.className = 'kdcb-backdrop';
    backdrop.hidden = true;

    shell.appendChild(sheetHeader);
    shell.appendChild(bubble);
    shell.appendChild(historyDrawer);
    shell.appendChild(actions);
    shell.appendChild(utilityPill);
    shell.appendChild(defectPanel);

    root.appendChild(backdrop);
    root.appendChild(shell);
    root.appendChild(launcher);
    document.body.appendChild(root);

    sheetClose.addEventListener('click', function () {
      closePanel();
    });

    sheetDefectBtn.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      openDefectOverlay();
      sendStatusPing('[DEFECT_FORM_OPENED]');
    });

    sheetResetBtn.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      resetChatState();
    });

    backdrop.addEventListener('click', function () {
      closePanel();
    });

    launcher.addEventListener('click', function () {
      if (state.panelOpen) {
        closePanel();
      } else {
        openPanel();
      }
    });

    launcher.addEventListener('mouseenter', function () {
      state.launcherHover = true;
      updateCherryVisualForContext();
    });

    launcher.addEventListener('mouseleave', function () {
      state.launcherHover = false;
      updateCherryVisualForContext();
    });

    launcher.addEventListener('dragstart', function (event) {
      event.preventDefault();
    });

    sendBtn.addEventListener('click', function () {
      handleSendMessage();
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        handleSendMessage();
      }
    });

    input.addEventListener('input', function () {
      input.style.height = 'auto';
      input.style.height = input.scrollHeight + 'px';
      updateInputOverflowState(true);
      renderInputState();
    });

    input.addEventListener('scroll', function () {
      updateInputOverflowState(true);
    });

    defectBtn.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      openPanel();
      openDefectOverlay();
      sendStatusPing('[DEFECT_FORM_OPENED]');
    });

    historyToggle.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      openPanel();
      toggleHistoryDrawer();
    });

    resetBtn.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      openPanel();
      resetChatState();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') {
        return;
      }

      if (state.defectOpen) {
        closeDefectOverlay(true);
        return;
      }

      if (state.panelOpen) {
        closePanel();
      }
    });

    return {
      root: root,
      shell: shell,
      cherryImage: cherryImage,
      bubble: bubble,
      actions: actions,
      inputRow: inputRow,
      input: input,
      sendBtn: sendBtn,
      utilityPill: utilityPill,
      defectBtn: defectBtn,
      historyToggle: historyToggle,
      historyDrawer: historyDrawer,
      defectPanel: defectPanel,
      sheetHeader: sheetHeader,
      backdrop: backdrop,
    };
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

  function openDefectOverlay() {
    if (!state.panelOpen) {
      openPanel();
    }

    state.defectOpen = true;
    renderModeVisibility();

    var existingForm = ui.defectPanel.querySelector('.kdcb-defect-form');
    if (existingForm) {
      setDefectStep(existingForm, 1);
      syncCallbackPhoneState(existingForm);
      updateDefectSubmitState(existingForm);
      var firstExistingField = existingForm.elements.full_name;
      if (firstExistingField && typeof firstExistingField.focus === 'function') {
        firstExistingField.focus();
      }
      return;
    }

    var schema = cfg.defect_schema || {};
    var trades = Array.isArray(schema.trades) && schema.trades.length
      ? schema.trades
      : ['Dach', 'Fenster', 'Sanitär', 'Elektro', 'Fassade', 'Innenausbau', 'Sonstiges'];
    var urgencies = Array.isArray(schema.urgencies) && schema.urgencies.length
      ? schema.urgencies
      : ['niedrig', 'mittel', 'hoch'];

    ui.defectPanel.innerHTML =
      '<div class="kdcb-defect-card" role="dialog" aria-label="Mängelformular">' +
      '<div class="kdcb-defect-head">' +
      '<strong class="kdcb-defect-title">Mängelformular</strong>' +
      '<button class="kdcb-defect-close" type="button" aria-label="Mängelformular schließen">×</button>' +
      '</div>' +
      '<form class="kdcb-defect-form">' +
      '<div class="kdcb-step" data-step="1">' +
      '<p class="kdcb-step-intro">Bitte geben Sie zuerst Ihre Kontaktdaten und die Objektadresse an.</p>' +
      '<div class="kdcb-grid kdcb-grid-step1">' +
      fieldHtml('Vor- und Nachname*', 'full_name', 'text', true, 120) +
      fieldHtml('E-Mail*', 'email', 'email', true, 120) +
      '</div>' +
      fieldHtml('Adresse des Bauvorhabens / Objektadresse*', 'object_address', 'text', true, 220) +
      '<div class="kdcb-form-nav"><button class="kdcb-next" type="button">Weiter</button></div>' +
      '</div>' +
      '<div class="kdcb-step" data-step="2" hidden>' +
      '<p class="kdcb-step-intro">Beschreiben Sie den Mangel möglichst konkret, damit K&D schnell reagieren kann.</p>' +
      '<div class="kdcb-grid kdcb-grid-step2">' +
      selectHtml('Gewerk / Bereich*', 'trade', trades) +
      fieldHtml('Ort des Mangels (Raum/Etage)*', 'defect_location', 'text', true, 120) +
      '</div>' +
      textareaHtml('Beschreibung des Mangels*', 'defect_description', true, 2000) +
      selectHtml('Dringlichkeit*', 'urgency', urgencies) +
      '<div class="kdcb-callback-card">' +
      '<label class="kdcb-checkbox"><input type="checkbox" name="callback_requested" value="1" /><span>Rückruf erwünscht</span></label>' +
      '<p class="kdcb-callback-hint">Wenn Sie einen Rückruf möchten, benötigen wir eine Telefonnummer.</p>' +
      '<div class="kdcb-callback-phone" data-callback-phone hidden>' +
      fieldHtml('Telefonnummer für Rückruf*', 'phone', 'tel', false, 80) +
      '</div>' +
      '</div>' +
      '<div class="kdcb-form-nav"><button class="kdcb-prev" type="button">Zurück</button><button class="kdcb-submit" type="submit">Mängelmeldung senden</button></div>' +
      '</div>' +
      '<div class="kdcb-status" aria-live="polite"></div>' +
      '</form>' +
      '</div>';

    var form = ui.defectPanel.querySelector('.kdcb-defect-form');
    var statusNode = form.querySelector('.kdcb-status');
    var nextBtn = form.querySelector('.kdcb-next');
    var prevBtn = form.querySelector('.kdcb-prev');
    var closeBtn = ui.defectPanel.querySelector('.kdcb-defect-close');

    if (state.defectDraft) {
      applyDefectDraft(form, state.defectDraft);
    }
    setDefectStep(form, 1);

    closeBtn.addEventListener('click', function () {
      closeDefectOverlay(true);
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
      if (event && event.target && event.target.name === 'callback_requested') {
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

    syncCallbackPhoneState(form);
    updateDefectSubmitState(form);

    var firstField = form.elements.full_name;
    if (firstField && typeof firstField.focus === 'function') {
      firstField.focus();
    }
  }

  function closeDefectOverlay(persistDraft) {
    if (!ui || !ui.defectPanel) {
      return;
    }

    var form = ui.defectPanel.querySelector('.kdcb-defect-form');
    if (persistDraft && form) {
      persistDefectDraftFromForm(form);
    }

    state.defectOpen = false;
    renderModeVisibility();

    if (state.panelOpen) {
      ui.input.focus();
    }
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
    return {
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

      if (key === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        return { ok: false, message: 'Bitte geben Sie eine gültige E-Mail ein.' };
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
        closeDefectOverlay(false);
      }, 900);
    } catch (error) {
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

    var pingMessages = getChatPayloadMessages();
    pingMessages.push({ role: 'user', content: message });

    fetch(cfg.chat_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        session_id: state.sessionId,
        page_url: window.location.href,
        page_title: document.title,
        messages: pingMessages.slice(-MAX_MESSAGES),
      }),
      credentials: 'same-origin',
    }).catch(function () {
      // Intentionally swallow ping errors.
    });
  }
})();
