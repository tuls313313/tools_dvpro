(function(){
  function qs(sel, root){ return (root||document).querySelector(sel); }
  function qsa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

  // Mobile menu
  var btn=document.getElementById("hamburger");
  var menu=document.getElementById("mobile-menu");
  if(btn&&menu){
    var icon=btn.querySelector("i");
    function setOpen(open){
      menu.classList.toggle("open",open);
      if(open){menu.removeAttribute("hidden")}else{menu.setAttribute("hidden","")}
      btn.setAttribute("aria-expanded",open?"true":"false");
      document.body.classList.toggle("menu-open",open);
      if(icon){icon.classList.toggle("fa-bars",!open);icon.classList.toggle("fa-times",open)}
    }
    btn.addEventListener("click",function(){setOpen(!menu.classList.contains("open"))});
    qsa("a",menu).forEach(function(a){a.addEventListener("click",function(){setOpen(false)})});
    window.addEventListener("resize",function(){if(window.innerWidth>=768)setOpen(false)});
  }

  // Lazy stylesheet switcher replacement for FA css onload
  qsa('link[data-fa-defer="1"]').forEach(function(link){
    if(link.sheet){ link.media='all'; return; }
    link.addEventListener('load', function(){ link.media='all'; });
    setTimeout(function(){ try{ link.media='all'; }catch(e){} }, 1200);
  });

  // Tools search page
  var input=document.getElementById('tool-search');
  if(input){
    var cards=qsa('#tools-grid .tool-card');
    var empty=document.getElementById('tools-empty');
    var count=document.getElementById('tool-count');
    var params=new URLSearchParams(window.location.search);
    var q=params.get('q');
    if(q) input.value=q;
    var filter=function(){
      var keyword=input.value.trim().toLowerCase();
      var visible=0;
      cards.forEach(function(card){
        var h3=card.querySelector('h3');
        var p=card.querySelector('p');
        var hay=((card.dataset.keywords||'')+' '+(h3?h3.textContent:'')+' '+(p?p.textContent:'')).toLowerCase();
        var show=!keyword || hay.indexOf(keyword)!==-1;
        card.classList.toggle('hidden-card', !show);
        if(show) visible+=1;
      });
      if(empty) empty.style.display=visible?'none':'block';
      if(count) count.textContent=keyword?('Tìm thấy '+visible+' tool cho "'+input.value.trim()+'"'):('Đang hiển thị '+visible+' tools');
    };
    input.addEventListener('input', filter);
    filter();
  }

  window.dvproCopyText=function(id, btn){
    var el=document.getElementById(id);
    if(!el) return;
    var text=el.value || el.textContent || '';
    window.dvproCopyValue(text, btn);
  };

  window.dvproCopyValue=function(value, btn){
    var text=String(value||'');
    var done=function(){
      if(!btn) return;
      var old=btn.innerHTML;
      btn.innerHTML='<i class="fas fa-check"></i>';
      setTimeout(function(){ btn.innerHTML=old; }, 1200);
    };
    if(navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(text).then(done).catch(function(){ done(); });
    }else{
      done();
    }
  };

  function callIfFn(name, args){
    try{
      if(typeof window[name]==='function'){
        return window[name].apply(window, args||[]);
      }
    }catch(e){}
    return undefined;
  }

  // Event delegation for data-action buttons / dynamic content
  document.addEventListener('click', function(e){
    // mail address/code copy by data attributes on dynamic nodes
    var copyNode=e.target.closest('[data-copy]');
    if(copyNode){
      window.dvproCopyValue(copyNode.getAttribute('data-copy')||'', copyNode);
      return;
    }

    var t=e.target.closest('[data-action]');
    if(!t) return;
    var action=t.getAttribute('data-action');
    if(action==='copy-account'){
      var account=document.getElementById('account-number');
      if(account) window.dvproCopyValue(account.textContent.trim(), t);
    }else if(action==='copy-text'){
      window.dvproCopyText(t.getAttribute('data-target')||'', t);
    }else if(action==='clear-input'){
      var id=t.getAttribute('data-target')||'inputData';
      var el=document.getElementById(id);
      if(el){ el.value=''; el.focus && el.focus(); }
    }else if(action==='reset-form'){
      var form=document.getElementById(t.getAttribute('data-form')||'toolForm');
      if(form) form.reset();
      var box=document.getElementById(t.getAttribute('data-box')||'resultBox');
      if(box) box.innerHTML='';
    }else if(action==='copy-result'){
      window.dvproCopyText(t.getAttribute('data-target')||'resultOut', t);
    }else if(action==='call'){
      var fn=t.getAttribute('data-fn')||'';
      var arg=t.getAttribute('data-arg');
      if(arg!==null && arg!==undefined && arg!==''){ callIfFn(fn, [arg, t]); }
      else { callIfFn(fn, [t]); }
    }else if(action==='recheck'){
      var user=t.getAttribute('data-user')||'';
      callIfFn('recheck', [user, t]);
    }else if(action==='reread-mail'){
      var idx=parseInt(t.getAttribute('data-index')||'0',10)||0;
      callIfFn('rereadMailAccount', [idx]);
    }else if(action==='open-mail-detail'){
      callIfFn('openMailDetail', [t]);
    }else if(action==='close-mail-detail'){
      callIfFn('closeMailDetail');
    }else if(action==='toggle-mail-group'){
      var gid=t.getAttribute('data-group')||'';
      callIfFn('toggleMailGroup', [gid, t]);
    }else if(action==='view-logs'){
      var id=parseInt(t.getAttribute('data-id')||'0',10)||0;
      callIfFn('viewLogs', [id]);
    }else if(action==='hide-logs'){
      callIfFn('hideLogs');
    }else if(action==='copy-secret'){
      window.dvproCopyValue(t.getAttribute('data-secret')||'', t);
    }else if(action==='copy-value'){
      window.dvproCopyValue(t.getAttribute('data-value')||'', t);
    }
  });
})();