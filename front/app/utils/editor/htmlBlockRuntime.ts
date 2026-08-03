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

  var last = 0;

  function report() {
    var height = Math.max(
      document.body ? document.body.scrollHeight : 0,
      document.documentElement ? document.documentElement.scrollHeight : 0
    );

    // Ignore sub-pixel churn: a viewport-relative rule such as min-height:100vh
    // reacts to the height we just applied, and reporting every jitter would
    // make the two chase each other.
    if (Math.abs(height - last) < 8) {
      return;
    }

    last = height;
    parent.postMessage({ type: ${JSON.stringify(HTML_BLOCK_HEIGHT_MESSAGE)}, token: TOKEN, height: height }, '*');
  }

  if (document.readyState === 'complete') {
    report();
  }

  window.addEventListener('load', report);
  window.addEventListener('resize', report);

  if (typeof ResizeObserver === 'function' && document.body) {
    new ResizeObserver(report).observe(document.body);
  }

  // Web fonts and images land after first paint and change the height.
  setTimeout(report, 120);
  setTimeout(report, 600);
  setTimeout(report, 1800);
})();
<\/script>`
}
