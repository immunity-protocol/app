// Immunity propagation map: a circular layout of agent "cells" that periodically
// publish antibodies and ripple them out across the mesh. Pure SVG, no deps.
//
// Mount with:  new PropagationMap(document.getElementById('prop-map'), opts)
//
// Options:
//   nodeCount        : number of cells (default 60)
//   radius           : layout radius in viewBox units (default 280)
//   nodeRadius       : idle node radius (default 4.5)
//   palette          : color tokens (idle, line, publisher, receiver, killed)

const NS = 'http://www.w3.org/2000/svg';

const DEFAULTS = {
  nodeCount: 60,
  radius: 280,
  nodeRadius: 4.5,
  palette: {
    idleFill:    'rgba(232, 232, 227, 0.04)',
    idleStroke:  'rgba(232, 232, 227, 0.18)',
    publisher:   '#e8d4a0',
    receiver:    '#7ab87a',
    killed:      'rgba(58, 58, 52, 0.55)',
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
    const pad = 40;
    const size = this.opts.radius * 2 + pad * 2;
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', `${-size / 2} ${-size / 2} ${size} ${size}`);
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    svg.setAttribute('width', '100%');
    svg.setAttribute('height', '100%');
    svg.style.display = 'block';
    svg.style.overflow = 'visible';
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
      this.svg.appendChild(ring);
    });

    // Center halo (where antibody emanations originate visually).
    const halo = document.createElementNS(NS, 'circle');
    halo.setAttribute('cx', 0);
    halo.setAttribute('cy', 0);
    halo.setAttribute('r', radius * 0.12);
    halo.setAttribute('fill', palette.centerHalo);
    halo.setAttribute('stroke', 'none');
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
      this.svg.appendChild(membrane);

      // Inner core (the "nucleus")
      const core = document.createElementNS(NS, 'circle');
      core.setAttribute('cx', x);
      core.setAttribute('cy', y);
      core.setAttribute('r', nodeRadius);
      core.setAttribute('fill', palette.idleFill);
      core.setAttribute('stroke', palette.idleStroke);
      core.setAttribute('stroke-width', '0.8');
      core.style.transition = 'fill 280ms ease-out, stroke 280ms ease-out, r 280ms ease-out';
      this.svg.appendChild(core);

      this.nodes.push({ i, x, y, angle, core, membrane, killed: false });
    }
  }
}

if (typeof window !== 'undefined') {
  window.PropagationMap = PropagationMap;
}
