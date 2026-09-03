/*
 * VaCom — de app. Vanilla JS, geen build-stap.
 *
 * Hoe "live" werkt: er is geen open verbinding met de server (kan niet op
 * gedeelde hosting). De app vraagt elke 3 seconden via api.php?actie=poll of
 * er iets nieuws is. Heeft de server niets nieuws, dan is dat antwoord een
 * paar bytes. Met het tabblad op de achtergrond stopt het pollen; bij
 * terugkeren wordt meteen één keer gepolld.
 */
(() => {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const POLL_MS = 3000;
  const ONLINE_MS = 3 * 60 * 1000;

  const S = {
    csrf: window.VACOM.csrf,
    me: null, channels: [], users: [], state: 0, maxId: 0,
    current: null, msgs: [], hasMore: false,
    pollTimer: null, pollFouten: 0, versturen: false, gelezenTot: 0,
  };

  const el = {
    kanalen: $('#lijst-kanalen'), dms: $('#lijst-dms'), collegas: $('#lijst-collegas'),
    berichten: $('#berichten'), leeg: $('#leeg'), naam: $('#chat-naam'), topic: $('#chat-topic'),
    schrijver: $('#schrijver'), invoer: $('#invoer'), stuur: $('#knop-stuur'), terug: $('#knop-terug'),
    zoekDialoog: $('#dialoog-zoeken'), zoekInvoer: $('#zoek-invoer'), zoekResultaten: $('#zoek-resultaten'),
    kanaalDialoog: $('#dialoog-kanaal'), kanaalForm: $('#form-kanaal'), kanaalNaam: $('#kanaal-naam'),
    kanaalTopic: $('#kanaal-topic'), kanaalFout: $('#kanaal-fout'),
    bannerIos: $('#banner-ios'),
  };

  const isMobiel = () => window.matchMedia('(max-width: 800px)').matches;
  const isTouch = window.matchMedia('(pointer: coarse)').matches;

  /* ---------- API ---------- */

  async function api(actie, data = {}) {
    const r = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-VaCom-Csrf': S.csrf },
      body: JSON.stringify({ actie, ...data }),
    });
    if (r.status === 401) { location.href = 'login.php'; throw new Error('Niet ingelogd'); }
    const j = await r.json().catch(() => ({ ok: false, fout: 'Onleesbaar antwoord van de server.' }));
    if (!j.ok) throw new Error(j.fout || 'Er ging iets mis.');
    return j;
  }

  /* ---------- Hulpjes ---------- */

  function esc(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function gebruiker(id) { return S.users.find((u) => u.id === id); }

  function isOnline(u) { return !!u.last_seen && Date.now() - new Date(u.last_seen).getTime() < ONLINE_MS; }

  function kanaalNaam(k) {
    if (!k) return '';
    if (k.type === 'dm') { const u = gebruiker(k.other_id); return u ? u.name : 'Onbekend'; }
    return '#' + k.name;
  }

  function avatarKleur(id) {
    const tinten = [42, 200, 150, 280, 20, 330, 100, 240, 60, 180];
    return `hsl(${tinten[id % tinten.length]} 60% 68%)`;
  }

  function initiaal(naam) { return (naam || '?').trim().charAt(0).toUpperCase(); }

  function tijd(iso) {
    return new Date(iso).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
  }

  function dagLabel(iso) {
    const d = new Date(iso), nu = new Date();
    const zelfdeDag = (a, b) => a.toDateString() === b.toDateString();
    if (zelfdeDag(d, nu)) return 'Vandaag';
    const gisteren = new Date(nu); gisteren.setDate(nu.getDate() - 1);
    if (zelfdeDag(d, gisteren)) return 'Gisteren';
    const opties = { weekday: 'long', day: 'numeric', month: 'long' };
    if (d.getFullYear() !== nu.getFullYear()) opties.year = 'numeric';
    return d.toLocaleDateString('nl-NL', opties);
  }

  function dagSleutel(iso) { return new Date(iso).toDateString(); }

  let mentionRegex = null;
  function bouwMentionRegex() {
    const namen = S.users.map((u) => u.name).sort((a, b) => b.length - a.length)
      .map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    mentionRegex = namen.length ? new RegExp('@(' + namen.join('|') + ')(?![\\p{L}\\p{N}])', 'giu') : null;
  }

  function opmaak(body) {
    let s = esc(body);
    s = s.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)"'\]])/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
    if (mentionRegex) {
      s = s.replace(mentionRegex, (m, naam) => {
        const ik = S.me && naam.toLowerCase() === S.me.name.toLowerCase();
        return `<span class="mention${ik ? ' ik' : ''}">@${naam}</span>`;
      });
    }
    return s;
  }

  let toastTimer = null;
  function toast(tekst) {
    let t = $('.toast');
    if (!t) { t = document.createElement('div'); t.className = 'toast'; document.body.appendChild(t); }
    t.textContent = tekst;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.remove(), 4000);
  }

  /* ---------- Zijbalk ---------- */

  function pasOverzichtToe(o) {
    S.me = o.me; S.channels = o.channels; S.users = o.users; S.state = o.state;
    S.maxId = Math.max(S.maxId, o.max_id);
    if (S.current) {
      const k = S.channels.find((c) => c.id === S.current.id);
      if (k) { k.unread = 0; S.current = k; } else { sluitKanaal(); }
    }
    bouwMentionRegex();
    renderZijbalk();
  }

  function renderZijbalk() {
    const actiefId = S.current ? S.current.id : 0;
    const item = (k, label, extra = '') => {
      const klassen = ['', k.id === actiefId ? 'actief' : '', k.unread ? 'ongelezen' : ''].join(' ').trim();
      const badge = k.unread ? `<span class="badge">${k.unread}</span>` : '';
      return `<li><button type="button" data-kanaal="${k.id}" class="${klassen}">${extra}<span class="naam">${esc(label)}</span>${badge}</button></li>`;
    };
    el.kanalen.innerHTML = S.channels.filter((k) => k.type === 'public')
      .map((k) => item(k, k.name, '<span class="hash">#</span>')).join('');
    el.dms.innerHTML = S.channels.filter((k) => k.type === 'dm')
      .map((k) => {
        const u = gebruiker(k.other_id);
        return item(k, u ? u.name : 'Onbekend', `<span class="stip${u && isOnline(u) ? ' online' : ''}"></span>`);
      }).join('') || '<li class="stil" style="padding:4px 10px;font-size:13px">Nog geen gesprekken</li>';
    el.collegas.innerHTML = S.users.filter((u) => u.active && u.id !== S.me.id)
      .map((u) => `<li><button type="button" data-collega="${u.id}"><span class="stip${isOnline(u) ? ' online' : ''}"></span><span class="naam">${esc(u.name)}</span></button></li>`)
      .join('') || '<li class="stil" style="padding:4px 10px;font-size:13px">Nog geen collega\'s</li>';

    const totaal = S.channels.reduce((n, k) => n + (k.unread || 0), 0);
    document.title = (totaal ? `(${totaal}) ` : '') + 'VaCom';
  }

  /* ---------- Kanaal openen ---------- */

  async function kiesKanaal(id) {
    const k = S.channels.find((c) => c.id === id);
    if (!k) return;
    S.current = k; S.msgs = []; S.hasMore = false; S.gelezenTot = 0;
    document.body.dataset.pane = 'chat';
    el.naam.textContent = kanaalNaam(k);
    el.topic.textContent = k.type === 'dm' ? '' : (k.topic || '');
    el.leeg.hidden = true;
    el.schrijver.hidden = false;
    el.invoer.placeholder = k.type === 'dm' ? `Bericht aan ${kanaalNaam(k)}` : `Bericht in ${kanaalNaam(k)}`;
    el.berichten.innerHTML = '';
    history.replaceState(null, '', '#k=' + id);
    renderZijbalk();
    try {
      const j = await api('berichten', { channel_id: id });
      if (S.current !== k) return;
      S.msgs = j.messages; S.hasMore = j.has_more;
      renderBerichten(true);
      markeerGelezen();
    } catch (e) { toast(e.message); }
    if (!isTouch) el.invoer.focus();
  }

  function sluitKanaal() {
    S.current = null; S.msgs = [];
    document.body.dataset.pane = 'lijst';
    el.naam.textContent = 'Welkom'; el.topic.textContent = '';
    el.leeg.hidden = false; el.schrijver.hidden = true;
    el.berichten.innerHTML = ''; el.berichten.appendChild(el.leeg);
    history.replaceState(null, '', location.pathname);
  }

  async function laadEerder() {
    if (!S.current || !S.msgs.length) return;
    const k = S.current;
    const oudsteId = S.msgs[0].id;
    const knop = $('.knop-meer', el.berichten);
    if (knop) knop.disabled = true;
    try {
      const j = await api('berichten', { channel_id: k.id, before: oudsteId });
      if (S.current !== k) return;
      const hoogteVoor = el.berichten.scrollHeight;
      S.msgs = j.messages.concat(S.msgs); S.hasMore = j.has_more;
      renderBerichten(false);
      el.berichten.scrollTop += el.berichten.scrollHeight - hoogteVoor;
    } catch (e) { toast(e.message); }
  }

  /* ---------- Berichten tekenen ---------- */

  function bijnaOnderaan() {
    const b = el.berichten;
    return b.scrollHeight - b.scrollTop - b.clientHeight < 120;
  }

  function renderBerichten(naarOnder) {
    const delen = [];
    if (S.hasMore) delen.push('<button type="button" class="knop mini knop-meer">Eerdere berichten</button>');
    let vorige = null;
    for (const m of S.msgs) {
      if (!vorige || dagSleutel(vorige.created) !== dagSleutel(m.created)) {
        delen.push(`<div class="datum-scheiding">${esc(dagLabel(m.created))}</div>`);
      }
      const vervolg = vorige && vorige.user_id === m.user_id && dagSleutel(vorige.created) === dagSleutel(m.created)
        && new Date(m.created) - new Date(vorige.created) < 5 * 60 * 1000;
      const tekst = m.deleted ? '<em class="stil">bericht verwijderd</em>' : opmaak(m.body);
      if (vervolg) {
        delen.push(`<div class="bericht vervolg" data-id="${m.id}"><div class="avatar"></div><div class="inhoud"><span class="tijd-klein">${tijd(m.created)}</span><div class="tekst">${tekst}</div></div></div>`);
      } else {
        delen.push(`<div class="bericht eerste" data-id="${m.id}">
          <div class="avatar" style="background:${avatarKleur(m.user_id)}">${esc(initiaal(m.author))}</div>
          <div class="inhoud"><div class="bericht-kop"><span class="auteur">${esc(m.author)}</span><span class="tijd">${tijd(m.created)}</span></div><div class="tekst">${tekst}</div></div>
        </div>`);
      }
      vorige = m;
    }
    if (!S.msgs.length) delen.push(`<div class="leeg"><p>Nog geen berichten in ${esc(kanaalNaam(S.current))}. Schrijf het eerste!</p></div>`);
    el.berichten.innerHTML = delen.join('');
    if (naarOnder) el.berichten.scrollTop = el.berichten.scrollHeight;
  }

  function voegToe(lijst) {
    if (!S.current) return;
    const bekend = new Set(S.msgs.map((m) => m.id));
    const nieuw = lijst.filter((m) => m.channel_id === S.current.id && !bekend.has(m.id));
    if (!nieuw.length) return;
    const onderaan = bijnaOnderaan();
    S.msgs = S.msgs.concat(nieuw).sort((a, b) => a.id - b.id);
    renderBerichten(onderaan || nieuw.some((m) => m.user_id === S.me.id));
  }

  function markeerGelezen() {
    if (!S.current || !S.msgs.length) return;
    const laatste = S.msgs[S.msgs.length - 1].id;
    if (laatste <= S.gelezenTot) return;
    S.gelezenTot = laatste;
    S.current.unread = 0;
    renderZijbalk();
    api('gelezen', { channel_id: S.current.id, last_id: laatste }).catch(() => {});
  }

  /* ---------- Versturen ---------- */

  function autoGroei() {
    el.invoer.style.height = 'auto';
    el.invoer.style.height = Math.min(el.invoer.scrollHeight, 160) + 'px';
  }

  async function stuur() {
    const body = el.invoer.value.trim();
    if (!body || !S.current || S.versturen) return;
    S.versturen = true;
    const k = S.current;
    el.invoer.value = ''; autoGroei();
    try {
      const j = await api('stuur', { channel_id: k.id, body });
      if (S.current === k) { voegToe([j.message]); markeerGelezen(); }
    } catch (e) {
      el.invoer.value = body; autoGroei();
      toast(e.message);
    } finally {
      S.versturen = false;
      if (!isTouch) el.invoer.focus();
    }
  }

  /* ---------- Pollen ---------- */

  function planPoll(ms = POLL_MS) {
    clearTimeout(S.pollTimer);
    if (document.hidden) return;
    S.pollTimer = setTimeout(poll, ms);
  }

  async function poll() {
    if (document.hidden) return;
    try {
      const q = new URLSearchParams({ actie: 'poll', since_id: S.maxId, state: S.state, channel_id: S.current ? S.current.id : 0 });
      const r = await fetch('api.php?' + q, { cache: 'no-store', headers: { Accept: 'application/json' } });
      if (r.status === 401) { location.href = 'login.php'; return; }
      const j = await r.json();
      S.pollFouten = 0;
      if (j.changed) {
        if (j.state !== S.state) pasOverzichtToe(await api('overzicht'));
        if (j.unread) {
          for (const k of S.channels) {
            if (!S.current || k.id !== S.current.id) k.unread = j.unread[k.id] ?? k.unread;
          }
          renderZijbalk();
        }
        if (j.messages && j.messages.length) { voegToe(j.messages); markeerGelezen(); }
        S.maxId = Math.max(S.maxId, j.max_id);
      } else if (S.users.some((u) => u.id !== S.me.id)) {
        // Niets nieuws — wel af en toe de online-stipjes verversen.
        if (Math.random() < 0.1) pasOverzichtToe(await api('overzicht'));
      }
    } catch (e) {
      S.pollFouten++;
    }
    planPoll(Math.min(POLL_MS * (1 + S.pollFouten), 30000));
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) clearTimeout(S.pollTimer); else poll();
  });
  window.addEventListener('online', () => poll());

  /* ---------- Zoeken ---------- */

  let zoekTimer = null;
  async function zoek() {
    const q = el.zoekInvoer.value.trim();
    if (!q) { el.zoekResultaten.innerHTML = ''; return; }
    try {
      const j = await api('zoek', { q });
      if (el.zoekInvoer.value.trim() !== q) return;
      if (!j.results.length) { el.zoekResultaten.innerHTML = '<p class="zoek-leeg">Niets gevonden.</p>'; return; }
      el.zoekResultaten.innerHTML = j.results.map((m) => {
        const k = S.channels.find((c) => c.id === m.channel_id);
        // De server markeert treffers met de stuurtekens U+0001 en U+0002 (komen nooit in tekst voor).
        const snip = esc(m.snippet).replace(/\u0001/g, '<mark>').replace(/\u0002/g, '</mark>');
        const d = new Date(m.created).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' });
        return `<button type="button" class="zoek-hit" data-kanaal="${m.channel_id}"><div class="waar"><b>${esc(m.author)}</b> in ${esc(kanaalNaam(k))} · ${d} ${tijd(m.created)}</div><div>${snip}</div></button>`;
      }).join('');
    } catch (e) { el.zoekResultaten.innerHTML = `<p class="zoek-leeg">${esc(e.message)}</p>`; }
  }

  /* ---------- Gebeurtenissen ---------- */

  document.addEventListener('click', async (e) => {
    const kanaalKnop = e.target.closest('[data-kanaal]');
    if (kanaalKnop) {
      if (el.zoekDialoog.open) el.zoekDialoog.close();
      kiesKanaal(Number(kanaalKnop.dataset.kanaal));
      return;
    }
    const collegaKnop = e.target.closest('[data-collega]');
    if (collegaKnop) {
      try {
        const j = await api('dm', { user_id: Number(collegaKnop.dataset.collega) });
        pasOverzichtToe(j);
        kiesKanaal(j.channel_id);
      } catch (err) { toast(err.message); }
      return;
    }
    if (e.target.closest('.knop-meer')) laadEerder();
  });

  el.terug.addEventListener('click', () => { document.body.dataset.pane = 'lijst'; history.replaceState(null, '', location.pathname); });

  el.schrijver.addEventListener('submit', (e) => { e.preventDefault(); stuur(); });
  el.invoer.addEventListener('input', autoGroei);
  el.invoer.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !isTouch) { e.preventDefault(); stuur(); }
  });

  $('#knop-zoeken').addEventListener('click', () => {
    el.zoekDialoog.showModal();
    el.zoekInvoer.focus(); el.zoekInvoer.select();
  });
  el.zoekInvoer.addEventListener('input', () => { clearTimeout(zoekTimer); zoekTimer = setTimeout(zoek, 250); });
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); $('#knop-zoeken').click(); }
  });

  $('#knop-kanaal-nieuw').addEventListener('click', () => {
    el.kanaalNaam.value = ''; el.kanaalTopic.value = ''; el.kanaalFout.hidden = true;
    el.kanaalDialoog.showModal(); el.kanaalNaam.focus();
  });
  $('#kanaal-annuleer').addEventListener('click', () => el.kanaalDialoog.close());
  el.kanaalForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const j = await api('kanaal_nieuw', { name: el.kanaalNaam.value, topic: el.kanaalTopic.value });
      el.kanaalDialoog.close();
      pasOverzichtToe(j);
      kiesKanaal(j.channel_id);
    } catch (err) { el.kanaalFout.textContent = err.message; el.kanaalFout.hidden = false; }
  });

  $('#knop-uitloggen').addEventListener('click', async () => {
    try { await api('uitloggen'); } catch (e) { /* toch naar login */ }
    location.href = 'login.php';
  });

  /* ---------- iPhone: tip om op het beginscherm te zetten ---------- */

  (() => {
    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const standalone = navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
    let gezien = false;
    try { gezien = localStorage.getItem('vacom_ios_tip') === '1'; } catch (e) { /* privé-modus */ }
    if (isIos && !standalone && !gezien) el.bannerIos.hidden = false;
    $('#banner-ios-sluit').addEventListener('click', () => {
      el.bannerIos.hidden = true;
      try { localStorage.setItem('vacom_ios_tip', '1'); } catch (e) { /* prima */ }
    });
  })();

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }

  /* ---------- Start ---------- */

  pasOverzichtToe(window.VACOM.overzicht);
  const m = location.hash.match(/#k=(\d+)/);
  if (m && S.channels.some((c) => c.id === Number(m[1]))) {
    kiesKanaal(Number(m[1]));
  } else if (!isMobiel() && S.channels.length) {
    kiesKanaal(S.channels[0].id);
  }
  planPoll();
})();
