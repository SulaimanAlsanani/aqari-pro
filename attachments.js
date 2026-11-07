(()=>{'use strict';
const DB_NAME='aqari-pro-local-attachments', STORE='files', MAX_FILE=10*1024*1024;
const extensions=new Set(['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','csv','txt']);
const entityNames={properties:'العقار',units:'الوحدة',tenants:'المستأجر',contracts:'العقد',dues:'الاستحقاق',payments:'سند القبض',expenses:'المصروف',tickets:'بلاغ الصيانة',vendors:'المورد',documents:'الوثيقة',inspections:'محضر الفحص',tasks:'المهمة'};
let databasePromise=null, selected=null;
function openDb(){
 if(!('indexedDB' in window))return Promise.reject(new Error('المتصفح لا يدعم تخزين الملفات المحلي'));
 if(databasePromise)return databasePromise;
 databasePromise=new Promise((resolve,reject)=>{
  const request=indexedDB.open(DB_NAME,1);
  request.onupgradeneeded=()=>{
   const db=request.result;
   if(!db.objectStoreNames.contains(STORE)){
    const store=db.createObjectStore(STORE,{keyPath:'id'});
    store.createIndex('record','record',{unique:false});
   }
  };
  request.onsuccess=()=>resolve(request.result);
  request.onerror=()=>reject(request.error||new Error('تعذر فتح مخزن المرفقات'));
 });
 databasePromise.catch(()=>{databasePromise=null});
 return databasePromise;
}
async function transaction(mode,action){
 const db=await openDb();
 return new Promise((resolve,reject)=>{
  const tx=db.transaction(STORE,mode), store=tx.objectStore(STORE);
  let result;
  try{result=action(store,tx)}catch(err){reject(err);return}
  tx.oncomplete=()=>resolve(result);
  tx.onerror=()=>reject(tx.error||new Error('فشلت عملية حفظ المرفق'));
  tx.onabort=()=>reject(tx.error||new Error('تم إلغاء العملية'));
 });
}
async function byRecord(record){
 const db=await openDb();
 return new Promise((resolve,reject)=>{
  const req=db.transaction(STORE,'readonly').objectStore(STORE).index('record').getAll(record);
  req.onsuccess=()=>resolve(req.result.sort((a,b)=>b.created.localeCompare(a.created)));
  req.onerror=()=>reject(req.error);
 });
}
async function getFile(id){
 const db=await openDb();
 return new Promise((resolve,reject)=>{
  const req=db.transaction(STORE,'readonly').objectStore(STORE).get(id);
  req.onsuccess=()=>resolve(req.result);
  req.onerror=()=>reject(req.error);
 });
}
const escapeText=v=>String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
function addButtons(){
 document.querySelectorAll('#content .record[data-attach-entity][data-attach-id]').forEach(record=>{
  if(record.querySelector('[data-attachment-open]'))return;
  const b=document.createElement('button');
  b.type='button';b.className='btn alt attachment-open';b.dataset.attachmentOpen='1';
  b.textContent='📎 المرفقات';b.style.marginTop='12px';b.style.fontSize='12px';
  b.dataset.entity=record.dataset.attachEntity;
  b.dataset.recordId=record.dataset.attachId;
  record.appendChild(b);
  updateButtonCount(b).catch(()=>{b.textContent='📎 المرفقات'});
 });
}
async function updateButtonCount(button){
 const {entity,recordId}=button.dataset;
 const files=await byRecord(entity+':'+recordId);
 if(!button.isConnected)return;
 button.textContent=files.length?'📎 مرفق ('+files.length+')':'📎 المرفقات';
 button.setAttribute('aria-label',files.length?'إدارة '+files.length+' مرفقات محفوظة':'إضافة مرفقات للسجل');
}
async function updateVisibleCounts(entity,id){
 const buttons=document.querySelectorAll('[data-attachment-open]');
 await Promise.all(Array.from(buttons).filter(b=>b.dataset.entity===entity&&b.dataset.recordId===id).map(updateButtonCount));
}
function confirmation(message){
 let toast=document.getElementById('attachmentToast');
 if(!toast){
  toast=document.createElement('div');toast.id='attachmentToast';
  toast.setAttribute('role','status');toast.setAttribute('aria-live','polite');
  toast.style.cssText='position:fixed;bottom:18px;left:18px;z-index:50;max-width:min(90vw,500px);padding:14px 18px;background:#eaf8ed;color:#174d32;border:1px solid #87bd99;border-radius:9px;box-shadow:0 5px 20px #0002;font-weight:700';
  document.body.appendChild(toast);
 }
 toast.textContent=message;
 toast.hidden=false;
}
function shell(){
 let el=document.getElementById('attachmentPanel');
 if(!el){
  el=document.createElement('div');el.id='attachmentPanel';document.body.appendChild(el);
 }
 return el;
}
function close(){
 shell().replaceChildren();selected=null;
}
async function show(entity,id){
 selected={entity,id:String(id)};
 const host=shell();
 host.innerHTML='<div class="modalbg" role="presentation"><section class="modal attachment-modal" role="dialog" aria-modal="true" aria-label="مرفقات السجل"><div class="toolbar"><h3>📎 مرفقات '+escapeText(entityNames[entity]||'السجل')+' #'+escapeText(id)+'</h3><button type="button" class="btn alt" data-attachment-close>إغلاق</button></div><div class="callout warn">المرفقات محفوظة محليًا في هذا المتصفح فقط؛ لا توجد مزامنة أو نسخ احتياطي سحابي. لا ترفع مستندات حقيقية أو حساسة في النسخة التجريبية العامة.</div><div class="field"><label for="attachmentInput">رفع مستندات (PDF، صور، Word، Excel، CSV أو TXT) — الحد 10 ميجابايت لكل ملف</label><input id="attachmentInput" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt"></div><div id="attachmentFeedback" class="muted" role="status"></div><div id="attachmentList" class="attachment-list"><p class="muted">جاري تحميل القائمة...</p></div></section></div>';
 await refresh();
}
function status(msg){const x=document.getElementById('attachmentFeedback');if(x)x.textContent=msg}
async function refresh(){
 if(!selected)return;
 const record=selected.entity+':'+selected.id;
 const list=document.getElementById('attachmentList');if(!list)return;
 try{
  const files=await byRecord(record);
  if(!list.isConnected||!selected||record!==selected.entity+':'+selected.id)return;
  list.replaceChildren();
  if(!files.length){const empty=document.createElement('p');empty.className='muted';empty.textContent='لا توجد مرفقات لهذا السجل بعد.';list.appendChild(empty);return}
  const heading=document.createElement('p');heading.className='muted';heading.textContent='عدد المرفقات: '+files.length;list.appendChild(heading);
  files.forEach(file=>{
   const r=document.createElement('div');r.className='attachment-item';
   const info=document.createElement('div');info.style.minWidth='0';
   const name=document.createElement('strong');name.textContent=file.name;name.style.overflowWrap='anywhere';
   const meta=document.createElement('div');meta.className='muted';meta.textContent=(file.size/1024/1024).toFixed(2)+' MB • '+new Date(file.created).toLocaleDateString('ar-SA');
   info.append(name,meta);
   const buttons=document.createElement('div');buttons.className='actions';
   const down=document.createElement('button');down.type='button';down.className='btn alt';down.textContent='تنزيل';down.dataset.attachmentDownload=file.id;
   const del=document.createElement('button');del.type='button';del.className='btn danger';del.textContent='حذف';del.dataset.attachmentDelete=file.id;
   if(/\.(pdf|png|jpg|jpeg|webp)$/i.test(file.name)){const preview=document.createElement('button');preview.type='button';preview.className='btn alt';preview.textContent='عرض';preview.dataset.attachmentPreview=file.id;buttons.appendChild(preview)}
   buttons.append(down,del);r.append(info,buttons);list.appendChild(r);
  });
 }catch(err){list.textContent='تعذر قراءة المرفقات: '+err.message}
}
async function upload(files){
 if(!selected)return;
 const target=selected.entity+':'+selected.id;
 let done=0,errors=[];
 for(const file of files){
  const ext=(file.name.split('.').pop()||'').toLowerCase();
  if(!extensions.has(ext)){errors.push(file.name+': نوع ملف غير مدعوم');continue}
  if(file.size===0||file.size>MAX_FILE){errors.push(file.name+': الملف فارغ أو يتجاوز 10 ميجابايت');continue}
  try{
   const id=crypto.randomUUID?crypto.randomUUID():Date.now()+'-'+Math.random().toString(36).slice(2);
   const item={id,record:target,name:file.name,type:file.type,size:file.size,created:new Date().toISOString(),blob:file};
   await transaction('readwrite',store=>{store.put(item)});
   done++;
  }catch(err){errors.push(file.name+': '+(err?.name==='QuotaExceededError'?'مساحة المتصفح ممتلئة':err.message))}
 }
 if(done>0){
  const current=selected;
  if(!errors.length){
   close();
   await updateVisibleCounts(current.entity,String(current.id));
   confirmation(done===1?'تم إرفاق الملف وحفظه في هذا المتصفح.':'تم إرفاق '+done+' ملفات وحفظها في هذا المتصفح.');
   return;
  }
  await updateVisibleCounts(current.entity,String(current.id));
 }
 status((done?'تم حفظ '+done+' ملف. ':'')+(errors.length?'تعذر إرفاق: '+errors.join(' | '):'لم يتم إرفاق أي ملف.'));
 await refresh();
}
async function downloadFile(id){
 const file=await getFile(id);if(!file||!file.blob)throw Error('الملف غير موجود');
 const url=URL.createObjectURL(file.blob),link=document.createElement('a');
 link.href=url;link.download=file.name;document.body.appendChild(link);link.click();link.remove();
 setTimeout(()=>URL.revokeObjectURL(url),3000);
}
async function previewFile(id){
 const file=await getFile(id);if(!file||!file.blob)throw Error('الملف غير موجود');
 if(!/\.(pdf|png|jpg|jpeg|webp)$/i.test(file.name))throw Error('نوع الملف لا يدعم العرض المباشر');
 const url=URL.createObjectURL(file.blob);
 const link=document.createElement('a');link.href=url;link.target='_blank';link.rel='noopener noreferrer';
 document.body.appendChild(link);link.click();link.remove();
 setTimeout(()=>URL.revokeObjectURL(url),60000);
}
async function deleteFile(id){
 const file=await getFile(id);if(!file)return;
 if(!confirm('حذف المرفق «'+file.name+'» نهائيًا من هذا المتصفح؟'))return;
 await transaction('readwrite',store=>{store.delete(id)});
 status('تم حذف المرفق.');await refresh();
 if(selected)await updateVisibleCounts(selected.entity,String(selected.id));
}
document.addEventListener('click',event=>{
 const btn=event.target.closest('[data-attachment-open],[data-attachment-close],[data-attachment-download],[data-attachment-delete],[data-attachment-preview]');
 if(!btn)return;
 event.preventDefault();event.stopPropagation();
 if(btn.hasAttribute('data-attachment-close')){close();return}
 if(btn.hasAttribute('data-attachment-open')){show(btn.dataset.entity,btn.dataset.recordId).catch(e=>status(e.message));return}
 if(btn.dataset.attachmentPreview){previewFile(btn.dataset.attachmentPreview).catch(e=>status(e.message));return}
 if(btn.dataset.attachmentDownload){downloadFile(btn.dataset.attachmentDownload).catch(e=>status(e.message));return}
 if(btn.dataset.attachmentDelete)deleteFile(btn.dataset.attachmentDelete).catch(e=>status(e.message));
},true);
document.addEventListener('change',event=>{
 if(event.target.id!=='attachmentInput')return;
 const files=Array.from(event.target.files||[]);event.target.value='';
 upload(files).catch(e=>status(e.message));
});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&selected)close()});
const content=document.getElementById('content');
if(content){new MutationObserver(addButtons).observe(content,{childList:true});addButtons()}
window.AqariAttachments={clearAll:()=>transaction('readwrite',store=>{store.clear()})};
})();