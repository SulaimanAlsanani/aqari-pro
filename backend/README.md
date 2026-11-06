# Aqari Pro — production API foundation

This is a **development-stage Laravel 12 API**, intended to replace browser-only storage in Aqari Pro V1 for an individual property owner.

## Local setup
1. PHP 8.3+, Composer, SQLite or MySQL, PHP extensions mbstring, fileinfo, PDO SQLite/MySQL and bcmath.
2. `cd backend && composer install`
3. `cp .env.example .env && php artisan key:generate`
4. Create `database/database.sqlite` when using SQLite, then `php artisan migrate`.
5. Provision the initial owner interactively with `php artisan owner:create owner@example.com 'اسم المالك'` on the server (no public registration endpoint, no password in shell history).
6. `php artisan serve`
7. `php artisan test`.

## واجهة المالك المتصلة فعليًا
- بعد تشغيل Laravel وتهيئة حساب مالك، افتح `http://localhost:8000/app/index.html`.
- الواجهة تستخدم `/api` على نفس الأصل لتجنب مشاركة الرموز عبر جهات أخرى، وتحتفظ برمز الدخول في ذاكرة الصفحة فقط.
- تشمل لوحة المؤشرات، والقوائم المحمية، والنماذج الأساسية، ورفع المرفقات وتنزيلها.
- ما تزال قيد التطوير: عمليات التعديل والحذف والتصديرات والإشعارات والحسابات التفصيلية.
- موقع GitHub Pages العام **لا يستخدم هذه الواجهة الجديدة بعد**؛ لا ترفع عليه مستندات حقيقية.

## API
- `POST /api/login` issues a short-lived Sanctum Bearer token.
- Protected: `GET /api/me`, `POST /api/logout`.
- `GET /api/portfolio` and `GET /api/overview` show owner-scoped information.
- Properties, units, tenants, leases, payments, due schedules, expenses and maintenance.
- `POST /api/documents` stores private uploaded files with ownership checks, file type and size restrictions.
- `GET /api/documents/{id}` and `DELETE /api/documents/{id}` are owner-authorized.

## Security invariants
- All domain queries are restricted to the authenticated owner.
- A property, unit, tenant, lease or payment ID owned by another user must return 404.
- Document uploads live on the private disk and require explicit ownership validation.
- Public self-registration is disabled.
- Every deployment must use HTTPS, `APP_DEBUG=false`, proper server secrets, backup policies, monitoring and secret management.
- GitHub Pages remains **demo-only** and is not connected to this API.

## Not yet launch-ready
Frontend authentication and API integration, robust password reset / account recovery, audit history, invoice and receipt workflows, notifications, encrypted backup/restore, admin provisioning, operations documentation, secure hosting, security review and end-to-end testing are still required.
