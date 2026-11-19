window.io = function(url) {
  console.log('Socket.io Adapter: Intercepted connection to', url);
  const mockSocket = {
    on: function(event, callback) {
      console.log('Socket.io Adapter: Listening on event', event);
      if (event === 'connect') {
        setTimeout(callback, 100);
      } else {
        const listen = () => {
          if (window.Echo) {
            const echoEvent = event.startsWith('.') ? event : '.' + event;
            window.Echo.channel('qms').listen(echoEvent, callback);
          } else {
            setTimeout(listen, 100);
          }
        };
        listen();
      }
      return mockSocket;
    },
    disconnect: function() { console.log('Socket.io Adapter: Disconnect called'); }
  };
  return mockSocket;
};
window.socket = window.io('global');

setInterval(() => {
  if (!document.body) return;
  if (window.location.pathname.includes('/admin') || window.location.pathname.includes('/teller')) {
    document.body.classList.add('glass-mode');
  } else {
    document.body.classList.remove('glass-mode');
  }
}, 300);

window.openOfficerModal = function(ticketId, tellerId, divisionId, refreshQueueFn, axiosInst, apiUrl) {
  const overlay = document.createElement('div');
  overlay.className = 'officer-modal-overlay';

  let officers = [];

  const modal = document.createElement('div');
  modal.className = 'officer-modal';
  modal.innerHTML = `
    <h2>Serve Customer</h2>
    <p>Who is serving this ticket?</p>
    <label class="officer-manual-label">
      <input type="checkbox" id="manual-toggle" checked> Type name manually
    </label>
    <div id="dropdown-container" class="officer-hidden">
      <select id="officer-select">
        <option value="">Loading personnel...</option>
      </select>
    </div>
    <div id="manual-container" class="officer-block">
      <input type="text" id="officer-input" placeholder="Enter personnel name" />
    </div>
    <button class="primary officer-btn-disabled" id="confirm-serve" disabled>Call Next</button>
    <button class="close-btn" id="cancel-serve">Cancel</button>
  `;
  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  axiosInst.get(`${apiUrl}/officers`).then(res => {
    officers = res.data;
    const select = document.getElementById('officer-select');
    select.textContent = '';
    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = '-- Select Personnel --';
    select.appendChild(defaultOpt);
    officers.forEach(o => {
      const opt = document.createElement('option');
      opt.value = o.name;
      opt.textContent = o.name;
      select.appendChild(opt);
    });
    validateBtn();
  }).catch(() => {
    const select = document.getElementById('officer-select');
    select.textContent = '';
    const errOpt = document.createElement('option');
    errOpt.value = '';
    errOpt.textContent = 'Error loading';
    select.appendChild(errOpt);
  });

  const validateBtn = () => {
    const isManual = document.getElementById('manual-toggle').checked;
    const officerName = isManual
      ? document.getElementById('officer-input').value.trim()
      : document.getElementById('officer-select').value;

    const btn = document.getElementById('confirm-serve');
    if (!officerName) {
      btn.style.opacity = '0.5';
      btn.style.cursor = 'not-allowed';
      btn.disabled = true;
    } else {
      btn.style.opacity = '1';
      btn.style.cursor = 'pointer';
      btn.disabled = false;
    }
  };

  document.getElementById('manual-toggle').onchange = (e) => {
    document.getElementById('dropdown-container').style.display = e.target.checked ? 'none' : 'block';
    document.getElementById('manual-container').style.display = e.target.checked ? 'block' : 'none';
    validateBtn();
  };

  document.getElementById('officer-select').onchange = validateBtn;
  document.getElementById('officer-input').onkeyup = validateBtn;
  setTimeout(validateBtn, 100);

  document.getElementById('cancel-serve').onclick = () => overlay.remove();

  document.getElementById('confirm-serve').onclick = async () => {
    const isManual = document.getElementById('manual-toggle').checked;
    const officerName = isManual
      ? document.getElementById('officer-input').value.trim()
      : document.getElementById('officer-select').value;

    document.getElementById('confirm-serve').innerText = 'Calling...';
    document.getElementById('confirm-serve').disabled = true;

    try {
      await axiosInst.post(`${apiUrl}/ticket/${ticketId}/accept`, {
        teller_id: tellerId,
        served_by: officerName || null
      });
      refreshQueueFn(divisionId);
      overlay.remove();
    } catch (err) {
      alert('Error accepting ticket.');
      overlay.remove();
    }
  };
};

