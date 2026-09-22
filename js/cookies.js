(function(){
  const STORAGE_KEY='axia_cookie_consent_v2';
  const $=id=>document.getElementById(id);

  function ocultarBanner(){ $('cookieBanner')?.classList.remove('activo'); }
  function cerrarModal(){ $('cookieModal')?.classList.remove('activo'); }
  function cerrarTodo(){ ocultarBanner(); cerrarModal(); }

  function guardar(preferencias){
    localStorage.setItem(STORAGE_KEY,JSON.stringify({preferencias,fecha:new Date().toISOString()}));
    document.documentElement.dataset.cookies=JSON.stringify(preferencias);
    cerrarTodo();
    window.dispatchEvent(new CustomEvent('axiaConsentimientoCookies',{detail:preferencias}));
  }

  document.addEventListener('DOMContentLoaded',function(){
    const banner=$('cookieBanner');
    const modal=$('cookieModal');
    if(!banner || !modal) return;

    let guardado=null;
    try{ guardado=JSON.parse(localStorage.getItem(STORAGE_KEY)); }catch(e){}

    if(!guardado){
      setTimeout(()=>banner.classList.add('activo'),350);
    }else{
      document.documentElement.dataset.cookies=JSON.stringify(guardado.preferencias||{});
    }

    $('cookieAceptarTodo')?.addEventListener('click',()=>{
      ocultarBanner();
      guardar({necesarias:true,analiticas:true,personalizacion:true});
    });

    $('cookieRechazar')?.addEventListener('click',()=>{
      ocultarBanner();
      guardar({necesarias:true,analiticas:false,personalizacion:false});
    });

    $('cookieConfigurar')?.addEventListener('click',()=>{
      ocultarBanner();
      modal.classList.add('activo');
    });

    $('cookieGuardar')?.addEventListener('click',()=>guardar({
      necesarias:true,
      analiticas:!!$('cookieAnaliticas')?.checked,
      personalizacion:!!$('cookiePersonalizacion')?.checked
    }));

    $('cookieCerrar')?.addEventListener('click',()=>{
      cerrarModal();
      guardar({necesarias:true,analiticas:false,personalizacion:false});
    });

    modal.addEventListener('click',e=>{
      if(e.target===modal){
        cerrarModal();
        guardar({necesarias:true,analiticas:false,personalizacion:false});
      }
    });
  });
})();
