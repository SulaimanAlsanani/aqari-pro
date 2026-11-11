# تشغيل عقاري برو كاملًا على GitHub Codespaces

يمكنك تشغيل **Laravel 12 وPostgreSQL 16** وتجربة تسجيل الدخول والعقارات والعقود والتحصيل والمرفقات من المتصفح، دون تثبيت Docker على جهازك.

## بدء التشغيل

**[فتح Aqari Pro في GitHub Codespaces](https://codespaces.new/SulaimanAlsanani/aqari-pro?quickstart=1)**

1. سجّل الدخول إلى GitHub واضغط **Create codespace** أو **Resume** إذا كان لديك Codespace سابق.
2. سيقرأ GitHub الملف `.devcontainer/devcontainer.json` وينشئ بيئة Linux فيها Docker-in-Docker. يبدأ السكربت `.devcontainer/start-codespace.sh` تلقائيًا عند فتح المحرر.
3. بعد نجاح بناء صورة Laravel، افتح تبويب **PORTS** واختر المنفذ **8080**. يجب أن يكون مستوى ظهور المنفذ **Private** وليس Public.
4. من عنوان المنفذ الذي يوفّره GitHub (عادةً `https://CODESPACE-NAME-8080.app.github.dev`)، افتح المسار `/staging/`.
5. افتح **Terminal** داخل Codespace وأنشئ أول حساب مالك تجريبي بالأمر التالي:

```bash
docker compose --env-file backend/deploy/local/.env.local \
  -f backend/deploy/local/compose.yaml \
  exec app php artisan aqari:provision-owner owner@example.com --name="Test Owner"
```

سيطلب الأمر كلمة مرور قوية دون طباعتها. استبدل البريد واسم المالك ببيانات تجريبية. لا يوجد تسجيل عام للملاك.

## إعادة التشغيل والمراقبة

عند إعادة فتح Codespace، يحاول السكربت تشغيل Docker وقاعدة البيانات والواجهة تلقائيًا. إذا لم يظهر المنفذ بعد الانتهاء من تهيئة البيئة، نفّذ من جذر المشروع:

```bash
bash backend/deploy/local/start.sh --codespaces
```

تابع حالة الحاويات:

```bash
docker compose --env-file backend/deploy/local/.env.local \
  -f backend/deploy/local/compose.yaml ps
```

إذا رغبت باختبار رفع مستندات تجريبية، حدّث توقيعات ClamAV أولًا:

```bash
docker compose --env-file backend/deploy/local/.env.local \
  -f backend/deploy/local/compose.yaml \
  --profile maintenance run --rm clamav-update
```

لإيقاف الخدمات مع الاحتفاظ بمجلدات قاعدة البيانات داخل Codespace:

```bash
docker compose --env-file backend/deploy/local/.env.local \
  -f backend/deploy/local/compose.yaml down
```

**لا تستخدم `down --volumes` إذا كانت هناك بيانات تجريبية تريد الاحتفاظ بها.** وعند حذف Codespace نفسه قد تضيع البيانات الموجودة في حاوياته. لا تعتبرها نسخًا احتياطية.

## ما يختلف عن GitHub Pages؟

- [GitHub Pages](https://sulaimanalsanani.github.io/aqari-pro/) يعرض نموذجًا ثابتًا مستقلًا يخزّن بياناته داخل المتصفح. لا يحتوي على خادم Laravel.
- **Codespaces** يشغّل Laravel وPostgreSQL فعليين داخل البيئة السحابية المؤقتة. واجهة Staging تتصل بالـAPI والجلسات والبيانات في PostgreSQL.
- جرى ضبط `APP_URL` ديناميكيًا باستخدام `CODESPACE_NAME` و`GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN`، وضُبط `SANCTUM_STATEFUL_DOMAINS` لعنوان المنفذ الحقيقي، وجرى تفعيل `SESSION_SECURE_COOKIE` لأن GitHub يوفّر واجهة HTTPS.
- منفذ التطبيق الداخلي مربوط بـ `127.0.0.1:8080`، ولا يُنشر منفذ PostgreSQL. لا تعتمد على حماية Laravel وحدها: **اترك Port Visibility = Private**. GitHub يفرض تسجيل الدخول للوصول للمنفذ الخاص.

## حدود النسخة

هذه بيئة **تجريبية غير دائمة**، وليست موقعًا عامًا أو خادم إنتاج. تتوقف عند السكون، وتتأثر بحدود GitHub Codespaces الشهرية. لا تدخِل بيانات ملاك حقيقية أو عقودًا حساسة أو مفاتيح إنتاج في Codespaces. استعادة كلمة المرور بالبريد غير فعّالة افتراضيًا لأن SMTP غير مربوط، والمرفقات تحتاج توقيعات ClamAV حديثة.

على الحسابات الشخصية توفر GitHub حصة شهرية مجانية تختلف حسب الخطة، وقد تتوقف البيئة عند استنفاد الحصة. تحتاج إلى الموافقة بنفسك لإنشاء Codespace؛ إضافة الملفات إلى GitHub لا تنشئ خادمًا يعمل باستمرار.

**إذا ظهر فشل أثناء البناء:** افتح Terminal ونفّذ `bash .devcontainer/start-codespace.sh`، ثم راجع سبب المشكلة في Docker أو الإنترنت أو الموارد. اختبارات GitHub Actions تختبر تهيئة الحاويات؛ تشغيل Codespaces الفعلي يحتاج إنشاء البيئة من حسابك.
