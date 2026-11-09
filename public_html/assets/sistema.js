(() => {
 'use strict';
 let layoutObserver=null;
 function measureLayout(){const header=document.querySelector('.app-header'),nav=document.querySelector('.app-navigation');if(header)document.documentElement.style.setProperty('--app-header-space',Math.ceil(header.getBoundingClientRect().height+14)+'px');const space=nav&&getComputedStyle(nav).position==='fixed'?Math.ceil(nav.getBoundingClientRect().height+12):12;document.documentElement.style.setProperty('--app-nav-space',space+'px');}
 const preferences=[['fontToggle','large-text'],['contrastToggle','high-contrast'],['motionToggle','reduce-motion']];
 function applyPreferences(){
  for(const [id,name] of preferences){let enabled=false;try{enabled=sessionStorage.getItem(name)==='true';}catch(_){}
   document.body.classList.toggle(name,enabled);document.documentElement.classList.toggle(name,enabled);
   document.getElementById(id)?.setAttribute('aria-pressed',String(enabled));
  }
 }
 function syncName(){const input=document.getElementById('anonymous'),name=document.getElementById('name');if(!input||!name)return;name.disabled=input.checked;if(input.checked)name.value='';const mic=document.querySelector('[data-dictate="name"]');if(mic)mic.disabled=input.checked;}
 function clearLocationError(){const state=document.getElementById('state'),city=document.getElementById('city'),error=document.getElementById('locationError');if(state&&city&&(state.value||!city.value.trim())){state.removeAttribute('aria-invalid');if(error)error.textContent='';}}
 function mount(){
  applyPreferences();const form=document.getElementById('setupForm');
  if(form){const steps=[...form.querySelectorAll('[data-step]')];let at=0;
   const show=(n,initial=false)=>{at=n;steps.forEach((element,i)=>element.hidden=i!==n);const legend=steps[n]?.querySelector('legend');if(legend)legend.tabIndex=-1;
    const label=document.getElementById('stepLabel');if(label)label.textContent=`Etapa ${n+1} de ${steps.length}`;
    const fill=document.getElementById('progressFill');if(fill)fill.style.width=`${(n+1)/steps.length*100}%`;
    if(!initial&&legend){legend.focus({preventScroll:true});legend.scrollIntoView({block:'start',behavior:'instant'});}
    window.dispatchEvent(new CustomEvent('rf:form-step',{detail:{step:n,initial}}));
   };
   form.rfShow=show;form.rfStep=()=>at;show(0,true);
   const error=document.getElementById('setupError');if(error){const invalid=form.querySelector('[aria-invalid="true"]');let atError=invalid?[...steps].findIndex(el=>el.contains(invalid)):0;if(atError<0)atError=0;if(atError>0)show(atError,true);(invalid||error).focus({preventScroll:true});}
  }
  syncName();measureLayout();if(window.ResizeObserver){layoutObserver?.disconnect();layoutObserver=new ResizeObserver(measureLayout);const header=document.querySelector('.app-header'),nav=document.querySelector('.app-navigation');if(header)layoutObserver.observe(header);if(nav)layoutObserver.observe(nav);}
 }
 function next(form){const at=form.rfStep();if(at===1){const city=form.querySelector('#city'),state=form.querySelector('#state');if(city.value.trim()&&!state.value){document.getElementById('locationError').textContent='Escolha o estado da cidade informada.';state.setAttribute('aria-invalid','true');state.focus();window.dispatchEvent(new CustomEvent('rf:validation',{detail:{message:'Escolha o estado da cidade informada.',targetId:'state'}}));return;}clearLocationError();}if(at<2)form.rfShow(at+1);}
 document.addEventListener('click',event=>{
  const button=event.target.closest('button');
  const skip=event.target.closest('a.skip');if(skip){event.preventDefault();const main=document.getElementById('conteudo');if(main){main.focus({preventScroll:true});main.scrollIntoView({block:'start',behavior:'instant'});}return;}
  if(!button)return;
  const pref=preferences.find(([id])=>id===button.id);
  if(pref){const name=pref[1],enabled=!document.body.classList.contains(name);try{sessionStorage.setItem(name,String(enabled));}catch(_){}applyPreferences();}
  if(button.id==='resetVisual'){try{for(const [,name] of preferences)sessionStorage.removeItem(name);}catch(_){}applyPreferences();window.dispatchEvent(new CustomEvent('rf:announce',{detail:{message:'Preferências visuais restauradas.'}}));}
  const form=button.closest('#setupForm');if(!form?.rfShow)return;
  if(button.id==='skipName'){form.querySelector('#name').value='';form.querySelector('#anonymous').checked=true;syncName();form.rfShow(1);}
  else if(button.hasAttribute('data-next-step'))next(form);
  else if(button.hasAttribute('data-prev-step'))form.rfShow(Math.max(0,form.rfStep()-1));
 });
 document.addEventListener('click',event=>{document.querySelectorAll('.access-menu[open]').forEach(menu=>{if(!menu.contains(event.target))menu.open=false;});});
 document.addEventListener('keydown',event=>{if(event.key!=='Escape')return;const menu=document.querySelector('.access-menu[open]');if(menu){menu.open=false;menu.querySelector('summary').focus();event.preventDefault();}});
 document.addEventListener('focusout',event=>{const menu=event.target.closest('.access-menu[open]');if(menu&&event.relatedTarget&&!menu.contains(event.relatedTarget))menu.open=false;});
 document.addEventListener('input',event=>{
  if(event.target.id==='city')clearLocationError();
  if(event.target.id!=='search')return;
  const normalize=text=>text.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();let count=0;
  document.querySelectorAll('[data-module]').forEach(card=>{card.hidden=!normalize(card.dataset.module).includes(normalize(event.target.value.trim()));if(!card.hidden)count++;});const empty=document.getElementById('noResults');if(empty)empty.hidden=count>0;
  window.dispatchEvent(new CustomEvent('rf:announce',{detail:{message:`${count} caminhos encontrados.`,delay:400}}));
 });
 document.addEventListener('change',event=>{if(event.target.id==='anonymous')syncName();if(event.target.id==='state')clearLocationError();});
 document.addEventListener('submit',event=>{const form=event.target;if(form.id==='setupForm'&&form.rfStep?.()<2){event.preventDefault();next(form);}},true);
 window.addEventListener('resize',measureLayout);
 document.addEventListener('focusin',event=>{const element=event.target;if(!(element instanceof HTMLElement)||element===document.body||element.id==='conteudo'||element.closest('.app-header,.app-navigation')||element.classList.contains('skip'))return;const rect=element.getBoundingClientRect(),header=document.querySelector('.app-header')?.getBoundingClientRect(),nav=document.querySelector('.app-navigation');const bottom=nav&&getComputedStyle(nav).position==='fixed'?nav.getBoundingClientRect().top:innerHeight;if(rect.top<(header?.bottom||0)+8||rect.bottom>bottom-8)element.scrollIntoView({block:'center',behavior:'instant'});});
 window.addEventListener('rf:mount',mount);mount();
})();
