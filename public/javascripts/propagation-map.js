// Immunity propagation map: a circular layout of agent "cells" that periodically
// publish antibodies and ripple them out across the mesh. Pure SVG, no deps.
//
// Mount with:  new PropagationMap(document.getElementById('prop-map'), opts)
//
// Options:
//   nodeCount        : number of cells (default 60)
//   radius           : layout radius in viewBox units (default 280)
//   nodeRadius       : idle node radius (default 4.5)
//   palette          : color tokens (idle, line, publisher, receiver, offline)
//   onNodeClick(i, node) : optional callback fired when a node is clicked

const NS = 'http://www.w3.org/2000/svg';

const DEFAULTS = {
  nodeCount: 60,
  radius: 280,
  nodeRadius: 4.5,
  // Cadence between auto-triggered waves (ms range, picked uniformly).
  // Spec calls for 8-15s; we run faster on the landing for demo legibility.
  eventIntervalMs: [3500, 7500],
  waveDurationMs: 2400,
  ringCount: 3,
  ringStaggerMs: 220,
  // Initial delay before the first wave fires after start().
  firstEventDelayMs: 700,
  palette: {
    idleFill:    'rgba(232, 232, 227, 0.04)',
    idleStroke:  'rgba(232, 232, 227, 0.18)',
    publisher:   '#e8d4a0',
    receiver:    '#7ab87a',
    offline:     'rgba(58, 58, 52, 0.55)',
    centerHalo:  'rgba(232, 212, 160, 0.06)',
    spokeStroke: 'rgba(232, 232, 227, 0.04)',
  },
};

class PropagationMap {
  constructor(rootEl, opts = {}) {
    if (!rootEl) throw new Error('PropagationMap: rootEl is required');
    this.root = rootEl;
    this.opts = { ...DEFAULTS, ...opts, palette: { ...DEFAULTS.palette, ...(opts.palette || {}) } };
    this.nodes = [];
    this._buildSvg();
    this._buildBackdrop();
    this._buildNodes();
  }

  _buildSvg() {
    const pad = 30;
    const size = this.opts.radius * 2 + pad * 2;
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', `${-size / 2} ${-size / 2} ${size} ${size}`);
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    svg.setAttribute('width', '100%');
    svg.setAttribute('height', '100%');
    svg.style.display = 'block';
    svg.style.overflow = 'hidden';
    this.svg = svg;
    this.root.appendChild(svg);
  }

  _buildBackdrop() {
    const { radius, palette } = this.opts;

    // Concentric rings as a subtle "petri dish" backdrop.
    [0.55, 0.78, 1.0].forEach((scale) => {
      const ring = document.createElementNS(NS, 'circle');
      ring.setAttribute('cx', 0);
      ring.setAttribute('cy', 0);
      ring.setAttribute('r', radius * scale);
      ring.setAttribute('fill', 'none');
      ring.setAttribute('stroke', palette.spokeStroke);
      ring.setAttribute('stroke-width', '1');
      ring.style.pointerEvents = 'none';
      this.svg.appendChild(ring);
    });

    // Center halo (where antibody emanations originate visually).
    const halo = document.createElementNS(NS, 'circle');
    halo.setAttribute('cx', 0);
    halo.setAttribute('cy', 0);
    halo.setAttribute('r', radius * 0.12);
    halo.setAttribute('fill', palette.centerHalo);
    halo.setAttribute('stroke', 'none');
    halo.style.pointerEvents = 'none';
    this.svg.appendChild(halo);
  }

  _buildNodes() {
    const { nodeCount, radius, nodeRadius, palette } = this.opts;
    for (let i = 0; i < nodeCount; i++) {
      const angle = (i / nodeCount) * Math.PI * 2 - Math.PI / 2;
      const x = Math.cos(angle) * radius;
      const y = Math.sin(angle) * radius;

      // Outer hairline ring (the "membrane")
      const membrane = document.createElementNS(NS, 'circle');
      membrane.setAttribute('cx', x);
      membrane.setAttribute('cy', y);
      membrane.setAttribute('r', nodeRadius + 3);
      membrane.setAttribute('fill', 'none');
      membrane.setAttribute('stroke', palette.idleStroke);
      membrane.setAttribute('stroke-width', '0.6');
      membrane.style.transition = 'stroke 220ms ease-out, opacity 220ms ease-out, r 220ms ease-out';
      membrane.style.pointerEvents = 'none';
      this.svg.appendChild(membrane);

      // Inner core (the "nucleus")
      const core = document.createElementNS(NS, 'circle');
      core.setAttribute('cx', x);
      core.setAttribute('cy', y);
      core.setAttribute('r', nodeRadius);
      core.setAttribute('fill', palette.idleFill);
      core.setAttribute('stroke', palette.idleStroke);
      core.setAttribute('stroke-width', '0.8');
      core.style.transition = 'fill 280ms ease-out, stroke 280ms ease-out, r 280ms ease-out, opacity 280ms ease-out';
      core.style.pointerEvents = 'none';
      this.svg.appendChild(core);

      // Generous transparent hit area for click/hover (sits on top, captures pointer)
      const hit = document.createElementNS(NS, 'circle');
      hit.setAttribute('cx', x);
      hit.setAttribute('cy', y);
      hit.setAttribute('r', 14);
      hit.setAttribute('fill', 'transparent');
      hit.style.cursor = 'pointer';
      this.svg.appendChild(hit);

      const node = { i, x, y, angle, core, membrane, hit, offline: false };
      hit.addEventListener('click', () => {
        if (typeof this.opts.onNodeClick === 'function') this.opts.onNodeClick(node.i, node);
      });
      hit.addEventListener('mouseenter', () => this._setHover(node, true));
      hit.addEventListener('mouseleave', () => this._setHover(node, false));

      this.nodes.push(node);
    }
  }