window.openManageOfficersModal = function() {
  const overlay = document.createElement('div');
  overlay.className = 'officer-modal-overlay';

  const modal = document.createElement('div');
  modal.className = 'officer-modal';
  modal.innerHTML = `
    <h2>Manage Personnel</h2>
    <p>Add or remove personnel available for the dropdown.</p>
    <div class="officer-add-row">
      <input type="text" id="new-officer-name" placeholder="New Personnel Name" class="officer-add-input" />
      <button id="add-officer-btn" class="officer-add-btn">Add</button>
    </div>
    <div id="officer-list" class="officer-list-scroll">
      <div class="officer-list-status">Loading...</div>
    </div>
    <button class="close-btn" id="close-manage">Close</button>
  `;
  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  const user = JSON.parse(localStorage.getItem('user'));
  const token = user ? user.token : '';
  const apiUrl = window.location.origin + '/api';

  const loadOfficers = () => {
    fetch(`${apiUrl}/teller/officers`, {
      headers: { 'Authorization': `Bearer ${token}` }
    })
    .then(r => r.json())
    .then(data => {
      const list = document.getElementById('officer-list');
      list.textContent = '';
      if (data.length === 0) {
        const emptyMsg = document.createElement('div');
        emptyMsg.className = 'officer-list-empty';
        emptyMsg.textContent = 'No personnel added.';
        list.appendChild(emptyMsg);
      }
      data.forEach(o => {
        const div = document.createElement('div');
        div.className = 'officer-list-item';
        const span = document.createElement('span');
        span.textContent = o.name;
        const btn = document.createElement('button');
        btn.textContent = 'Delete';
        btn.addEventListener('click', () => window.deleteOfficer(o.id));
        div.append(span, btn);
        list.appendChild(div);
      });
    });
  };

  window.deleteOfficer = (id) => {
    if (!confirm('Delete this personnel?')) return;
    fetch(`${apiUrl}/teller/officers/${id}`, {
      method: 'DELETE',
      headers: { 'Authorization': `Bearer ${token}` }
    }).then(() => loadOfficers());
  };

  document.getElementById('add-officer-btn').onclick = () => {
    const name = document.getElementById('new-officer-name').value.trim();
    if (!name) return;
    document.getElementById('add-officer-btn').innerText = '...';
    fetch(`${apiUrl}/teller/officers`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ name })
    }).then(() => {
      document.getElementById('new-officer-name').value = '';
      document.getElementById('add-officer-btn').innerText = 'Add';
      loadOfficers();
    });
  };

  document.getElementById('close-manage').onclick = () => overlay.remove();

  loadOfficers();
};

window._wasVideoMuted = false;
window._activeFbPlayer = null;
window._isMutedByTTS = false;
window._isMutedByFullScreen = false;

window.fbAsyncInit = function() {
  FB.init({
    xfbml      : false,
    version    : 'v19.0'
  });
  FB.Event.subscribe('xfbml.ready', function(msg) {
    if (msg.type === 'video') {
      window._activeFbPlayer = msg.instance;
      if (window._isMutedByTTS || window._isMutedByFullScreen) {
        setTimeout(() => { if (window._activeFbPlayer) window._activeFbPlayer.mute(); }, 500);
      }
    }
  });
  setTimeout(() => { if (window.FB && window.FB.XFBML) window.FB.XFBML.parse(); }, 500);
};

