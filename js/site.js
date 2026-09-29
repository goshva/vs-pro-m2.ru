/* Motion layer. Content is fully visible without this file; hidden states are set only here.
   Nothing pins or hijacks the scroll: every effect follows the page as it moves. */
(() => {
  const d = document.documentElement;
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => [...c.querySelectorAll(s)];
  const RM = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const FINE = matchMedia('(hover: hover) and (pointer: fine)').matches;
  const G = window.gsap && window.ScrollTrigger;
  const ready = () => d.classList.add('ready');

  /* ---------- menu ---------- */
  let lenis = null;
  const menuBtn = $('#menuBtn'), overlay = $('#overlay');
  const setMenu = open => {
    d.classList.toggle('menu-open', open);
    menuBtn.setAttribute('aria-expanded', open);
    menuBtn.setAttribute('aria-label', open ? 'Закрыть меню' : 'Меню');
    overlay.setAttribute('aria-hidden', !open);
    if (lenis) open ? lenis.stop() : lenis.start();
    else document.body.style.overflow = open ? 'hidden' : '';
  };
  menuBtn.addEventListener('click', () => setMenu(!d.classList.contains('menu-open')));
  addEventListener('keydown', e => { if (e.key === 'Escape' && d.classList.contains('menu-open')) setMenu(false); });
  $$('#overlay a').forEach(a => a.addEventListener('click', () => setMenu(false)));
  matchMedia('(min-width: 1181px)').addEventListener('change', e => { if (e.matches) setMenu(false); });

  if (!G || RM) { ready(); return; }

  gsap.registerPlugin(ScrollTrigger);
  if (window.SplitText) gsap.registerPlugin(SplitText);
  const split = (el, opts) => window.SplitText ? SplitText.create(el, opts) : null;

  /* ---------- smooth scroll (wheel only; touch stays native) ---------- */
  if (window.Lenis) {
    lenis = new Lenis({ lerp: 0.11, smoothWheel: true });
    lenis.on('scroll', ScrollTrigger.update);
    gsap.ticker.add(t => lenis.raf(t * 1000));
    gsap.ticker.lagSmoothing(0);
  }
  $$('a[href^="#"]').forEach(a => a.addEventListener('click', e => {
    const id = a.getAttribute('href');
    const t = id.length > 1 ? $(id) : null;
    if (id.length > 1 && !t) return;
    e.preventDefault();
    const go = () => lenis ? lenis.scrollTo(t || 0, { offset: t ? -70 : 0, duration: 1.2 }) : (t ? t.scrollIntoView({ behavior: 'smooth' }) : scrollTo({ top: 0, behavior: 'smooth' }));
    d.classList.contains('menu-open') ? setTimeout(go, 300) : go();
  }));

  /* header hides on the way down, returns on the way up */
  const header = $('header');
  let lastY = 0;
  ScrollTrigger.create({ onUpdate: s => {
    const y = s.scroll();
    if (Math.abs(y - lastY) < 4) return;
    header.classList.toggle('hide', y > lastY && y > 400 && !d.classList.contains('menu-open'));
    lastY = y;
  }});

  /* magnetic buttons (mouse only) */
  if (FINE) $$('.magnetic').forEach(el => {
    const mx = gsap.quickTo(el, 'x', { duration: .6, ease: 'power3' }), my = gsap.quickTo(el, 'y', { duration: .6, ease: 'power3' });
    el.addEventListener('pointermove', e => {
      const r = el.getBoundingClientRect();
      mx((e.clientX - r.left - r.width / 2) * .25); my((e.clientY - r.top - r.height / 2) * .35);
    });
    el.addEventListener('pointerleave', () => gsap.to(el, { x: 0, y: 0, duration: .9, ease: 'elastic.out(1,.4)' }));
  });

  const mm = gsap.matchMedia();

  /* ---------- hero entrance ---------- */
  const hs = split('#heroTitle', { type: 'lines', mask: 'lines' });
  const intro = gsap.timeline({ defaults: { ease: 'expo.out' }, delay: .1 });
  intro
    .fromTo('.photo', { clipPath: 'inset(100% 0% 0% 0% round 260px 260px 30px 30px)' }, { clipPath: 'inset(0% 0% 0% 0% round 260px 260px 30px 30px)', duration: 1.5, ease: 'expo.inOut' }, 0)
    .from('.photo img', { scale: 1.3, duration: 2, ease: 'expo.out' }, .25)
    .from('.pill', { y: 16, opacity: 0, duration: 1 }, .2);
  if (hs) intro.from(hs.lines, { yPercent: 105, duration: 1.3, stagger: .1 }, .3);
  intro
    .from('.hero-copy [data-h]:not(.pill)', { y: 28, opacity: 0, duration: 1.2, stagger: .1 }, .6)
    .from('.float', { y: 30, opacity: 0, scale: .92, duration: 1.1, stagger: .15 }, 1)
    .from('header', { y: -20, opacity: 0, duration: 1 }, .2)
    .add(() => gsap.set('header', { clearProps: 'transform,opacity' }));
  ready();
  $$('.trust b[data-count]').forEach(b => {
    const end = +b.dataset.count, suf = b.dataset.suffix || '', o = { v: 0 };
    gsap.to(o, { v: end, duration: 2, delay: .9, ease: 'power3.out', onUpdate: () => { b.textContent = Math.round(o.v) + suf; } });
  });

  /* gentle depth on scroll; floating cards follow the mouse a little */
  gsap.to('.photo img', { yPercent: 6, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: true } });
  gsap.to('.f1', { y: -50, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: true } });
  gsap.to('.f2', { y: -90, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: true } });
  if (FINE) {
    const floats = $$('.float').map((f, i) => ({ x: gsap.quickTo(f, 'x', { duration: 1.2, ease: 'power3' }), k: i ? -14 : 12 }));
    $('.hero').addEventListener('pointermove', e => {
      const nx = e.clientX / innerWidth - .5;
      floats.forEach(f => f.x(nx * f.k));
    });
  }

  /* ---------- manifest ---------- */
  const ms = split('.manifest-text', { type: 'lines', mask: 'lines' });
  if (ms) gsap.from(ms.lines, { yPercent: 105, duration: 1.2, ease: 'expo.out', stagger: .08, scrollTrigger: { trigger: '.manifest-text', start: 'top 82%' } });
  gsap.from('.manifest-sign', { y: 24, opacity: 0, duration: 1.2, ease: 'expo.out', scrollTrigger: { trigger: '.manifest-sign', start: 'top 92%' } });

  /* ---------- generic reveals ---------- */
  $$('[data-split]').forEach(el => {
    const s = split(el, { type: 'lines', mask: 'lines' });
    if (s) gsap.from(s.lines, { yPercent: 105, duration: 1.2, ease: 'expo.out', stagger: .08, scrollTrigger: { trigger: el, start: 'top 86%' } });
  });
  $$('[data-fade]').forEach(el => gsap.from(el, { y: 40, opacity: 0, duration: 1.2, ease: 'expo.out', scrollTrigger: { trigger: el, start: 'top 90%' } }));

  /* principles: the statement corrects itself as you read it */
  const sq = $('#statement .sq'), line = $('#statement .line'), scrib = $$('#statement .scribble path');
  gsap.set(scrib, { strokeDasharray: 1, strokeDashoffset: 1 });
  gsap.from('#statement', { y: 40, opacity: 0, duration: 1.2, ease: 'expo.out', scrollTrigger: { trigger: '#statement', start: 'top 88%' } });
  gsap.timeline({ scrollTrigger: { trigger: '#statement', start: 'top 80%', end: 'top 35%', scrub: .6 } })
    .fromTo(line, { scaleX: 0, rotation: -3 }, { scaleX: 1, rotation: -3, ease: 'none', duration: 1 })
    .to(sq, { color: '#aaa196', ease: 'none', duration: 1 }, 0)
    .to(scrib, { strokeDashoffset: 0, stagger: .3, ease: 'none', duration: .8 }, .7);

  gsap.fromTo('.about-img', { clipPath: 'inset(100% 0% 0% 0% round 28px)' }, {
    clipPath: 'inset(0% 0% 0% 0% round 28px)', duration: 1.5, ease: 'expo.inOut', scrollTrigger: { trigger: '.about-img', start: 'top 82%' } });
  gsap.fromTo('.about-img img', { yPercent: -6, scale: 1.12 }, { yPercent: 2, scale: 1, ease: 'none', scrollTrigger: { trigger: '.about-img', start: 'top bottom', end: 'bottom top', scrub: true } });
  gsap.from('.seal', { scale: .4, rotation: -90, opacity: 0, duration: 1.4, ease: 'back.out(1.6)', scrollTrigger: { trigger: '.about-img', start: 'top 60%' } });
  const rows = $$('.principles li');
  gsap.from(rows, { x: -30, opacity: 0, duration: 1.1, ease: 'expo.out', stagger: .1, scrollTrigger: { trigger: '.principles', start: 'top 85%' } });
  rows.forEach(li => ScrollTrigger.create({ trigger: li, start: 'top 72%', onEnter: () => li.classList.add('on'), onLeaveBack: () => li.classList.remove('on') }));

  /* services: sticky counter rolls 01 → 03 while the stage cards pass by */
  const stageBox = $('#stages'), stageCards = $$('.stage'), rollT = $('.roll-track'), titles = $$('.side-title .st'), dots = $$('.side-dots b');
  const setStage = i => {
    rollT.style.transform = `translateY(${-i * 1.2}em)`;
    titles.forEach((t, k) => t.classList.toggle('on', k === i));
    dots.forEach((t, k) => t.classList.toggle('on', k === i));
    stageCards.forEach((c, k) => c.classList.toggle('active', k === i));
  };
  stageCards.forEach((c, i) => {
    gsap.from(c, { y: 70, opacity: 0, duration: 1.2, ease: 'expo.out', scrollTrigger: { trigger: c, start: 'top 88%' } });
    gsap.from($$('li', c), { x: -18, opacity: 0, duration: .9, ease: 'expo.out', stagger: .06, scrollTrigger: { trigger: c, start: 'top 72%' } });
    ScrollTrigger.create({ trigger: c, start: 'top 55%', end: 'bottom 55%', onToggle: s => { if (s.isActive) setStage(i); } });
  });
  setStage(0);
  mm.add('(min-width: 901px)', () => {
    stageBox.classList.add('live');
    gsap.fromTo('.side-bar i', { scaleY: 0 }, { scaleY: 1, ease: 'none', scrollTrigger: { trigger: '.stages-list', start: 'top 55%', end: 'bottom 55%', scrub: true } });
    ScrollTrigger.refresh();
    return () => stageBox.classList.remove('live');
  });

  /* approach */
  gsap.from('.sc', { y: 50, opacity: 0, duration: 1.2, ease: 'expo.out', stagger: .1, scrollTrigger: { trigger: '.adv', start: 'top 85%' } });
  $$('.sc').forEach(c => {
    gsap.from($('.sc-word .w', c), { yPercent: 105, duration: 1.3, ease: 'expo.out', delay: .2, scrollTrigger: { trigger: c, start: 'top 85%' } });
    const strike = $('.strike', c);
    if (strike) gsap.fromTo(strike, { scaleX: 0, transformOrigin: 'left center' }, { scaleX: 1, duration: .9, ease: 'expo.inOut', delay: .8, scrollTrigger: { trigger: c, start: 'top 85%' } });
  });

  /* process: the house draws itself while you read the steps */
  const steps = $$('#steps li');
  gsap.set($$('.house path,.house rect,.house circle'), { strokeDasharray: 1, strokeDashoffset: 1 });
  gsap.set('.house .key', { opacity: 0, rotate: -100, svgOrigin: '372 262' });
  const wide = matchMedia('(min-width: 901px)').matches;
  const draw = gsap.timeline({ scrollTrigger: wide
    ? { trigger: '#steps', start: 'top 70%', end: 'bottom 55%', scrub: .6 }
    : { trigger: '.house', start: 'top 90%', end: 'bottom 45%', scrub: .6 } });
  ['.g1', '.g2', '.g3', '.g4'].forEach((g, i) => draw.to($$(`.house ${g} path,.house ${g} rect,.house ${g} circle`), { strokeDashoffset: 0, duration: 1, stagger: .12, ease: 'none' }, i));
  draw.to('.house .key', { opacity: 1, rotate: 0, duration: .7, ease: 'back.out(2)' }, 3.8)
      .to('.house .key circle,.house .key path', { strokeDashoffset: 0, duration: .5 }, 3.8);
  steps.forEach(li => ScrollTrigger.create({ trigger: li, start: 'top 75%', onEnter: () => li.classList.add('on'), onLeaveBack: () => li.classList.remove('on') }));

  /* team */
  gsap.fromTo('.team-img', { clipPath: 'inset(0% 100% 0% 0% round 30px)' }, { clipPath: 'inset(0% 0% 0% 0% round 30px)', duration: 1.5, ease: 'expo.inOut', scrollTrigger: { trigger: '.team-img', start: 'top 82%' } });
  gsap.fromTo('.team-img img', { yPercent: -5 }, { yPercent: 5, ease: 'none', scrollTrigger: { trigger: '.team-img', start: 'top bottom', end: 'bottom top', scrub: true } });

  if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => ScrollTrigger.refresh());
  addEventListener('load', () => ScrollTrigger.refresh());
})();
