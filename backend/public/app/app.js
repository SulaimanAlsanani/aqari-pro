(()=>{
'use strict';
let accessToken=null,active='overview',user=null;
const $=id=>document.getElementById(id);
const sections=[
 ['overview','لوحة أملاكي'],['properties','العقارات'],['units','الوحدات'],['tenants','المستأجرون'],
 ['leases','العقود'],['dues','الأقساط'],['payments','المقبوضات'],['expenses','المصروفات'],
 ['tickets','الصيانة'],['documents','المرفقات']
];
const fields={
 properties:[['id','الرقم'],['name','العقار'],['city','المدينة'],['district','الحي'],['type','النوع'],['units_count','الوحدات']],
 units:[['id','الرقم'],['code','الوحدة'],['type','النوع'],['floor','الدور'],['area','المساحة']],
 tenants:[['id','الرقم'],['name','المستأجر'],['phone','الجوال'],['email','البريد']],
 leases:[['id','الرقم'],['unit_id','الوحدة'],['tenant_id','المستأجر'],['starts_on','البداية'],['ends_on','النهاية'],['total_amount','الإجمالي'],['status','الحالة']],
 dues:[['id','الرقم'],['lease_id','العقد'],['due_on','الاستحقاق'],['amount','القسط'],['paid_amount','المسدّد'],['balance_amount','المتبقي'],['payment_status','الحالة']],
 payments:[['id','الرقم'],['lease_id','العقد'],['amount','المبلغ'],['paid_on','التاريخ'],['method','الطريقة']],
 expenses:[['id','الرقم'],['property_id','العقار'],['category','التصنيف'],['amount','المبلغ'],['incurred_on','التاريخ']],
 tickets:[['id','الرقم'],['property_id','العقار'],['title','البلاغ'],['priority','الأولوية'],['status','الحالة']],
 documents:[['id','الرقم'],['entity_type','مرتبط بـ'],['entity_id','رقم السجل'],['original_name','الاسم'],['size','الحجم']]
};
const templates={
 properties:{endpoint:'/properties',fields:[['name','اسم العقار','text'],['city','المدينة','text'],['district','الحي','text'],['type','نوع العقار','choice','residential:سكني|commercial:تجاري|mixed:مختلط'],['address','العنوان','text']]},
 units:{endpoint:'/properties/{property_id}/units',fields:[['property_id','رقم العقار','number'],['code','رمز الوحدة','text'],['type','نوع الوحدة','text'],['floor','الدور','text'],['area','المساحة','number']]},
 tenants:{endpoint:'/tenants',fields:[['name','اسم المستأجر','text'],['phone','رقم الجوال','text'],['email','البريد','email']]},
 leases:{endpoint:'/leases',fields:[['unit_id','رقم الوحدة','number'],['tenant_id','رقم المستأجر','number'],['starts_on','تاريخ البداية','date'],['ends_on','تاريخ النهاية','date'],['total_amount','القيمة الإجمالية','number'],['deposit','التأمين','number'],['installments','عدد الأقساط (1 إلى 36)','number']]},
 payments:{endpoint:'/leases/{lease_id}/payments',fields:[['lease_id','رقم العقد','number'],['amount','مبلغ التحصيل','number'],['paid_on','تاريخ السداد','date'],['method','طريقة السداد','choice','bank_transfer:تحويل بنكي|cash:نقدي|card:بطاقة'],['reference','المرجع','text']]},
 expenses:{endpoint:'/properties/{property_id}/expenses',fields:[['property_id','رقم العقار','number'],['category','التصنيف','text'],['description','الوصف','text'],['amount','المبلغ','number'],['incurred_on','التاريخ','date']]},
 tickets:{endpoint:'/properties/{property_id}/tickets',fields:[['property_id','رقم العقار','number'],['unit_id','رقم الوحدة (اختياري)','number'],['title','وصف البلاغ','text'],['description','التفاصيل','text'],['priority','الأولوية','choice','low:منخفض|normal:عادي|high:عالٍ|urgent:عاجل'],['estimated_cost','التكلفة المتوقعة','number']]},
 documents:{endpoint:'/documents',fields:[['entity_type','نوع السجل','choice','property:عقار|unit:وحدة|tenant:مستأجر|lease:عقد|payment:سند قبض|due:استحقاق|expense:مصروف|ticket:صيانة'],['entity_id','رقم السجل','number'],['file','اختيار الملف','file']]}
};
function el(tag,text,className){
 const e=document.createElement(tag);if(text!==undefined&&text!==null)e.textContent=String(text);if(className)e.className=className;return e;
}
function notify(msg,error=false){const n=$('notice');n.textContent=msg;n.classList.toggle('error',error)}
function resetAuth(){
 accessToken=null;user=null;$('loginPanel').classList.remove('hidden');$('workspace').classList.add('hidden');$('logout').classList.add('hidden');$('password').value='';
}
async function api(path,options={}){
 if(!accessToken&&path!=='/login')throw Error('انتهت الجلسة؛ سجل الدخول مرة أخرى');
 const headers={'Accept':'application/json',...options.headers};
 if(accessToken)headers.Authorization='Bearer '+accessToken;
 let body=options.body;
 if(body&&!(body instanceof FormData)){headers['Content-Type']='application/json';body=JSON.stringify(body)}
 let response;
 try{response=await fetch('/api'+path,{...options,body,headers,credentials:'omit'})}
 catch{throw Error('تعذر الاتصال بخادم عقاري برو')}
 let data;try{data=await response.json()}catch{data={message:'تعذر قراءة استجابة الخادم'}}
 if(response.status===401&&path!=='/login'){resetAuth();throw Error('انتهت الجلسة، يرجى تسجيل الدخول')}
 if(!response.ok){
  const first=data.errors?Object.values(data.errors)[0]?.[0]:null;
  throw Error(first||data.message||'فشلت العملية: '+response.status)
 }
 return data;
}
function nav(){
 const n=$('nav');n.replaceChildren();
 sections.forEach(([key,label])=>{
  const b=el('button',label,key===active?'active':'');
  b.type='button';b.addEventListener('click',()=>{active=key;nav();refresh()});n.appendChild(b);
 });
}
function drawOverview(data){
 const parent=$('view');parent.replaceChildren();
 const metrics=el('div',null,'metrics');
 [['العقارات',data.properties],['الوحدات',data.units],['المستأجرون',data.tenants],['البلاغات المفتوحة',data.open_tickets],
  ['المقبوضات (ر.س)',data.received],['المصروفات (ر.س)',data.expenses],['صافي التدفق (ر.س)',data.net_cashflow]].forEach(([label,val])=>{
  const card=el('div',null,'metric');card.append(el('span',label,'muted'),el('strong',val));metrics.appendChild(card);
 });
 parent.appendChild(metrics);
 const info=el('div',null,'panel');
 info.append(el('h3','ملف المالك'),el('p','مرحبًا '+(user?.name||'مالك العقار')),el('p','تُقرأ البيانات من خادم العقارات الموثّق. لا تُحفظ كلمة المرور أو رمز الدخول في التخزين الدائم للمتصفح.','muted'));
 parent.appendChild(info);
}
function drawList(data,key){
 const parent=$('view');parent.replaceChildren();
 const row=el('div',null,'toolbar');
 const note=el('p','عدد السجلات: '+(data.total??data.data?.length??0),'muted');row.append(note);
 if(templates[key]){const add=el('button','+ إضافة سجل','primary');add.addEventListener('click',()=>drawForm(key));row.append(add)}
 parent.append(row);
 const wrap=el('div',null,'table-wrap'),table=el('table'),thead=el('thead'),tr=el('tr');
 for(const [,label] of fields[key])tr.appendChild(el('th',label));if(key==='documents'){tr.appendChild(el('th','الحالة'));tr.appendChild(el('th','الملف'))}
 thead.appendChild(tr);table.appendChild(thead);
 const body=el('tbody');
 for(const item of data.data||[]){
  const r=el('tr');
  for(const [field] of fields[key]){
   const value=item[field]??'—';
   r.appendChild(el('td',typeof value==='object'?'—':value));
  }
  if(key==='documents'){
   const state=el('td','✓ تم الإرفاق','tag');r.appendChild(state);
   const td=el('td'),b=el('button','تنزيل','secondary');
   b.addEventListener('click',()=>download(item.id,item.original_name));td.appendChild(b);r.appendChild(td);
  }
  body.appendChild(r);
 }
 table.appendChild(body);wrap.appendChild(table);parent.appendChild(wrap);
 if(!(data.data||[]).length)parent.appendChild(el('p','لا توجد سجلات في هذا القسم.','muted'));
 if(data.current_page&&data.last_page>1){
  const controls=el('div',null,'actions');
  const prev=el('button','السابق','secondary'),next=el('button','التالي','secondary');
  prev.disabled=data.current_page<=1;next.disabled=data.current_page>=data.last_page;
  prev.addEventListener('click',()=>refresh(data.current_page-1));next.addEventListener('click',()=>refresh(data.current_page+1));
  controls.append(prev,el('span','صفحة '+data.current_page+' من '+data.last_page,'muted'),next);parent.appendChild(controls);
 }
}
function drawForm(key){
 const parent=$('view');parent.replaceChildren();
 const config=templates[key],section=el('section',null,'panel'),heading=el('h3','إضافة '+sections.find(x=>x[0]===key)?.[1]);
 const form=el('form');form.enctype='multipart/form-data';
 for(const [name,label,type,values] of config.fields){
  const div=el('div',null,'field');const lab=el('label',label);lab.htmlFor='field-'+name;
  let input;
  if(type==='choice'){
   input=el('select');
   for(const str of values.split('|')){const [v,t]=str.split(':');const opt=el('option',t);opt.value=v;input.appendChild(opt)}
  }else{input=el('input');input.type=type==='file'?'file':type; if(type==='number'){input.min='0';input.step='any'}}
  input.name=name;input.id='field-'+name;
  if(!['district','address','email','deposit','floor','area','reference','description','unit_id','estimated_cost'].includes(name))input.required=true;
  if(type==='file')input.accept='.pdf,.png,.jpg,.jpeg,.webp,.doc,.docx,.xls,.xlsx,.txt,.csv';
  div.append(lab,input);form.appendChild(div);
 }
 const actions=el('div',null,'actions'),save=el('button','حفظ','primary');save.type='submit';
 const cancel=el('button','إلغاء','secondary');cancel.type='button';cancel.addEventListener('click',()=>refresh());
 actions.append(save,cancel);form.appendChild(actions);
 form.addEventListener('submit',async e=>{
  e.preventDefault();save.disabled=true;
  try{
   const raw=new FormData(form),payload={};
   raw.forEach((value,key)=>{
    if(value===''||value instanceof File&&value.size===0)return;
    payload[key]=value;
   });
   let url=config.endpoint;
   for(const place of ['property_id','lease_id'])if(url.includes('{'+place+'}')){url=url.replace('{'+place+'}',encodeURIComponent(payload[place]));delete payload[place]}
   if(key==='documents'){
    const upload=new FormData();
    for(const [k,v] of Object.entries(payload))upload.append(k,v);
    await api(url,{method:'POST',body:upload});
   }else{
    for(const numeric of ['unit_id','tenant_id','entity_id','total_amount','deposit','amount','installments','area','estimated_cost'])
     if(numeric in payload)payload[numeric]=Number(payload[numeric]);
    await api(url,{method:'POST',body:payload});
   }
   notify(key==='documents'?'✓ تم إرفاق الملف بنجاح':'تم حفظ السجل بنجاح');await refresh();
  }catch(err){notify(err.message,true)}finally{save.disabled=false}
 });
 section.append(heading,form);parent.appendChild(section);
}
async function download(id,name){
 try{
  const response=await fetch('/api/documents/'+encodeURIComponent(id),{headers:{Authorization:'Bearer '+accessToken},credentials:'omit'});
  if(!response.ok)throw Error('تعذر تنزيل الملف');
  const blob=await response.blob(),url=URL.createObjectURL(blob),a=el('a');
  a.href=url;a.download=name||'attachment';document.body.appendChild(a);a.click();a.remove();
  setTimeout(()=>URL.revokeObjectURL(url),4000);
 }catch(err){notify(err.message,true)}
}
async function refresh(page=1){
 $('sectionTitle').textContent=sections.find(x=>x[0]===active)?.[1]||'عقاري برو';
 const view=$('view');view.replaceChildren(el('p','جاري تحميل البيانات...','muted'));
 try{
  if(active==='overview'){drawOverview(await api('/overview'));return}
  const url=active==='properties'?'/portfolio':'/'+active;
  const data=await api(url+'?page='+page);
  drawList(active==='properties'?data.properties:data,active);
 }catch(err){view.replaceChildren(el('p',err.message,'muted'));notify(err.message,true)}
}
$('loginForm').addEventListener('submit',async e=>{
 e.preventDefault();const button=e.target.querySelector('button[type=submit]');button.disabled=true;
 try{
  const data=await api('/login',{method:'POST',body:{email:$('email').value,password:$('password').value}});
  accessToken=data.token;user=data.user;
  $('loginPanel').classList.add('hidden');$('workspace').classList.remove('hidden');$('logout').classList.remove('hidden');$('password').value='';
  active='overview';nav();notify('تم تسجيل الدخول');await refresh();
 }catch(err){notify(err.message,true)}finally{button.disabled=false}
});
$('logout').addEventListener('click',async()=>{
 try{await api('/logout',{method:'POST'})}catch{}
 resetAuth();notify('تم تسجيل الخروج');
});
$('reload').addEventListener('click',()=>refresh());
notify('النظام يتطلب خادم Laravel مفعّلًا؛ هذه واجهة الإنتاج وليست النسخة التجريبية العامة.');
})();
