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
    me: null, channels: [], users: [], state: 0, maxId: 0, portalUrl: '',
    current: null, msgs: [], hasMore: false,
    pollTimer: null, pollFouten: 0, versturen: false, gelezenTot: 0, rx: 0,
  };

  const el = {
    kanalen: $('#lijst-kanalen'), dms: $('#lijst-dms'), collegas: $('#lijst-collegas'),
    projecten: $('#lijst-projecten'), sectieProjecten: $('#sectie-projecten'), chatPortal: $('#chat-portal'),
    berichten: $('#berichten'), leeg: $('#leeg'), naam: $('#chat-naam'), topic: $('#chat-topic'),
    schrijver: $('#schrijver'), invoer: $('#invoer'), stuur: $('#knop-stuur'), terug: $('#knop-terug'),
    zoekDialoog: $('#dialoog-zoeken'), zoekInvoer: $('#zoek-invoer'), zoekResultaten: $('#zoek-resultaten'),
    kanaalDialoog: $('#dialoog-kanaal'), kanaalForm: $('#form-kanaal'), kanaalNaam: $('#kanaal-naam'),
    kanaalTopic: $('#kanaal-topic'), kanaalFout: $('#kanaal-fout'),
    bannerIos: $('#banner-ios'),
    optiesKnop: $('#knop-kanaal-opties'), optiesDialoog: $('#dialoog-opties'), optiesTitel: $('#opties-titel'),
    optiesProject: $('#opties-project'), optiesTopic: $('#opties-topic'), optiesLeden: $('#opties-leden'),
    optiesAantal: $('#opties-aantal'), optiesCollega: $('#opties-collega'), optiesFout: $('#opties-fout'),
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

  // Is dit bericht precies één link naar een afbeelding of gif? Dan tonen we
  // het beeld zelf (v0.3) — zo plak je een gif-link (bijv. van Tenor of
  // Giphy) en speelt hij gewoon af in het gesprek.
  function alsAfbeelding(body) {
    const kaal = body.trim();
    if (/\s/.test(kaal)) return null;
    if (!/^https:\/\/[^\s]+\.(gif|png|jpe?g|webp)(\?[^\s]*)?$/i.test(kaal)) return null;
    return kaal;
  }

  // Bestaat een bericht alleen uit een paar emoji? Dan groot tonen (v0.3).
  function isAlleenEmoji(body) {
    const kaal = body.replace(/\s+/g, '');
    if (!kaal || [...kaal].length > 6) return false;
    return /^(?:\p{Extended_Pictographic}|\p{Emoji_Presentation}|[\u{1F3FB}-\u{1F3FF}\u{FE0F}\u{200D}\u{20E3}])+$/u.test(kaal);
  }

  function opmaak(body) {
    const img = alsAfbeelding(body);
    if (img) {
      // Laadt het beeld niet (verwijderd, geblokkeerd)? Toon dan de kale link.
      const url = esc(img);
      return `<a href="${url}" target="_blank" rel="noopener"><img class="bericht-beeld" src="${url}" alt="" loading="lazy" onerror="this.parentElement.textContent = this.src"></a>`;
    }
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

  /* ---------- Emoji-reacties (v0.3) ---------- */

  const EMOJI = ['👍','❤️','😂','😮','🎉','✅','🙏','👀','🔥','💪','😅','😢','🤔','👌','🚀','☕','🍀','😍','🤣','😉','🥳','👏','🫶','💡','⚡','🌟','🧡','😴','🤷','🤞','🍰','🍻','🌞','🌧️','📸','✏️','📌','🏠','🏗️','🗓️'];

  function reactieChips(m) {
    const lijst = m.reactions || [];
    if (!lijst.length && m.deleted) return '';
    const chips = lijst.map((r) => {
      const mijn = r.user_ids.includes(S.me.id);
      const namen = r.user_ids.map((id) => { const u = gebruiker(id); return u ? u.name : 'iemand'; }).join(', ');
      return `<button type="button" class="reactie-chip${mijn ? ' mijn' : ''}" data-toggle-reactie="${esc(r.emoji)}" data-bericht="${m.id}" title="${esc(namen)}">${esc(r.emoji)}<span class="aantal">${r.count}</span></button>`;
    });
    // Het plusje om te reageren; bij een bericht zonder reacties verschijnt
    // het alleen bij aanwijzen (CSS), zodat het gesprek rustig blijft.
    if (!m.deleted) chips.push(`<button type="button" class="reactie-chip plus" data-reageer="${m.id}" title="Reageer met emoji" aria-label="Reageer met emoji">☺<span class="plusje">+</span></button>`);
    if (!chips.length) return '';
    return `<div class="reacties${lijst.length ? '' : ' zonder'}">${chips.join('')}</div>`;
  }

  function zetReacties(berichtId, reactions) {
    const m = S.msgs.find((x) => x.id === berichtId);
    if (!m) return;
    m.reactions = reactions;
    const onderaan = bijnaOnderaan();
    renderBerichten(onderaan);
  }

  async function toggleReactie(berichtId, emoji) {
    const m = S.msgs.find((x) => x.id === berichtId);
    if (!m) return;
    const set = (m.reactions || []).find((r) => r.emoji === emoji);
    const mijn = !!set && set.user_ids.includes(S.me.id);
    // Optimistisch bijwerken; de server is daarna de waarheid.
    if (mijn) {
      set.user_ids = set.user_ids.filter((id) => id !== S.me.id);
      set.count--;
      if (!set.count) m.reactions = m.reactions.filter((r) => r !== set);
    } else if (set) {
      set.user_ids.push(S.me.id); set.count++;
    } else {
      m.reactions = (m.reactions || []).concat([{ emoji, count: 1, user_ids: [S.me.id] }]);
    }
    renderBerichten(bijnaOnderaan());
    try {
      const j = await api('reageer', { message_id: berichtId, emoji, value: mijn ? 0 : 1 });
      zetReacties(berichtId, j.reactions);
      S.rx = Math.max(S.rx, j.rx || 0);
    } catch (e) { toast(e.message); }
  }

  /* ---------- Emoji-picker ---------- */
  // Eén klein paneel voor twee doelen: een reactie op een bericht kiezen, of
  // een emoji in het schrijfveld invoegen. Native emoji, geen plaatjes.

  let pickerDoel = null; // {soort:'reactie', berichtId} of {soort:'invoer'}

  function pickerEl() {
    let p = $('#emoji-picker');
    if (p) return p;
    p = document.createElement('div');
    p.id = 'emoji-picker';
    p.className = 'glas';
    p.hidden = true;
    p.innerHTML = EMOJI.map((e) => `<button type="button" data-emoji="${e}">${e}</button>`).join('');
    document.body.appendChild(p);
    p.addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-emoji]');
      if (!b || !pickerDoel) return;
      const emoji = b.dataset.emoji;
      if (pickerDoel.soort === 'reactie') {
        toggleReactie(pickerDoel.berichtId, emoji);
      } else {
        const inv = el.invoer;
        const p1 = inv.selectionStart ?? inv.value.length;
        inv.value = inv.value.slice(0, p1) + emoji + inv.value.slice(inv.selectionEnd ?? p1);
        inv.selectionStart = inv.selectionEnd = p1 + emoji.length;
        autoGroei();
        if (!isTouch) inv.focus();
      }
      sluitPicker();
    });
    return p;
  }

  function openPicker(doel, bijElement) {
    const p = pickerEl();
    pickerDoel = doel;
    p.hidden = false;
    if (isMobiel()) {
      p.classList.add('onderin');
      p.style.left = p.style.top = p.style.right = '';
    } else {
      p.classList.remove('onderin');
      const r = bijElement.getBoundingClientRect();
      const b = p.getBoundingClientRect();
      p.style.left = Math.max(8, Math.min(r.left, window.innerWidth - b.width - 8)) + 'px';
      p.style.top = (r.top - b.height - 8 > 8 ? r.top - b.height - 8 : r.bottom + 8) + 'px';
    }
  }

  function sluitPicker() {
    const p = $('#emoji-picker');
    if (p) p.hidden = true;
    pickerDoel = null;
  }

  document.addEventListener('click', (e) => {
    if (!e.target.closest('#emoji-picker') && !e.target.closest('[data-reageer]') && !e.target.closest('#knop-emoji')) sluitPicker();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') sluitPicker(); });

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
    S.portalUrl = o.portal ? (o.portal_url || '') : '';
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
    // Projectkanalen (uit de Portal) apart van de losse kanalen.
    const projecten = S.channels.filter((k) => k.type === 'public' && k.project_token);
    el.sectieProjecten.hidden = projecten.length === 0;
    el.projecten.innerHTML = projecten.map((k) => item(k, k.name, '<span class="hash">#</span>')).join('');
    el.kanalen.innerHTML = S.channels.filter((k) => k.type === 'public' && !k.project_token)
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
    if (k.project_token && S.portalUrl) {
      el.chatPortal.href = S.portalUrl + 'project_ipp.php?p=' + encodeURIComponent(k.project_token);
      el.chatPortal.hidden = false;
    } else {
      el.chatPortal.hidden = true;
    }
    el.leeg.hidden = true;
    el.schrijver.hidden = false;
    el.optiesKnop.hidden = k.type !== 'public'; // v0.4: opties alleen bij kanalen, niet bij gesprekken
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
    el.optiesKnop.hidden = true;
    if (el.optiesDialoog.open) el.optiesDialoog.close();
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
      const groot = !m.deleted && isAlleenEmoji(m.body) ? ' alleen-emoji' : '';
      const chips = reactieChips(m);
      if (vervolg) {
        delen.push(`<div class="bericht vervolg" data-id="${m.id}"><div class="avatar"></div><div class="inhoud"><span class="tijd-klein">${tijd(m.created)}</span><div class="tekst${groot}">${tekst}</div>${chips}</div></div>`);
      } else {
        delen.push(`<div class="bericht eerste" data-id="${m.id}">
          <div class="avatar" style="background:${avatarKleur(m.user_id)}">${esc(initiaal(m.author))}</div>
          <div class="inhoud"><div class="bericht-kop"><span class="auteur">${esc(m.author)}</span><span class="tijd">${tijd(m.created)}</span></div><div class="tekst${groot}">${tekst}</div>${chips}</div>
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
      const q = new URLSearchParams({ actie: 'poll', since_id: S.maxId, state: S.state, rx: S.rx, channel_id: S.current ? S.current.id : 0 });
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
        // Reactie-wijzigingen in het open kanaal verwerken (v0.3): de server
        // stuurt de actuele sets van de laatste berichten; lege set = chips weg.
        if (j.reactions && S.current) {
          let anders = false;
          for (const id of j.reactions.ids) {
            const m = S.msgs.find((x) => x.id === id);
            if (!m) continue;
            const nieuw = j.reactions.sets[id] || [];
            if (JSON.stringify(nieuw) !== JSON.stringify(m.reactions || [])) { m.reactions = nieuw; anders = true; }
          }
          if (anders) renderBerichten(bijnaOnderaan());
        }
        S.rx = Math.max(S.rx, j.rx || 0);
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
    const chip = e.target.closest('[data-toggle-reactie]');
    if (chip) { toggleReactie(Number(chip.dataset.bericht), chip.dataset.toggleReactie); return; }
    const plus = e.target.closest('[data-reageer]');
    if (plus) { openPicker({ soort: 'reactie', berichtId: Number(plus.dataset.reageer) }, plus); return; }
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

  $('#knop-emoji').addEventListener('click', (e) => {
    const p = $('#emoji-picker');
    if (p && !p.hidden && pickerDoel && pickerDoel.soort === 'invoer') { sluitPicker(); return; }
    openPicker({ soort: 'invoer' }, e.currentTarget);
  });

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

  /* ---------- Kanaal-opties (v0.4) ---------- */
  // Leden bekijken, collega toevoegen (mag ieder lid), verlaten, en voor de
  // superadmin/aanmaker: losse kanalen sluiten of verwijderen. Projectkanalen
  // volgen de Portal: hun leden zijn de gekoppelde manager en designers.

  let optiesInfo = null;

  function optiesFout(tekst) {
    el.optiesFout.textContent = tekst || '';
    el.optiesFout.hidden = !tekst;
  }

  async function openOpties() {
    const k = S.current;
    if (!k || k.type !== 'public') return;
    optiesFout('');
    el.optiesTitel.textContent = kanaalNaam(k);
    el.optiesTopic.value = k.topic || '';
    el.optiesLeden.innerHTML = '<li class="stil">Laden…</li>';
    el.optiesDialoog.showModal();
    try {
      const j = await api('kanaal_leden', { channel_id: k.id });
      if (S.current !== k) return;
      optiesInfo = j;
      renderOpties();
    } catch (e) { optiesFout(e.message); }
  }

  function renderOpties() {
    const k = S.current, j = optiesInfo;
    if (!k || !j) return;
    el.optiesProject.hidden = !j.is_project;
    el.optiesTopic.disabled = j.is_project; // onderwerp van een projectkanaal komt uit de Portal
    el.optiesAantal.textContent = `(${j.members.length})`;
    const superadmin = S.me && S.me.role === 'superadmin';
    el.optiesLeden.innerHTML = j.members.map((m) => {
      const ik = m.id === S.me.id;
      const label = m.via === 'portal' ? '<span class="lid-via">via Portal</span>'
        : m.via === 'handmatig' ? '<span class="lid-via">toegevoegd</span>' : '';
      const magWeg = !ik && superadmin && m.via === 'handmatig';
      const weg = magWeg ? `<button type="button" class="knop mini" data-lid-weg="${m.id}" title="Verwijderen uit kanaal">✕</button>` : '';
      return `<li><span class="stip${isOnline(gebruiker(m.id) || {}) ? ' online' : ''}"></span><span class="naam">${esc(m.name)}${ik ? ' <span class="stil">(jij)</span>' : ''}</span>${label}${weg}</li>`;
    }).join('') || '<li class="stil">Nog geen leden.</li>';
    // Collega's die nog geen lid zijn
    const lidIds = new Set(j.members.map((m) => m.id));
    const kandidaten = S.users.filter((u) => u.active && !lidIds.has(u.id));
    el.optiesCollega.innerHTML = kandidaten.length
      ? '<option value="">Collega toevoegen…</option>' + kandidaten.map((u) => `<option value="${u.id}">${esc(u.name)}</option>`).join('')
      : '<option value="">Iedereen is al lid</option>';
    $('#opties-toevoegen').disabled = !kandidaten.length;
    // Verlaten kan alleen als je 'handmatig' lid bent (anders kom je terug of hoor je er gewoon bij)
    const mijnLid = j.members.find((m) => m.id === S.me.id);
    $('#opties-verlaten').hidden = !(mijnLid && mijnLid.via === 'handmatig');
    $('#opties-archiveren').hidden = !j.kan_sluiten;
    $('#opties-verwijderen').hidden = !j.kan_wissen;
  }

  el.optiesKnop.addEventListener('click', openOpties);
  $('#opties-sluit').addEventListener('click', () => el.optiesDialoog.close());
  el.optiesDialoog.addEventListener('click', (e) => { if (e.target === el.optiesDialoog) el.optiesDialoog.close(); });

  el.optiesTopic.addEventListener('change', async () => {
    if (!S.current || optiesInfo?.is_project) return;
    try {
      const j = await api('kanaal_topic', { channel_id: S.current.id, topic: el.optiesTopic.value });
      pasOverzichtToe(j);
      el.topic.textContent = S.current.topic || '';
      toast('Onderwerp bijgewerkt');
    } catch (e) { optiesFout(e.message); }
  });

  $('#opties-toevoegen').addEventListener('click', async () => {
    const uid = Number(el.optiesCollega.value);
    if (!uid || !S.current) return;
    const k = S.current;
    try {
      const j = await api('lid_toevoegen', { channel_id: k.id, user_id: uid });
      pasOverzichtToe(j);
      const naam = (gebruiker(uid) || {}).name || 'collega';
      toast(`${naam} toegevoegd aan ${kanaalNaam(k)}`);
      optiesInfo = await api('kanaal_leden', { channel_id: k.id });
      renderOpties();
    } catch (e) { optiesFout(e.message); }
  });

  el.optiesLeden.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-lid-weg]');
    if (!b || !S.current) return;
    const k = S.current;
    try {
      const j = await api('lid_verwijderen', { channel_id: k.id, user_id: Number(b.dataset.lidWeg) });
      pasOverzichtToe(j);
      optiesInfo = await api('kanaal_leden', { channel_id: k.id });
      renderOpties();
    } catch (err) { optiesFout(err.message); }
  });

  $('#opties-verlaten').addEventListener('click', async () => {
    if (!S.current) return;
    const naam = kanaalNaam(S.current);
    try {
      const j = await api('lid_verwijderen', { channel_id: S.current.id });
      el.optiesDialoog.close();
      pasOverzichtToe(j); // je zit er niet meer in → sluitKanaal() via pasOverzichtToe
      toast(`Je hebt ${naam} verlaten`);
    } catch (e) { optiesFout(e.message); }
  });

  $('#opties-archiveren').addEventListener('click', async () => {
    if (!S.current) return;
    const naam = kanaalNaam(S.current);
    if (!confirm(`${naam} sluiten? Het kanaal verdwijnt uit de zijbalk maar blijft doorzoekbaar.`)) return;
    try {
      const j = await api('kanaal_sluiten', { channel_id: S.current.id });
      el.optiesDialoog.close();
      pasOverzichtToe(j);
      toast(`${naam} gesloten`);
    } catch (e) { optiesFout(e.message); }
  });

  $('#opties-verwijderen').addEventListener('click', async () => {
    if (!S.current) return;
    const naam = kanaalNaam(S.current);
    if (!confirm(`${naam} DEFINITIEF verwijderen, inclusief alle berichten? Dit kan niet ongedaan worden gemaakt.`)) return;
    try {
      const j = await api('kanaal_verwijderen', { channel_id: S.current.id });
      el.optiesDialoog.close();
      pasOverzichtToe(j);
      toast(`${naam} verwijderd`);
    } catch (e) { optiesFout(e.message); }
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
    // Standaard #algemeen, anders het eerste losse kanaal, anders het eerste wat er is.
    const start = S.channels.find((c) => c.type === 'public' && c.name === 'algemeen')
      || S.channels.find((c) => c.type === 'public' && !c.project_token)
      || S.channels[0];
    kiesKanaal(start.id);
  }
  planPoll();
})();
