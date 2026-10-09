/**
 * TrafficBoard - renders live signals from /api/state.php.
 *
 *   new TrafficBoard(element, { road: 'all' | 1..4, compact: false, pollMs: 1000 });
 *
 * The server's clock is the only clock that matters: each response carries now_ms and
 * we keep an offset so countdowns are correct even if this screen's clock is wrong.
 * If the link to the server drops, the screen fails safe to flashing orange.
 */
(function () {
  'use strict';
  var TEXT = {
    green: 'GO', orange: 'PREPARE TO STOP', red: 'STOP',
    flash_orange: 'CAUTION - GIVE WAY', off: ''
  };
  var STALE_MS = 10000;

  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function fmt(ms) {
    var s = Math.max(0, Math.ceil(ms / 1000));
    return Math.floor(s / 60) + ':' + pad(s % 60);
  }

  function TrafficBoard(root, opts) {
    this.root = root;
    this.opts = Object.assign({ road: 'all', compact: false, pollMs: 1000, url: '/api/state.php' }, opts || {});
    this.offset = 0;
    this.state = null;
    this.lastOk = 0;
    this.build();
    this.poll();
    var self = this;
    setInterval(function () { self.poll(); }, this.opts.pollMs);
    setInterval(function () { self.render(); }, 200);
  }

  TrafficBoard.prototype.build = function () {
    this.root.innerHTML = '';
    this.root.classList.add(this.opts.compact ? 'board-compact' : (this.opts.road === 'all' ? 'board-grid' : 'board-single'));
    this.banner = document.createElement('div');
    this.banner.className = 'link-banner';
    this.banner.textContent = 'SIGNAL LINK LOST - TREAT AS FLASHING ORANGE, GIVE WAY';
    this.root.appendChild(this.banner);
    this.row = document.createElement('div');
    this.row.className = 'board-row';
    this.root.appendChild(this.row);
    this.cells = {};
  };

  TrafficBoard.prototype.cell = function (road) {
    if (this.cells[road.id]) { return this.cells[road.id]; }
    var col = document.createElement('div');
    col.className = 'signal';
    col.innerHTML =
      '<div class="signal-name"></div>' +
      '<div class="housing"><span class="lamp red"></span><span class="lamp orange"></span><span class="lamp green"></span></div>' +
      '<div class="signal-text"></div><div class="signal-count"></div>';
    this.row.appendChild(col);
    return (this.cells[road.id] = {
      el: col,
      name: col.querySelector('.signal-name'),
      text: col.querySelector('.signal-text'),
      count: col.querySelector('.signal-count'),
      lamps: { red: col.querySelector('.red'), orange: col.querySelector('.orange'), green: col.querySelector('.green') }
    });
  };

  TrafficBoard.prototype.poll = function () {
    var self = this, t0 = Date.now();
    fetch(this.opts.url, { cache: 'no-store' })
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (s) {
        var t1 = Date.now();
        self.offset = s.now_ms - (t0 + t1) / 2; // assume symmetric latency
        self.state = s;
        self.lastOk = t1;
        if (self.opts.onUpdate) { self.opts.onUpdate(s); }
        self.render();
      })
      .catch(function () { /* keep counting down locally; render() decides when it is stale */ });
  };

  TrafficBoard.prototype.render = function () {
    if (!this.state) { return; }
    var stale = Date.now() - this.lastOk > STALE_MS;
    var serverNow = Date.now() + this.offset;
    this.banner.style.display = stale ? 'block' : 'none';
    var wanted = this.opts.road;
    var self = this;
    this.state.roads.forEach(function (road) {
      if (wanted !== 'all' && String(road.id) !== String(wanted)) { return; }
      var c = self.cell(road);
      var light = stale && road.light !== 'off' ? 'flash_orange' : road.light;
      c.name.textContent = road.name;
      c.el.className = 'signal state-' + light;
      c.lamps.red.className = 'lamp red' + (light === 'red' ? ' on' : '');
      c.lamps.orange.className = 'lamp orange' + (light === 'orange' || light === 'flash_orange' ? ' on' : '') + (light === 'flash_orange' ? ' blink' : '');
      c.lamps.green.className = 'lamp green' + (light === 'green' ? ' on' : '');
      c.text.textContent = TEXT[light];
      var count = '';
      if (!stale) {
        if (road.changes_at_ms && (light === 'green' || light === 'orange')) { count = fmt(road.changes_at_ms - serverNow); }
        else if (light === 'red' && road.green_at_ms) { count = fmt(road.green_at_ms - serverNow); }
      }
      c.count.textContent = count;
    });
  };

  window.TrafficBoard = TrafficBoard;
})();
