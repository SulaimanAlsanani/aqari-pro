(()=>{
'use strict';
const NOW=new Date().toISOString().slice(0,10),KEY='aqari-pro-gh-v1';
const seed={
owners:[{id:1,name:'عبدالله السالم',phone:'0500000001',fee:5}],
properties:[{id:1,ownerId:1,name:'عمارة النخيل',city:'الرياض',district:'الياسمين',type:'سكني'}],
units:[{id:1,propertyId:1,code:'101',type:'شقة',area:110,floor:'الأول'},{id:2,propertyId:1,code:'102',type:'شقة',area:100,floor:'الأول'}],
tenants:[{id:1,name:'أحمد محمد',phone:'0550000001'}],
contracts:[{id:1,unitId:1,tenantId:1,start:'2026-10-01',end:'2027-09-30',total:30000,installments:12,deposit:2500,ejarStatus:'مسودة محلية'}],
dues:[{id:1,contractId:1,date:'2026-10-01',amount:2500},{id:2,contractId:1,date:'2026-11-01',amount:2500},{id:3,contractId:1,date:'2026-12-01',amount:2500}],
payments:[{id:1,dueId:1,amount:2500,date:'2026-10-02',method:'تحويل بنكي',reference:'DEMO-001'}],
expenses:[{id:1,propertyId:1,category:'صيانة',description:'إصلاح مضخة المياه',amount:450,date:'2026-11-03'}],
vendors:[{id:1,name:'مؤسسة الصيانة الحديثة',phone:'0551111111',service:'صيانة عامة'}],
tickets:[{id:1,propertyId:1,title:'تسرب مياه',priority:'عالية',status:'مفتوح',cost:0}],
documents:[],inspections:[],tasks:[],payouts:[],audit:[],ejarLogs:[]
};

function makeDemoData(){
 const d=JSON.parse(JSON.stringify(seed)), a=['سالم','فهد','ناصر','خالد','راشد','عبدالعزيز','بدر','فيصل','ياسر','تركي','ماجد','وليد'], b=['القحطاني','العتيبي','الشمري','الغامدي','الحربي','الدوسري','السبيعي','الزهراني'];
 const cities=['الرياض','جدة','الدمام','الخبر','مكة المكرمة','المدينة المنورة'],districts=['الياسمين','الملقا','الروضة','العليا','الصفا','الشاطئ','النهضة','الصحافة'],names=['النخيل','الواحة','الصفوة','الندى','الريادة','الأفق','الريان','اليسر'];
 const cats=['صيانة','كهرباء','مياه','نظافة','حراسة','سباكة','تأمين','مرافق'],services=['صيانة عامة','كهرباء','تكييف','مصاعد','نظافة','سباكة'];
 const nm=i=>a[i%a.length]+' '+b[Math.floor(i/a.length)%b.length],date=(y,m,day)=>y+'-'+String(m).padStart(2,'0')+'-'+String(day).padStart(2,'0');
 const addMonth=(str,n)=>{let [y,m,day]=str.split('-').map(Number),v=new Date(Date.UTC(y,m-1+n,1));let max=new Date(Date.UTC(v.getUTCFullYear(),v.getUTCMonth()+1,0)).getUTCDate();return date(v.getUTCFullYear(),v.getUTCMonth()+1,Math.min(day,max))};
 let oid=2,pid=2,uid=3,tid=2,cid=2,did=4,rid=2,eid=2,vid=2,wid=2,docid=1,iid=1,taskid=1,payoutid=1;
 // v1: single-owner portfolio; ownerId retained for future office edition.
 for(let i=0;i<35;i++)d.vendors.push({id:vid++,name:'مؤسسة '+names[i%8]+' للخدمات '+(i+1),phone:'05'+String(80000000+i).padStart(8,'0'),service:services[i%6]});
 for(let i=0;i<74;i++){
  const p=pid++,kind=i%8===0?'تجاري':i%6===0?'مختلط':'سكني';
  d.properties.push({id:p,ownerId:1,name:(kind==='تجاري'?'مركز ':'عمارة ')+names[i%8]+' '+(i+2),city:cities[i%6],district:districts[i%8],type:kind});
  for(let j=0;j<6+i%11;j++){
   const u=uid++,shop=kind==='تجاري'||kind==='مختلط'&&j%4===0;
   d.units.push({id:u,propertyId:p,code:(shop?'م':'ش')+'-'+(100+j),type:shop?'محل':'شقة',floor:shop?'أرضي':['الأرضي','الأول','الثاني','الثالث'][j%4],area:shop?65+j%4*15:80+j%5*20});
   if((i*13+j*7)%9<2)continue;
   const t=tid++,c=cid++,start=date(2025+i%2,1+(i+j)%12,1+(j%4)*5),end=addMonth(start,12),amount=shop?4000+(i%7)*400:1800+(i%9)*250;
   d.tenants.push({id:t,name:nm(t)+' (تجريبي '+t+')',phone:'05'+String(70000000+t).padStart(8,'0')});
   d.contracts.push({id:c,unitId:u,tenantId:t,start,end,total:amount*12,installments:12,deposit:amount,ejarStatus:['مسودة محلية','جاهز للمراجعة','فحص محاكاة'][c%3]});
   for(let q=0;q<12;q++){
    const dueDate=addMonth(start,q),du=did++;d.dues.push({id:du,contractId:c,date:dueDate,amount});
    if(dueDate>NOW)continue;
    const st=(c*11+q*7)%10;
    if(st>=3)d.payments.push({id:rid++,dueId:du,amount:st===3||st===4?amount/2:amount,date:dueDate,method:['تحويل بنكي','نقدي','مدى'][st%3],reference:'DEMO-'+String(du).padStart(6,'0')});
   }
   if((i+j)%3===0)d.inspections.push({id:iid++,propertyId:p,unitId:u,date:start,status:'تم الاستلام',notes:'محضر فحص تجريبي'});
  }
  for(let q=0;q<9;q++)d.expenses.push({id:eid++,propertyId:p,category:cats[(i+q)%8],description:'مصروف تجريبي - '+cats[(i+q)%8],amount:120+(i*57+q*139)%1800,date:date(2026,1+(q+i)%11,1+q*3)});
  for(let q=0;q<3;q++)d.tickets.push({id:wid++,propertyId:p,title:['تسرب مياه','صيانة المصعد','أعمال كهربائية'][q],priority:['عادية','متوسطة','عالية'][q],status:['مفتوح','قيد التنفيذ','مكتمل'][(i+q)%3],cost:(i+q)%3===2?450:0});
  d.documents.push({id:docid++,propertyId:p,title:'وثيقة عقار '+p,kind:i%2?'صك':'تأمين',expiry:date(2027,1+i%12,15)});
  if(i%2===0)d.tasks.push({id:taskid++,title:'متابعة عقار '+p,date:date(2026,11,7+i%20),status:i%3?'مفتوحة':'مكتملة'});
  // Owner payouts are not part of the individual-owner edition.
 }
 return d;
}

let db;try{db=JSON.parse(localStorage.getItem(KEY))||makeDemoData()}catch(e){db=makeDemoData()}for(const k in seed)if(!Array.isArray(db[k]))db[k]=[];
const $=s=>document.querySelector(s),find=(s,id)=>db[s].find(x=>String(x.id)===String(id)),sum=(a,k)=>a.reduce((s,x)=>s+Number(x[k]||0),0),fmt=v=>Number(v||0).toLocaleString('en-US',{maximumFractionDigits:2}),money=v=>fmt(v)+' ر.س',esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),nextId=kind=>window.AqariDemoFinance.nextRecordId(db[kind]);
let current='dashboard',filter='all',search='',invoiceFilter='';
const pname=id=>find('properties',id)?.name||'غير محدد',u=id=>find('units',id),t=id=>find('tenants',id),c=id=>find('contracts',id),propFromDue=d=>u(c(d.contractId)?.unitId)?.propertyId,paid=d=>db.payments.filter(x=>x.dueId===d.id).reduce((cents,x)=>cents+Math.round(Number(x.amount)*100),0)/100,balance=d=>Math.max(0,Math.round(Number(d.amount)*100)-Math.round(paid(d)*100))/100, filteredP=()=>db.properties.filter(x=>filter==='all'||String(x.id)===filter), filteredD=()=>db.dues.filter(d=>filter==='all'||String(propFromDue(d))===filter), filteredE=()=>db.expenses.filter(e=>filter==='all'||String(e.propertyId)===filter),matches=x=>JSON.stringify(x).toLowerCase().includes(search.toLowerCase());
function persist(){try{localStorage.setItem(KEY,JSON.stringify(db))}catch(e){alert('فشل الحفظ المحلي')}}
function log(action,entity){db.audit.unshift({id:nextId('audit'),date:new Date().toLocaleString('ar-SA'),action,entity})}
const sections=[
['الرئيسية',[['dashboard','لوحة أملاكي'],['tasks','المهام والتنبيهات']]],
['أملاكي',[['properties','عقاراتي'],['units','الوحدات'],['tenants','المستأجرون']]],
['التأجير',[['contracts','العقود'],['dues','الاستحقاقات'],['payments','سندات القبض'],['inspections','الفحص والتسليم']]],
['المالية',[['expenses','المصروفات'],['ledger','الحركة المالية']]],
['التشغيل',[['tickets','طلبات الصيانة'],['vendors','الموردون'],['documents','الوثائق']]],
['النظام',[['ejar','تكامل إيجار'],['reports','التقارير التفصيلية'],['audit','سجل التدقيق'],['settings','الإعدادات']]]
];
const configs={
owners:{title:'الملاك',fields:[['name','اسم المالك'],['phone','الجوال'],['fee','أتعاب الإدارة %','number']]},
properties:{title:'عقاراتي',fields:[['name','اسم العقار'],['city','المدينة'],['district','الحي'],['type','نوع العقار']]},
units:{title:'الوحدات',fields:[['propertyId','العقار','properties'],['code','رقم الوحدة'],['type','نوع الوحدة'],['floor','الدور'],['area','المساحة م²','number']]},
tenants:{title:'المستأجرون',fields:[['name','الاسم'],['phone','الجوال']]},
contracts:{title:'العقود',fields:[['unitId','الوحدة','units'],['tenantId','المستأجر','tenants'],['start','بداية العقد','date'],['end','نهاية العقد','date'],['total','القيمة الإجمالية','number'],['installments','عدد الأقساط','number'],['deposit','التأمين','number']]},
expenses:{title:'المصروفات',fields:[['propertyId','العقار','properties'],['category','التصنيف'],['description','الوصف'],['amount','المبلغ','number'],['date','التاريخ','date'],['invoiceReference','مرجع الفاتورة (اختياري)','optional']]},
vendors:{title:'الموردون',fields:[['name','اسم المورد'],['phone','رقم التواصل'],['service','نوع الخدمة']]},
tickets:{title:'طلبات الصيانة',fields:[['propertyId','العقار','properties'],['title','وصف البلاغ'],['priority','الأولوية'],['status','الحالة'],['cost','التكلفة','number']]},
documents:{title:'الوثائق',fields:[['propertyId','العقار','properties'],['title','اسم الوثيقة'],['kind','نوع الوثيقة'],['expiry','تاريخ الانتهاء','date']]},
inspections:{title:'الفحص والتسليم',fields:[['propertyId','العقار','properties'],['unitId','الوحدة','units'],['date','التاريخ','date'],['status','حالة التسليم'],['notes','الملاحظات']]},
tasks:{title:'المهام والتنبيهات',fields:[['title','المهمة'],['date','الموعد','date'],['status','الحالة']]},
payouts:{title:'تسويات الملاك',fields:[['ownerId','المالك','owners'],['date','تاريخ التسوية','date'],['amount','المبلغ المصروف','number'],['reference','مرجع التحويل']]}
};
function title(s,action){return '<div class="toolbar"><h3>'+s+'</h3>'+(action?'<button class="btn" data-do="add" data-kind="'+action+'">+ إضافة</button>':'')+'</div>'}
function line(label,value){return '<div class="row" style="padding:9px 0;border-bottom:1px solid #edf1f2"><span class="muted">'+label+'</span><b>'+value+'</b></div>'}
function badge(s,color=''){return '<span class="badge '+color+'">'+esc(s)+'</span>'}
function propFilter(){return '<div class="filters" style="margin:0 0 16px"><select id="propertyFilter"><option value="all">جميع العقارات</option>'+db.properties.map(x=>'<option value="'+x.id+'" '+(filter==x.id?'selected':'')+'>'+esc(x.name)+'</option>').join('')+'</select><input id="findText" placeholder="بحث في البيانات" value="'+esc(search)+'"></div>'}
function renderNav(){ $('#nav').innerHTML=sections.map(([group,items])=>'<div class="navgroup">'+group+'</div>'+items.map(([key,label])=>'<button class="navbtn '+(current===key?'active':'')+'" data-page="'+key+'">'+label+'</button>').join('')).join('')}
function filteredList(k){return db[k].filter(x=>matches(x)&&(['properties'].includes(k)?filter==='all'||x.id==filter:['units','expenses','tickets','documents','inspections'].includes(k)?filter==='all'||x.propertyId==filter:['contracts'].includes(k)?filter==='all'||u(x.unitId)?.propertyId==filter:true))}
function generic(k){
 const cfg=configs[k];
 const rows=filteredList(k).filter(x=>k!=='expenses'||!invoiceFilter||String(x.invoiceReference??'').toLowerCase().includes(invoiceFilter.trim().toLowerCase()));
 const invoiceSearch=k==='expenses'?'<div class="filters" style="margin:0 0 16px"><label for="expenseInvoiceFilter">البحث بمرجع الفاتورة</label><input id="expenseInvoiceFilter" aria-label="البحث بمرجع الفاتورة" placeholder="ابحث عن رقم الفاتورة" value="'+esc(invoiceFilter)+'"></div>':'';
 return propFilter()+invoiceSearch+'<div class="panel">'+title(cfg.title,k)+(rows.length?'<p class="muted">عدد السجلات: '+rows.length+' — يُعرض أول 100 سجل؛ استخدم البحث والتصفية للوصول إلى البقية.</p>'+rows.slice(0,100).map(x=>'<div class="record" data-attach-entity="'+k+'" data-attach-id="'+x.id+'"><div class="row"><b>'+esc(x.name||x.title||x.code||x.description||'سجل #'+x.id)+'</b>'+badge(x.status||x.type||x.kind||'مسجل','blue')+'</div><div class="line"></div>'+cfg.fields.slice(0,7).map(([key,label,kind])=>line(label,kind==='owners'?esc(find('owners',x[key])?.name):kind==='properties'?esc(pname(x[key])):kind==='tenants'?esc(t(x[key])?.name):kind==='units'?esc(u(x[key])?.code):key==='amount'||key==='total'||key==='cost'?money(x[key]):key==='invoiceReference'&&!x[key]?'غير مسجل':esc(x[key]))).join('')+'</div>').join(''):'<div class="empty">لا توجد سجلات مطابقة</div>')+'</div>';
}
function dashboard(){const ds=filteredD(),exp=filteredE(),inc=sum(db.payments.filter(x=>ds.some(y=>y.id===x.dueId)),'amount'),cost=sum(exp,'amount'),late=ds.filter(d=>d.date<NOW&&balance(d)>0),units=db.units.filter(x=>filter==='all'||x.propertyId==filter),occupied=units.filter(x=>db.contracts.some(c=>c.unitId===x.id&&c.start<=NOW&&c.end>=NOW)).length;
const stats=[['المقبوضات',money(inc)],['المتأخرات',money(late.reduce((s,d)=>s+balance(d),0))],['المصروفات',money(cost)],['صافي التدفق',money(inc-cost)]];
return propFilter()+'<div class="metrics">'+stats.map(x=>'<div class="metric"><div class="muted">'+x[0]+'</div><div class="num">'+x[1]+'</div></div>').join('')+'</div><div class="panel"><h3>إشغال الوحدات</h3><strong>'+occupied+' / '+units.length+'</strong><div class="bar"><span style="width:'+(units.length?occupied/units.length*100:0)+'%"></span></div><div class="muted">شاغرة: '+(units.length-occupied)+'</div></div><div class="panel">'+title('المستحقات المتأخرة')+(late.slice(0,15).map(d=>'<div class="record" data-attach-entity="dues" data-attach-id="'+d.id+'"><div class="row"><b>'+esc(t(c(d.contractId)?.tenantId)?.name)+'</b>'+badge('متأخر','red')+'</div>'+line('المبلغ المتبقي',money(balance(d)))+'<div class="actions"><button class="btn" data-do="pay" data-id="'+d.id+'">تسجيل تحصيل</button></div></div>').join('')||'<div class="empty">لا توجد متأخرات</div>')+'</div>'}
function dues(){return propFilter()+'<div class="panel">'+title('جدول الاستحقاقات')+filteredD().filter(matches).slice(0,120).map(d=>'<div class="record" data-attach-entity="dues" data-attach-id="'+d.id+'"><div class="row"><b>'+esc(t(c(d.contractId)?.tenantId)?.name||'مستأجر')+'</b>'+badge(balance(d)===0?'مسدد':d.date<NOW?'متأخر':'غير مسدد',balance(d)===0?'green':'orange')+'</div>'+line('العقار',esc(pname(propFromDue(d))))+line('تاريخ الاستحقاق',esc(d.date))+line('القسط',money(d.amount))+line('المتبقي',money(balance(d)))+(balance(d)>0?'<div class="actions"><button class="btn" data-do="pay" data-id="'+d.id+'">تسجيل دفعة</button></div>':'')+'</div>').join('')+'</div>'}
function payments(){return '<div class="panel">'+title('سندات القبض')+db.payments.slice(-120).reverse().map(x=>'<div class="record" data-attach-entity="payments" data-attach-id="'+x.id+'">'+line('رقم السند','#'+x.id)+line('التاريخ',esc(x.date))+line('قيمة التحصيل',money(x.amount))+line('طريقة السداد',esc(x.method))+line('المرجع',esc(x.reference))+'</div>').join('')+'</div>'}
function ledger(){let rows=[...db.payments.map(x=>({date:x.date,type:'تحصيل',amount:x.amount})),...db.expenses.map(x=>({date:x.date,type:'مصروف',amount:-x.amount}))].sort((a,b)=>b.date.localeCompare(a.date));return '<div class="panel">'+title('الحركة المالية')+rows.map(x=>'<div class="record"><div class="row"><b>'+x.type+'</b><strong>'+money(x.amount)+'</strong></div><div class="muted">'+esc(x.date)+'</div></div>').join('')+'</div>'}
function ejar(){return '<div class="panel">'+title('مركز تكامل منصة إيجار')+'<div class="callout warn"><b>وضع محاكاة فقط.</b> لا يتصل هذا التطبيق بواجهات إيجار الحكومية ولا يرسل عقودًا رسمية.</div>'+db.contracts.map(x=>'<div class="record" data-attach-entity="contracts" data-attach-id="'+x.id+'"><div class="row"><b>عقد #'+x.id+' — '+esc(t(x.tenantId)?.name)+'</b>'+badge(x.ejarStatus||'مسودة محلية','blue')+'</div>'+line('رقم الوحدة',esc(u(x.unitId)?.code))+line('قيمة العقد',money(x.total))+'<div class="actions"><button class="btn alt" data-do="checkEjar" data-id="'+x.id+'">فحص البيانات</button><button class="btn" data-do="mockEjar" data-id="'+x.id+'">محاكاة الإرسال</button></div></div>').join('')+'</div><div class="panel">'+title('سجل محاكاة الربط')+db.ejarLogs.slice(0,50).map(x=>'<div class="record">'+esc(x.date)+' — '+esc(x.action)+' — '+esc(x.message)+'</div>').join('')+'</div>'}
function reports(){let ds=filteredD(),cost=sum(filteredE(),'amount'),income=sum(db.payments.filter(x=>ds.some(d=>d.id===x.dueId)),'amount');return propFilter()+'<div class="panel">'+title('تقرير المحفظة العقارية')+line('الاستحقاقات',money(sum(ds,'amount')))+line('المقبوضات',money(income))+line('الرصيد غير المحصل',money(ds.reduce((a,d)=>a+balance(d),0)))+line('المصروفات',money(cost))+line('صافي التدفق',money(income-cost))+'</div><div class="panel">'+title('تقرير العقارات')+filteredP().map(p=>{let dd=ds.filter(d=>propFromDue(d)==p.id),i=sum(db.payments.filter(x=>dd.some(d=>d.id===x.dueId)),'amount'),e=sum(filteredE().filter(x=>x.propertyId==p.id),'amount');return '<div class="record"><b>'+esc(p.name)+'</b>'+line('مقبوض',money(i))+line('مصروف',money(e))+line('صافي',money(i-e))+'</div>'}).join('')+'</div><button class="btn" data-do="csv">تصدير CSV</button>'}
function auditPage(){return '<div class="panel">'+title('سجل العمليات')+db.audit.map(x=>'<div class="record">'+esc(x.date)+' — '+esc(x.action)+' — '+esc(x.entity)+'</div>').join('')+'</div>'}
function settings(){return '<div class="panel"><h3>ملف مالك العقار</h3>'+line('اسم المالك',esc(db.owners[0]?.name||'مالك العقارات'))+line('رقم التواصل',esc(db.owners[0]?.phone||'غير مسجل'))+'<div class="actions"><button class="btn alt" data-do="editOwnerProfile">تعديل بيانات المالك</button><button class="btn alt" data-attachment-open data-entity="owners" data-record-id="1">📎 مرفقات المالك</button></div></div><div class="panel"><h3>الإعدادات والنسخ الاحتياطية</h3><div class="callout warn">نسخة تجريبية عامة. لا تدخل أي معلومات أو مستندات حقيقية. البيانات محلية في متصفحك فقط.</div><div class="actions"><button class="btn" data-do="backup">تصدير نسخة JSON</button><button class="btn alt" data-do="restoreBackup">استعادة نسخة JSON</button><input type="file" id="restoreBackupInput" accept=".json,application/json" hidden><button class="btn alt" data-do="seedDemo">تحميل محفظة تجريبية لمالك واحد (يستبدل البيانات المحلية)</button></div><p class="muted">نسخة JSON لا تتضمن المرفقات؛ نزّل ملفاتك منفردة من كل سجل. المرفقات تخزّن داخل هذا المتصفح فقط ولا توجد مزامنة سحابية أو دخول مستخدمين أو تكامل حكومي فعلي.</p></div>'}
function render(){renderNav();$('#title').textContent=sections.flatMap(x=>x[1]).find(x=>x[0]===current)?.[1]||'عقاري برو';$('#content').innerHTML=current==='dashboard'?dashboard():current==='dues'?dues():current==='payments'?payments():current==='ledger'?ledger():current==='ejar'?ejar():current==='reports'?reports():current==='audit'?auditPage():current==='settings'?settings():configs[current]?generic(current):'<div class="panel">هذه الصفحة قيد التطوير</div>'}
function field([key,label,kind]){let control=kind&&db[kind]?'<select name="'+key+'" required>'+db[kind].map(x=>'<option value="'+x.id+'">'+esc(x.name||x.code||x.title)+'</option>').join('')+'</select>':'<input name="'+key+'" type="'+(kind==='number'?'number':kind==='date'?'date':'text')+'" '+(kind==='number'?'min="0" step="any"':'')+' '+(kind==='date'?'value="'+NOW+'"':'')+(kind==='optional'?'':' required')+'>';return '<div class="field"><label>'+label+'</label>'+control+'</div>'}
function updateUnitChoices(form){
 const propertyId=form.querySelector('[name="propertyId"]')?.value||form.querySelector('[name="contractPropertyId"]')?.value;
 const unitSelect=form.querySelector('[name="unitId"]');
 if(!unitSelect||!propertyId)return;
 const previous=unitSelect.value;
 const eligible=db.units.filter(unit=>String(unit.propertyId)===String(propertyId));
 unitSelect.replaceChildren();
 for(const unit of eligible){
  const option=document.createElement('option');option.value=String(unit.id);option.textContent=unit.code;unitSelect.appendChild(option);
 }
 if(eligible.some(unit=>String(unit.id)===previous))unitSelect.value=previous;
 if(!eligible.length){
  const option=document.createElement('option');option.value='';option.textContent='لا توجد وحدات لهذا العقار';unitSelect.appendChild(option);
 }
 unitSelect.disabled=!eligible.length;
}
function openModal(k,extra=''){
 const cfg=configs[k],isContract=k==='contracts';
 const propertyChoice=isContract?'<div class="field"><label>العقار</label><select name="contractPropertyId" required>'+db.properties.map(p=>'<option value="'+p.id+'">'+esc(p.name)+'</option>').join('')+'</select></div>':'';
 $('#modal').innerHTML='<div class="modalbg"><div class="modal"><div class="toolbar"><h3>'+(cfg?cfg.title:'تسجيل دفعة')+'</h3><button class="btn alt" data-do="close">إغلاق</button></div><form id="editForm" data-kind="'+k+'" data-extra="'+extra+'"><div class="formgrid">'+propertyChoice+(cfg?cfg.fields.map(field).join(''):field(['amount','قيمة التحصيل','number'])+field(['date','التاريخ','date'])+'<div class="field"><label>طريقة الدفع</label><select name="method" required><option value="bank_transfer">تحويل بنكي</option><option value="cash">نقدًا</option><option value="card">بطاقة</option></select></div>'+field(['reference','المرجع']))+'</div><div class="actions"><button type="submit" class="btn">حفظ</button></div></form></div></div>';
 if(isContract||k==='inspections')updateUnitChoices($('#editForm'));
}
function download(name,str,type){let link=document.createElement('a'),url=URL.createObjectURL(new Blob([str],{type}));link.href=url;link.download=name;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000)}
document.addEventListener('click',e=>{const b=e.target.closest('[data-page],[data-do]');if(!b)return;if(b.dataset.page){current=b.dataset.page;search='';render();return}const a=b.dataset.do,k=b.dataset.kind,id=Number(b.dataset.id);if(a==='add')openModal(k);if(a==='editOwnerProfile'){$('#modal').innerHTML='<div class="modalbg"><div class="modal"><h3>ملف مالك العقار</h3><form id="ownerProfileForm"><div class="formgrid"><div class="field"><label>اسم المالك</label><input name="name" required value="'+esc(db.owners[0]?.name||'')+'"></div><div class="field"><label>رقم التواصل</label><input name="phone" required value="'+esc(db.owners[0]?.phone||'')+'"></div></div><div class="actions"><button class="btn" type="submit">حفظ</button><button class="btn alt" type="button" data-do="close">إلغاء</button></div></form></div></div>'}if(a==='pay')openModal('payment',id);if(a==='close')$('#modal').innerHTML='';if(a==='seedDemo'){if(confirm('سيتم استبدال بيانات هذا المتصفح ومحْو كل المرفقات المحلية ببيانات وهمية لمالك واحد. صدّر بياناتك ونزّل مرفقاتك المهمة أولاً. هل تتابع؟')){const clear=window.AqariAttachments?.clearAll();Promise.resolve(clear).then(()=>{db=makeDemoData();persist();current='dashboard';filter='all';search='';render();alert('تم تحميل محفظة المالك التجريبية')}).catch(err=>alert('تعذرت إعادة التهيئة؛ لم يتم استبدال البيانات: '+err.message))}}if(a==='backup')download('aqari-backup.json',JSON.stringify(db,null,2),'application/json');if(a==='restoreBackup')$('#restoreBackupInput')?.click();if(a==='csv')download('aqari-report.csv','\ufeffنوع,تاريخ,مبلغ\n'+[...db.payments.map(x=>['تحصيل',x.date,x.amount]),...db.expenses.map(x=>['مصروف',x.date,x.amount])].map(x=>x.join(',')).join('\n'),'text/csv');if(a==='checkEjar'||a==='mockEjar'){let x=c(id),errors=[];if(!u(x?.unitId))errors.push('الوحدة غير موجودة');if(!t(x?.tenantId))errors.push('المستأجر غير موجود');if(!x?.total||x.end<x.start)errors.push('بيانات العقد غير صحيحة');const message=errors.join('، ')||'بيانات العقد الأساسية مكتملة';if(a==='mockEjar'&&!errors.length)x.ejarStatus='محاكاة إرسال ناجحة';db.ejarLogs.unshift({id:nextId('ejarLogs'),date:new Date().toLocaleString('ar-SA'),action:a==='mockEjar'?'إرسال تجريبي':'فحص',message});persist();alert(message+(a==='mockEjar'&&!errors.length?'\nتمت المحاكاة محليًا فقط':''));render()}});
document.addEventListener('change',async e=>{
 if(e.target.id!=='restoreBackupInput')return;
 const input=e.target,file=input.files?.[0];input.value='';
 if(!file)return;
 if(file.size===0||file.size>10*1024*1024){alert('حجم ملف النسخة غير صالح أو يزيد على 10 ميجابايت');return}
 let restored;
 try{restored=window.AqariDemoBackup.validate(JSON.parse(await file.text()))}
 catch(err){alert('رفض استعادة النسخة: '+err.message);return}
 if(!confirm('سيتم استبدال جميع بيانات المحفظة المحلية وحذف جميع المرفقات المخزنة في هذا المتصفح. نسخة JSON لا تحتوي على المرفقات. احتفظ بنسخة احتياطية ونزّل المرفقات أولًا. هل تؤكد الاستعادة؟'))return;
 const previous=db;
 try{
  localStorage.setItem(KEY,JSON.stringify(restored));
  if(!window.AqariAttachments?.clearAll)throw Error('أداة حذف المرفقات غير متاحة');
  await window.AqariAttachments.clearAll();
  db=restored;current='dashboard';filter='all';search='';render();
  alert('تمت استعادة البيانات التجريبية بنجاح. المرفقات القديمة حُذفت ولا توجد مرفقات داخل JSON.');
 }catch(err){
  try{localStorage.setItem(KEY,JSON.stringify(previous))}catch{}
  db=previous;
  alert('فشلت الاستعادة، وتمت محاولة استرجاع البيانات السابقة: '+err.message);
 }
});
document.addEventListener('change',e=>{if(e.target.id==='propertyFilter'){filter=e.target.value;render();return}if(e.target.closest('#editForm')&&['propertyId','contractPropertyId'].includes(e.target.name))updateUnitChoices(e.target.closest('#editForm'))});
document.addEventListener('input',e=>{if(['findText','expenseInvoiceFilter'].includes(e.target.id)){const targetId=e.target.id,value=e.target.value,pos=e.target.selectionStart;if(targetId==='findText')search=value;else invoiceFilter=value;render();let n=document.getElementById(targetId);if(n){n.focus();n.setSelectionRange(pos,pos)}}});
document.addEventListener('submit',e=>{if(e.target.id==='ownerProfileForm'){e.preventDefault();const fields=Object.fromEntries(new FormData(e.target));if(!db.owners.length)db.owners.push({id:1,name:fields.name,phone:fields.phone,fee:0});else{db.owners[0].name=fields.name;db.owners[0].phone=fields.phone;}persist();$('#modal').innerHTML='';render();return}if(e.target.id!=='editForm')return;e.preventDefault();const form=e.target,k=form.dataset.kind,f=Object.fromEntries(new FormData(form)),record={id:nextId(k==='payment'?'payments':k),...f};for(const key in record)if(['amount','total','installments','deposit','cost','area','fee'].includes(key))record[key]=Number(record[key]);if(k==='contracts'){const chosenProperty=f.contractPropertyId;if(!db.units.some(unit=>String(unit.id)===String(record.unitId)&&String(unit.propertyId)===String(chosenProperty))){alert('الوحدة المختارة لا تتبع العقار المحدد');return}delete record.contractPropertyId}if(k!=='payment'){try{window.AqariDemoFinance.validateRecordRelation(k,record,db)}catch(err){alert(err.message);return}}if(k==='payment'){const d=find('dues',Number(form.dataset.extra));if(!d){alert('القسط غير موجود');return}try{record.amount=window.AqariDemoFinance.validatePayment(f.amount,d.amount,db.payments.filter(x=>x.dueId===d.id).map(x=>x.amount));record.reference=window.AqariDemoFinance.validateReceiptReference(f.reference,db.payments)}catch(err){alert(err.message);return}record.dueId=d.id;db.payments.push(record);log('تسجيل تحصيل','سند #'+record.id)}else if(k==='contracts'){if(record.start>record.end||record.total<=0||![1,2,4,12].includes(record.installments)){alert('بيانات العقد غير صحيحة؛ الأقساط 1 أو 2 أو 4 أو 12');return}if(db.contracts.some(x=>x.unitId==record.unitId&&x.start<=record.end&&record.start<=x.end)){alert('يوجد عقد متداخل على هذه الوحدة');return}let installments;try{installments=window.AqariDemoFinance.createLeaseSchedule(record.start,record.end,f.total,record.installments)}catch(err){alert(err.message);return}record.ejarStatus='مسودة محلية';db.contracts.push(record);const firstDueId=nextId('dues');installments.forEach((due,i)=>db.dues.push({id:firstDueId+i,contractId:record.id,...due}));log('إنشاء عقد',record.id)}else{if(k==='properties')record.ownerId=1;if(k==='expenses'){try{window.AqariDemoFinance.validateUniqueExpense(record,db.expenses)}catch(err){alert(err.message);return}}db[k].push(record);log('إضافة سجل',k)}persist();$('#modal').innerHTML='';render()});
render();
})();