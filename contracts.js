/* Local demonstration contract lifecycle only; does not change official Ejar contracts. */
(function(root){
'use strict';
function validDate(date){
 if(typeof date!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(date))return false;
 const d=new Date(date+'T00:00:00Z');
 return Number.isFinite(d.getTime())&&d.toISOString().slice(0,10)===date;
}
function nextDay(date){
 if(!validDate(date))throw Error('تاريخ غير صحيح');
 const d=new Date(date+'T00:00:00Z');d.setUTCDate(d.getUTCDate()+1);
 return d.toISOString().slice(0,10);
}
function effectiveEnd(contract){
 return contract.vacatedAt&&validDate(contract.vacatedAt)&&contract.vacatedAt<contract.end?contract.vacatedAt:contract.end;
}
function conflicts(contracts,candidate,skipId){
 if(!validDate(candidate.start)||!validDate(candidate.end)||candidate.start>candidate.end)return true;
 return contracts.some(x=>String(x.id)!==String(skipId??'')&&String(x.unitId)===String(candidate.unitId)&&x.start<=candidate.end&&candidate.start<=effectiveEnd(x));
}
function canVacate(contract,date){
 return validDate(date)&&date>=contract.start&&date<=contract.end&&!contract.vacatedAt;
}
function state(contract,today){
 if(contract.vacatedAt)return 'تم الإخلاء';
 if(today<contract.start)return 'قادم';
 if(today>contract.end)return 'منتهي';
 return 'ساري';
}
function expiring(contracts,today,days){
 if(!validDate(today)||!Number.isInteger(days)||days<0)throw Error('Invalid expiring query');
 const until=new Date(today+'T00:00:00Z');until.setUTCDate(until.getUTCDate()+days);
 const max=until.toISOString().slice(0,10);
 return contracts.filter(c=>!c.vacatedAt&&c.end>=today&&c.end<=max);
}
root.AqariContracts=Object.freeze({validDate,nextDay,effectiveEnd,conflicts,canVacate,state,expiring});
})(typeof window!=='undefined'?window:globalThis);
