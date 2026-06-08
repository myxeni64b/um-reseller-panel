document.addEventListener('click', function (e) {
  var target = e.target.closest('[data-copy]');
  if (!target) return;
  var text = target.getAttribute('data-copy') || '';
  if (!text) return;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(function () {
      target.setAttribute('data-copied', '1');
      var original = target.textContent;
      target.textContent = 'Copied';
      setTimeout(function () {
        target.textContent = original;
        target.removeAttribute('data-copied');
      }, 1200);
    });
  }
});

(function () {
  function b64ToBytes(b64) {
    var s = atob(b64), out = new Uint8Array(s.length), i;
    for (i = 0; i < s.length; i++) out[i] = s.charCodeAt(i);
    return out;
  }
  function bytesToB64(bytes) {
    var s = '', i;
    for (i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s);
  }
  function textToBytes(str) {
    if (window.TextEncoder) return new TextEncoder().encode(str);
    var utf8 = unescape(encodeURIComponent(str)), out = new Uint8Array(utf8.length), i;
    for (i = 0; i < utf8.length; i++) out[i] = utf8.charCodeAt(i);
    return out;
  }
  function buildPayload(form, submitter) {
    var fd;
    try {
      fd = new FormData(form);
    } catch (e) {
      return null;
    }
    if (submitter && submitter.name) {
      fd.append(submitter.name, submitter.value || '1');
    }
    var data = {}, hasFile = false;
    fd.forEach(function (value, key) {
      if (typeof File !== 'undefined' && value instanceof File && value.name) {
        hasFile = true;
        return;
      }
      if (Object.prototype.hasOwnProperty.call(data, key)) {
        if (!Array.isArray(data[key])) data[key] = [data[key]];
        data[key].push(String(value));
      } else {
        data[key] = String(value);
      }
    });
    if (hasFile) return null;
    return JSON.stringify({ ts: Date.now(), fields: data });
  }
  function encryptPayload(text) {
    if (!window.__PANEL_KEY__ || !window.crypto || !window.crypto.subtle) {
      return Promise.reject(new Error('Shield key unavailable'));
    }
    var iv = new Uint8Array(16);
    window.crypto.getRandomValues(iv);
    return window.crypto.subtle.importKey('raw', b64ToBytes(window.__PANEL_KEY__), { name: 'AES-CBC' }, false, ['encrypt'])
      .then(function (key) {
        return window.crypto.subtle.encrypt({ name: 'AES-CBC', iv: iv }, key, textToBytes(text));
      })
      .then(function (cipherBuf) {
        return {
          iv: bytesToB64(iv),
          payload: bytesToB64(new Uint8Array(cipherBuf))
        };
      });
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    if (!window.__PANEL_SHIELD_ACTIVE__ || !window.__PANEL_SHIELD_FORM__) return;
    var method = (form.getAttribute('method') || 'GET').toUpperCase();
    if (method !== 'POST') return;
    if (form.getAttribute('data-shield-skip') === '1') return;
    if (form.__shieldSubmitting) return;

    var payload = buildPayload(form, e.submitter || null);
    if (!payload) return;

    e.preventDefault();
    form.__shieldSubmitting = true;
    encryptPayload(payload).then(function (box) {
      var proxy = document.createElement('form');
      proxy.method = 'POST';
      proxy.action = form.getAttribute('action') || window.location.href;
      proxy.style.display = 'none';
      if (form.getAttribute('target')) proxy.target = form.getAttribute('target');
      if (form.getAttribute('accept-charset')) proxy.setAttribute('accept-charset', form.getAttribute('accept-charset'));
      [
        ['__shield', '1'],
        ['__shield_iv', box.iv],
        ['__shield_payload', box.payload]
      ].forEach(function (pair) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = pair[0];
        input.value = pair[1];
        proxy.appendChild(input);
      });
      document.body.appendChild(proxy);
      proxy.submit();
    }).catch(function () {
      form.__shieldSubmitting = false;
      HTMLFormElement.prototype.submit.call(form);
    });
  }, true);
})();