  // ============================================================ public API

  /**
   * Auto-fire entry point. Kept for backwards compatibility with the older
   * landing-page demo; the dashboard drives waves manually via applyEvents,
   * so calling start() on the dashboard is a no-op.
   */
  start() {
    if (this._started || this.opts.autoFire === false) return;
    this._started = true;
    this._scheduleNext(this.opts.firstEventDelayMs);
  }

  stop() {
    this._started = false;
    if (this._nextTimer) clearTimeout(this._nextTimer);
  }

  /** Fire one random wave. Useful for tests and debug poking. */
  triggerNow() {
    const candidates = this.nodes.filter((n) => !n.offline);
    if (candidates.length === 0) return;
    this._emitFromPublisher(candidates[Math.floor(Math.random() * candidates.length)]);
  }

  /** Mark a node as offline (gray, skipped by waves) or back online. */
  setOffline(idx, offline) {
    const node = this.nodes[idx];
    if (!node) return;
    node.offline = !!offline;
    this._applyIdleVisual(node);
  }

  /**
   * Bind agent IDs to node indices. Pass an array of agent_id strings; the
   * order is the node order. Subsequent applyEvents calls reference agents
   * by id rather than by raw node index.
   */
  setAgentMap(agentIds) {
    this._agentMap = new Map();
    agentIds.forEach((id, idx) => {
      if (idx < this.nodes.length) this._agentMap.set(id, idx);
    });
  }

  /**
   * Set which agents are currently online. Anything not in `onlineSet`
   * goes gray; agents in the set come back to alive coloring.
   * `onlineSet` is an array or Set of agent_id strings.
   */
  setOnlineSet(onlineSet) {
    if (!this._agentMap) return;
    const set = onlineSet instanceof Set ? onlineSet : new Set(onlineSet);
    this._agentMap.forEach((idx, agentId) => {
      this.setOffline(idx, !set.has(agentId));
    });
  }

  /**
   * Pulse a node briefly without sending out a propagation wave. Used for
   * check_event activity (the agent looked something up, but no antibody
   * was published).
   */
  pulseNode(idxOrAgentId) {
    const idx = typeof idxOrAgentId === 'string'
      ? (this._agentMap ? this._agentMap.get(idxOrAgentId) : undefined)
      : idxOrAgentId;
    if (idx === undefined) return;
    const node = this.nodes[idx];
    if (!node || node.offline) return;
    this._flashReceiver(node);
  }

  /** Fire a propagation wave from a specific agent / node. */
  triggerWaveAt(idxOrAgentId) {
    const idx = typeof idxOrAgentId === 'string'
      ? (this._agentMap ? this._agentMap.get(idxOrAgentId) : undefined)
      : idxOrAgentId;
    if (idx === undefined) return;
    const node = this.nodes[idx];
    if (!node || node.offline) return;
    this._emitFromPublisher(node);
  }

  /**
   * Apply a batch of activity events. `checks` produce node pulses;
   * `blocks` produce full propagation waves. Each event is shaped
   * `{ agent_id, ... }`.
   */
  applyEvents({ checks = [], blocks = [] } = {}) {
    checks.forEach((c) => this.pulseNode(c.agent_id));
    blocks.forEach((b) => this.triggerWaveAt(b.agent_id));
  }

  // ========================================================== animation loop

  _scheduleNext(forceWaitMs) {
    const wait = forceWaitMs ?? this._randomInterval();
    if (this._nextTimer) clearTimeout(this._nextTimer);
    this._nextTimer = setTimeout(() => this._triggerWave(), wait);
  }

