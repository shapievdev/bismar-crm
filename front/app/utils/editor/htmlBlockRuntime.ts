/** Message type used by an embedded block to report its height. */
export const HTML_BLOCK_HEIGHT_MESSAGE = 'bismar:html-block-height'

/** Message type used when an in-page anchor inside a block is followed. */
export const HTML_BLOCK_SCROLL_MESSAGE = 'bismar:html-block-scroll'

/**
 * A tiny script appended to every embedded HTML block.
 *
 * It exists because the block runs in a sandbox with no `allow-same-origin`,
 * which is what keeps its scripts away from the host page. That isolation costs
 * two things, and this restores both:
 *
 *  - The parent cannot measure the block, so the block reports its own height.
 *  - A document loaded through `srcdoc` has the address `about:srcdoc`, and
 *    browsers resolve relative links against the *parent* URL. An in-page
 *    anchor like `#section` would therefore navigate the frame to the host
 *    application instead of scrolling — and since a sandboxed frame sends no
 *    session cookie, the reader would land on the login screen. Anchors are
 *    handled inside the document instead.
 */
export function htmlBlockRuntime(token: string): string {
  return `
<script>
(function () {
  var TOKEN = ${JSON.stringify(token)};

  document.addEventListener('click', function (event) {
    var anchor = event.target && event.target.closest && event.target.closest('a[href^="#"]');

    if (!anchor) {
      return;
    }

    event.preventDefault();

    var id = decodeURIComponent(anchor.getAttribute('href').slice(1));
    var target = id ? document.getElementById(id) : null;
    var offset = target
      ? target.getBoundingClientRect().top + (window.scrollY || 0)
      : 0;

    // The frame is usually sized to its content and so has no scrollbar of its
    // own; the parent has to move instead. It cannot measure this document
    // across the origin boundary, so the offset is handed over.
    parent.postMessage(
      { type: ${JSON.stringify('bismar:html-block-scroll')}, token: TOKEN, offset: offset },
      '*'
    );

    // Harmless when the frame does not scroll, useful when its height is pinned.
    if (target) {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }, true);

  var last = 0, queued = false;

  /*
   * Сколько места занимает содержимое.
   *
   * Не высота корневого элемента: она никогда не меньше видимой области, и
   * любой блок — хоть в две строки — возвращал ровно ту высоту, которую ему
   * дали. Высота «по содержимому» была ею только на словах: короткая разметка
   * всё равно вставала в опорные восемьсот точек.
   *
   * Поэтому считаем по самим детям тела: их нижний край плюс отступы тела.
   * Дети — а не прокрутка тела одним числом — потому что в блок кладут и
   * разметку, выложенную абсолютом: для неё тело не контейнер, и в его
   * прокрутку она не попадает, а в прямоугольники детей попадает.
   */
  function measure() {
    var body = document.body;

    if (!body) {
      return 0;
    }

    var offset = window.scrollY || 0, bottom = 0, kids = body.children, i, box;

    for (i = 0; i < kids.length; i++) {
      box = kids[i].getBoundingClientRect();

      if (box.width || box.height) {
        bottom = Math.max(bottom, box.bottom + offset);
      }
    }

    var edges = getComputedStyle(body);

    bottom += (parseFloat(edges.paddingBottom) || 0) + (parseFloat(edges.marginBottom) || 0);

    return Math.ceil(Math.max(body.scrollHeight, bottom));
  }

  function report() {
    queued = false;

    if (!document.body) {
      return;
    }

    var height = measure();

    // Ignore sub-pixel churn: a viewport-relative rule such as min-height:100vh
    // reacts to the height we just applied, and reporting every jitter would
    // make the two chase each other.
    if (Math.abs(height - last) < 8) {
      return;
    }

    last = height;

    /*
     * С замером уходят ещё два числа.
     *
     * overflow — прежний замер с запасом: им ловится разметка, вылезшая за
     * пределы тела способом, которого дети не показывают. Он осмыслен только
     * когда больше окна — ниже окна он всегда равен окну.
     *
     * viewport — окно, в котором замер снят: по нему снаружи видно, меряет
     * ли разметка себя по содержимому или по выданной рамке, а второй случай
     * закреплять пикселями нельзя.
     */
    parent.postMessage(
      {
        type: ${JSON.stringify(HTML_BLOCK_HEIGHT_MESSAGE)},
        token: TOKEN,
        height: height,
        overflow: Math.max(
          document.body.scrollHeight,
          document.documentElement ? document.documentElement.scrollHeight : 0
        ),
        viewport: window.innerHeight || 0
      },
      '*'
    );
  }

  /*
   * Замеры сходятся к одному на кадр.
   *
   * Поводов сообщить высоту много и они приходят пачками: наблюдатель за
   * размером тела срабатывает на каждом шаге чужой раскладки. Каждое сообщение
   * наружу — это ещё и отложенный пересчёт у страницы вокруг, и пачка из
   * двадцати подряд стоила бы ровно столько же, сколько один замер после них.
   */
  function schedule() {
    if (queued) {
      return;
    }

    queued = true;

    if (typeof requestAnimationFrame === 'function') {
      requestAnimationFrame(report);
    }
    else {
      setTimeout(report, 16);
    }
  }

  if (document.readyState === 'complete') {
    report();
  }

  window.addEventListener('load', schedule);
  window.addEventListener('resize', schedule);

  if (typeof ResizeObserver === 'function' && document.body) {
    new ResizeObserver(schedule).observe(document.body);
  }

  /*
   * Web fonts and images land after first paint and change the height.
   *
   * Эти три — напрямую, без кадра: во вкладке, открытой в фоне, кадров не
   * выдают вовсе, и блок, измеряемый только по ним, остался бы без высоты до
   * тех пор, пока на вкладку не посмотрят. Друг другу они не мешают: между
   * ними и так сотни миллисекунд.
   */
  setTimeout(report, 120);
  setTimeout(report, 600);
  setTimeout(report, 1800);
})();
<\/script>`
}
