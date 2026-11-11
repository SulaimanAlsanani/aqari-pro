# Aqari Pro — التحقق من النسخ المشفرة على S3 الحقيقي

الأداة `backend/scripts/verify-real-s3-recovery.sh` تُجري **تجربة استعادة فعلية** لنسختين مشفرتين من S3: نسخة PostgreSQL ونسخة المرفقات المشار إليها في قاعدة البيانات. لم تُشغّل الأداة حتى الآن مع مخزن حقيقي، ولا تعني اختبارات GitHub على S3 المحاكى نجاح التخزين الفعلي.

## الشروط الأساسية (خارج GitHub)

- خادم استعادة **منفصل ومعزول عن الإنتاج**، بصلاحية S3 للقراءة فقط من نطاق المفاتيح المطلوبة.
- اعتماد قائمة المفاتيح التي أنتجتها عمليتا النسخ: `offsite-backup.sh` و `offsite-attachments.sh`، مع ملف `.ok` المقابل لكل نسخة.
- ملف الهوية الخاص لـ `age` محفوظ في مسار خاص على خادم الاستعادة (وضع `0600`)، وليس داخل المستودع أو داخل S3.
- قاعدة PostgreSQL **فارغة ومُنشأة مسبقًا** باسم يبدأ بـ `aqari_restore_`. لا تستخدم قاعدة أصلية أو مشتركة.
- نسخة Laravel منفصلة للاختبار، بلا `bootstrap/cache/config.php` وببيانات اتصال قاعدة الاستعادة، كي يتحقق الأمر `aqari:verify-attachment-export` من المرفقات والسجلات المستعادة.
- مساحة مؤقتة خاصة كافية للنسخ المشفرة وغير المشفرة؛ تُحذف الملفات المؤقتة بعد التشغيل.

تهيئة المتغيرات من مخزن أسرار خاص، **وليس من محادثة أو ملف يُرسل إلى GitHub**:

```dotenv
AQARI_VERIFY_BUCKET=your-private-recovery-bucket
AQARI_VERIFY_DB_KEY=aqari-pro/postgresql/CHOSEN-OBJECT.tar.age
AQARI_VERIFY_ATTACHMENTS_KEY=aqari-pro/attachments/CHOSEN-OBJECT.tar.age
AQARI_VERIFY_AGE_IDENTITY=/secure-offline/recovery-identity.txt
AQARI_VERIFY_WORKDIR=/srv/private-recovery-work
AQARI_VERIFY_RESTORE_DB=aqari_restore_validation01
AQARI_VERIFY_LARAVEL_ROOT=/srv/isolated-recovery-laravel
PGHOST=isolated-recovery-postgres
PGPORT=5432
PGUSER=recovery_operator
PGPASSWORD=from-protected-secret-store
DB_DATABASE=aqari_restore_validation01
```

بعد مراجعة أسماء الخوادم والمخزن والصلاحيات:

```bash
bash backend/scripts/verify-real-s3-recovery.sh
```

### ما الذي تثبته الأداة؟

1. تنزيل ملفات `.tar.age` وملفات الإكمال `.ok` من S3 عن طريق `get-object`.
2. مقارنة SHA-256 للملف المشفّر بما سُجل في ملف الإكمال؛ ورفض التغيّر.
3. فك التشفير باستخدام الهوية الخاصة لـ `age`؛ رفض رمز أو ملف غير صالح.
4. رفض أسماء الملفات الخطرة والعناصر غير المتوقعة قبل استخراج الأرشيف.
5. اختبار `pg_restore` داخل قاعدة `aqari_restore_*` الفارغة؛ لا حذف ولا استبدال لقاعدة أخرى.
6. مطابقة المرفقات وبصماتها الرقمية وسجلات PostgreSQL المستعادة بواسطة Laravel.
7. ترك قاعدة الاستعادة منفصلة بعد الاختبار لأغراض التدقيق؛ ومسح الملفات المؤقتة التي تحتوي على بيانات مفكوك تشفيرها.

**هذا ليس فحصًا لإعدادات IAM العامة أو سياسة الحذف أو إنذارات التخزين أو استمرارية الخدمة.** يجب فحص منع الوصول العام، التشفير على مستوى المخزن، سياسات الاحتفاظ والنسخ الاحتياطي المتكرر، وتسجيل أحداث التنزيل لدى مزود S3. كما يجب تأكيد تطابق وقتي نسختي PostgreSQL والمرفقات في حالة النظام أثناء التشغيل.

لا يُسمح اعتبار اختبار S3 الحقيقي ناجحًا إلا عند ظهور رسالة النجاح **من هذا التشغيل على خادم الاستعادة المخصص** مع سجل خالٍ من الأخطاء. إذا لم توجد البنية الخاصة فلا يمكن إصدار ادعاء نجاح.
