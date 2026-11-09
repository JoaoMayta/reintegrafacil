(() => {
  'use strict';
  const menu = document.getElementById('principal');
  const toggle = document.querySelector('.menu-toggle');
  const narrow = window.matchMedia('(max-width: 780px)');
  const setMenu = open => {
    menu.hidden = narrow.matches && !open;
    toggle.setAttribute('aria-expanded', String(!menu.hidden));
  };
  const syncMenu = () => {
    toggle.hidden = !narrow.matches;
    setMenu(false);
  };
  syncMenu();
  narrow.addEventListener('change', syncMenu);
  toggle.addEventListener('click', () => setMenu(menu.hidden));
  menu.addEventListener('click', event => {
    if (event.target.closest('a') && narrow.matches) setMenu(false);
  });
  document.addEventListener('click', event => {
    if (!narrow.matches || menu.hidden || menu.contains(event.target) || toggle.contains(event.target)) return;
    const restore = menu.contains(document.activeElement);
    setMenu(false);
    if (restore) toggle.focus({ preventScroll: true });
  });
  document.addEventListener('focusin', event => {
    if (narrow.matches && !menu.hidden && !menu.contains(event.target) && !toggle.contains(event.target)) setMenu(false);
  });
  document.addEventListener('click', event => {
    const link = event.target.closest('a[href^="#"]');
    if (!link || link.hash.length < 2) return;
    const target = document.getElementById(decodeURIComponent(link.hash.slice(1)));
    if (!target) return;
    const focus = target.id === 'conteudo' ? target : target.querySelector('h1,h2') || target;
    focus.tabIndex = -1;
    // A rolagem segue o link nativo; o foco acompanha o começo do conteúdo.
    requestAnimationFrame(() => focus.focus({ preventScroll: true }));
  });
  document.querySelectorAll('a[target="_blank"]').forEach(link => {
    if (!link.querySelector('.new-tab-note')) {
      const note = document.createElement('span');
      note.className = 'sr-only new-tab-note'; note.textContent = ' (abre em outra aba)';
      link.append(note);
    }
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && narrow.matches && !menu.hidden) {
      setMenu(false);
      toggle.focus();
    }
  });

  [['landingFont', 'large-text'], ['landingContrast', 'high-contrast']].forEach(([id, className]) => {
    const button = document.getElementById(id);
    const apply = enabled => {
      document.documentElement.classList.toggle(className, enabled);
      button.setAttribute('aria-pressed', String(enabled));
    };
    try { apply(sessionStorage.getItem(className) === 'true'); } catch (_) {}
    button.addEventListener('click', () => {
      const enabled = !document.documentElement.classList.contains(className);
      apply(enabled);
      try { sessionStorage.setItem(className, String(enabled)); } catch (_) {}
    });
  });

  const localAudio = new Audio(); localAudio.preload = 'none';
  const localClips = new Map(); let localRun = 0;
  fetch('/assets/vozes/manifest.json').then(r => r.json()).then(data => { for (const clip of data.items || []) if (clip.url?.startsWith('/assets/vozes/')) localClips.set(clip.text.replace(/\s+/g,' ').trim(),clip); }).catch(() => {});
  const status = document.getElementById('landingVoiceStatus');
  const stopButton = document.getElementById('landingStop');
  let current = null;
  let activeButton = null;
  let region = null;
  const clear = () => {
    const restore = document.activeElement === stopButton ? activeButton : null;
    if (activeButton) activeButton.setAttribute('aria-pressed', 'false');
    if (region) region.classList.remove('reading-active');
    current = null;
    activeButton = null;
    region = null;
    stopButton.hidden = true;
    if (restore?.isConnected) restore.focus({ preventScroll: true });
  };
  const stop = () => {
    localRun++; localAudio.pause();
    clear();
    if (window.speechSynthesis) window.speechSynthesis.cancel();
  };
  document.querySelectorAll('[data-read]').forEach(button => {
    button.addEventListener('click', () => {
      if (button === activeButton) {
        stop();
        status.textContent = 'Leitura interrompida.';
        return;
      }
      stop();
      const clip = localClips.get(button.dataset.read.replace(/\s+/g,' ').trim());
      if (clip) {
        const token=localRun; activeButton=button; current=clip;
        region=button.closest('[data-reading-region]') || document.querySelector('.hero');
        button.setAttribute('aria-pressed','true');stopButton.hidden=false;
        localAudio.src=clip.url;
        localAudio.onplaying=()=>{if(token!==localRun)return;region?.classList.add('reading-active');status.textContent='Lendo a orientação.';};
        localAudio.onended=()=>{if(token!==localRun)return;clear();status.textContent='Leitura concluída.';};
        localAudio.play().catch(()=>{if(token!==localRun)return;clear();status.textContent='Não foi possível iniciar a voz. Você pode continuar lendo.';});
        return;
      }
      if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) {
        status.textContent = 'A voz não está disponível neste navegador. As orientações estão escritas na página.';
        return;
      }
      const utterance = new SpeechSynthesisUtterance(button.dataset.read);
      utterance.lang = 'pt-BR';
      utterance.rate = .9;
      const voices = window.speechSynthesis.getVoices();
      utterance.voice = voices.find(voice => voice.lang === 'pt-BR') || voices.find(voice => voice.lang.startsWith('pt')) || null;
      current = utterance;
      activeButton = button;
      region = button.closest('[data-reading-region]') || document.querySelector('.hero');
      button.setAttribute('aria-pressed', 'true');
      stopButton.hidden = false;
      utterance.onstart = () => {
        if (current !== utterance) return;
        if (region) region.classList.add('reading-active');
        status.textContent = 'Lendo a orientação. Toque em Ouvir novamente para parar.';
      };
      utterance.onend = () => {
        if (current !== utterance) return;
        clear();
        status.textContent = 'Leitura concluída.';
      };
      utterance.onerror = () => {
        if (current !== utterance) return;
        clear();
        status.textContent = 'Não foi possível iniciar a voz. Você pode continuar pela orientação escrita.';
      };
      try { window.speechSynthesis.speak(utterance); }
      catch (_) { clear(); status.textContent = 'Não foi possível iniciar a voz. Você pode continuar pela orientação escrita.'; }
    });
  });
  stopButton.addEventListener('click', () => {
    stop();
    status.textContent = 'Leitura interrompida.';
  });
  document.addEventListener('click', event => {
    const link=event.target.closest('a[href]');
    if (link && (link.target === '_blank' || new URL(link.href, location.href).origin !== location.origin)) stop();
  });
  window.addEventListener('pagehide', stop);
})();
