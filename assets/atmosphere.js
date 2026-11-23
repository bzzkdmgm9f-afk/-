'use strict';

// Procedural particle curtains; no external graphics runtime or network assets.
(() => {
  const canvas = document.getElementById('atmosphere-canvas');
  const context = canvas?.getContext('2d', {alpha: false});
  if (!context) return;
  const motion = matchMedia('(prefers-reduced-motion: reduce)');
  let timer = 0;
  let last = 0;
  let elapsed = 0;
  let ribbons = [];
  const bloom = document.createElement('canvas');
  const glowContext = bloom.getContext('2d');
  let narrow = false;
  let pixelScale = 1;

  // Each row follows the same folded surface. The brighter hems consist of
  // individual grains too, so the silhouette holds without a painted stroke.
  function makeRibbon(columns, rows, seed, distant) {
    function random() {
      seed = (seed * 16807) % 2147483647;
      return (seed - 1) / 2147483646;
    }
    const strands = [];
    for (let col = 0; col < columns; col++) {
      const points = [];
      for (let row = 0; row < rows; row++) {
        if (random() < .12) continue;
        const across = Math.max(0, Math.min(1, (row + (random() - .5) * .52) / (rows - 1)));
        const hem = Math.exp(-across * 15) + .66 * Math.exp((across - 1) * 16);
        const seam = .5 + .5 * Math.cos(across * Math.PI * 10);
        const glint = random();
        points.push({
          across, along: (random() - .5) * .88, scatter: random() - .5,
          intensity: (.23 + random() * .48 + .52 * hem) * (.63 + .37 * seam),
          radius: (.78 + random() * .76 + hem * .69) * (glint > .985 ? 1.7 : 1),
          colour: glint > .87 ? 2 : (across < .18 || (across > .75 && seam > .5) ? 1 : 0)
        });
      }
      strands.push(points);
    }
    return {strands, distant};
  }

  function resize() {
    narrow = innerWidth < 761;
    pixelScale = Math.min(devicePixelRatio || 1, 1.5, 1740 / innerWidth, 1250 / innerHeight);
    canvas.width = Math.max(1, Math.round(innerWidth * pixelScale));
    canvas.height = Math.max(1, Math.round(innerHeight * pixelScale));
    bloom.width = Math.max(1, Math.round(canvas.width / 12));
    bloom.height = Math.max(1, Math.round(canvas.height / 12));
    ribbons = narrow
      ? [makeRibbon(81, 17, 301, true), makeRibbon(166, 36, 5281, false)]
      : [makeRibbon(154, 18, 301, true), makeRibbon(370, 42, 5281, false)];
    draw();
    schedule();
  }

  function drawRibbon({strands, distant}, time) {
    const w = canvas.width, h = canvas.height;
    const silver = .5 + .5 * Math.sin(time * .09);
    const bodyColour = 'rgb(' + Math.round(131 + silver * 37) + ',198,' + Math.round(181 + silver * 10) + ')';
    const palette = distant ? ['#487c70', '#94b5a4', '#c0cabc'] : [bodyColour, '#c9f8df', '#fffee9'];
    const step = 1.36 / (strands.length - 1);
    const shift = distant ? .57 : 0;
    const phase = .17 * Math.sin(time * .11 + shift);
    const twistPhase = .4 + .32 * Math.sin(time * .094);
    const pleatPhase = .25 * Math.sin(time * .13);
    const height = distant ? .65 : 1;
    const grainScale = pixelScale * (distant ? .79 : 1);
    for (let col = 0; col < strands.length; col++) {
      const u = -.18 + col * step;
      const bend = u * (narrow ? 5.0 : 7.0) + phase + shift;
      const twist = u * 8.4 + twistPhase;
      const side = Math.cos(twist);

      const mid = (narrow ? .31 : .61) + (distant ? .34 : 0)
        + height * (.175 * Math.sin(bend - 1.08) + .083 * Math.sin(bend * 1.73 - .93) - .16 * u);
      const lift = height * (.138 + .031 * Math.sin(bend - .2));
      const sweep = .048 * Math.sin(twist);
      const facing = .73 + .25 * Math.cos(twist - .8);
      const flare = .79 + .21 * Math.pow(Math.cos(2.3 * bend + .18 * time), 2);
      const lateralCurve = .009 * Math.sin(bend);
      const pleatAngle = 1.36 * bend + pleatPhase;
      for (const grain of strands[col]) {
        const v = grain.across * 2 - 1;
        const pleat = (1 - v * v) * .045 * Math.sin(pleatAngle + v * 2);
        const x = w * (u + grain.along * step + sweep * v + lateralCurve * (1 - v * v));
        const y = h * (mid + lift * side * v + pleat + .0015 * grain.scatter);
        const size = grain.radius * grainScale;
        context.fillStyle = palette[grain.colour];
        context.globalAlpha = Math.min(1, grain.intensity * facing * flare * (distant ? .60 : 1.54));
        context.fillRect(x, y, size, size);
      }
    }
  }

  function draw() {
    const w = canvas.width, h = canvas.height;
    context.globalAlpha = 1;
    context.fillStyle = '#09100f';
    context.fillRect(0, 0, w, h);
    const glow = context.createRadialGradient(w * .78, h * .4, 0, w * .78, h * .4, Math.max(w * .61, h * .58));
    glow.addColorStop(0, '#193630');
    glow.addColorStop(.64, '#10211e');
    glow.addColorStop(1, '#09100f');
    context.fillStyle = glow;
    context.fillRect(0, 0, w, h);
    for (const ribbon of ribbons) drawRibbon(ribbon, elapsed);
    // Downsample the actual light from the grains, then add its soft reflection.
    // This keeps the glow on the folds when their width and position change.
    if (glowContext) {
      glowContext.drawImage(canvas, 0, 0, bloom.width, bloom.height);
      context.globalCompositeOperation = 'screen';
      context.globalAlpha = .39;
      context.drawImage(bloom, 0, 0, w, h);
      context.globalCompositeOperation = 'source-over';
    }
    context.globalAlpha = 1;
  }

  function schedule() {
    clearTimeout(timer);
    timer = 0;
    if (document.hidden || motion.matches) { last = 0; return; }
    timer = setTimeout(() => {
      const now = performance.now();
      if (last) elapsed += Math.min(now - last, 100) / 1000;
      last = now;
      draw();
      schedule();
    }, narrow ? 66 : 42);
  }

  motion.addEventListener('change', schedule);
  document.addEventListener('visibilitychange', schedule);
  let resizeTimer = 0;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(resize, 120);
  });
  window.addEventListener('pagehide', () => { clearTimeout(timer); clearTimeout(resizeTimer); });
  window.addEventListener('pageshow', schedule);
  resize();
})();