  _randomInterval() {
    const [min, max] = this.opts.eventIntervalMs;
    return min + Math.random() * (max - min);
  }

  _triggerWave() {
    if (!this._started) return;
    const candidates = this.nodes.filter((n) => !n.offline);
    if (candidates.length > 0) {
      const pub = candidates[Math.floor(Math.random() * candidates.length)];
      this._emitFromPublisher(pub);
    }
    this._scheduleNext();
  }

  // =================================================== publisher / receiver

  _emitFromPublisher(pub) {
    const { palette, waveDurationMs, ringCount, ringStaggerMs, nodeRadius } = this.opts;

    // Brighten publisher
    pub.core.setAttribute('fill', palette.publisher);
    pub.core.setAttribute('stroke', palette.publisher);
    pub.core.setAttribute('r', String(nodeRadius * 1.6));
    pub.membrane.setAttribute('stroke', palette.publisher);
    setTimeout(() => this._applyIdleVisual(pub), 800);

    // Expanding rings, staggered
    for (let k = 0; k < ringCount; k++) {
      setTimeout(() => this._emitRing(pub), k * ringStaggerMs);
    }

    // Receiver flashes timed by distance from publisher
    this.nodes.forEach((n) => {
      if (n.i === pub.i || n.offline) return;
      const dx = n.x - pub.x;
      const dy = n.y - pub.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      const maxDist = this.opts.radius * 2;
      const delay = (dist / maxDist) * (waveDurationMs * 0.65);
      setTimeout(() => this._flashReceiver(n), delay);
    });
  }

  _emitRing(pub) {
    const { palette, radius, waveDurationMs } = this.opts;
    const ring = document.createElementNS(NS, 'circle');
    ring.setAttribute('cx', pub.x);
    ring.setAttribute('cy', pub.y);
    ring.setAttribute('r', '6');
    ring.setAttribute('fill', 'none');
    ring.setAttribute('stroke', palette.publisher);
    ring.setAttribute('stroke-width', '1.4');
    ring.setAttribute('opacity', '0.55');
    ring.style.pointerEvents = 'none';
    ring.style.transition =
      `r ${waveDurationMs}ms cubic-bezier(0.2, 0.65, 0.25, 0.95),` +
      ` opacity ${waveDurationMs}ms ease-out,` +
      ` stroke-width ${waveDurationMs}ms ease-out`;
    this.svg.appendChild(ring);
    requestAnimationFrame(() => {
      ring.setAttribute('r', String(radius * 0.95));
      ring.setAttribute('opacity', '0');
      ring.setAttribute('stroke-width', '0.4');
    });
    setTimeout(() => ring.remove(), waveDurationMs + 120);
  }

  _flashReceiver(node) {
    if (node.offline) return;
    const { palette, nodeRadius } = this.opts;
    node.core.setAttribute('fill', palette.receiver);
    node.core.setAttribute('stroke', palette.receiver);
    node.core.setAttribute('r', String(nodeRadius * 1.4));
    node.membrane.setAttribute('stroke', palette.receiver);
    setTimeout(() => this._applyIdleVisual(node), 360);
  }

  // ============================================================ visuals helpers

  _applyIdleVisual(node) {
    const { palette, nodeRadius } = this.opts;
    if (node.offline) {
      node.core.setAttribute('fill', palette.offline);
      node.core.setAttribute('stroke', palette.offline);
      node.core.setAttribute('r', String(nodeRadius));
      node.core.setAttribute('opacity', '0.55');
      node.membrane.setAttribute('stroke', palette.offline);
      node.membrane.setAttribute('opacity', '0.4');
      node.membrane.setAttribute('r', String(nodeRadius + 3));
    } else {
      node.core.setAttribute('fill', palette.idleFill);
      node.core.setAttribute('stroke', palette.idleStroke);
      node.core.setAttribute('r', String(nodeRadius));
      node.core.removeAttribute('opacity');
      node.membrane.setAttribute('stroke', palette.idleStroke);
      node.membrane.removeAttribute('opacity');
      node.membrane.setAttribute('r', String(nodeRadius + 3));
    }
  }

  _setHover(node, hovering) {
    if (node.offline) return;
    const { nodeRadius, palette } = this.opts;
    if (hovering) {
      node.membrane.setAttribute('r', String(nodeRadius + 5));
      node.membrane.setAttribute('stroke', palette.publisher);
    } else {
      node.membrane.setAttribute('r', String(nodeRadius + 3));
      node.membrane.setAttribute('stroke', palette.idleStroke);
    }
  }
}

if (typeof window !== 'undefined') {
  window.PropagationMap = PropagationMap;
}