window.loadFacebookSdk = function() {
  if (window.FB) {
    setTimeout(() => { if (window.FB && window.FB.XFBML) window.FB.XFBML.parse(); }, 500);
    return;
  }
  if (document.getElementById('facebook-jssdk')) return;
  const js = document.createElement('script');
  js.id = 'facebook-jssdk';
  js.async = true;
  js.defer = true;
  js.crossOrigin = 'anonymous';
  js.src = 'https:' + '/\x2fconnect.facebook.net/en_US/sdk.js';
  document.head.appendChild(js);
};

const executeMute = (mute) => {
  try {
    let iframe = document.querySelector('iframe');
    if (iframe && !iframe.src.includes('facebook.com')) {
      iframe.contentWindow.postMessage(JSON.stringify({event:'command',func: mute ? 'mute' : 'unMute',args:[]}), '*');
    }

    if (window._activeFbPlayer) {
      if (mute) window._activeFbPlayer.mute();
      else window._activeFbPlayer.unmute();
    }

    let video = document.querySelector('video');
    if (video) {
      if (mute) {
        window._wasVideoMuted = video.muted;
        video.muted = true;
      } else if (!window._wasVideoMuted) {
        video.muted = false;
      }
    }
  } catch(e) {}
};

window.muteMedia = () => {
  window._isMutedByTTS = true;
  executeMute(true);
};

window.unmuteMedia = () => {
  window._isMutedByTTS = false;
  if (!window._isMutedByFullScreen) {
    executeMute(false);
  }
};

window.muteFullScreen = () => {
  window._isMutedByFullScreen = true;
  executeMute(true);
};

window.unmuteFullScreen = () => {
  window._isMutedByFullScreen = false;
  if (!window._isMutedByTTS) {
    executeMute(false);
  }
};

(function() {
  let observer = null;

  function setupObserver() {
    if (!window.location.pathname.includes('/tv/')) return;

    if (!observer) {
      observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.intersectionRatio < 0.99) {
            entry.target.style.opacity = '0';
          } else {
            entry.target.style.opacity = '1';
          }
        });
      }, {
        root: null,
        threshold: [0, 0.1, 0.5, 0.9, 0.99, 1.0]
      });
    }

    const tickets = document.querySelectorAll('.rounded-xl, .relative.rounded-lg');
    tickets.forEach(t => {
      if (!t.dataset.observed) {
        observer.observe(t);
        t.dataset.observed = 'true';
        t.style.transition = 'opacity 0.2s ease-in-out';
      }
    });
  }

  setInterval(setupObserver, 1000);
})();

window._cachedVoices = [];
window._loadVoices = () => {
  if ('speechSynthesis' in window) {
    window._cachedVoices = window.speechSynthesis.getVoices();
  }
};
window._loadVoices();
if ('speechSynthesis' in window) {
  window.speechSynthesis.onvoiceschanged = window._loadVoices;
}
window.getSystemVoice = () => {
  if (!window._cachedVoices || window._cachedVoices.length === 0) window._loadVoices();
  let defaultVoice = window._cachedVoices.find(v => v.default);
  if (defaultVoice) return defaultVoice;
  const userLang = navigator.language || 'en-US';
  let langVoice = window._cachedVoices.find(v => v.lang === userLang);
  if (langVoice) return langVoice;
  let baseLangVoice = window._cachedVoices.find(v => v.lang && v.lang.startsWith(userLang.slice(0, 2)));
  if (baseLangVoice) return baseLangVoice;
  return null;
};

window.onNativeTtsEnd = () => {
  if (window.unmuteMedia) window.unmuteMedia();
  window.dispatchEvent(new Event('nativeTtsEnd'));
};
window.onNativeTtsError = () => {
  console.error("Native Android TTS Error");
  window.dispatchEvent(new Event('nativeTtsEnd'));
};
