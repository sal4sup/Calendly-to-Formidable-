(function () {
    'use strict';

    var config = window.ctfbBooking || {};

    var MONTHS = [
        'January','February','March','April','May','June',
        'July','August','September','October','November','December'
    ];

    var state = {
        eventType:        config.eventType   || '',
        displayMode:      config.displayMode || 'calendar',
        /* calendar */
        year: 0, month: 0,
        slots: {}, cache: {}, pending: 0,
        selectedDate: null,
        /* list */
        weekStart:        null,
        listSlots:        {},
        listSelectedDay:  null,
        /* shared */
        selectedTime:     null
    };

    /* ── Tiny utilities ─────────────────────────────────── */

    function pad(n)  { return n < 10 ? '0' + n : String(n); }
    function el(id)  { return document.getElementById(id); }
    function show(e) { if (e) e.style.display = ''; }
    function hide(e) { if (e) e.style.display = 'none'; }

    function dateKey(y, m, d) { return y + '-' + pad(m) + '-' + pad(d); }
    function monthKey(y, m)   { return y + '-' + pad(m); }
    function todayKey() {
        var n = new Date();
        return dateKey(n.getFullYear(), n.getMonth() + 1, n.getDate());
    }

    function addDays(dateStr, days) {
        var d = new Date(dateStr + 'T12:00:00Z');
        d.setUTCDate(d.getUTCDate() + days);
        return dateKey(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate());
    }

    function formatLong(iso) {
        return new Date(iso + 'T12:00:00').toLocaleDateString('en-US', {
            weekday: 'long', month: 'long', day: 'numeric'
        });
    }
    function formatShort(iso) {
        return new Date(iso + 'T12:00:00').toLocaleDateString('en-US', {
            month: 'short', day: 'numeric'
        });
    }
    function formatWeekday(iso) {
        return new Date(iso + 'T12:00:00').toLocaleDateString('en-US', { weekday: 'long' });
    }
    function formatMonthDay(iso) {
        return new Date(iso + 'T12:00:00').toLocaleDateString('en-US', {
            month: 'long', day: 'numeric'
        });
    }
    function formatTime(iso) {
        return new Date(iso).toLocaleTimeString('en-US', {
            hour: 'numeric', minute: '2-digit', hour12: true
        });
    }

    /* ── Quick hash for sessionStorage keys ─────────────── */

    function quickHash(str) {
        var h = 0;
        for (var i = 0; i < str.length; i++) {
            h = (Math.imul ? Math.imul(h, 31) : h * 31) + str.charCodeAt(i) | 0;
        }
        return Math.abs(h).toString(36);
    }

    /* ── SessionStorage cache (Option D) ────────────────── */
    /* Mirrors the 5-min server transient TTL                */

    var SS_TTL = 300000;

    function ssGet(key) {
        try {
            var raw = sessionStorage.getItem(key);
            if (!raw) return null;
            var obj = JSON.parse(raw);
            if (Date.now() - obj.ts > SS_TTL) { sessionStorage.removeItem(key); return null; }
            return obj.data;
        } catch (e) { return null; }
    }

    function ssSet(key, data) {
        try {
            sessionStorage.setItem(key, JSON.stringify({ ts: Date.now(), data: data }));
        } catch (e) { /* quota exceeded — ignore */ }
    }

    /* ── API ─────────────────────────────────────────────── */

    function apiGet(endpoint, params) {
        var url = config.restUrl + endpoint;
        if (params) {
            var p = [];
            for (var k in params) {
                if (params.hasOwnProperty(k))
                    p.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
            }
            if (p.length) url += '?' + p.join('&');
        }
        return fetch(url, {
            method: 'GET',
            headers: { 'X-WP-Nonce': config.nonce }
        }).then(function (r) { return r.json(); });
    }

    function apiPost(endpoint, data) {
        return fetch(config.restUrl + endpoint, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
            body:    JSON.stringify(data)
        }).then(function (r) { return r.json(); });
    }

    /* ── Step indicator ─────────────────────────────────── */

    function setStep(n) {
        var steps = document.querySelectorAll('#ctfb-booking .ctfb-step[data-step]');
        for (var i = 0; i < steps.length; i++) {
            var num = parseInt(steps[i].getAttribute('data-step'), 10);
            steps[i].classList.remove('ctfb-step--active', 'ctfb-step--done');
            if (num < n)       steps[i].classList.add('ctfb-step--done');
            else if (num === n) steps[i].classList.add('ctfb-step--active');
        }
    }

    /* ── Panel helpers ───────────────────────────────────── */

    var STEP_MAP = {
        'ctfb-step-datetime': 1,
        'ctfb-step-form':     2,
        'ctfb-step-done':     3
    };

    function showPanel(id) {
        var loadEl = el('ctfb-loading');
        if (loadEl) hide(loadEl);
        hide(el('ctfb-global-error'));
        var panels = document.querySelectorAll('#ctfb-booking > .ctfb-panel');
        for (var i = 0; i < panels.length; i++) hide(panels[i]);
        show(el(id));
        setStep(STEP_MAP[id] || 1);
    }

    function showError(msg) {
        var e = el('ctfb-global-error');
        e.textContent = msg;
        var loadEl = el('ctfb-loading');
        if (loadEl) hide(loadEl);
        var panels = document.querySelectorAll('#ctfb-booking > .ctfb-panel');
        for (var i = 0; i < panels.length; i++) hide(panels[i]);
        show(e);
    }

    /* ── Slot helpers ────────────────────────────────────── */

    function groupSlots(rawSlots) {
        var g = {};
        for (var i = 0; i < rawSlots.length; i++) {
            var dt = new Date(rawSlots[i].start_time);
            var k  = dateKey(dt.getFullYear(), dt.getMonth() + 1, dt.getDate());
            if (!g[k]) g[k] = [];
            g[k].push(rawSlots[i].start_time);
        }
        return g;
    }

    function mergeInto(target, source) {
        for (var k in source) {
            if (!source.hasOwnProperty(k)) continue;
            if (!target[k]) target[k] = [];
            target[k] = target[k].concat(source[k]);
        }
    }

    /* ══════════════════════════════════════════════════════
       CALENDAR MODE
    ══════════════════════════════════════════════════════ */

    function getWeekChunks(year, month) {
        var start  = dateKey(year, month + 1, 1);
        var ey     = month === 11 ? year + 1 : year;
        var em     = month === 11 ? 1 : month + 2;
        var end    = dateKey(ey, em, 1);
        var chunks = [];
        var cursor = start;
        while (cursor < end) {
            var next = addDays(cursor, 7);
            if (next > end) next = end;
            chunks.push({ start: cursor, end: next });
            cursor = next;
        }
        return chunks;
    }

    function fetchMonth(year, month) {
        var mk    = monthKey(year, month + 1);
        var today = todayKey();

        /* In-memory cache */
        if (state.cache[mk]) {
            state.slots   = state.cache[mk];
            state.pending = 0;
            renderCalendar();
            return;
        }

        /* SessionStorage cache — Option D */
        var ssKey  = 'ctfb_m_' + mk + '_' + quickHash(state.eventType);
        var ssData = ssGet(ssKey);
        if (ssData) {
            state.slots     = ssData;
            state.pending   = 0;
            state.cache[mk] = ssData;
            renderCalendar();
            return;
        }

        var chunks = getWeekChunks(year, month);

        /* Priority sort — Option C: chunk containing today first,
           then future chunks in ascending order, past chunks last */
        var sorted = chunks.slice().sort(function (a, b) {
            var aHas = a.start <= today && today < a.end;
            var bHas = b.start <= today && today < b.end;
            if (aHas && !bHas) return -1;
            if (!aHas && bHas) return 1;
            var aFut = a.end > today;
            var bFut = b.end > today;
            if (aFut && !bFut) return -1;
            if (!aFut && bFut) return 1;
            return a.start < b.start ? -1 : 1;
        });

        state.pending = chunks.length;
        state.slots   = {};
        renderCalendar(); /* skeleton — grid visible immediately */

        var collected = {};

        function doChunk(chunk) {
            apiGet('available-times', {
                event_type: state.eventType,
                start_date: chunk.start
            }).then(function (data) {
                if (data.success && data.slots && data.slots.length) {
                    var g = groupSlots(data.slots);
                    mergeInto(collected, g);
                    mergeInto(state.slots, g);
                }
                if (--state.pending === 0) {
                    state.cache[mk] = collected;
                    ssSet(ssKey, collected); /* save for next visit */
                }
                renderCalendar();
            }).catch(function () {
                if (--state.pending === 0) state.cache[mk] = collected;
                renderCalendar();
            });
        }

        /* Fire all chunks — sorted so nearest-future resolves first */
        for (var i = 0; i < sorted.length; i++) {
            (function (c) { doChunk(c); })(sorted[i]);
        }
    }

    function renderCalendar() {
        var lastDate = new Date(state.year, state.month + 1, 0).getDate();
        var startDow = (new Date(state.year, state.month, 1).getDay() + 6) % 7;
        var today    = todayKey();
        var loading  = state.pending > 0;
        var now      = new Date();

        var titleEl = el('ctfb-cal-title');
        if (titleEl) titleEl.textContent = MONTHS[state.month] + ' ' + state.year;

        var prevBtn = el('ctfb-cal-prev');
        if (prevBtn) {
            prevBtn.disabled =
                state.year < now.getFullYear() ||
                (state.year === now.getFullYear() && state.month <= now.getMonth());
        }

        var grid = el('ctfb-cal-grid');
        if (!grid) return;
        grid.innerHTML = '';

        for (var p = 0; p < startDow; p++) {
            var emp = document.createElement('span');
            emp.className = 'ctfb-cal-cell ctfb-cal-cell--empty';
            grid.appendChild(emp);
        }

        var hasAny = false;
        for (var d = 1; d <= lastDate; d++) {
            var dk  = dateKey(state.year, state.month + 1, d);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ctfb-cal-cell';
            btn.setAttribute('data-date', dk);
            btn.textContent = d;

            var isPast   = dk < today;
            var hasSlots = state.slots[dk] && state.slots[dk].length > 0;

            if (isPast) {
                btn.classList.add('ctfb-cal-cell--past');
                btn.disabled = true;
            } else if (hasSlots) {
                btn.classList.add('ctfb-cal-cell--available');
                hasAny = true;
            } else if (loading) {
                /* still waiting for data — neutral skeleton look */
                btn.classList.add('ctfb-cal-cell--loading');
                btn.disabled = true;
            } else {
                btn.classList.add('ctfb-cal-cell--unavailable');
                btn.disabled = true;
            }

            if (dk === today && !isPast) btn.classList.add('ctfb-cal-cell--today');
            if (dk === state.selectedDate) btn.classList.add('ctfb-cal-cell--selected');
            btn.addEventListener('click', onCalDayClick);
            grid.appendChild(btn);
        }

        var emptyMsg = el('ctfb-cal-empty');
        if (emptyMsg) (!hasAny && !loading) ? show(emptyMsg) : hide(emptyMsg);
    }

    function onCalDayClick(e) {
        var dk = e.currentTarget.getAttribute('data-date');
        if (!state.slots[dk] || !state.slots[dk].length) return;
        state.selectedDate = dk;
        hide(el('ctfb-cal-section'));
        renderCalTimesList(dk);
        el('ctfb-times-title').textContent = formatLong(dk);
        show(el('ctfb-times-section'));
    }

    function renderCalTimesList(dk) {
        var container = el('ctfb-times-list');
        container.innerHTML = '';
        var times = (state.slots[dk] || []).slice().sort();
        for (var i = 0; i < times.length; i++) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ctfb-time-btn';
            btn.textContent = formatTime(times[i]);
            btn.setAttribute('data-time', times[i]);
            btn.setAttribute('data-date', dk);
            btn.addEventListener('click', onTimeClick);
            container.appendChild(btn);
        }
    }

    function initCalendarMode() {
        var now = new Date();
        state.year  = now.getFullYear();
        state.month = now.getMonth();

        /* Render skeleton cells first so the panel appears populated */
        show(el('ctfb-cal-section'));
        renderCalendar();

        /* Now reveal the panel — no empty flash */
        showPanel('ctfb-step-datetime');

        /* Fire parallel chunk requests */
        fetchMonth(state.year, state.month);

        el('ctfb-cal-prev').addEventListener('click', function () {
            state.month--;
            if (state.month < 0) { state.month = 11; state.year--; }
            state.selectedDate = null;
            hide(el('ctfb-times-section'));
            show(el('ctfb-cal-section'));
            fetchMonth(state.year, state.month);
        });

        el('ctfb-cal-next').addEventListener('click', function () {
            state.month++;
            if (state.month > 11) { state.month = 0; state.year++; }
            state.selectedDate = null;
            hide(el('ctfb-times-section'));
            show(el('ctfb-cal-section'));
            fetchMonth(state.year, state.month);
        });

        el('ctfb-back-to-cal').addEventListener('click', function () {
            hide(el('ctfb-times-section'));
            show(el('ctfb-cal-section'));
        });
    }

    /* ══════════════════════════════════════════════════════
       LIST MODE
    ══════════════════════════════════════════════════════ */

    function fetchWeek(startDate) {
        state.weekStart = startDate;
        state.listSlots = {};

        var label = formatShort(startDate) + ' \u2013 ' + formatShort(addDays(startDate, 6));
        el('ctfb-week-label').textContent = label;
        el('ctfb-prev-week').disabled = startDate <= todayKey();

        /* SessionStorage cache — Option D */
        var ssKey  = 'ctfb_w_' + startDate + '_' + quickHash(state.eventType);
        var ssData = ssGet(ssKey);
        if (ssData) {
            state.listSlots = ssData;
            renderDayCards();
            return;
        }

        /* Show skeleton immediately — Option A */
        renderDayCardSkeletons();

        apiGet('available-times', {
            event_type: state.eventType,
            start_date: startDate
        }).then(function (data) {
            state.listSlots = (data.success && data.slots) ? groupSlots(data.slots) : {};
            ssSet(ssKey, state.listSlots);
            renderDayCards();
        }).catch(function () {
            var c = el('ctfb-week-slots');
            c.innerHTML = '<p class="ctfb-cal-empty">Could not load availability. Please try again.</p>';
        });
    }

    function renderDayCardSkeletons() {
        var container = el('ctfb-week-slots');
        container.innerHTML = '';
        for (var i = 0; i < 4; i++) {
            var skel = document.createElement('div');
            skel.className = 'ctfb-day-card ctfb-day-card--skeleton';
            skel.innerHTML =
                '<span class="ctfb-day-card-info">' +
                    '<span class="ctfb-skel-line ctfb-skel-line--name"></span>' +
                    '<span class="ctfb-skel-line ctfb-skel-line--date"></span>' +
                '</span>' +
                '<span class="ctfb-skel-badge"></span>';
            container.appendChild(skel);
        }
    }

    function renderDayCards() {
        var container = el('ctfb-week-slots');
        container.innerHTML = '';
        var today  = todayKey();
        var hasAny = false;

        for (var i = 0; i < 7; i++) {
            var dk    = addDays(state.weekStart, i);
            if (dk < today) continue;
            var times = state.listSlots[dk] ? state.listSlots[dk].slice().sort() : [];
            if (!times.length) continue;
            hasAny = true;

            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'ctfb-day-card';
            card.setAttribute('data-date', dk);

            var info = document.createElement('span');
            info.className = 'ctfb-day-card-info';
            info.innerHTML =
                '<strong class="ctfb-day-card-name">' + formatWeekday(dk) + '</strong>' +
                '<span class="ctfb-day-card-date">' + formatMonthDay(dk) + '</span>';

            var badge = document.createElement('span');
            badge.className = 'ctfb-day-card-badge';
            badge.textContent = times.length + (times.length === 1 ? ' slot' : ' slots');

            var arrow = document.createElement('span');
            arrow.className = 'ctfb-day-card-arrow';
            arrow.setAttribute('aria-hidden', 'true');
            arrow.innerHTML = '&#8250;';

            card.appendChild(info);
            card.appendChild(badge);
            card.appendChild(arrow);
            card.addEventListener('click', function (e) {
                onListDayClick(e.currentTarget.getAttribute('data-date'));
            });
            container.appendChild(card);
        }

        if (!hasAny) {
            container.innerHTML =
                '<p class="ctfb-cal-empty">No availability this week. Try the next week.</p>';
        }
    }

    function onListDayClick(dk) {
        state.listSelectedDay = dk;
        el('ctfb-list-times-title').textContent = formatLong(dk);

        var container = el('ctfb-list-times-list');
        container.innerHTML = '';
        var times = (state.listSlots[dk] || []).slice().sort();
        for (var i = 0; i < times.length; i++) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ctfb-time-btn';
            btn.textContent = formatTime(times[i]);
            btn.setAttribute('data-time', times[i]);
            btn.setAttribute('data-date', dk);
            btn.addEventListener('click', onTimeClick);
            container.appendChild(btn);
        }

        hide(el('ctfb-list-days-panel'));
        show(el('ctfb-list-times-panel'));
    }

    function initListMode() {
        state.weekStart = todayKey();
        el('ctfb-week-label').textContent = '\u2014';
        el('ctfb-prev-week').disabled = true;

        /* Render skeletons before revealing panel */
        show(el('ctfb-list-section'));
        renderDayCardSkeletons();
        showPanel('ctfb-step-datetime');

        /* Fire the API immediately after painting */
        fetchWeek(state.weekStart);

        el('ctfb-prev-week').addEventListener('click', function () {
            hide(el('ctfb-list-times-panel'));
            show(el('ctfb-list-days-panel'));
            fetchWeek(addDays(state.weekStart, -7));
        });
        el('ctfb-next-week').addEventListener('click', function () {
            hide(el('ctfb-list-times-panel'));
            show(el('ctfb-list-days-panel'));
            fetchWeek(addDays(state.weekStart, 7));
        });
        el('ctfb-list-back-to-days').addEventListener('click', function () {
            hide(el('ctfb-list-times-panel'));
            show(el('ctfb-list-days-panel'));
        });
    }

    /* ══════════════════════════════════════════════════════
       SHARED: time click → form → submit
    ══════════════════════════════════════════════════════ */

    function onTimeClick(e) {
        var time = e.currentTarget.getAttribute('data-time');
        var dk   = e.currentTarget.getAttribute('data-date');
        state.selectedTime = time;
        state.selectedDate = dk;

        var allBtns = document.querySelectorAll('.ctfb-time-btn');
        for (var i = 0; i < allBtns.length; i++) {
            allBtns[i].classList.remove('ctfb-time-btn--selected');
        }
        e.currentTarget.classList.add('ctfb-time-btn--selected');

        el('ctfb-selected-info').textContent =
            formatLong(dk) + ' at ' + formatTime(time);
        showPanel('ctfb-step-form');
    }

    function onFormSubmit(e) {
        e.preventDefault();
        var form  = el('ctfb-booking-form');
        var errEl = el('ctfb-form-error');
        hide(errEl);

        var name    = form.querySelector('[name="name"]').value.trim();
        var email   = form.querySelector('[name="email"]').value.trim();
        var company = form.querySelector('[name="company"]').value.trim();
        var phone   = form.querySelector('[name="phone"]').value.trim();
        var country = form.querySelector('[name="country"]').value.trim();
        var fwd     = form.querySelector('[name="freight_forwarder"]').value;
        var hp      = form.querySelector('[name="website"]').value;

        if (!name || !email) {
            errEl.textContent = 'Please fill in all required fields.';
            show(errEl); return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errEl.textContent = 'Please enter a valid email address.';
            show(errEl); return;
        }

        var btn = el('ctfb-submit');
        btn.disabled    = true;
        btn.textContent = 'Booking\u2026';

        apiPost('create', {
            event_type:       state.eventType,
            start_time:       state.selectedTime,
            name:             name,
            email:            email,
            company:          company,
            phone:            phone,
            country:          country,
            freight_forwarder: fwd,
            website:          hp
        }).then(function (data) {
            if (!data.success) {
                errEl.textContent = data.message || 'Booking failed. Please try again.';
                show(errEl);
                btn.disabled    = false;
                btn.textContent = 'Confirm Booking';
                return;
            }
            if (data.booking_url) {
                el('ctfb-booking-link').href = data.booking_url;
                showPanel('ctfb-step-done');
                window.location.href = data.booking_url;
            }
        }).catch(function () {
            errEl.textContent = 'An error occurred. Please try again.';
            show(errEl);
            btn.disabled    = false;
            btn.textContent = 'Confirm Booking';
        });
    }

    /* ── Init ────────────────────────────────────────────── */

    function start() {
        if (state.displayMode === 'list') {
            initListMode();
        } else {
            initCalendarMode();
        }
    }

    function init() {
        if (!el('ctfb-booking')) return;

        el('ctfb-back-to-datetime').addEventListener('click', function () {
            if (state.displayMode === 'list') {
                hide(el('ctfb-list-times-panel'));
                show(el('ctfb-list-days-panel'));
            } else {
                hide(el('ctfb-times-section'));
                show(el('ctfb-cal-section'));
            }
            showPanel('ctfb-step-datetime');
        });

        el('ctfb-booking-form').addEventListener('submit', onFormSubmit);

        if (state.eventType) {
            /* Event type known — show UI instantly, no loader */
            start();
        } else {
            /* Must fetch event types first — show loader, 5-second max wait */
            var loadEl = el('ctfb-loading');
            if (loadEl) show(loadEl);

            var timeout = setTimeout(function () {
                showError('Booking is taking too long. Please refresh the page.');
            }, 5000);

            apiGet('event-types').then(function (data) {
                clearTimeout(timeout);
                if (data.success && data.event_types && data.event_types.length) {
                    state.eventType = data.event_types[0].uri;
                    start();
                } else {
                    showError('No meeting types are configured. Please contact the administrator.');
                }
            }).catch(function () {
                clearTimeout(timeout);
                showError('Could not load booking data. Please refresh the page.');
            });
        }
    }

    if (document.readyState !== 'loading') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }

})();
