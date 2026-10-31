// Casa Norte homepage motion: quiet fade-rise reveals as sections arrive.
// Elements are visible by default; the observer only animates when motion
// is welcome, so no content ever depends on this script to appear.
const revealTargets = document.querySelectorAll('[data-reveal]')
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches

if (!reducedMotion && 'IntersectionObserver' in window && revealTargets.length > 0) {
  document.documentElement.classList.add('cn-motion')
  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          entry.target.classList.add('cn-in')
          observer.unobserve(entry.target)
        }
      }
    },
    { threshold: 0.12 },
  )
  revealTargets.forEach((target) => observer.observe(target))
}
