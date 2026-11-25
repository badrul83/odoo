# DEPO ERP — কাজ হস্তান্তর (২১ নভেম্বর ২০২৬)

## এই ফাইলই নতুন কাজের scope

ব্যবহারকারীর সর্বশেষ scope: বিদ্যমান PHP project-এ Odoo 20 Enterprise-ধাঁচে শুধু এই ১২টি module-এর flow সম্পূর্ণ করা: Dashboard, Sales, Purchase, Inventory, Invoice, CRM, To-do, Accounts, Contacts, Contract, Appointment, Settings। বর্তমান project structure ও screen যতটা সম্ভব অক্ষত রাখতে হবে। নতুন scope হিসেবে অন্য module যোগ করা যাবে না।

`ERP20_PHP_IMPLEMENTATION.md` হলো আগের implementation-এর ঐতিহাসিক নোট। সেখানে থাকা ১২-module scope-এর বাইরের backlog-কে নতুন নির্দেশ হিসেবে ধরবে না। PHP tree-তে Calendar, Discuss, Manufacturing-এর মতো অতিরিক্ত/পূর্ববর্তী code থাকতে পারে; এগুলোর কাজ এগিয়ে নিও না এবং শুধু archive পরিষ্কার করার জন্য existing source structure ভেঙো না। Launcher থেকে এগুলো hide করা হয়েছে।

## Project

মূল project location: `C:\xampp\htdocs\ODOO`

PHP app: `php-erp/`

XAMPP Apache চালু থাকলে entry URL: `http://localhost/ODOO/login.php`। এই URL শুধু app-এ ঢোকার পথ; business flow চালাতে project-এর নিজস্ব MySQL database/configuration লাগবে।

## বর্তমান বাস্তব অবস্থা

- PHP ERP source, migrations, root launcher UI-র প্রাসঙ্গিক পরিবর্তিত files এবং implementation notes এই ZIP-এ আছে।
- `.env.example`-এ placeholder আছে। কোনো private `.env`, database dump, session file, QA data folder বা অন্য project-এর data ZIP-এ নেই।
- Project-এর নিজস্ব DB connection এখানে configured নেই; MariaDB-তে project connected—এমন দাবি নেই। SQL migrations, database persistence এবং browser end-to-end flow live DB-তে যাচাই করা হয়নি।
- সর্বশেষ code checks: ৮১টি PHP ফাইলে lint pass, `php-erp/public/app.js` এবং `booking.js`-এ Node syntax check pass, `git diff --check` pass। এগুলো runtime/DB verification নয়।
- ZIP বানানোর সময় session files বাদ দেওয়া হয়েছে; `php-erp/storage`-এ শুধু security scaffold (`.htaccess`, `index.php`) রাখা হয়েছে।

## শুরু করার নির্দেশনা

1. আগে project files ও `ERP20_PHP_IMPLEMENTATION.md` পড়ে বর্তমান code inventory করো। কোনো file বা data অন্য project থেকে নিও না।
2. ১২টি module-এর জন্য form field → JS payload → route → permission → repository/SQL → linked record → dashboard/KPI—এই ক্রমে one-by-one audit করো; বাদ পড়া field বা ভাঙা hand-off থাকলে code-এ ঠিক করো।
3. Local MySQL project configuration অনুপস্থিত থাকলে অনুমান করে migrate বা sample business data লিখবে না। User-এর project database configuration দরকার হবে runtime verification-এর জন্য। MariaDB project-কে ব্যবহার/সংযুক্ত করবে না।
4. প্রতিটি পরিবর্তনের পরে PHP/JS syntax ও relevant route/data-flow checks করে ফল আলাদা করে জানাবে; code-level check-কে end-to-end verified বলবে না।
5. User যে screenshots দিয়েছেন কেবল সেই design/fields এবং ১২-module DEPO workflow-তেই সীমাবদ্ধ থাকবে।
