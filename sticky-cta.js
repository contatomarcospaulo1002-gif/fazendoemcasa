(function () {
  var bar = document.getElementById("sticky-cta");
  var hero = document.getElementById("hero");
  if (!bar || !hero) return;

  var mq = window.matchMedia("(max-width: 899px)");

  function update() {
    if (!mq.matches) {
      bar.hidden = true;
      return;
    }
    var heroBottom = hero.getBoundingClientRect().bottom;
    bar.hidden = heroBottom > 80;
  }

  window.addEventListener("scroll", update, { passive: true });
  window.addEventListener("resize", update);
  if (mq.addEventListener) mq.addEventListener("change", update);
  else mq.addListener(update);
  update();
})();
