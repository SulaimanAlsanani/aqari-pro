/* Private document staging UI. Loaded only in the first-party HTTPS staging app. */
(function (root) {
  'use strict';

  function create({api, task, status, document: doc}) {
    const $ = id => doc.getElementById(id);
    let properties = [];
    let contracts = [];
    let page = 1;
    let lastPage = 1;

    function label(tag, content) {
      const node = doc.createElement(tag);
      node.textContent = String(content ?? '');
      return node;
    }

    async function allPages(query) {
      const records = [];
      let last = 1;
      for (let index = 1; index <= last; index++) {
        const response = await query(index);
        last = Number(response.last_page) || 1;
        if (last > 25) throw new Error('تتجاوز البيانات 500 سجل؛ يلزم البحث الخادمي للقوائم الكبيرة.');
        records.push(...(response.data || []));
      }
      return records;
    }

    function parentOptions() {
      const previous = $('attachmentParent').value;
      $('attachmentParent').replaceChildren();
      const list = $('attachmentType').value === 'contract' ? contracts : properties;
      for (const record of list) {
        const option = label('option',
          $('attachmentType').value === 'contract' ?
            'عقد #' + record.id + ' — وحدة ' + (record.unit?.code || record.unit_id) :
            record.name + ' — ' + record.city);
        option.value = String(record.id);
        $('attachmentParent').append(option);
      }
      if (list.some(record => String(record.id) === previous)) $('attachmentParent').value = previous;
      syncControls();
    }

    async function refreshChoices() {
      [properties, contracts] = await Promise.all([
        allPages(index => api.properties(index)),
        allPages(index => api.contracts(index))
      ]);
      parentOptions();
      await refreshList();
    }

    async function refreshList() {
      $('attachmentList').replaceChildren();
      const parentId = $('attachmentParent').value;
      if (!parentId) {
        $('attachmentPager').replaceChildren();
        lastPage = 1;
        syncControls();
        return;
      }

      const response = $('attachmentType').value === 'contract'
        ? await api.contractAttachments(parentId, page)
        : await api.propertyAttachments(parentId, page);

      page = Number(response.current_page) || page;
      lastPage = Math.max(1, Number(response.last_page) || 1);
      for (const file of response.data || []) {
        const row = doc.createElement('li');
        const info = doc.createElement('div');
        info.append(label('strong', file.original_name),
          label('small', ' · ' + (Number(file.size_bytes || 0) / 1024).toFixed(1) + ' KB'));
        const download = label('a', 'تنزيل آمن');
        download.href = api.attachmentDownloadUrl(file.id);
        // Laravel sends Content-Disposition: attachment; never embed sensitive files.
        download.rel = 'noopener';
        row.append(info, download);
        $('attachmentList').append(row);
      }

      const pager = $('attachmentPager');
      pager.replaceChildren();
      const previous = label('button', 'السابق');
      previous.type = 'button';
      previous.addEventListener('click', () => task(async () => {
        page--;
        await refreshList();
      }));
      const next = label('button', 'التالي');
      next.type = 'button';
      next.addEventListener('click', () => task(async () => {
        page++;
        await refreshList();
      }));
      pager.append(previous, label('span', 'صفحة ' + page + ' من ' + lastPage), next);
      syncControls();
    }

    function syncControls() {
      const controls = $('attachmentPager').querySelectorAll('button');
      if (controls[0]) controls[0].disabled = page <= 1;
      if (controls[1]) controls[1].disabled = page >= lastPage;
      const submit = $('attachmentForm').querySelector('button[type="submit"]');
      if (submit) submit.disabled = !$('attachmentParent').value;
    }

    function reset() {
      page = 1;
      lastPage = 1;
      properties = [];
      contracts = [];
      $('attachmentForm').reset();
      for (const id of ['attachmentParent', 'attachmentList', 'attachmentPager']) $(id).replaceChildren();
      syncControls();
    }

    $('attachmentType').addEventListener('change', () => task(async () => {
      page = 1;
      parentOptions();
      await refreshList();
    }));
    $('attachmentParent').addEventListener('change', () => task(async () => {
      page = 1;
      await refreshList();
    }));
    $('attachmentForm').addEventListener('submit', event => {
      event.preventDefault();
      task(async () => {
        const parent = $('attachmentParent').value;
        const file = $('attachmentFile').files?.[0];
        if (!parent || !file) throw new Error('اختر السجل والملف أولًا.');
        if (file.size > 10 * 1024 * 1024) throw new Error('الملف أكبر من 10 ميجابايت.');
        if ($('attachmentType').value === 'contract') {
          await api.uploadContractAttachment(parent, file);
        } else {
          await api.uploadPropertyAttachment(parent, file);
        }
        $('attachmentFile').value = '';
        page = 1;
        await refreshList();
        status('تم رفع المرفق إلى التخزين الخاص بالخادم');
      });
    });

    return Object.freeze({refresh: refreshChoices, refreshChoices, refreshList, reset, syncControls});
  }

  root.AqariAttachmentStaging = Object.freeze({create});
})(typeof window !== 'undefined' ? window : globalThis);
