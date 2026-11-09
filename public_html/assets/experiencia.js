(() => {
 'use strict';
 document.addEventListener('click',event=>{
  const print=event.target.closest('.print-guide');if(print){document.querySelectorAll('.source-details').forEach(el=>el.open=true);window.print();}
 });
})();
