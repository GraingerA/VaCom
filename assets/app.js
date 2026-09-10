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
    pollTimer: null, pollFouten: 0, versturen: false, gelezenTot: 0, rx: 0, mx: 0, bewerkId: 0,
    // v0.7.1: openen bij het eerste ongelezen bericht — id van dat bericht (0 = niets
    // ongelezen), wanneer het kanaal geopend werd, en of de gebruiker sindsdien zelf scrolde.
    nieuwVanaf: 0, plakSinds: 0, gescrold: false,
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
    optiesGesloten: $('#opties-gesloten'),
    // v0.4.1: gesloten kanalen (zijbalk-sectie + alleen-lezen-balk boven het gesprek)
    gesloten: $('#lijst-gesloten'), sectieGesloten: $('#sectie-gesloten'), geslotenAantal: $('#gesloten-aantal'),
    geslotenBalk: $('#gesloten-balk'), geslotenTekst: $('#gesloten-tekst'), knopHeropenen: $('#knop-heropenen'),
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
    if (k.type === 'dm') {
      if (S.me && k.other_id === S.me.id) return 'Jijzelf (notities)'; // v0.5: gesprek met jezelf
      const u = gebruiker(k.other_id); return u ? u.name : 'Onbekend';
    }
    return '#' + k.name;
  }
  // v0.5.1: bij een projectkanaal staan onder de kanaalnaam de leden, niet
  // "Project · manager" — zo zie je meteen wie er meeleest.
  async function laadProjectLeden(k) {
    if (!k || !k.project_token) return;
    try {
      const j = await api('kanaal_leden', { channel_id: k.id });
      if (S.current === k) el.topic.textContent = j.members.map((m) => m.name).join(', ');
    } catch (e) { /* dan blijft het onderwerp staan */ }
  }

  // Onder de kanaalnaam: het onderwerp, of bij een gesprek de status van de ander (v0.5).
  function kanaalTopic(k) {
    if (!k) return '';
    if (k.type === 'dm') { const u = gebruiker(k.other_id); return u && u.status ? u.status : ''; }
    return k.topic || '';
  }

  function avatarKleur(id) {
    const tinten = [42, 200, 150, 280, 20, 330, 100, 240, 60, 180];
    return `hsl(${tinten[id % tinten.length]} 60% 68%)`;
  }

  function initiaal(naam) { return (naam || '?').trim().charAt(0).toUpperCase(); }

  // v0.5: avatar-inhoud — de foto als die er is, anders de gekleurde initiaal.
  function avatarInhoud(id, naam) {
    const u = gebruiker(id);
    if (u && u.avatar) return `<img src="avatar.php?u=${id}&v=${u.avatar}" alt="">`;
    return esc(initiaal(naam));
  }
  function avatarStijl(id) {
    const u = gebruiker(id);
    return u && u.avatar ? '' : ` style="background:${avatarKleur(id)}"`;
  }

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
      // v0.8: gif-/beeldlinks (Giphy) zijn niet klikbaar — ze staan al op ware grootte.
      // Alleen geüploade foto's (bijlagen) openen groot in de lichtbak.
      return `<img class="bericht-beeld" src="${url}" alt="" loading="lazy" onerror="this.replaceWith(this.src)">`;
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
    // v0.7.4: het plusje om te reageren zit nu in de zwevende knoppenbalk
    // rechtsboven (berichtActies) — de chips-rij bestaat alleen nog als er
    // écht reacties zijn, zodat aanwijzen niets meer verschuift.
    if (!chips.length) return '';
    return `<div class="reacties">${chips.join('')}</div>`;
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
    if (S.current && S.current.archived) { toast('Dit kanaal is gesloten — alleen lezen.'); return; }
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
  // Eén paneel voor twee doelen: een reactie op een bericht kiezen, of een
  // emoji in het schrijfveld invoegen. Native emoji, geen plaatjes.
  // v0.5.1: met zoekveld, categorieën en "Recent" (uit assets/emoji.js; de
  // korte EMOJI-lijst hierboven is de reserve als dat bestand ontbreekt).

  let pickerDoel = null; // {soort:'reactie', berichtId} of {soort:'invoer'}
  const EMOJI_CATS = Array.isArray(window.VACOM_EMOJI) && window.VACOM_EMOJI.length
    ? window.VACOM_EMOJI
    : [{ naam: 'Emoji', lijst: EMOJI.map((e) => [e, '']) }];

  function recentEmoji() {
    try { const r = JSON.parse(localStorage.getItem('vacom_emoji_recent') || '[]'); return Array.isArray(r) ? r : []; } catch (e) { return []; }
  }
  function onthoudEmoji(e) {
    const r = [e].concat(recentEmoji().filter((x) => x !== e)).slice(0, 16);
    try { localStorage.setItem('vacom_emoji_recent', JSON.stringify(r)); } catch (x) { /* prima */ }
  }

  function pickerKnop(e, woorden) {
    return `<button type="button" data-emoji="${e}" title="${esc(woorden || '')}">${e}</button>`;
  }

  // Het raster vullen: alles per categorie, of alleen de treffers bij een zoekterm.
  function vulPicker(zoek) {
    const raster = $('#emoji-raster');
    const q = (zoek || '').trim().toLowerCase();
    if (q) {
      const treffers = [];
      for (const cat of EMOJI_CATS) for (const [e, w] of cat.lijst) if ((w || '').includes(q) || e === q) treffers.push(pickerKnop(e, w));
      raster.innerHTML = treffers.length ? `<div class="emoji-groep">${treffers.join('')}</div>` : '<p class="emoji-leeg">Niets gevonden.</p>';
      return;
    }
    const delen = [];
    const recent = recentEmoji();
    if (recent.length) delen.push(`<h4>Recent</h4><div class="emoji-groep">${recent.map((e) => pickerKnop(e, '')).join('')}</div>`);
    for (const cat of EMOJI_CATS) delen.push(`<h4>${esc(cat.naam)}</h4><div class="emoji-groep">${cat.lijst.map(([e, w]) => pickerKnop(e, w)).join('')}</div>`);
    raster.innerHTML = delen.join('');
  }

  function pickerEl() {
    let p = $('#emoji-picker');
    if (p) return p;
    p = document.createElement('div');
    p.id = 'emoji-picker';
    p.className = 'glas';
    p.hidden = true;
    p.innerHTML = '<input type="search" id="emoji-zoek" placeholder="Zoek een emoji… (bijv. duim, feest, taart)" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>'
      + '<div id="emoji-raster"></div>';
    document.body.appendChild(p);
    $('#emoji-zoek').addEventListener('input', (ev) => vulPicker(ev.target.value));
    $('#emoji-zoek').addEventListener('keydown', (ev) => {
      // Enter = de eerste treffer kiezen, zonder muis.
      if (ev.key === 'Enter') { const b = $('#emoji-raster [data-emoji]'); if (b) b.click(); ev.preventDefault(); }
    });
    p.addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-emoji]');
      if (!b || !pickerDoel) return;
      const emoji = b.dataset.emoji;
      onthoudEmoji(emoji);
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
    $('#emoji-zoek').value = '';
    vulPicker('');
    $('#emoji-raster').scrollTop = 0;
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
      $('#emoji-zoek').focus();
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
      // Kanaal net gesloten of heropend? Dan blijft het open staan, maar
      // wisselt het van modus (schrijfveld ↔ alleen-lezen-balk).
      if (k) { k.unread = 0; S.current = k; toonKanaalModus(k); el.topic.textContent = kanaalTopic(k); laadProjectLeden(k); } else { sluitKanaal(); }
    }
    bouwMentionRegex();
    renderZijbalk();
    renderKopIk();
    if ($('#dialoog-instellingen').open) renderInstellingen();
    if ($('#dialoog-dm').open) renderDmKeuze();
  }

  // v0.5: wie ben ik, in de header (avatar + naam).
  function renderKopIk() {
    if (!S.me) return;
    const av = $('#kop-avatar');
    av.innerHTML = avatarInhoud(S.me.id, S.me.name);
    av.style.background = (gebruiker(S.me.id) || {}).avatar ? '' : avatarKleur(S.me.id);
    $('#kop-naam').textContent = S.me.name;
  }

  function renderZijbalk() {
    const actiefId = S.current ? S.current.id : 0;
    // v0.7: badge blauw als er ongelezen berichten bij zijn waarin je genoemd wordt.
    const badgeVan = (k) => k.unread
      ? `<span class="badge${k.mentions ? ' vermelding' : ''}"${k.mentions ? ' title="Je wordt hier genoemd"' : ''}>${k.unread}</span>` : '';
    const item = (k, label, extra = '', extraKlas = '') => {
      const klassen = [extraKlas, k.id === actiefId ? 'actief' : '', k.unread ? 'ongelezen' : ''].join(' ').trim();
      return `<li><button type="button" data-kanaal="${k.id}" class="${klassen}">${extra}<span class="naam">${esc(label)}</span>${badgeVan(k)}</button></li>`;
    };
    // Projectkanalen (uit de Portal) apart van de losse kanalen. Gesloten
    // kanalen (v0.4.1) staan in geen van beide, maar onderin bij "Gesloten".
    const open = S.channels.filter((k) => !k.archived);
    const projecten = open.filter((k) => k.type === 'public' && k.project_token);
    // v0.5: de sectie blijft altijd staan (met het +-knopje), ook zonder kanalen.
    el.projecten.innerHTML = projecten.map((k) => item(k, k.name, '<span class="hash">#</span>')).join('')
      || '<li class="stil" style="padding:4px 10px;font-size:13px">Nog geen projectkanalen — start er een met +</li>';
    el.kanalen.innerHTML = open.filter((k) => k.type === 'public' && !k.project_token)
      .map((k) => item(k, k.name, '<span class="hash">#</span>')).join('');
    const gesloten = S.channels.filter((k) => k.archived);
    el.sectieGesloten.hidden = gesloten.length === 0;
    el.geslotenAantal.textContent = gesloten.length ? `(${gesloten.length})` : '';
    el.gesloten.innerHTML = gesloten.map((k) => item(k, k.name, '<span class="hash">#</span>', 'gesloten')).join('');
    // v0.5: status van een collega klein achter de naam.
    const statusHtml = (u) => (u && u.status ? `<span class="status-tekst" title="${esc(u.status)}">${esc(u.status)}</span>` : '');
    // v0.8: mini-avatar met het online-stipje in de hoek, i.p.v. alleen een stipje.
    const miniAvatar = (u, online) => u
      ? `<span class="avatar mini"${avatarStijl(u.id)}>${avatarInhoud(u.id, u.name)}<span class="stip${online ? ' online' : ''}"></span></span>`
      : `<span class="stip${online ? ' online' : ''}"></span>`;
    el.dms.innerHTML = S.channels.filter((k) => k.type === 'dm')
      .map((k) => {
        const u = gebruiker(k.other_id);
        const zelf = k.other_id === S.me.id;
        const naam = `<span class="naam">${esc(kanaalNaam(k))}</span>${zelf ? '' : statusHtml(u)}`;
        const klassen = [k.id === actiefId ? 'actief' : '', k.unread ? 'ongelezen' : ''].join(' ').trim();
        return `<li><button type="button" data-kanaal="${k.id}" class="${klassen}">${miniAvatar(u, zelf || (u && isOnline(u)))}${naam}${badgeVan(k)}</button></li>`;
      }).join('') || '<li class="stil" style="padding:4px 10px;font-size:13px">Nog geen gesprekken — start er een met +</li>';
    // v0.7.4: jezelf bovenaan, met "(jij)" — zo zie je je eigen status zoals
    // collega's hem zien. Klikken opent je notities-gesprek.
    const ik = gebruiker(S.me.id);
    const ikRij = ik ? `<li><button type="button" data-collega="${ik.id}" class="ikzelf">${miniAvatar(ik, true)}<span class="naam">${esc(ik.name)} <span class="stil">(jij)</span></span>${statusHtml(ik)}</button></li>` : '';
    el.collegas.innerHTML = ikRij + (S.users.filter((u) => u.active && u.id !== S.me.id)
      .map((u) => `<li><button type="button" data-collega="${u.id}">${miniAvatar(u, isOnline(u))}<span class="naam">${esc(u.name)}</span>${statusHtml(u)}</button></li>`)
      .join('') || '<li class="stil" style="padding:4px 10px;font-size:13px">Nog geen collega\'s</li>');

    // v0.5: ongelezen-tellers per sectie — zichtbaar in de kop zodra een
    // sectie is ingeklapt (CSS verbergt ze bij een open sectie).
    const somVan = (lijst) => lijst.reduce((n, k) => n + (k.unread || 0), 0);
    const zetBadge = (id, lijst) => {
      const b = $('#' + id); if (!b) return;
      const n = somVan(lijst);
      b.textContent = n; b.hidden = !n;
      b.classList.toggle('vermelding', lijst.some((k) => k.mentions)); // v0.7
    };
    zetBadge('badge-kanalen', open.filter((k) => k.type === 'public' && !k.project_token));
    zetBadge('badge-berichten', S.channels.filter((k) => k.type === 'dm'));
    zetBadge('badge-projecten', projecten);

    // v0.7: teller op de @-knop in de header = ongelezen berichten waarin je genoemd wordt.
    const genoemd = S.channels.reduce((n, k) => n + (k.mentions || 0), 0);
    const teller = $('#vermelding-teller');
    if (teller) { teller.textContent = genoemd; teller.hidden = !genoemd; }

    const totaal = S.channels.reduce((n, k) => n + (k.unread || 0), 0);
    document.title = (totaal ? `(${totaal}) ` : '') + 'VaCom';
  }

  /* ---------- Thema (v0.5) ---------- */
  // Zelfde voorkeur als de Portal: localStorage 'ipp_theme' ('light' of 'dark').
  // v0.6.1: is er nog geen keuze opgeslagen, dan volgen we in Portal-modus de
  // standaard van de Portal (licht sinds Portal v4.9.1) — voorheen viel VaCom
  // dan op donker terug, waardoor collega's die nooit aan het thema-knopje
  // hadden gezeten de Portal licht zagen en de VaCom-popup erin donker. In
  // losse modus blijft donker het standaardthema. (index.php zet dezelfde
  // keuze al vóór het tekenen, zodat de popup niet eerst even donker flitst.)
  function zetThema(licht) {
    document.body.classList.toggle('light-mode', licht);
    const b = $('#knop-thema');
    if (b) { b.textContent = licht ? '☽' : '☀'; b.title = licht ? 'Naar donker' : 'Naar licht'; }
  }
  (() => {
    const standaard = window.VACOM.overzicht && window.VACOM.overzicht.portal ? 'light' : 'dark';
    let licht = standaard === 'light';
    try { licht = (localStorage.getItem('ipp_theme') || standaard) === 'light'; } catch (e) { /* privé-modus */ }
    zetThema(licht);
    $('#knop-thema').addEventListener('click', () => {
      const naarLicht = !document.body.classList.contains('light-mode');
      try { localStorage.setItem('ipp_theme', naarLicht ? 'light' : 'dark'); } catch (e) { /* prima */ }
      zetThema(naarLicht);
    });
  })();

  /* ---------- Kanaal openen ---------- */

  async function kiesKanaal(id) {
    const k = S.channels.find((c) => c.id === id);
    if (!k) return;
    S.current = k; S.msgs = []; S.hasMore = false; S.gelezenTot = 0;
    S.nieuwVanaf = 0; S.gescrold = false;
    document.body.dataset.pane = 'chat';
    el.naam.textContent = kanaalNaam(k);
    el.topic.textContent = kanaalTopic(k);
    if (k.project_token && S.portalUrl) {
      el.chatPortal.href = S.portalUrl + 'project_ipp.php?p=' + encodeURIComponent(k.project_token);
      el.chatPortal.hidden = false;
    } else {
      el.chatPortal.hidden = true;
    }
    el.leeg.hidden = true;
    toonKanaalModus(k);
    el.optiesKnop.hidden = false; // v0.5.1: ook bij gesprekken (uit je lijst halen)
    laadProjectLeden(k); // v0.5.1: bij een projectkanaal staan de leden onder de naam
    // v0.5.1: ingesloten in de Portal — de kanaalkop is dan de kop van de popup:
    // naam, daaronder de leden, rechts "Open in VaCom" en ✕. Geen ⋯.
    if (window.VACOM.embed) {
      el.optiesKnop.hidden = true;
      $('#chat-open-vacom').href = 'index.php?project=' + encodeURIComponent(k.project_token || '') + '#k=' + k.id;
      $('#chat-open-vacom').hidden = false;
      $('#chat-sluit').hidden = false;
    }
    el.invoer.placeholder = k.type === 'dm'
      ? (k.other_id === S.me.id ? 'Notitie voor jezelf' : `Bericht aan ${kanaalNaam(k)}`)
      : `Bericht in ${kanaalNaam(k)}`;
    el.berichten.innerHTML = '';
    history.replaceState(null, '', '#k=' + id);
    renderZijbalk();
    try {
      const j = await api('berichten', { channel_id: id });
      if (S.current !== k) return;
      S.msgs = j.messages; S.hasMore = j.has_more;
      // v0.7.1: waar was je gebleven? Het eerste ongelezen bericht van een ander
      // wordt het startpunt, met een "Nieuwe berichten"-streep erboven (zoals in
      // Slack). Niets ongelezen → gewoon onderaan, bij het nieuwste bericht.
      const gelezenTot = k.last_read_id || 0;
      const eerste = S.msgs.find((m) => m.id > gelezenTot && m.user_id !== S.me.id);
      S.nieuwVanaf = eerste ? eerste.id : 0;
      S.plakSinds = Date.now();
      renderBerichten(true);
      markeerGelezen();
    } catch (e) { toast(e.message); }
    if (!isTouch) el.invoer.focus();
  }

  // Na het tekenen op de juiste plek gaan staan: bij de "Nieuwe berichten"-
  // streep als het kanaal net geopend is en de gebruiker nog niet zelf
  // scrolde, anders onderaan. Foto's en gifs laden ná het tekenen en duwen de
  // inhoud omlaag; daarom doet de load-listener hieronder dit de eerste
  // seconden nog eens opnieuw.
  function plakScroll() {
    const b = el.berichten;
    const versGeopend = Date.now() - S.plakSinds < 4000 && !S.gescrold;
    const nv = versGeopend && S.nieuwVanaf ? b.querySelector('#nieuw-vanaf') : null;
    if (nv) b.scrollTop = nv.getBoundingClientRect().top - b.getBoundingClientRect().top + b.scrollTop - 10;
    else b.scrollTop = b.scrollHeight;
  }
  el.berichten.addEventListener('load', () => { if (Date.now() - S.plakSinds < 4000 && !S.gescrold) plakScroll(); }, true);
  el.berichten.addEventListener('wheel', () => { S.gescrold = true; }, { passive: true });

  /* ---------- Lichtbak (v0.7.3): foto of gif groot in een popup ---------- */
  // Een klik op een beeld in het gesprek opende een nieuw tabblad. Nu: een
  // overlay in de pagina zelf, met de bestandsnaam, "Openen in nieuw tabblad"
  // en Downloaden. Sluiten met ✕, Esc of een klik naast het beeld. De link
  // eronder blijft een gewone link (rechtermuisknop, middelklik werken nog).
  function lichtbak() {
    let l = $('#lichtbak');
    if (l) return l;
    l = document.createElement('div');
    l.id = 'lichtbak';
    l.hidden = true;
    l.innerHTML = `<div class="lichtbak-kop"><span class="lichtbak-naam" id="lichtbak-naam"></span>
        <a class="knop mini" id="lichtbak-nieuw" target="_blank" rel="noopener">Nieuw tabblad ↗</a>
        <a class="knop mini" id="lichtbak-download" download>Downloaden</a>
        <button type="button" class="knop icoon" id="lichtbak-sluit" title="Sluiten" aria-label="Sluiten">✕</button></div>
      <img id="lichtbak-beeld" alt="">`;
    document.body.appendChild(l);
    l.addEventListener('click', (e) => { if (e.target === l || e.target.id === 'lichtbak-sluit') sluitLichtbak(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !l.hidden) sluitLichtbak(); });
    return l;
  }
  function openLichtbak(url, naam) {
    const l = lichtbak();
    $('#lichtbak-beeld').src = url;
    $('#lichtbak-naam').textContent = naam || '';
    $('#lichtbak-nieuw').href = url;
    $('#lichtbak-download').href = url;
    $('#lichtbak-download').setAttribute('download', naam || '');
    l.hidden = false;
    document.body.classList.add('lichtbak-open');
  }
  function sluitLichtbak() {
    const l = $('#lichtbak');
    if (!l) return;
    l.hidden = true;
    $('#lichtbak-beeld').removeAttribute('src');
    document.body.classList.remove('lichtbak-open');
  }
  el.berichten.addEventListener('click', (e) => {
    const a = e.target.closest('a.beeld-link');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return; // ctrl/cmd-klik: gewoon een nieuw tabblad
    e.preventDefault();
    openLichtbak(a.href, a.dataset.naam || a.href.split('/').pop().split('?')[0]);
  });
  el.berichten.addEventListener('touchmove', () => { S.gescrold = true; }, { passive: true });

  // v0.8: zwevend "naar beneden"-knopje. Zichtbaar zodra je meer dan een
  // schermhoogte-achtig stuk boven het einde staat (bv. na het openen van
  // een kanaal met veel nieuwe berichten); klik = soepel naar het laatste bericht.
  const knopOnder = $('#knop-onder');
  function toonKnopOnder() {
    const b = el.berichten;
    knopOnder.hidden = !S.current || !S.msgs.length || b.scrollHeight - b.scrollTop - b.clientHeight < 200;
  }
  el.berichten.addEventListener('scroll', toonKnopOnder, { passive: true });
  knopOnder.addEventListener('click', () => {
    S.gescrold = true;
    el.berichten.scrollTo({ top: el.berichten.scrollHeight, behavior: 'smooth' });
    markeerGelezen();
  });

  // Open kanaal: schrijfveld. Gesloten kanaal (v0.4.1): alleen-lezen-balk met,
  // voor wie dat mag, een Heropenen-knop. Projectkanalen heropen je via de
  // Portal (project uit de prullenbak halen), dus daar geen knop.
  function magHeropenen(k) {
    return !!k.archived && !k.project_token && !!S.me && (S.me.role === 'superadmin' || k.created_by === S.me.id);
  }

  function toonKanaalModus(k) {
    const dicht = !!k.archived;
    el.schrijver.hidden = dicht;
    el.geslotenBalk.hidden = !dicht;
    if (!dicht) return;
    el.geslotenTekst.textContent = k.project_token
      ? 'Dit projectkanaal is gesloten omdat het project in de prullenbak van de Portal staat. Je kunt het alleen nog lezen.'
      : 'Dit kanaal is gesloten — je kunt het alleen nog lezen.';
    el.knopHeropenen.hidden = !magHeropenen(k);
  }

  async function heropenKanaal() {
    const k = S.current;
    if (!k || !k.archived) return;
    const naam = kanaalNaam(k);
    try {
      const j = await api('kanaal_heropenen', { channel_id: k.id });
      if (el.optiesDialoog.open) el.optiesDialoog.close();
      pasOverzichtToe(j);
      toast(`${naam} heropend`);
    } catch (e) { toast(e.message); }
  }

  function sluitKanaal() {
    S.current = null; S.msgs = [];
    el.optiesKnop.hidden = true;
    $('#knop-onder').hidden = true; // v0.8
    el.geslotenBalk.hidden = true;
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
      // v0.7.1: streep boven het eerste bericht dat je nog niet gelezen had.
      if (S.nieuwVanaf && m.id === S.nieuwVanaf) delen.push('<div class="datum-scheiding nieuw" id="nieuw-vanaf">Nieuwe berichten</div>');
      const vervolg = vorige && vorige.user_id === m.user_id && dagSleutel(vorige.created) === dagSleutel(m.created)
        && new Date(m.created) - new Date(vorige.created) < 5 * 60 * 1000;
      const tekst = berichtTekst(m);
      const groot = !m.deleted && !m.attachment && isAlleenEmoji(m.body) ? ' alleen-emoji' : '';
      const chips = reactieChips(m);
      const acties = berichtActies(m);
      const bewerkt = m.edited && !m.deleted ? `<span class="bewerkt" title="Bewerkt ${tijd(m.edited)}">(bewerkt)</span>` : '';
      if (vervolg) {
        delen.push(`<div class="bericht vervolg" data-id="${m.id}"><div class="avatar"></div><div class="inhoud"><span class="tijd-klein">${tijd(m.created)}</span><div class="tekst${groot}">${tekst}</div>${bewerkt ? `<div class="bewerkt-regel">${bewerkt}</div>` : ''}${chips}</div>${acties}</div>`);
      } else {
        delen.push(`<div class="bericht eerste" data-id="${m.id}">
          <div class="avatar"${avatarStijl(m.user_id)}>${avatarInhoud(m.user_id, m.author)}</div>
          <div class="inhoud"><div class="bericht-kop"><span class="auteur">${esc(m.author)}</span><span class="tijd">${tijd(m.created)}</span>${bewerkt}</div><div class="tekst${groot}">${tekst}</div>${chips}</div>${acties}
        </div>`);
      }
      vorige = m;
    }
    if (!S.msgs.length) {
      delen.push(S.current && S.current.archived
        ? `<div class="leeg"><p>Geen berichten in ${esc(kanaalNaam(S.current))}.</p></div>`
        : `<div class="leeg"><p>Nog geen berichten in ${esc(kanaalNaam(S.current))}. Schrijf het eerste!</p></div>`);
    }
    el.berichten.innerHTML = delen.join('');
    if (naarOnder) plakScroll();
    setTimeout(toonKnopOnder, 50); // v0.8: pijltje tonen/verbergen na de nieuwe scrollpositie
  }

  /* ---------- Berichttekst, bijlagen, bewerken/verwijderen (v0.6) ---------- */

  // Tekst van een bericht: verwijderd, in bewerking, of gewoon — plus de bijlage.
  function berichtTekst(m) {
    if (m.deleted) return '<em class="stil">bericht verwijderd</em>';
    if (S.bewerkId === m.id) {
      return `<div class="bewerk-veld"><textarea rows="2" data-bewerk-tekst="${m.id}" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>${esc(m.body)}</textarea>
        <div class="bewerk-knoppen"><button type="button" class="knop mini primair" data-bewerk-opslaan="${m.id}">Opslaan</button><button type="button" class="knop mini" data-bewerk-annuleer="${m.id}">Annuleren</button><span class="stil bewerk-tip">Enter = opslaan · Esc = annuleren</span></div></div>`;
    }
    const a = m.attachment;
    // Een bijlage zonder bijschrift heeft de bestandsnaam als tekst (voor het
    // zoeken); die tonen we dan niet nog een keer als tekst.
    if (m.stemming) return pollHtml(m); // v0.8: de vraag staat in de poll zelf
    const tekst = a && m.body === a.name ? '' : opmaak(m.body);
    return tekst + bijlageHtml(m);
  }

  function bijlageHtml(m) {
    const a = m.attachment;
    if (!a) return '';
    const url = `bestand.php?m=${m.id}`;
    if (a.type === 'image') {
      const maat = a.w && a.h ? ` width="${a.w}" height="${a.h}"` : '';
      return `<a class="bijlage-beeld beeld-link" href="${url}" target="_blank" rel="noopener" title="${esc(a.name)} — groot bekijken" data-naam="${esc(a.name)}"><img class="bericht-beeld" src="${url}${a.thumb ? '&thumb=1' : ''}" alt="${esc(a.name)}" loading="lazy"${maat}></a>`;
    }
    return `<a class="bijlage" href="${url}" target="_blank" rel="noopener" title="Openen of downloaden"><span class="bijlage-icoon">${bijlageIcoon(a)}</span><span class="bijlage-info"><span class="bijlage-naam">${esc(a.name)}</span><span class="bijlage-meta">${grootte(a.size)}</span></span></a>`;
  }

  function bijlageIcoon(a) {
    const ext = (a.name.split('.').pop() || '').toLowerCase();
    if (a.mime === 'application/pdf' || ext === 'pdf') return '📕';
    if (['doc', 'docx', 'odt', 'rtf', 'txt', 'md'].includes(ext)) return '📝';
    if (['xls', 'xlsx', 'csv', 'ods'].includes(ext)) return '📊';
    if (['ppt', 'pptx', 'key'].includes(ext)) return '📽️';
    if (['zip', 'rar', '7z', 'gz'].includes(ext)) return '🗜️';
    if (['mp4', 'mov', 'avi', 'webm', 'm4v'].includes(ext)) return '🎬';
    if (['mp3', 'wav', 'm4a', 'aac'].includes(ext)) return '🎵';
    if (['heic', 'heif', 'tif', 'tiff', 'psd', 'ai', 'svg', 'bmp', 'raw'].includes(ext)) return '🖼️';
    if (['dwg', 'dxf', 'skp', 'rvt', 'ifc', '3dm', 'max', 'blend', 'obj', 'fbx'].includes(ext)) return '📐';
    return '📄';
  }

  function grootte(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return Math.round(b / 1024) + ' kB';
    return (b / 1048576).toFixed(b < 10485760 ? 1 : 0).replace('.', ',') + ' MB';
  }

  /* ---------- Polls (v0.8) ---------- */
  // Een poll is een bericht met m.stemming: {id, multi, closed, options[{id,text,count,user_ids}], my[], total}.
  function pollHtml(m) {
    const p = m.stemming;
    const dicht = p.closed || (S.current && S.current.archived);
    const stemmenTotaal = p.options.reduce((n, o) => n + o.count, 0);
    // Per antwoord: vinkvakje, tekst, aantal, en een dun balkje eronder (v0.8.1).
    const rijen = p.options.map((o) => {
      const pct = stemmenTotaal ? Math.round(o.count / stemmenTotaal * 100) : 0;
      const mijn = p.my.includes(o.id);
      const namen = o.user_ids.map((id) => (gebruiker(id) || {}).name || 'iemand').join(', ');
      return `<button type="button" class="poll-optie${mijn ? ' mijn' : ''}" data-poll-stem="${o.id}" data-stemming="${p.id}"${dicht ? ' disabled' : ''} title="${esc(namen)}">
        <span class="poll-vink" aria-hidden="true">${mijn ? '✓' : ''}</span>
        <span class="poll-tekst">${esc(o.text)}</span>
        <span class="poll-aantal">${o.count}${o.count ? ` · ${pct}%` : ''}</span>
        <span class="poll-spoor"><span class="poll-balk" style="width:${pct}%"></span></span></button>`;
    }).join('');
    const magSluiten = !p.closed && S.me && (m.user_id === S.me.id || S.me.role === 'superadmin') && !(S.current && S.current.archived);
    const info = `${p.total} ${p.total === 1 ? 'stem' : 'stemmen'}${p.multi ? ' · meerkeuze' : ''}${p.closed ? ' · gesloten' : ''}`;
    return `<div class="poll${p.closed ? ' gesloten' : ''}"><div class="poll-vraag">${esc(m.body)}</div><div class="poll-lijst">${rijen}</div>
      <div class="poll-voet"><span class="poll-info">${info}</span><span class="poll-voet-knoppen">${p.total ? `<button type="button" class="knop mini" data-poll-bekijk="${p.id}">Stemmen</button>` : ''}${magSluiten ? `<button type="button" class="knop mini" data-poll-sluit="${p.id}">Sluiten</button>` : ''}</span></div></div>`;
  }

  // "Bekijk stemmen": per antwoord wie erop stemde, en (in een kanaal) wie nog niet.
  async function toonPollUitslag(stemmingId) {
    const m = S.msgs.find((x) => x.stemming && x.stemming.id === stemmingId);
    if (!m) return;
    const p = m.stemming;
    const d = $('#dialoog-poll-uitslag');
    // Stemmer als rond mini-avatarje (naam bij aanwijzen) — compact, geen chips (v0.8.1).
    const kopje = (id, naam) => {
      const u = gebruiker(id);
      const n = naam || (u ? u.name : 'iemand');
      return `<span class="avatar uitslag-kopje"${avatarStijl(id)} title="${esc(n)}">${avatarInhoud(id, n)}</span>`;
    };
    const stemmenTotaal = p.options.reduce((n, o) => n + o.count, 0);
    $('#poll-uitslag-vraag').textContent = m.body;
    $('#poll-uitslag-info').textContent = `${p.total} ${p.total === 1 ? 'stemmer' : 'stemmers'}${p.multi ? ' · meerkeuze' : ''}${p.closed ? ' · gesloten' : ''}`;
    const gestemd = new Set();
    $('#poll-uitslag-lijst').innerHTML = p.options.map((o) => {
      o.user_ids.forEach((id) => gestemd.add(id));
      const pct = stemmenTotaal ? Math.round(o.count / stemmenTotaal * 100) : 0;
      return `<div class="uitslag-optie${o.count ? '' : ' zonder'}">
        <div class="uitslag-regel"><span class="uitslag-naam">${esc(o.text)}</span><span class="uitslag-kopjes">${o.user_ids.map((id) => kopje(id)).join('')}</span><span class="uitslag-aantal">${o.count}${o.count ? ` · ${pct}%` : ''}</span></div>
        <span class="poll-spoor"><span class="poll-balk" style="width:${pct}%"></span></span></div>`;
    }).join('');
    const rest = $('#poll-uitslag-rest');
    rest.hidden = true;
    d.showModal();
    // Wie heeft nog niet gestemd? Alleen zinvol in een kanaal met een ledenlijst.
    if (S.current && S.current.type === 'public') {
      try {
        const j = await api('kanaal_leden', { channel_id: S.current.id });
        const niet = j.members.filter((l) => !gestemd.has(l.id));
        if (niet.length) {
          rest.innerHTML = `<div class="uitslag-regel"><span class="uitslag-naam stil">Nog niet gestemd</span><span class="uitslag-kopjes gedempt">${niet.map((l) => kopje(l.id, l.name)).join('')}</span><span class="uitslag-aantal">${niet.length}</span></div>`;
          rest.hidden = false;
        }
      } catch (e) { /* dan zonder */ }
    }
  }

  async function stemOp(stemmingId, optieId) {
    const m = S.msgs.find((x) => x.stemming && x.stemming.id === stemmingId);
    if (!m) return;
    const p = m.stemming;
    let keuze;
    if (p.multi) keuze = p.my.includes(optieId) ? p.my.filter((id) => id !== optieId) : p.my.concat([optieId]);
    else keuze = p.my.includes(optieId) ? [] : [optieId]; // nog eens klikken = stem intrekken
    try {
      const j = await api('stemming_stem', { stemming_id: stemmingId, option_ids: keuze });
      m.stemming = j.stemming;
      S.mx = Math.max(S.mx, j.mx || 0);
      renderBerichten(false);
    } catch (e) { toast(e.message); }
  }

  async function sluitPoll(stemmingId) {
    const m = S.msgs.find((x) => x.stemming && x.stemming.id === stemmingId);
    if (!m || !confirm('Poll sluiten? Daarna kan niemand meer stemmen.')) return;
    try {
      const j = await api('stemming_sluit', { stemming_id: stemmingId });
      m.stemming = j.stemming;
      S.mx = Math.max(S.mx, j.mx || 0);
      renderBerichten(false);
    } catch (e) { toast(e.message); }
  }

  // Knopjes rechtsboven op een bericht: bewerken (eigen) en verwijderen (eigen, of superadmin).
  // Zwevende knoppenbalk rechtsboven op een bericht (v0.7.4: zweeft óver het
  // bericht, verschuift niets): reageren met emoji (iedereen), bewerken
  // (eigen bericht) en verwijderen (eigen, of superadmin).
  function berichtActies(m) {
    if (m.deleted || !S.me || (S.current && S.current.archived) || S.bewerkId === m.id) return '';
    const eigen = m.user_id === S.me.id;
    const superadmin = S.me.role === 'superadmin';
    const knoppen = [`<button type="button" data-reageer="${m.id}" title="Reageer met emoji" aria-label="Reageer met emoji">☺<span class="plusje">+</span></button>`];
    if (eigen) knoppen.push(`<button type="button" data-bewerk="${m.id}" title="Bewerken" aria-label="Bewerken">✎</button>`);
    if (eigen || superadmin) knoppen.push(`<button type="button" data-wis="${m.id}" title="Verwijderen" aria-label="Verwijderen">🗑</button>`);
    return `<div class="bericht-acties">${knoppen.join('')}</div>`;
  }

  function startBewerken(id) {
    S.bewerkId = id;
    renderBerichten(false);
    const t = $(`[data-bewerk-tekst="${id}"]`);
    if (t) { t.style.height = Math.min(t.scrollHeight + 2, 240) + 'px'; t.focus(); t.setSelectionRange(t.value.length, t.value.length); }
  }

  function stopBewerken() {
    S.bewerkId = 0;
    renderBerichten(false);
  }

  async function slaBewerkingOp(id) {
    const t = $(`[data-bewerk-tekst="${id}"]`);
    const m = S.msgs.find((x) => x.id === id);
    if (!t || !m) return;
    const body = t.value.trim();
    if (body === m.body) { stopBewerken(); return; }
    try {
      const j = await api('bewerk', { message_id: id, body });
      Object.assign(m, j.message);
      S.mx = Math.max(S.mx, j.mx || 0);
      stopBewerken();
    } catch (e) { toast(e.message); }
  }

  async function verwijderBericht(id) {
    const m = S.msgs.find((x) => x.id === id);
    if (!m) return;
    if (!confirm(m.attachment ? 'Dit bericht én het bestand verwijderen?' : 'Dit bericht verwijderen?')) return;
    try {
      const j = await api('verwijder', { message_id: id });
      m.deleted = true; m.body = ''; m.attachment = null; m.reactions = [];
      S.mx = Math.max(S.mx, j.mx || 0);
      renderBerichten(false);
    } catch (e) { toast(e.message); }
  }

  // Wijzigingen van anderen (poll 'updates'): tekst/bijlage/verwijderd bijwerken.
  function verwerkUpdates(lijst) {
    let anders = false;
    for (const u of lijst) {
      const m = S.msgs.find((x) => x.id === u.id);
      if (!m) continue;
      if (m.body !== u.body || m.edited !== u.edited || m.deleted !== u.deleted || JSON.stringify(m.attachment) !== JSON.stringify(u.attachment)
          || JSON.stringify(m.stemming || null) !== JSON.stringify(u.stemming || null)) {
        if (S.bewerkId === m.id && u.deleted) S.bewerkId = 0; // net verwijderd door de superadmin
        Object.assign(m, u);
        if (u.deleted) m.reactions = [];
        anders = true;
      }
    }
    if (anders) renderBerichten(bijnaOnderaan());
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
    S.current.mentions = 0; // v0.7
    S.current.last_read_id = Math.max(S.current.last_read_id || 0, laatste); // v0.7.1
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
      const q = new URLSearchParams({ actie: 'poll', since_id: S.maxId, state: S.state, rx: S.rx, mx: S.mx, channel_id: S.current ? S.current.id : 0 });
      const r = await fetch('api.php?' + q, { cache: 'no-store', headers: { Accept: 'application/json' } });
      if (r.status === 401) { location.href = 'login.php'; return; }
      const j = await r.json();
      S.pollFouten = 0;
      if (j.changed) {
        if (j.state !== S.state) pasOverzichtToe(await api('overzicht'));
        if (j.unread) {
          const genoemdVoor = S.channels.reduce((n, k) => n + (k.mentions || 0), 0);
          const nieuwGenoemd = [];
          for (const k of S.channels) {
            if (!S.current || k.id !== S.current.id) {
              k.unread = j.unread[k.id] ?? k.unread;
              if (j.mentions) { // v0.7
                const was = k.mentions || 0;
                k.mentions = j.mentions[k.id] ?? was;
                if (k.mentions > was) nieuwGenoemd.push(k);
              }
            }
          }
          renderZijbalk();
          // v0.7: net ergens anders genoemd? Even zeggen waar.
          if (nieuwGenoemd.length && S.channels.reduce((n, k) => n + (k.mentions || 0), 0) > genoemdVoor) {
            toast(`Je wordt genoemd in ${nieuwGenoemd.map(kanaalNaam).join(', ')}`);
          }
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
        if (j.updates && S.current) verwerkUpdates(j.updates); // v0.6: bewerkt/verwijderd
        S.rx = Math.max(S.rx, j.rx || 0);
        S.mx = Math.max(S.mx, j.mx || 0);
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
        const dicht = k && k.archived ? ' <span class="tag">gesloten</span>' : '';
        return `<button type="button" class="zoek-hit" data-kanaal="${m.channel_id}"><div class="waar"><b>${esc(m.author)}</b> in ${esc(kanaalNaam(k))}${dicht} · ${d} ${tijd(m.created)}</div><div>${snip}</div></button>`;
      }).join('');
    } catch (e) { el.zoekResultaten.innerHTML = `<p class="zoek-leeg">${esc(e.message)}</p>`; }
  }

  /* ---------- Gebeurtenissen ---------- */

  document.addEventListener('click', async (e) => {
    const chip = e.target.closest('[data-toggle-reactie]');
    if (chip) { toggleReactie(Number(chip.dataset.bericht), chip.dataset.toggleReactie); return; }
    const plus = e.target.closest('[data-reageer]');
    if (plus) { openPicker({ soort: 'reactie', berichtId: Number(plus.dataset.reageer) }, plus); return; }
    // v0.8: stemmen / poll sluiten
    const stem = e.target.closest('[data-poll-stem]');
    if (stem) { if (!stem.disabled) stemOp(Number(stem.dataset.stemming), Number(stem.dataset.pollStem)); return; }
    const pollDicht = e.target.closest('[data-poll-sluit]');
    if (pollDicht) { sluitPoll(Number(pollDicht.dataset.pollSluit)); return; }
    const pollBekijk = e.target.closest('[data-poll-bekijk]');
    if (pollBekijk) { toonPollUitslag(Number(pollBekijk.dataset.pollBekijk)); return; }
    // v0.6: bewerken / verwijderen
    const bewerk = e.target.closest('[data-bewerk]');
    if (bewerk) { startBewerken(Number(bewerk.dataset.bewerk)); return; }
    const wis = e.target.closest('[data-wis]');
    if (wis) { verwijderBericht(Number(wis.dataset.wis)); return; }
    const opslaan = e.target.closest('[data-bewerk-opslaan]');
    if (opslaan) { slaBewerkingOp(Number(opslaan.dataset.bewerkOpslaan)); return; }
    const annuleer = e.target.closest('[data-bewerk-annuleer]');
    if (annuleer) { stopBewerken(); return; }
    const kanaalKnop = e.target.closest('[data-kanaal]');
    if (kanaalKnop) {
      if (el.zoekDialoog.open) el.zoekDialoog.close();
      const vd = $('#dialoog-vermeldingen'); if (vd && vd.open) vd.close(); // v0.7
      kiesKanaal(Number(kanaalKnop.dataset.kanaal));
      return;
    }
    const collegaKnop = e.target.closest('[data-collega]');
    if (collegaKnop) {
      const dmD = $('#dialoog-dm');
      if (dmD.open) dmD.close();
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
  // v0.7.4: het logo brengt je naar de VaCom-"homepage": op de telefoon terug
  // naar de lijst (een kanaal vult daar het hele scherm), op desktop naar
  // boven in de lijst. Geen herlaad nodig.
  const logoLink = $('a.logo');
  if (logoLink) logoLink.addEventListener('click', (e) => {
    e.preventDefault();
    document.body.dataset.pane = 'lijst';
    history.replaceState(null, '', location.pathname);
    const lijsten = $('.zijbalk-lijsten');
    if (lijsten) lijsten.scrollTop = 0;
  });

  $('#knop-emoji').addEventListener('click', (e) => {
    const p = $('#emoji-picker');
    if (p && !p.hidden && pickerDoel && pickerDoel.soort === 'invoer') { sluitPicker(); return; }
    openPicker({ soort: 'invoer' }, e.currentTarget);
  });

  el.schrijver.addEventListener('submit', (e) => { e.preventDefault(); stuur(); });

  /* ---------- GIF zoeken via Giphy (v0.5.3) ---------- */
  // Zelfde soort paneel als de emoji-picker: zoekveld, raster met previews,
  // klik = de gif gaat meteen als bericht het kanaal in (één kale gif-link,
  // die de app al als beeld toont). Alleen zichtbaar als de server een
  // Giphy-sleutel heeft (window.VACOM.giphy).
  const gifKnop = $('#knop-gif');
  let gifTimer = null;

  function gifPaneel() {
    let p = $('#gif-picker');
    if (p) return p;
    p = document.createElement('div');
    p.id = 'gif-picker';
    p.className = 'glas';
    p.hidden = true;
    p.innerHTML = '<input type="search" id="gif-zoek" placeholder="Zoek een gif… (bijv. applaus, koffie, vrijdag)" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>'
      + '<div id="gif-raster"><p class="gif-melding">Laden…</p></div>'
      + '<p class="gif-voet">Powered by GIPHY</p>';
    document.body.appendChild(p);
    $('#gif-zoek').addEventListener('input', () => { clearTimeout(gifTimer); gifTimer = setTimeout(() => zoekGifs($('#gif-zoek').value), 350); });
    $('#gif-zoek').addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); clearTimeout(gifTimer); zoekGifs($('#gif-zoek').value); } });
    p.addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-gif]');
      if (!b) return;
      stuurGif(b.dataset.gif);
      sluitGifPaneel();
    });
    return p;
  }

  async function zoekGifs(q) {
    const raster = $('#gif-raster');
    raster.innerHTML = '<p class="gif-melding">Laden…</p>';
    try {
      const j = await api('gif_zoeken', { q: (q || '').trim() });
      if ($('#gif-zoek').value.trim() !== (q || '').trim()) return; // er is intussen verder getypt
      raster.innerHTML = j.gifs.length
        ? j.gifs.map((g) => `<button type="button" data-gif="${esc(g.url)}" title="${esc(g.titel)}"><img src="${esc(g.preview)}" alt="" loading="lazy" width="${g.w}" height="${g.h}"></button>`).join('')
        : '<p class="gif-melding">Niets gevonden.</p>';
    } catch (e) { raster.innerHTML = `<p class="gif-melding">${esc(e.message)}</p>`; }
  }

  async function stuurGif(url) {
    if (!S.current || S.versturen) return;
    S.versturen = true;
    const k = S.current;
    try {
      const j = await api('stuur', { channel_id: k.id, body: url });
      if (S.current === k) { voegToe([j.message]); markeerGelezen(); }
    } catch (e) { toast(e.message); }
    finally { S.versturen = false; }
  }

  function openGifPaneel() {
    const p = gifPaneel();
    sluitPicker(); // niet twee panelen tegelijk
    $('#gif-zoek').value = '';
    p.hidden = false;
    if (isMobiel()) {
      p.classList.add('onderin');
      p.style.left = p.style.top = p.style.right = p.style.bottom = '';
    } else {
      // Vast aan de ónderkant (vlak boven de knop), niet aan de bovenkant: het
      // paneel is bij openen nog leeg ("Laden…") en groeit zodra de gifs binnen
      // zijn — met een vaste top groeide het over het schrijfveld en het scherm uit.
      p.classList.remove('onderin');
      const r = gifKnop.getBoundingClientRect();
      const b = p.getBoundingClientRect();
      p.style.left = Math.max(8, Math.min(r.left, window.innerWidth - b.width - 8)) + 'px';
      p.style.top = 'auto';
      p.style.bottom = (window.innerHeight - r.top + 8) + 'px';
      $('#gif-zoek').focus();
    }
    zoekGifs('');
  }

  function sluitGifPaneel() {
    const p = $('#gif-picker');
    if (p) p.hidden = true;
  }

  if (window.VACOM.giphy) {
    gifKnop.hidden = false;
    gifKnop.addEventListener('click', () => {
      const p = $('#gif-picker');
      if (p && !p.hidden) { sluitGifPaneel(); return; }
      openGifPaneel();
    });
    document.addEventListener('click', (e) => {
      if (!e.target.closest('#gif-picker') && !e.target.closest('#knop-gif')) sluitGifPaneel();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') sluitGifPaneel(); });
  }
  /* ---------- @-vermeldingen: autocomplete in het schrijfveld (v0.7) ---------- */
  // Typ @ en (een deel van) een naam: er verschijnt een lijstje collega's boven
  // het schrijfveld. Pijltjes + Enter/Tab kiezen, Esc sluit, klikken kan ook.
  // De keuze komt als "@Naam " in het veld — precies de vorm die de server
  // herkent (vacom_vermeldingen_in) en die de client blauw markeert.
  // LET OP: deze keydown-listener staat vóór die van "Enter = versturen"
  // hieronder, zodat Enter met een open lijstje kiest i.p.v. verstuurt.
  let mentionKeuze = null; // { start, lijst, index }

  function mentionPaneel() {
    let p = $('#mention-picker');
    if (p) return p;
    p = document.createElement('div');
    p.id = 'mention-picker'; p.className = 'glas'; p.hidden = true;
    document.body.appendChild(p);
    // mousedown i.p.v. click: zo verliest het schrijfveld de focus niet.
    p.addEventListener('mousedown', (ev) => {
      const b = ev.target.closest('[data-mention-id]');
      if (!b) return;
      ev.preventDefault();
      kiesMention(Number(b.dataset.mentionId));
    });
    return p;
  }

  function sluitMention() { const p = $('#mention-picker'); if (p) p.hidden = true; mentionKeuze = null; }

  // Collega's die passen bij wat er na de @ getypt is: begin van de naam, of
  // begin van een los woord erin ("vr" vindt ook "Jan de Vries"). Max. 8.
  function mentionKandidaten(q) {
    const s = q.trim().toLowerCase();
    return S.users.filter((u) => u.active && (!s
      || u.name.toLowerCase().startsWith(s)
      || u.name.toLowerCase().split(/\s+/).some((w) => w.startsWith(s)))).slice(0, 8);
  }

  function toonMention() {
    const inv = el.invoer;
    if (document.activeElement !== inv || (S.current && S.current.archived)) { sluitMention(); return; }
    const pos = inv.selectionStart ?? inv.value.length;
    const m = inv.value.slice(0, pos).match(/(^|\s)@([^@\n]{0,40})$/u);
    if (!m) { sluitMention(); return; }
    const lijst = mentionKandidaten(m[2]);
    if (!lijst.length) { sluitMention(); return; }
    // Gekozen regel vasthouden als die nog in de (kleinere) lijst staat.
    const vorigeId = mentionKeuze && mentionKeuze.lijst[mentionKeuze.index] ? mentionKeuze.lijst[mentionKeuze.index].id : 0;
    const index = Math.max(0, lijst.findIndex((u) => u.id === vorigeId));
    mentionKeuze = { start: pos - m[2].length - 1, lijst, index };
    renderMention();
  }

  function renderMention() {
    const p = mentionPaneel();
    const k = mentionKeuze;
    if (!k) { p.hidden = true; return; }
    p.innerHTML = k.lijst.map((u, i) => `<button type="button" data-mention-id="${u.id}" class="${i === k.index ? 'actief' : ''}">
        <span class="avatar klein"${avatarStijl(u.id)}>${avatarInhoud(u.id, u.name)}</span>
        <span class="naam">${esc(u.name)}</span>${u.id === S.me.id ? '<span class="stil">jij</span>' : ''}
      </button>`).join('');
    p.hidden = false;
    if (isMobiel()) {
      p.classList.add('onderin');
      p.style.left = p.style.top = p.style.bottom = '';
    } else {
      p.classList.remove('onderin');
      const r = el.schrijver.getBoundingClientRect();
      p.style.left = Math.max(8, Math.min(r.left + 8, window.innerWidth - 288)) + 'px';
      p.style.top = 'auto';
      p.style.bottom = (window.innerHeight - r.top + 6) + 'px';
    }
    const a = p.querySelector('.actief');
    if (a) a.scrollIntoView({ block: 'nearest' });
  }

  function kiesMention(id) {
    const k = mentionKeuze, u = gebruiker(id);
    if (!k || !u) { sluitMention(); return; }
    const inv = el.invoer;
    const pos = inv.selectionStart ?? inv.value.length;
    const invoeg = '@' + u.name + ' ';
    inv.value = inv.value.slice(0, k.start) + invoeg + inv.value.slice(pos);
    inv.selectionStart = inv.selectionEnd = k.start + invoeg.length;
    sluitMention();
    autoGroei();
    inv.focus();
  }

  el.invoer.addEventListener('input', toonMention);
  el.invoer.addEventListener('click', toonMention); // cursor met de muis verplaatst
  el.invoer.addEventListener('blur', () => setTimeout(sluitMention, 150));
  el.invoer.addEventListener('keydown', (e) => {
    const k = mentionKeuze;
    if (!k) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      k.index = (k.index + (e.key === 'ArrowDown' ? 1 : k.lijst.length - 1)) % k.lijst.length;
      renderMention();
    } else if (e.key === 'Enter' || e.key === 'Tab') {
      e.preventDefault(); e.stopImmediatePropagation();
      kiesMention(k.lijst[k.index].id);
    } else if (e.key === 'Escape') {
      e.preventDefault(); e.stopImmediatePropagation();
      sluitMention();
    }
  });

  /* ---------- Vermeldingen-overzicht (v0.7): de @-knop in de header ---------- */
  async function openVermeldingen() {
    const lijst = $('#vermeldingen-lijst');
    const dlg = $('#dialoog-vermeldingen');
    lijst.innerHTML = '<p class="zoek-leeg">Laden…</p>';
    dlg.showModal();
    try {
      const j = await api('vermeldingen');
      if (!j.results.length) { lijst.innerHTML = `<p class="zoek-leeg">Nog niemand heeft je met @${esc(S.me.name)} genoemd.</p>`; return; }
      lijst.innerHTML = j.results.map((m) => {
        const k = S.channels.find((c) => c.id === m.channel_id);
        const d = new Date(m.created).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' });
        const tekst = alsAfbeelding(m.body) ? '🖼️ afbeelding'
          : (m.attachment && m.body === m.attachment.name) ? '📎 ' + esc(m.body)
          : opmaak(m.body);
        return `<button type="button" class="zoek-hit${m.ongelezen ? ' ongelezen' : ''}" data-kanaal="${m.channel_id}"><div class="waar"><b>${esc(m.author)}</b> in ${esc(kanaalNaam(k))}${k && k.archived ? ' <span class="tag">gesloten</span>' : ''} · ${d} ${tijd(m.created)}</div><div class="tekst">${tekst}</div></button>`;
      }).join('');
    } catch (e) { lijst.innerHTML = `<p class="zoek-leeg">${esc(e.message)}</p>`; }
  }
  $('#knop-vermeldingen').addEventListener('click', openVermeldingen);

  el.invoer.addEventListener('input', autoGroei);
  el.invoer.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !isTouch) { e.preventDefault(); stuur(); }
  });

  // Toetsen in het bewerk-veld (v0.6): Enter = opslaan, Esc = annuleren.
  document.addEventListener('keydown', (e) => {
    const t = e.target.closest && e.target.closest('[data-bewerk-tekst]');
    if (!t) return;
    if (e.key === 'Enter' && !e.shiftKey && !isTouch) { e.preventDefault(); slaBewerkingOp(Number(t.dataset.bewerkTekst)); }
    else if (e.key === 'Escape') { e.preventDefault(); stopBewerken(); }
  });

  /* ---------- Bestanden en foto's sturen (v0.6) ---------- */
  // Drie wegen naar hetzelfde: de paperclip-knop, plakken in het schrijfveld
  // (screenshot!) en slepen op het gesprek. Elk bestand wordt een eigen bericht;
  // wat er in het schrijfveld staat gaat als bijschrift mee met het eerste.
  async function uploadBestanden(bestanden) {
    const lijst = [...(bestanden || [])].filter((f) => f && f.size !== undefined);
    if (!lijst.length || !S.current) return;
    if (S.current.archived) { toast('Dit kanaal is gesloten — alleen lezen.'); return; }
    const k = S.current;
    let bijschrift = el.invoer.value.trim();
    if (bijschrift) { el.invoer.value = ''; autoGroei(); }
    for (const f of lijst) {
      if (f.size > 25 * 1024 * 1024) { toast(`${f.name}: te groot (max. 25 MB).`); continue; }
      const fd = new FormData();
      fd.append('actie', 'stuur_bestand');
      fd.append('channel_id', k.id);
      fd.append('body', bijschrift);
      fd.append('bestand', f, f.name || 'bestand');
      bijschrift = '';
      toast(`Uploaden: ${f.name || 'bestand'}…`);
      try {
        const r = await fetch('api.php', { method: 'POST', headers: { 'X-VaCom-Csrf': S.csrf }, body: fd });
        const j = await r.json().catch(() => ({ ok: false, fout: r.status === 413 ? 'Bestand is te groot voor de server.' : 'Onleesbaar antwoord van de server.' }));
        if (!j.ok) throw new Error(j.fout || 'Upload mislukt.');
        if (S.current === k) { voegToe([j.message]); markeerGelezen(); }
        S.maxId = Math.max(S.maxId, j.max_id || 0);
      } catch (e) { toast(e.message); }
    }
    const t = $('.toast'); if (t && /^Uploaden:/.test(t.textContent)) t.remove();
  }

  /* ---------- Poll plaatsen (v0.8) ---------- */
  const pollDialoog = $('#dialoog-poll'), pollOpties = $('#poll-opties'), pollFout = $('#poll-fout');
  function pollOptieVeld(n) {
    const d = document.createElement('div');
    d.className = 'poll-optie-veld';
    d.innerHTML = `<input type="text" maxlength="120" placeholder="Antwoord ${n}" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore><button type="button" class="knop mini" data-optie-weg title="Weghalen">✕</button>`;
    return d;
  }
  function pollReset() {
    $('#poll-vraag').value = '';
    $('#poll-multi').checked = false;
    pollFout.hidden = true;
    pollOpties.innerHTML = '';
    pollOpties.appendChild(pollOptieVeld(1));
    pollOpties.appendChild(pollOptieVeld(2));
  }
  $('#knop-poll').addEventListener('click', () => {
    if (!S.current) return;
    if (S.current.archived) { toast('Dit kanaal is gesloten — alleen lezen.'); return; }
    pollReset();
    pollDialoog.showModal();
    if (!isTouch) $('#poll-vraag').focus();
  });
  $('#poll-optie-plus').addEventListener('click', () => {
    const n = pollOpties.children.length;
    if (n >= 10) { toast('Maximaal tien antwoorden.'); return; }
    const veld = pollOptieVeld(n + 1);
    pollOpties.appendChild(veld);
    veld.querySelector('input').focus();
  });
  pollOpties.addEventListener('click', (e) => {
    const weg = e.target.closest('[data-optie-weg]');
    if (weg && pollOpties.children.length > 2) weg.parentElement.remove();
  });
  // Enter in een antwoordveld springt naar het volgende (of maakt er een bij), i.p.v. plaatsen.
  pollOpties.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const velden = [...pollOpties.querySelectorAll('input')];
    const i = velden.indexOf(e.target);
    if (i < velden.length - 1) velden[i + 1].focus();
    else if (velden.length < 10 && e.target.value.trim()) $('#poll-optie-plus').click();
  });
  $('#poll-annuleer').addEventListener('click', () => pollDialoog.close());
  $('#poll-uitslag-sluit').addEventListener('click', () => $('#dialoog-poll-uitslag').close());
  $('#dialoog-poll-uitslag').addEventListener('click', (e) => { if (e.target === e.currentTarget) e.currentTarget.close(); });
  pollDialoog.addEventListener('click', (e) => { if (e.target === pollDialoog) pollDialoog.close(); });
  $('#form-poll').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!S.current) return;
    const k = S.current;
    const options = [...pollOpties.querySelectorAll('input')].map((i) => i.value.trim()).filter(Boolean);
    if (options.length < 2) { pollFout.textContent = 'Geef minstens twee antwoorden.'; pollFout.hidden = false; return; }
    $('#poll-plaatsen').disabled = true;
    try {
      const j = await api('stemming_maak', { channel_id: k.id, question: $('#poll-vraag').value.trim(), options, multi: $('#poll-multi').checked ? 1 : 0 });
      pollDialoog.close();
      if (S.current === k) { voegToe([j.message]); markeerGelezen(); }
      S.maxId = Math.max(S.maxId, j.max_id || 0);
    } catch (err) { pollFout.textContent = err.message; pollFout.hidden = false; }
    finally { $('#poll-plaatsen').disabled = false; }
  });

  $('#knop-bestand').addEventListener('click', () => $('#bestand-invoer').click());
  $('#bestand-invoer').addEventListener('change', (e) => { uploadBestanden(e.target.files); e.target.value = ''; });
  el.invoer.addEventListener('paste', (e) => {
    const files = [...((e.clipboardData && e.clipboardData.files) || [])];
    if (files.length) { e.preventDefault(); uploadBestanden(files); }
  });
  // Slepen op de gesprekskaart
  const sleepDoel = $('#chat');
  let sleepTeller = 0;
  sleepDoel.addEventListener('dragenter', (e) => { if (e.dataTransfer && [...e.dataTransfer.types].includes('Files')) { sleepTeller++; sleepDoel.classList.add('sleep'); } });
  sleepDoel.addEventListener('dragover', (e) => { if (e.dataTransfer && [...e.dataTransfer.types].includes('Files')) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; } });
  sleepDoel.addEventListener('dragleave', () => { if (--sleepTeller <= 0) { sleepTeller = 0; sleepDoel.classList.remove('sleep'); } });
  sleepDoel.addEventListener('drop', (e) => {
    sleepTeller = 0; sleepDoel.classList.remove('sleep');
    if (e.dataTransfer && e.dataTransfer.files.length) { e.preventDefault(); uploadBestanden(e.dataTransfer.files); }
  });

  $('#knop-zoeken').addEventListener('click', () => {
    el.zoekDialoog.showModal();
    el.zoekInvoer.focus(); el.zoekInvoer.select();
  });
  el.zoekInvoer.addEventListener('input', () => { clearTimeout(zoekTimer); zoekTimer = setTimeout(zoek, 250); });
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); $('#knop-zoeken').click(); }
  });

  $('#kanaal-annuleer').addEventListener('click', () => el.kanaalDialoog.close());

  /* ---------- Inklapbare secties (v0.5) ---------- */
  // De stand van elke sectie (open/dicht) wordt per browser onthouden. Een
  // klik op het +-knopje in de kop mag de sectie niet toggelen.
  (() => {
    let stand = {};
    try { stand = JSON.parse(localStorage.getItem('vacom_secties') || '{}') || {}; } catch (e) { /* privé-modus */ }
    document.querySelectorAll('details.sectie').forEach((d) => {
      const naam = d.dataset.sectie;
      if (naam in stand) d.open = !!stand[naam];
      d.addEventListener('toggle', () => {
        stand[naam] = d.open;
        try { localStorage.setItem('vacom_secties', JSON.stringify(stand)); } catch (e) { /* prima */ }
      });
      d.querySelectorAll('summary .knop').forEach((b) => {
        b.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); b.dispatchEvent(new CustomEvent('actie')); });
      });
    });
  })();
  // De +-knopjes zitten in een <summary>; hun echte werk hangt aan het 'actie'-event.
  $('#knop-kanaal-nieuw').addEventListener('actie', () => {
    el.kanaalNaam.value = ''; el.kanaalTopic.value = ''; el.kanaalFout.hidden = true;
    el.kanaalDialoog.showModal(); el.kanaalNaam.focus();
  });

  /* ---------- Projectkanaal starten (v0.5) ---------- */
  const projectDialoog = $('#dialoog-project'), projectKeuze = $('#project-keuze'), projectFout = $('#project-fout');

  $('#knop-project-nieuw').addEventListener('actie', async () => {
    projectFout.hidden = true;
    projectKeuze.innerHTML = '<option value="">Laden…</option>';
    $('#project-aanmaken').disabled = true;
    projectDialoog.showModal();
    try {
      const j = await api('projecten_beschikbaar');
      if (!j.projects.length) {
        projectKeuze.innerHTML = '<option value="">Geen projecten zonder kanaal</option>';
        projectFout.textContent = 'Alle projecten waar je aan gekoppeld bent hebben al een kanaal — of je bent (nog) nergens aan gekoppeld in de Portal.';
        projectFout.hidden = false;
        return;
      }
      projectKeuze.innerHTML = '<option value="">Kies een project…</option>'
        + j.projects.map((p) => `<option value="${esc(p.token)}">${esc(p.name)}${p.manager ? ' · ' + esc(p.manager) : ''}</option>`).join('');
      $('#project-aanmaken').disabled = false;
    } catch (e) { projectFout.textContent = e.message; projectFout.hidden = false; }
  });
  $('#project-annuleer').addEventListener('click', () => projectDialoog.close());
  $('#form-project').addEventListener('submit', async (e) => {
    e.preventDefault();
    const token = projectKeuze.value;
    if (!token) return;
    try {
      const j = await api('projectkanaal_aanmaken', { token });
      projectDialoog.close();
      pasOverzichtToe(j);
      kiesKanaal(j.channel_id);
      toast('Projectkanaal gestart');
    } catch (err) { projectFout.textContent = err.message; projectFout.hidden = false; }
  });
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
    if (!k) return;
    optiesFout('');
    el.optiesTitel.textContent = kanaalNaam(k);
    // v0.5.1: bij een gesprek (DM) is er maar één optie — uit je lijst halen.
    // De CSS-klasse 'dm' verbergt de rest (onderwerp, leden, kanaalknoppen).
    el.optiesDialoog.classList.toggle('dm', k.type === 'dm');
    if (k.type === 'dm') { el.optiesDialoog.showModal(); return; }
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
    const dicht = !!j.archived; // v0.4.1: gesloten kanaal = alleen lezen, leden ongewijzigd
    el.optiesProject.hidden = !j.is_project;
    el.optiesGesloten.hidden = !dicht;
    el.optiesTopic.disabled = j.is_project || dicht; // onderwerp van een projectkanaal komt uit de Portal
    el.optiesAantal.textContent = `(${j.members.length})`;
    const superadmin = S.me && S.me.role === 'superadmin';
    el.optiesLeden.innerHTML = j.members.map((m) => {
      const ik = m.id === S.me.id;
      const label = m.via === 'portal' ? '<span class="lid-via">via Portal</span>'
        : m.via === 'handmatig' ? '<span class="lid-via">toegevoegd</span>' : '';
      const magWeg = !ik && superadmin && m.via === 'handmatig' && !dicht;
      const weg = magWeg ? `<button type="button" class="knop mini" data-lid-weg="${m.id}" title="Verwijderen uit kanaal">✕</button>` : '';
      return `<li><span class="stip${isOnline(gebruiker(m.id) || {}) ? ' online' : ''}"></span><span class="naam">${esc(m.name)}${ik ? ' <span class="stil">(jij)</span>' : ''}</span>${label}${weg}</li>`;
    }).join('') || '<li class="stil">Nog geen leden.</li>';
    // Collega's die nog geen lid zijn
    const lidIds = new Set(j.members.map((m) => m.id));
    const kandidaten = S.users.filter((u) => u.active && !lidIds.has(u.id));
    el.optiesCollega.innerHTML = dicht ? '<option value="">Kanaal is gesloten</option>'
      : kandidaten.length
        ? '<option value="">Collega toevoegen…</option>' + kandidaten.map((u) => `<option value="${u.id}">${esc(u.name)}</option>`).join('')
        : '<option value="">Iedereen is al lid</option>';
    el.optiesCollega.disabled = dicht;
    $('#opties-toevoegen').disabled = dicht || !kandidaten.length;
    // Verlaten kan alleen als je 'handmatig' lid bent (anders kom je terug of hoor je er gewoon bij)
    const mijnLid = j.members.find((m) => m.id === S.me.id);
    // v0.5.1: een projectkanaal verlaat je niet (je hoort erbij zolang je gekoppeld bent).
    $('#opties-verlaten').hidden = dicht || j.is_project || !(mijnLid && mijnLid.via === 'handmatig');
    $('#opties-archiveren').hidden = !j.kan_sluiten;
    $('#opties-heropenen').hidden = !j.kan_heropenen;
    $('#opties-verwijderen').hidden = !j.kan_wissen;
  }

  el.optiesKnop.addEventListener('click', openOpties);
  // v0.5.1: ✕ in de ingesloten kop → de Portal sluit de popup (zelfde origin).
  $('#chat-sluit').addEventListener('click', () => { try { window.parent.postMessage({ vacom: 'sluit' }, location.origin); } catch (e) { /* los geopend: niets */ } });
  $('#opties-verbergen').addEventListener('click', async () => {
    const k = S.current;
    if (!k || k.type !== 'dm') return;
    try {
      const j = await api('gesprek_verbergen', { channel_id: k.id });
      el.optiesDialoog.close();
      pasOverzichtToe(j); // gesprek staat niet meer in de lijst → chat sluit vanzelf
      toast('Gesprek uit je lijst gehaald');
    } catch (e) { optiesFout(e.message); }
  });
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
    if (!confirm(`${naam} sluiten? Het kanaal verhuist naar "Gesloten kanalen" onderin de zijbalk: iedereen kan het nog lezen en doorzoeken, maar er kan niets meer in geplaatst worden. Heropenen kan altijd.`)) return;
    try {
      const j = await api('kanaal_sluiten', { channel_id: S.current.id });
      el.optiesDialoog.close();
      pasOverzichtToe(j); // kanaal blijft open staan, nu alleen-lezen
      toast(`${naam} gesloten`);
    } catch (e) { optiesFout(e.message); }
  });

  // Heropenen (v0.4.1): vanuit de opties én vanuit de balk boven het gesprek.
  $('#opties-heropenen').addEventListener('click', heropenKanaal);
  el.knopHeropenen.addEventListener('click', heropenKanaal);

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

  /* ---------- Instellingen (v0.5): avatarfoto, gegevens, thema ---------- */

  const instDialoog = $('#dialoog-instellingen');
  function instellingenFout(t) { const f = $('#instellingen-fout'); f.textContent = t || ''; f.hidden = !t; }

  function renderInstellingen() {
    if (!S.me) return;
    const av = $('#instellingen-avatar');
    const heeftFoto = !!(gebruiker(S.me.id) || {}).avatar;
    av.innerHTML = avatarInhoud(S.me.id, S.me.name);
    av.style.background = heeftFoto ? '' : avatarKleur(S.me.id);
    $('#avatar-weg').hidden = !heeftFoto;
    $('#inst-naam').textContent = S.me.name;
    $('#inst-rol').textContent = S.me.role === 'superadmin' ? 'Superadmin' : 'Medewerker';
    $('#inst-email').textContent = S.me.email || '—';
    // Status alleen invullen als je er niet net zelf in aan het typen bent.
    const st = $('#inst-status');
    if (document.activeElement !== st) st.value = S.me.status || '';
    const licht = document.body.classList.contains('light-mode');
    $('#inst-thema').textContent = licht ? '☀ Licht — wissel naar donker' : '☽ Donker — wissel naar licht';
    // v0.5.1: kanaalbeheer, alleen voor de superadmin.
    const beheer = $('#inst-beheer');
    beheer.hidden = S.me.role !== 'superadmin';
    if (!beheer.hidden && !beheer.dataset.geladen) laadKanaalbeheer();
  }

  async function laadKanaalbeheer() {
    const ul = $('#beheer-kanalen');
    $('#inst-beheer').dataset.geladen = '1';
    try {
      const j = await api('beheer_kanalen');
      ul.innerHTML = j.kanalen.map((k) => `<li>
          <span class="naam">#${esc(k.name)}${k.is_project ? ' <span class="lid-via">project</span>' : ''}${k.archived ? ' <span class="lid-via">gesloten</span>' : ''}<br>
          <span class="stil beheer-info">${k.leden} ${k.leden === 1 ? 'lid' : 'leden'} · ${k.berichten} ${k.berichten === 1 ? 'bericht' : 'berichten'}</span></span>
          <button type="button" class="knop mini gevaar" data-kanaal-wis="${k.id}" data-naam="${esc(k.name)}">Verwijderen</button>
        </li>`).join('') || '<li class="stil">Geen kanalen.</li>';
    } catch (e) { ul.innerHTML = `<li class="stil">${esc(e.message)}</li>`; }
  }
  $('#beheer-kanalen').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-kanaal-wis]');
    if (!b) return;
    if (!confirm(`Kanaal #${b.dataset.naam} definitief verwijderen, inclusief alle berichten? Het is daarna ook niet meer te doorzoeken. Dit kan niet ongedaan worden gemaakt.`)) return;
    try {
      const j = await api('beheer_kanaal_wissen', { channel_id: Number(b.dataset.kanaalWis) });
      pasOverzichtToe(j);
      delete $('#inst-beheer').dataset.geladen;
      laadKanaalbeheer();
      toast('Kanaal verwijderd');
    } catch (err) { instellingenFout(err.message); }
  });

  function openInstellingen() { instellingenFout(''); renderInstellingen(); instDialoog.showModal(); }
  $('#knop-instellingen').addEventListener('click', openInstellingen);
  $('#kop-ik').addEventListener('click', openInstellingen);
  $('#instellingen-sluit').addEventListener('click', () => instDialoog.close());
  instDialoog.addEventListener('click', (e) => { if (e.target === instDialoog) instDialoog.close(); });
  $('#inst-thema').addEventListener('click', () => { $('#knop-thema').click(); renderInstellingen(); });

  // Status (v0.5)
  async function slaStatusOp() {
    const st = $('#inst-status');
    const nieuw = st.value.trim();
    if (nieuw === (S.me.status || '')) return;
    try {
      const j = await api('status_zetten', { status: nieuw });
      st.blur();
      pasOverzichtToe(j);
      toast(nieuw ? 'Status opgeslagen' : 'Status gewist');
    } catch (err) { instellingenFout(err.message); }
  }
  $('#inst-status-opslaan').addEventListener('click', slaStatusOp);
  $('#inst-status').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); slaStatusOp(); } });

  /* ---------- Nieuw gesprek (v0.5): + bij Berichten ---------- */

  const dmDialoog = $('#dialoog-dm');
  function renderDmKeuze() {
    const q = $('#dm-filter').value.trim().toLowerCase();
    const rij = (u, label, sub) => `<li><button type="button" data-collega="${u.id}">
        <span class="avatar klein"${avatarStijl(u.id)}>${avatarInhoud(u.id, u.name)}</span>
        <span class="naam">${esc(label)}</span>${sub ? `<span class="status-tekst">${esc(sub)}</span>` : ''}
      </button></li>`;
    const lijst = [];
    if (!q || 'jijzelf notities'.includes(q)) lijst.push(rij(S.me, 'Jijzelf', 'notities voor jezelf'));
    S.users.filter((u) => u.active && u.id !== S.me.id && (!q || u.name.toLowerCase().includes(q)))
      .forEach((u) => lijst.push(rij(u, u.name, u.status || (isOnline(u) ? 'online' : ''))));
    $('#dm-keuze').innerHTML = lijst.join('') || '<li class="leeg-tekst">Geen collega gevonden.</li>';
  }
  $('#knop-dm-nieuw').addEventListener('actie', () => {
    $('#dm-filter').value = '';
    renderDmKeuze();
    dmDialoog.showModal();
    if (window.matchMedia('(min-width: 801px)').matches) $('#dm-filter').focus();
  });
  $('#dm-filter').addEventListener('input', renderDmKeuze);
  $('#dm-sluit').addEventListener('click', () => dmDialoog.close());
  dmDialoog.addEventListener('click', (e) => { if (e.target === dmDialoog) dmDialoog.close(); });

  $('#avatar-kiezen').addEventListener('click', () => $('#avatar-bestand').click());
  $('#avatar-bestand').addEventListener('change', (e) => {
    const bestand = e.target.files && e.target.files[0];
    e.target.value = '';
    if (!bestand) return;
    instellingenFout('');
    if (!/^image\//.test(bestand.type)) { instellingenFout('Kies een afbeelding (jpg, png, gif, webp).'); return; }
    openCrop(bestand); // v0.7.4: eerst bijsnijden/zoomen, dan uploaden
  });

  async function uploadAvatar(blob) {
    $('#avatar-kiezen').disabled = true;
    try {
      // Multipart i.p.v. JSON: de foto gaat als bestand mee, het CSRF-token in de header.
      const fd = new FormData();
      fd.append('actie', 'avatar_upload');
      fd.append('avatar', blob, 'avatar.jpg');
      const r = await fetch('api.php', { method: 'POST', headers: { 'X-VaCom-Csrf': S.csrf }, body: fd });
      const j = await r.json().catch(() => ({ ok: false, fout: 'Onleesbaar antwoord van de server.' }));
      if (!j.ok) throw new Error(j.fout || 'Upload mislukt.');
      pasOverzichtToe(j);
      if (S.current) renderBerichten(bijnaOnderaan());
      toast('Foto opgeslagen');
    } catch (err) { instellingenFout(err.message); }
    finally { $('#avatar-kiezen').disabled = false; }
  }

  /* ---------- Foto bijsnijden (v0.7.4) ---------- */
  // De foto staat in een vierkant kader; je sleept hem en zoomt met de
  // schuif of het scrollwiel. Bij "Gebruiken" tekenen we precies het kader
  // op een canvas (384×384) en sturen dát als JPEG naar de server — die
  // maakt er zoals altijd een 192×192 van. De foto blijft altijd het hele
  // kader vullen (geen lege randen), net als bij een telefoon-app.
  const cropDialoog = $('#dialoog-avatar'), cropKader = $('#crop-kader'), cropBeeld = $('#crop-beeld'), cropZoom = $('#crop-zoom');
  const crop = { url: null, natW: 0, natH: 0, basis: 1, zoom: 1, x: 0, y: 0, sleep: null };
  const K = () => cropKader.clientWidth; // kadermaat in px (CSS)

  function openCrop(bestand) {
    if (crop.url) URL.revokeObjectURL(crop.url);
    crop.url = URL.createObjectURL(bestand);
    cropBeeld.onload = () => {
      crop.natW = cropBeeld.naturalWidth; crop.natH = cropBeeld.naturalHeight;
      crop.basis = K() / Math.min(crop.natW, crop.natH); // kleinste zijde vult het kader
      crop.zoom = 1; crop.x = 0; crop.y = 0;
      cropZoom.value = '1';
      tekenCrop();
    };
    cropBeeld.onerror = () => { instellingenFout('Deze afbeelding kan niet geladen worden.'); cropDialoog.close(); };
    cropBeeld.src = crop.url;
    cropDialoog.showModal();
  }

  function cropMaten() {
    const w = crop.natW * crop.basis * crop.zoom, h = crop.natH * crop.basis * crop.zoom, k = K();
    // Verschuiving begrenzen: het kader moet altijd helemaal gevuld blijven.
    crop.x = Math.max(-(w - k) / 2, Math.min((w - k) / 2, crop.x));
    crop.y = Math.max(-(h - k) / 2, Math.min((h - k) / 2, crop.y));
    return { w, h, k, left: (k - w) / 2 + crop.x, top: (k - h) / 2 + crop.y };
  }

  function tekenCrop() {
    if (!crop.natW) return;
    const m = cropMaten();
    cropBeeld.style.width = m.w + 'px'; cropBeeld.style.height = m.h + 'px';
    cropBeeld.style.left = m.left + 'px'; cropBeeld.style.top = m.top + 'px';
  }

  // Zoomen om het midden van het kader: de verschuiving schaalt mee.
  function zetZoom(nieuw) {
    nieuw = Math.max(1, Math.min(4, nieuw));
    const f = nieuw / crop.zoom;
    crop.x *= f; crop.y *= f; crop.zoom = nieuw;
    cropZoom.value = String(nieuw);
    tekenCrop();
  }
  cropZoom.addEventListener('input', () => zetZoom(parseFloat(cropZoom.value)));
  cropKader.addEventListener('wheel', (e) => { e.preventDefault(); zetZoom(crop.zoom * (e.deltaY < 0 ? 1.08 : 1 / 1.08)); }, { passive: false });
  cropKader.addEventListener('pointerdown', (e) => {
    crop.sleep = { px: e.clientX, py: e.clientY, x: crop.x, y: crop.y };
    cropKader.setPointerCapture(e.pointerId);
    e.preventDefault();
  });
  cropKader.addEventListener('pointermove', (e) => {
    if (!crop.sleep) return;
    crop.x = crop.sleep.x + (e.clientX - crop.sleep.px);
    crop.y = crop.sleep.y + (e.clientY - crop.sleep.py);
    tekenCrop();
  });
  const stopSleep = () => { crop.sleep = null; };
  cropKader.addEventListener('pointerup', stopSleep);
  cropKader.addEventListener('pointercancel', stopSleep);

  function sluitCrop() {
    cropDialoog.close();
    if (crop.url) { URL.revokeObjectURL(crop.url); crop.url = null; }
    cropBeeld.removeAttribute('src');
  }
  $('#avatar-crop-sluit').addEventListener('click', sluitCrop);
  $('#avatar-crop-annuleer').addEventListener('click', sluitCrop);
  cropDialoog.addEventListener('cancel', (e) => { e.preventDefault(); sluitCrop(); }); // Esc

  $('#avatar-crop-ok').addEventListener('click', async () => {
    if (!crop.natW) return;
    const m = cropMaten();
    const uit = 384, s = uit / m.k;
    const c = document.createElement('canvas');
    c.width = uit; c.height = uit;
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, uit, uit); // transparante png's krijgen wit
    ctx.drawImage(cropBeeld, m.left * s, m.top * s, m.w * s, m.h * s);
    const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9));
    if (!blob) { instellingenFout('Bijsnijden mislukt.'); return; }
    sluitCrop();
    await uploadAvatar(blob);
  });
  $('#avatar-weg').addEventListener('click', async () => {
    try {
      const j = await api('avatar_verwijderen');
      pasOverzichtToe(j);
      if (S.current) renderBerichten(bijnaOnderaan());
      toast('Foto verwijderd');
    } catch (err) { instellingenFout(err.message); }
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
  // v0.5.1: ?project=TOKEN (vanuit de Portal) opent dat projectkanaal direct.
  const projectKanaal = window.VACOM.project ? S.channels.find((c) => c.project_token === window.VACOM.project) : null;
  if (projectKanaal) {
    kiesKanaal(projectKanaal.id);
  } else if (window.VACOM.embed) {
    // Ingesloten in de Portal maar het kanaal staat niet in mijn lijst: geen
    // lid (niet gekoppeld, of ooit verlaten). Deelnemen kan met één klik.
    document.body.dataset.pane = 'chat';
    el.naam.textContent = 'Projectkanaal';
    el.leeg.innerHTML = '<p class="stil">Je bent (nog) geen lid van dit projectkanaal.</p><button type="button" class="knop primair" id="knop-deelnemen">Deelnemen</button>';
    $('#knop-deelnemen').addEventListener('click', async () => {
      try { await api('kanaal_deelnemen', { project_token: window.VACOM.project }); location.reload(); }
      catch (e) { toast(e.message); }
    });
  } else if (m && S.channels.some((c) => c.id === Number(m[1]))) {
    kiesKanaal(Number(m[1]));
  } else if (!isMobiel() && S.channels.some((c) => !c.archived)) {
    // Standaard #algemeen, anders het eerste losse kanaal, anders het eerste
    // wat er is — maar nooit een gesloten kanaal.
    const open = S.channels.filter((c) => !c.archived);
    const start = open.find((c) => c.type === 'public' && c.name === 'algemeen')
      || open.find((c) => c.type === 'public' && !c.project_token)
      || open[0];
    kiesKanaal(start.id);
  }
  planPoll();
})();
