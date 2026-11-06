(()=>{
'use strict';
let accessToken=null,active='overview',user=null;
const $=id=>document.getElementById(id);
const sections=[
 ['overview','لوحة أملاكي'],['alerts','التنبيهات'],['properties','العقارات'],['units','الوحدات'],['tenants','المستأجرون'],
 ['leases','العقود'],['dues','الأقساط'],['payments','المقبوضات'],['expenses','المصروفات'],
 ['tickets','الصيانة'],['vendors','الموردون'],['documents','المرفقات'],['reports','التقارير المالية'],['audit','سجل العمليات'],['account','حسابي']
];
const fields={
 properties:[['id','الرقم'],['name','العقار'],['city','المدينة'],['district','الحي'],['type','النوع'],['units_count','الوحدات']],
 units:[['id','الرقم'],['code','الوحدة'],['type','النوع'],['floor','الدور'],['area','المساحة']],
 tenants:[['id','الرقم'],['name','المستأجر'],['phone','الجوال'],['email','البريد']],
 leases:[['id','الرقم'],['unit_id','الوحدة'],['tenant_id','المستأجر'],['starts_on','البداية'],['ends_on','النهاية'],['total_amount','الإجمالي'],['status','الحالة']],
 dues:[['id','الرقم'],['lease_id','العقد'],['due_on','الاستحقاق'],['amount','القسط'],['paid_amount','المسدّد'],['balance_amount','المتبقي'],['payment_status','الحالة']],
 payments:[['id','الرقم'],['lease_id','العقد'],['amount','المبلغ'],['paid_on','التاريخ'],['method','الطريقة'],['voided_at','تاريخ الإلغاء'],['void_reason','سبب الإلغاء']],
 expenses:[['id','الرقم'],['property_id','العقار'],['category','التصنيف'],['amount','المبلغ'],['incurred_on','التاريخ']],
 tickets:[['id','الرقم'],['property_id','العقار'],['title','البلاغ'],['priority','الأولوية'],['status','الحالة'],['vendor_id','المورد']],
 vendors:[['id','الرقم'],['name','المورد'],['service','الخدمة'],['phone','الجوال'],['email','البريد']],
 documents:[['id','الرقم'],['entity_type','مرتبط بـ'],['entity_id','رقم السجل'],['original_name','الاسم'],['size','الحجم']],
 audit:[['created_at','التاريخ'],['entity_type','القسم'],['entity_id','رقم السجل'],['action','العملية']]
};
const templates={
 properties:{endpoint:'/properties',fields:[['name','اسم العقار','text'],['city','المدينة','text'],['district','الحي','text'],['type','نوع العقار','choice','residential:سكني|commercial:تجاري|mixed:مختلط'],['address','العنوان','text']]},
 units:{endpoint:'/properties/{property_id}/units',fields:[['property_id','رقم العقار','number'],['code','رمز الوحدة','text'],['type','نوع الوحدة','text'],['floor','الدور','text'],['area','المساحة','number']]},
 tenants:{endpoint:'/tenants',fields:[['name','اسم المستأجر','text'],['phone','رقم الجوال','text'],['email','البريد','email']]},
 leases:{endpoint:'/leases',fields:[['unit_id','رقم الوحدة','number'],['tenant_id','رقم المستأجر','number'],['starts_on','تاريخ البداية','date'],['ends_on','تاريخ النهاية','date'],['total_amount','القيمة الإجمالية','number'],['deposit','التأمين','number'],['installments','عدد الأقساط (1 أو 2 أو 4 أو 12)','choice','1:سنوي|2:نصف سنوي|4:ربع سنوي|12:شهري']]},
 payments:{endpoint:'/leases/{lease_id}/payments',fields:[['lease_id','رقم العقد','number'],['amount','مبلغ التحصيل','number'],['paid_on','تاريخ السداد','date'],['method','طريقة السداد','choice','bank_transfer:تحويل بنكي|cash:نقدي|card:بطاقة'],['reference','المرجع','text']]},
 expenses:{endpoint:'/properties/{property_id}/expenses',fields:[['property_id','رقم العقار','number'],['category','التصنيف','text'],['description','الوصف','text'],['amount','المبلغ','number'],['incurred_on','التاريخ','date']]},
 tickets:{endpoint:'/properties/{property_id}/tickets',fields:[['property_id','رقم العقار','number'],['unit_id','رقم الوحدة (اختياري)','number'],['vendor_id','رقم المورد (اختياري)','number'],['title','وصف البلاغ','text'],['description','التفاصيل','text'],['priority','الأولوية','choice','low:منخفض|normal:عادي|high:عالٍ|urgent:عاجل'],['estimated_cost','التكلفة المتوقعة','number']]},
 vendors:{endpoint:'/vendors',fields:[['name','اسم المورد','text'],['service','نوع الخدمة','text'],['phone','الجوال','text'],['email','البريد','email'],['notes','ملاحظات','text']]},
 documents:{endpoint:'/documents',fields:[['entity_type','نوع السجل','choice','owner:المالك|property:عقار|unit:وحدة|tenant:مستأجر|lease:عقد|payment:سند قبض|due:استحقاق|expense:مصروف|ticket:صيانة|vendor:مورد'],['entity_id','رقم السجل','number'],['file','اختيار الملف','file']]}
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
 for(const [,label] of fields[key])tr.appendChild(el('th',label));if(['properties','units','tenants','vendors','leases','payments','tickets','dues','expenses'].includes(key))tr.appendChild(el('th','الإجراءات'));
 if(key==='documents'){tr.appendChild(el('th','الحالة'));tr.appendChild(el('th','الملف'))}
 thead.appendChild(tr);table.appendChild(thead);
 const body=el('tbody');
 for(const item of data.data||[]){
  const r=el('tr');
  for(const [field] of fields[key]){
   const value=item[field]??'—';
   r.appendChild(el('td',typeof value==='object'?'—':value));
  }
  if(['properties','units','tenants','vendors'].includes(key)){
   const action=el('td'),edit=el('button','تعديل','secondary');
   edit.type='button';edit.addEventListener('click',()=>drawForm(key,item));action.appendChild(edit);
   if(key==='tenants'){
    const stmt=el('button','كشف الحساب','secondary');stmt.type='button';stmt.addEventListener('click',()=>showTenantStatement(item.id));action.appendChild(stmt);
   }
   r.appendChild(action);
  }
  if(key==='tickets'){
   const td=el('td'),actions=el('div',null,'actions');
   if(item.status==='open'){
    const start=el('button','بدء التنفيذ','secondary');start.type='button';
    start.addEventListener('click',async()=>{
     start.disabled=true;
     try{await api('/tickets/'+item.id,{method:'PATCH',body:{status:'in_progress'}});notify('تم بدء تنفيذ طلب الصيانة');await refresh()}
     catch(error){notify(error.message,true);start.disabled=false}
    });
    actions.append(start);
   }
   if(['open','in_progress'].includes(item.status)){
    const resolve=el('button','إغلاق مع التكلفة','secondary');resolve.type='button';
    resolve.addEventListener('click',()=>showMaintenanceResolution(item));actions.append(resolve);
   }
   td.append(actions);r.append(td);
  }
  if(key==='payments'){
   const td=el('td');
   if(!item.voided_at){
    const button=el('button','إلغاء السند','secondary');button.type='button';
    button.addEventListener('click',async()=>{
     const reason=prompt('أدخل سبب إلغاء سند القبض (10 أحرف على الأقل). سيبقى السند محفوظًا في السجلات.');
     if(reason===null)return;
     if(reason.trim().length<10){notify('يرجى كتابة سبب واضح لإلغاء السند',true);return}
     button.disabled=true;
     try{await api('/payments/'+item.id+'/void',{method:'POST',body:{reason:reason.trim()}});notify('تم إلغاء أثر سند القبض مع الاحتفاظ بسجل المراجعة');await refresh()}
     catch(err){notify(err.message,true);button.disabled=false}
    });
    td.appendChild(button);
   }else td.appendChild(el('span','ملغى','tag'));
   r.appendChild(td);
  }
  if(key==='leases'){
   const td=el('td'),bar=el('div',null,'actions');
   const options=item.status==='draft'?[['activate','تفعيل'],['cancel','إلغاء المسودة']]:item.status==='active'?[['complete','إكمال']]:[];
   for(const [action,label] of options){
    const button=el('button',label,'secondary');button.type='button';
    button.addEventListener('click',async()=>{
     if(!confirm('تأكيد إجراء «'+label+'» على العقد رقم '+item.id+'؟'))return;
     button.disabled=true;
     try{await api('/leases/'+item.id+'/'+action,{method:'POST'});notify('تم تحديث حالة العقد');await refresh()}
     catch(err){notify(err.message,true);button.disabled=false}
    });
    bar.appendChild(button);
   }
   if(['active','completed'].includes(item.status)){
    const renew=el('button','تجديد العقد','secondary');renew.type='button';
    renew.addEventListener('click',()=>showRenewal(item));bar.appendChild(renew);
   }
   td.appendChild(bar);r.appendChild(td);
  }
  if(key==='documents'){
   const state=el('td','✓ تم الإرفاق','tag');r.appendChild(state);
   const td=el('td'),buttons=el('div',null,'actions'),b=el('button','تنزيل','secondary');
   b.addEventListener('click',()=>download(item.id,item.original_name));buttons.appendChild(b);
   const remove=el('button','حذف المرفق','secondary');remove.type='button';
   remove.addEventListener('click',async()=>{
    if(!confirm('تأكيد الحذف النهائي للملف «'+item.original_name+'»؟'))return;
    remove.disabled=true;
    try{await api('/documents/'+item.id,{method:'DELETE'});notify('تم حذف المرفق');await refresh()}
    catch(error){notify(error.message,true);remove.disabled=false}
   });buttons.appendChild(remove);td.appendChild(buttons);r.appendChild(td);
  }
  const entityKinds={properties:'property',units:'unit',tenants:'tenant',leases:'lease',payments:'payment',dues:'due',expenses:'expense',tickets:'ticket',vendors:'vendor'};
  if(entityKinds[key]){
   const currentAction=r.lastElementChild;
   // Reuse the action cell when one exists; otherwise create it.
   let target;
   if(['properties','units','tenants','vendors','leases','payments','tickets'].includes(key))target=currentAction;
   else {target=el('td');r.append(target)}
   const attach=el('button','📎 المرفقات','secondary');attach.type='button';
   attach.addEventListener('click',()=>showRecordDocuments(entityKinds[key],item.id));
   target.appendChild(attach);
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
function showMaintenanceResolution(ticket){
 const view=$('view');view.replaceChildren();
 const panel=el('section',null,'panel'),h=el('h3','إغلاق بلاغ صيانة #'+ticket.id);
 const info=el('p','سيتم إغلاق البلاغ وإنشاء مصروف واحد مرتبط به عند وجود تكلفة فعلية. لا يمكن إغلاق البلاغ مرتين.','muted');
 const form=el('form'),fields=[['actual_cost','التكلفة الفعلية (ر.س)','number'],['resolved_on','تاريخ المعالجة','date'],['description','وصف المصروف (اختياري)','text']];
 for(const [name,label,type] of fields){
  const box=el('div',null,'field'),lab=el('label',label),input=el('input');
  input.name=name;input.id='resolve-'+name;input.type=type;
  if(name!=='description')input.required=true;
  if(type==='number'){input.min='0';input.step='0.01'}
  if(type==='date')input.value=new Date().toISOString().slice(0,10);
  lab.htmlFor=input.id;box.append(lab,input);form.append(box);
 }
 const actions=el('div',null,'actions'),submit=el('button','إغلاق البلاغ وتسجيل المصروف','primary'),back=el('button','رجوع','secondary');
 submit.type='submit';back.type='button';back.addEventListener('click',()=>refresh());actions.append(submit,back);form.append(actions);
 form.addEventListener('submit',async event=>{
  event.preventDefault();submit.disabled=true;
  const values=Object.fromEntries(new FormData(form));
  const payload={actual_cost:Number(values.actual_cost),resolved_on:values.resolved_on};
  if(values.description.trim())payload.description=values.description.trim();
  try{await api('/tickets/'+ticket.id+'/resolve',{method:'POST',body:payload});notify('تم إغلاق البلاغ وتسجيل تكلفته بنجاح');await refresh()}
  catch(error){notify(error.message,true);submit.disabled=false}
 });
 panel.append(h,info,form);view.append(panel);
}
function showRenewal(lease){
 const parent=$('view');parent.replaceChildren();
 const section=el('section',null,'panel');
 section.appendChild(el('h3','تجديد العقد رقم '+lease.id));
 section.appendChild(el('p','سيُنشأ عقد جديد مدته سنة تبدأ بعد نهاية العقد السابق مباشرةً، لنفس الوحدة والمستأجر. ويبقى العقد السابق محفوظًا.','muted'));
 const form=el('form');
 const inputs=[['total_amount','القيمة السنوية الجديدة (ر.س)','number'],['deposit','التأمين الجديد (ر.س) — اختياري','number']];
 for(const [field,label,type] of inputs){
  const wrap=el('div',null,'field'),lab=el('label',label),input=el('input');
  input.id='renew-'+field;input.name=field;input.type=type;input.min='0';input.step='0.01';input.required=field==='total_amount';
  if(field==='total_amount')input.value=lease.total_amount||'';
  lab.htmlFor=input.id;wrap.append(lab,input);form.append(wrap);
 }
 const wrap=el('div',null,'field'),label=el('label','عدد أقساط السنة'),select=el('select');
 select.id='renew-installments';select.name='installments';
 for(const [value,name] of [['1','سنوي'],['2','نصف سنوي'],['4','ربع سنوي'],['12','شهري']]){
  const opt=el('option',name);opt.value=value;select.append(opt);
 }
 select.value='12';label.htmlFor=select.id;wrap.append(label,select);form.append(wrap);
 const controls=el('div',null,'actions'),submit=el('button','إنشاء مسودة التجديد','primary'),back=el('button','رجوع','secondary');
 submit.type='submit';back.type='button';back.addEventListener('click',()=>refresh());controls.append(submit,back);form.append(controls);
 form.addEventListener('submit',async event=>{
  event.preventDefault();submit.disabled=true;
  const payload=Object.fromEntries(new FormData(form));
  payload.total_amount=Number(payload.total_amount);
  payload.installments=Number(payload.installments);
  if(payload.deposit==='')delete payload.deposit;else payload.deposit=Number(payload.deposit);
  try{
   await api('/leases/'+lease.id+'/renew',{method:'POST',body:payload});
   notify('تم إنشاء مسودة تجديد العقد وربطها بالعقد السابق');await refresh();
  }catch(error){notify(error.message,true);submit.disabled=false}
 });
 section.append(form);parent.append(section);
}
async function showTenantStatement(tenantId,page=1){
 const view=$('view');view.replaceChildren(el('p','جاري تحميل كشف حساب المستأجر...','muted'));
 try{
  const data=await api('/tenants/'+tenantId+'/statement?page='+page);
  view.replaceChildren();
  const toolbar=el('div',null,'toolbar');
  toolbar.appendChild(el('h3','كشف حساب: '+data.tenant.name));
  const back=el('button','العودة إلى المستأجرين','secondary');
  back.type='button';back.addEventListener('click',()=>refresh());
  toolbar.appendChild(back);view.append(toolbar);
  const metrics=el('div',null,'metrics');
  for(const [label,value] of [['إجمالي الاستحقاقات',data.totals.billed],['إجمالي التحصيل',data.totals.received],['المبلغ المتبقي',data.totals.outstanding]]){
   const card=el('div',null,'metric');card.append(el('span',label+' (ر.س)','muted'),el('strong',value));metrics.append(card);
  }
  view.append(metrics);
  const wrap=el('div',null,'table-wrap'),table=el('table'),thead=el('thead'),hrow=el('tr');
  for(const name of ['القسط','التاريخ','الوحدة','المستحق','المسدد','المتبقي','الحالة'])hrow.appendChild(el('th',name));
  thead.appendChild(hrow);table.appendChild(thead);
  const tbody=el('tbody');
  for(const d of data.dues.data){
   const tr=el('tr');
   for(const value of [d.sequence,d.due_on,d.lease?.unit?.code||'—',d.amount,d.paid_amount,d.balance_amount,d.payment_status])tr.appendChild(el('td',value));
   tbody.appendChild(tr);
  }
  table.appendChild(tbody);wrap.appendChild(table);view.append(wrap);
  if(data.dues.last_page>1){
   const controls=el('div',null,'actions'),prev=el('button','السابق','secondary'),next=el('button','التالي','secondary');
   prev.disabled=page<=1;next.disabled=page>=data.dues.last_page;
   prev.addEventListener('click',()=>showTenantStatement(tenantId,page-1));
   next.addEventListener('click',()=>showTenantStatement(tenantId,page+1));
   controls.append(prev,el('span','صفحة '+page+' من '+data.dues.last_page,'muted'),next);view.append(controls);
  }
 }catch(error){view.replaceChildren(el('p',error.message,'muted'));notify(error.message,true)}
}
function drawForm(key,record=null){
 const parent=$('view');parent.replaceChildren();
 const config=templates[key],section=el('section',null,'panel'),heading=el('h3',(record?'تعديل ':'إضافة ')+sections.find(x=>x[0]===key)?.[1]);
 const form=el('form');form.enctype='multipart/form-data';
 for(const [name,label,type,values] of config.fields){
  const div=el('div',null,'field');const lab=el('label',label);lab.htmlFor='field-'+name;
  let input;
  if(type==='choice'){
   input=el('select');
   for(const str of values.split('|')){const [v,t]=str.split(':');const opt=el('option',t);opt.value=v;input.appendChild(opt)}
  }else{input=el('input');input.type=type==='file'?'file':type; if(type==='number'){input.min='0';input.step='any'}}
  input.name=name;input.id='field-'+name;
  if(record&&record[name]!==undefined&&record[name]!==null)input.value=record[name];
  if(!['district','address','email','deposit','floor','area','reference','description','unit_id','vendor_id','estimated_cost'].includes(name))input.required=true;
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
   let url=record?'/'+key+'/'+encodeURIComponent(record.id):config.endpoint;
   for(const place of ['property_id','lease_id'])if(url.includes('{'+place+'}')){url=url.replace('{'+place+'}',encodeURIComponent(payload[place]));delete payload[place]}
   if(key==='documents'){
    const upload=new FormData();
    for(const [k,v] of Object.entries(payload))upload.append(k,v);
    await api(url,{method:'POST',body:upload});
   }else{
    for(const numeric of ['unit_id','tenant_id','entity_id','total_amount','deposit','amount','installments','area','estimated_cost'])
     if(numeric in payload)payload[numeric]=Number(payload[numeric]);
    await api(url,{method:record?'PATCH':'POST',body:payload});
   }
   notify(key==='documents'?'✓ تم إرفاق الملف بنجاح':record?'تم حفظ التعديلات بنجاح':'تم حفظ السجل بنجاح');await refresh();
  }catch(err){notify(err.message,true)}finally{save.disabled=false}
 });
 section.append(heading,form);parent.appendChild(section);
}
async function showRecordDocuments(entity,id){
 const view=$('view');view.replaceChildren(el('p','جاري تحميل المرفقات...','muted'));
 try{
  const params=new URLSearchParams({entity_type:entity,entity_id:String(id)});
  const files=await api('/documents?'+params.toString());
  view.replaceChildren();
  const toolbar=el('div',null,'toolbar');
  toolbar.append(el('h3','مرفقات السجل #'+id));
  const back=el('button','الرجوع','secondary');back.addEventListener('click',()=>refresh());toolbar.append(back);
  view.append(toolbar);
  const pane=el('section',null,'panel'),uploadForm=el('form');
  const label=el('label','إرفاق ملف جديد'),input=el('input');
  input.type='file';input.name='file';input.required=true;
  input.accept='.pdf,.png,.jpg,.jpeg,.webp,.doc,.docx,.xls,.xlsx,.txt,.csv';
  label.append(input);uploadForm.append(label);
  const submit=el('button','رفع الملف','primary');submit.type='submit';uploadForm.append(submit);
  uploadForm.addEventListener('submit',async e=>{
   e.preventDefault();submit.disabled=true;
   try{
    const form=new FormData();form.append('entity_type',entity);form.append('entity_id',String(id));form.append('file',input.files[0]);
    await api('/documents',{method:'POST',body:form});
    notify('✓ تم إرفاق الملف بنجاح');
    await showRecordDocuments(entity,id);
   }catch(error){notify(error.message,true);submit.disabled=false}
  });
  pane.append(uploadForm,el('h3','✓ الملفات المرفقة ('+files.total+')'));
  for(const file of files.data){
   const row=el('div',null,'toolbar'),name=el('span','📄 '+file.original_name+' — تم الإرفاق');
   const buttons=el('div',null,'actions'),down=el('button','تنزيل','secondary'),remove=el('button','حذف','secondary');
   down.addEventListener('click',()=>download(file.id,file.original_name));
   remove.addEventListener('click',async()=>{
    if(!confirm('حذف المرفق «'+file.original_name+'» نهائيًا؟'))return;
    remove.disabled=true;
    try{await api('/documents/'+file.id,{method:'DELETE'});notify('تم حذف المرفق');await showRecordDocuments(entity,id)}
    catch(error){notify(error.message,true);remove.disabled=false}
   });
   buttons.append(down,remove);row.append(name,buttons);pane.append(row);
  }
  if(!files.data.length)pane.append(el('p','لا توجد ملفات مرفقة بهذا السجل.','muted'));
  if(files.last_page>1)pane.append(el('p','يعرض أول 25 مرفقًا؛ تظهر بقية المرفقات في قسم المرفقات العام.','muted'));
  view.append(pane);
 }catch(error){view.replaceChildren(el('p',error.message,'muted'));notify(error.message,true)}
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
async function showCashflow(){
 const view=$('view');view.replaceChildren();
 const panel=el('section',null,'panel'),form=el('form');form.className='form-grid';
 const year=new Date().getFullYear();
 for(const [name,label,value] of [['from','من تاريخ',year+'-01-01'],['to','إلى تاريخ',year+'-12-31']]){
  const field=el('div',null,'field'),lab=el('label',label),input=el('input');
  input.type='date';input.name=name;input.value=value;input.required=true;lab.htmlFor='report-'+name;input.id='report-'+name;
  field.append(lab,input);form.appendChild(field);
 }
 const button=el('button','عرض التقرير','primary');button.type='submit';
 const actions=el('div',null,'actions');actions.appendChild(button);panel.append(form,actions);view.append(panel);
 const result=el('section',null,'panel');result.appendChild(el('p','اختر الفترة لعرض المقبوضات والمصروفات حسب العقار.','muted'));view.append(result);
 form.addEventListener('submit',async ev=>{
  ev.preventDefault();button.disabled=true;
  try{
   const query=new URLSearchParams(new FormData(form));
   const data=await api('/reports/cashflow?'+query.toString());
   result.replaceChildren(el('h3','ملخص الفترة: '+data.from+' إلى '+data.to));
   const metrics=el('div',null,'metrics');
   for(const [label,value] of [['المقبوضات',data.totals.received],['المصروفات',data.totals.expenses],['صافي التدفق',data.totals.net]]){
    const metric=el('div',null,'metric');metric.append(el('span',label+' (ر.س)','muted'),el('strong',value));metrics.append(metric);
   }
   result.appendChild(metrics);
   const wrap=el('div',null,'table-wrap'),table=el('table'),thead=el('thead'),header=el('tr');
   for(const label of ['العقار','المدينة','المقبوضات','المصروفات','الصافي'])header.appendChild(el('th',label));
   thead.append(header);table.append(thead);
   const tbody=el('tbody');
   for(const row of data.properties){
    const tr=el('tr');
    for(const value of [row.name,row.city,row.received,row.expenses,row.net])tr.appendChild(el('td',value));
    tbody.appendChild(tr);
   }
   table.append(tbody);wrap.append(table);result.append(wrap);
  }catch(error){notify(error.message,true)}finally{button.disabled=false}
 });
 form.requestSubmit();
}
function showAccount(){
 const view=$('view');view.replaceChildren();
 const section=el('section',null,'panel'),h=el('h3','تغيير كلمة المرور');
 const explanation=el('p','تغيير كلمة المرور يلغي جميع جلسات تسجيل الدخول. يجب استخدام كلمة جديدة من 12 حرفًا على الأقل.','muted');
 const form=el('form');
 const pairs=[['current_password','كلمة المرور الحالية'],['password','كلمة المرور الجديدة'],['password_confirmation','تأكيد كلمة المرور الجديدة']];
 for(const [name,label] of pairs){
  const group=el('div',null,'field'),lab=el('label',label),input=el('input');
  input.id='account-'+name;input.name=name;input.type='password';input.required=true;
  input.autocomplete=name==='current_password'?'current-password':'new-password';
  if(name!=='current_password')input.minLength=12;
  lab.htmlFor=input.id;group.append(lab,input);form.append(group);
 }
 const action=el('button','تحديث كلمة المرور','primary');action.type='submit';
 form.appendChild(action);
 form.addEventListener('submit',async e=>{
  e.preventDefault();action.disabled=true;
  try{
   const credentials=Object.fromEntries(new FormData(form));
   await api('/account/change-password',{method:'POST',body:credentials});
   resetAuth();notify('تم تغيير كلمة المرور وإلغاء الجلسات، سجل الدخول بكلمة المرور الجديدة.');
  }catch(err){notify(err.message,true)}finally{action.disabled=false}
 });
 section.append(h,explanation,form);view.append(section);
}
async function showAlerts(){
 const view=$('view');view.replaceChildren(el('p','جاري تحميل التنبيهات...','muted'));
 const data=await api('/alerts');
 view.replaceChildren();
 const metrics=el('div',null,'metrics');
 for(const [label,value] of [['أقساط متأخرة',data.overdue_count],['أقساط خلال 30 يومًا',data.upcoming_count],['عقود تنتهي خلال 30 يومًا',data.expiring_leases.length]]){
  const item=el('div',null,'metric');item.append(el('span',label,'muted'),el('strong',value));metrics.append(item);
 }
 view.append(metrics);
 const panel=el('div',null,'panel');panel.append(el('h3','مواعيد الاستحقاقات غير المسددة'));
 const wrap=el('div',null,'table-wrap'),table=el('table'),head=el('tr');
 for(const x of ['الحالة','المستأجر','الوحدة','الاستحقاق','المتبقي (ر.س)'])head.append(el('th',x));
 const thead=el('thead');thead.append(head);table.append(thead);
 const body=el('tbody');
 for(const due of data.dues){
  const tr=el('tr');
  for(const value of [due.type==='overdue'?'متأخر':'قادم',due.tenant??'—',due.unit??'—',due.due_on,due.balance])tr.append(el('td',value));
  body.append(tr);
 }
 table.append(body);wrap.append(table);panel.append(wrap);
 if(!data.dues.length)panel.append(el('p','لا توجد استحقاقات غير مسددة ضمن نطاق التنبيهات.','muted'));
 view.append(panel);
 const expiring=el('div',null,'panel');expiring.append(el('h3','العقود التي تنتهي خلال 30 يومًا'));
 if(!data.expiring_leases.length)expiring.append(el('p','لا توجد عقود تنتهي خلال الفترة.','muted'));
 for(const lease of data.expiring_leases){
  expiring.append(el('p','العقد رقم '+lease.id+' — ينتهي في '+lease.ends_on,'muted'));
 }
 view.append(expiring);
}
async function refresh(page=1){
 $('sectionTitle').textContent=sections.find(x=>x[0]===active)?.[1]||'عقاري برو';
 const view=$('view');view.replaceChildren(el('p','جاري تحميل البيانات...','muted'));
 try{
  if(active==='overview'){drawOverview(await api('/overview'));return}
  if(active==='alerts'){await showAlerts();return}
  if(active==='reports'){await showCashflow();return}
  if(active==='account'){showAccount();return}
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
