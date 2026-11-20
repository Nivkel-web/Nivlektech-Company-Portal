/*
 | Live updates for the ticket page and the Live Management board.
 | Plain JavaScript, uses polling (works on any PHP host, no websocket server).
 */
(function () {
    'use strict';

    var ENDPOINT = 'live-data.php';

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function getJSON(url) {
        return fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
            if (r.status === 401) { window.location = '../login.php'; throw new Error('logged out'); }
            return r.json();
        });
    }

    /* ======================================================
       1) Single ticket: new replies appear without reloading
       ====================================================== */
    if (window.TICKET_LIVE) {
        var T = window.TICKET_LIVE;
        var thread = document.getElementById('thread');

        function pollReplies() {
            if (document.hidden) return;
            getJSON(ENDPOINT + '?action=replies&id=' + T.id + '&after=' + T.lastId).then(function (d) {
                if (d.error) return;
                if (d.html) {
                    var none = document.getElementById('noReplies');
                    if (none) none.remove();
                    var tmp = document.createElement('div');
                    tmp.innerHTML = d.html;
                    while (tmp.firstChild) {
                        if (tmp.firstChild.classList) tmp.firstChild.classList.add('new-reply');
                        thread.appendChild(tmp.firstChild);
                    }
                    T.lastId = d.last_id;
                }
                var st = document.getElementById('liveStatus');
                if (st && d.status_html) st.innerHTML = d.status_html;
                var as = document.getElementById('liveAssignee');
                if (as && d.assignee) as.textContent = d.assignee;
                var v = document.getElementById('viewers');
                if (v) v.innerHTML = d.also_viewing && d.also_viewing.length
                    ? '<i class="bi bi-eye"></i> Also viewing: ' + d.also_viewing.map(esc).join(', ')
                    : '';
            }).catch(function () {});
        }
        pollReplies();
        setInterval(pollReplies, 5000);
    }

    /* ======================================================
       2) Live Management board
       ====================================================== */
    if (window.LIVE_BOARD) {
        var B = window.LIVE_BOARD;
        var known = null;            // ids seen so far (to flash new tickets)
        var timer = null;
        var busy = false;            // a user is interacting with a select -> don't redraw under them
        var lastData = null;

        var liveToggle  = document.getElementById('liveToggle');
        var soundToggle = document.getElementById('soundToggle');
        var errBox      = document.getElementById('boardError');

        function beep() {
            try {
                var ctx = new (window.AudioContext || window.webkitAudioContext)();
                var o = ctx.createOscillator(), g = ctx.createGain();
                o.connect(g); g.connect(ctx.destination);
                o.frequency.value = 880; g.gain.value = 0.08;
                o.start(); setTimeout(function () { o.stop(); ctx.close(); }, 180);
            } catch (e) {}
        }

        function cardHtml(t, isNew) {
            var userOpts = '<option value="">Unassigned</option>' + B.users.map(function (u) {
                return '<option value="' + u.id + '"' + (+t.assigned_to === u.id ? ' selected' : '') + '>' + esc(u.name) + '</option>';
            }).join('');
            var stOpts = Object.keys(B.statuses).map(function (k) {
                return '<option value="' + k + '"' + (t.status === k ? ' selected' : '') + '>' + esc(B.statuses[k]) + '</option>';
            }).join('');
            return '<div class="tcard p-' + esc(t.priority) + (isNew ? ' is-new' : '') + '" data-id="' + t.id + '">'
                + '<div class="meta d-flex justify-content-between"><span>' + esc(t.ticket_no) + '</span><span>' + esc(t.age) + '</span></div>'
                + '<a class="subj" href="view-ticket.php?id=' + t.id + '">' + esc(t.subject) + '</a>'
                + '<div class="meta mb-2">' + esc(t.creator_name || 'Unknown') + ' · ' + esc(t.category)
                + (t.overdue ? ' · <span class="text-danger fw-bold">Overdue</span>' : '')
                + (t.priority === 'urgent' ? ' · <span class="text-danger fw-bold">Urgent</span>' : '') + '</div>'
                + '<div class="d-flex gap-1">'
                + '<select class="form-select form-select-sm js-assign">' + userOpts + '</select>'
                + '<select class="form-select form-select-sm js-status">' + stOpts + '</select>'
                + '</div></div>';
        }

        function render(d) {
            lastData = d;
            document.getElementById('lastUpdate').textContent = d.time;

            // counters
            document.querySelectorAll('[data-count]').forEach(function (el) {
                var v = d.counts[el.getAttribute('data-count')] || 0;
                if (String(v) !== el.textContent) {
                    el.textContent = v;
                    var tile = el.closest('.live-tile');
                    tile.classList.remove('pulse'); void tile.offsetWidth; tile.classList.add('pulse');
                }
            });

            // columns
            var cols = { open: [], in_progress: [], on_hold: [], resolved: [] };
            var seen = {};
            var hasNew = false;
            d.tickets.forEach(function (t) {
                seen[t.id] = true;
                var isNew = known !== null && !known[t.id];
                if (isNew && t.status === 'open') hasNew = true;
                if (cols[t.status]) cols[t.status].push(cardHtml(t, isNew));
            });
            Object.keys(cols).forEach(function (k) {
                document.querySelector('[data-col="' + k + '"]').innerHTML =
                    cols[k].join('') || '<div class="text-muted small text-center py-3">Nothing here</div>';
                document.querySelector('[data-colcount="' + k + '"]').textContent = cols[k].length;
            });
            if (hasNew && soundToggle.checked) beep();
            known = seen;

            // online users
            document.getElementById('onlineList').innerHTML = d.online.length
                ? d.online.map(function (u) {
                    return '<li class="list-group-item"><i class="bi bi-circle-fill dot-online"></i> ' + esc(u.fullname)
                        + ' <small class="text-muted">(' + esc(u.role) + ')</small>'
                        + (u.watching_ticket ? ' <a class="small" href="view-ticket.php?id=' + u.watching_ticket + '">viewing ticket</a>' : '') + '</li>';
                }).join('')
                : '<li class="list-group-item text-muted">Nobody</li>';

            // workload
            document.getElementById('loadList').innerHTML = d.load.length
                ? d.load.map(function (u) {
                    return '<li class="list-group-item d-flex justify-content-between">' + esc(u.fullname)
                        + '<span class="badge bg-primary">' + u.cnt + '</span></li>';
                }).join('')
                : '<li class="list-group-item text-muted">No assigned tickets</li>';
        }

        function load() {
            if (busy || document.hidden) return Promise.resolve();
            return getJSON(ENDPOINT + '?action=board').then(function (d) {
                if (d.error) { errBox.textContent = d.error; errBox.classList.remove('d-none'); return; }
                errBox.classList.add('d-none');
                render(d);
            }).catch(function () {
                errBox.textContent = 'Connection problem. Retrying...';
                errBox.classList.remove('d-none');
            });
        }

        function schedule() {
            clearInterval(timer);
            if (liveToggle.checked) timer = setInterval(load, 5000);
        }

        // Quick updates (status / assignee) directly from a card
        document.getElementById('board').addEventListener('change', function (ev) {
            var sel = ev.target;
            var isStatus = sel.classList.contains('js-status');
            var isAssign = sel.classList.contains('js-assign');
            if (!isStatus && !isAssign) return;

            var card = sel.closest('.tcard');
            var body = new URLSearchParams();
            body.set('action', 'update');
            body.set('id', card.getAttribute('data-id'));
            body.set(isStatus ? 'status' : 'assigned_to', sel.value);

            card.style.opacity = '.5';
            fetch(ENDPOINT, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-CSRF-Token': B.csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json(); }).then(function (d) {
                busy = false;
                if (d.error) { errBox.textContent = d.error; errBox.classList.remove('d-none'); }
                return load();
            }).catch(function () { busy = false; card.style.opacity = ''; });
        });

        // do not redraw while a dropdown is open
        document.getElementById('board').addEventListener('focusin',  function (e) { if (e.target.tagName === 'SELECT') busy = true; });
        document.getElementById('board').addEventListener('focusout', function (e) { if (e.target.tagName === 'SELECT') busy = false; });

        liveToggle.addEventListener('change', schedule);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });

        load().then(schedule);
    }
})();
