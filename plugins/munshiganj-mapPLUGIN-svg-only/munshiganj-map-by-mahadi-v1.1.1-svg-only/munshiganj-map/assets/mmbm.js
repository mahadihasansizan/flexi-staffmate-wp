(function(){
  var XLINK_NS = 'http://www.w3.org/1999/xlink';

  function ready(fn){
    if(document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function getAreaKey(el){
    return el.getAttribute('data-area') || (el.id ? el.id.replace('link-','') : '');
  }

  /** SVG <a> may use href alone or legacy xlink:href; keep both in sync so the correct URL is followed. */
  function setSvgAnchorHref(anchor, url){
    if(url){
      anchor.setAttribute('href', url);
      if(anchor.hasAttributeNS(XLINK_NS, 'href')){
        anchor.setAttributeNS(XLINK_NS, 'href', url);
      }
      anchor.removeAttribute('data-mmbm-nourl');
    }else{
      anchor.setAttribute('href', '#');
      if(anchor.hasAttributeNS(XLINK_NS, 'href')){
        anchor.removeAttributeNS(XLINK_NS, 'href');
      }
      anchor.setAttribute('data-mmbm-nourl','1');
    }
  }

  ready(function(){
    document.querySelectorAll('.mmbm-map-wrap').forEach(function(wrap){
      var tooltip = wrap.querySelector('.mmbm-tooltip');
      var svg = wrap.querySelector('svg');
      if(!tooltip || !svg) return;

      // Wire links + labels from admin settings (keys match SVG data-area / id link-<key>)
      svg.querySelectorAll('a.region').forEach(function(a){
        var key = getAreaKey(a);
        if(!key) return;

        var data = window.MMBM_MAP_DATA && window.MMBM_MAP_DATA[key] ? window.MMBM_MAP_DATA[key] : null;
        var url = data && data.url ? String(data.url).trim() : '';
        var label = data && data.label ? data.label : key;

        if(url){
          setSvgAnchorHref(a, url);
          a.setAttribute('target', '_blank');
          a.setAttribute('rel', 'noopener noreferrer');
        }else{
          setSvgAnchorHref(a, '');
          a.removeAttribute('target');
          a.removeAttribute('rel');
        }

        a.setAttribute('aria-label', label);
      });

      function showTooltip(text, x, y){
        tooltip.textContent = text || '';
        tooltip.style.left = x + 'px';
        tooltip.style.top = y + 'px';
        tooltip.classList.add('is-visible');
        tooltip.setAttribute('aria-hidden','false');
      }
      function hideTooltip(){
        tooltip.classList.remove('is-visible');
        tooltip.setAttribute('aria-hidden','true');
      }

      wrap.addEventListener('mousemove', function(e){
        var link = e.target.closest('a.region');
        if(!link) return;
        var key = getAreaKey(link);
        var data = window.MMBM_MAP_DATA && window.MMBM_MAP_DATA[key] ? window.MMBM_MAP_DATA[key] : null;
        var label = data && data.label ? data.label : key;

        var rect = wrap.getBoundingClientRect();
        var x = (e.clientX - rect.left) + 14;
        var y = (e.clientY - rect.top)  - 10;
        showTooltip(label, x, y);
      });

      wrap.addEventListener('mouseleave', hideTooltip);

      // Prevent click if empty url
      wrap.addEventListener('click', function(e){
        var link = e.target.closest('a.region');
        if(!link) return;
        if(link.getAttribute('data-mmbm-nourl') === '1'){
          e.preventDefault();
        }
      });
    });
  });
})();
