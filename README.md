# Systems Plus Computer College — Grading and Academic Evaluation

Faculty encode grades in the same workbook layout used in class: prelim, midterm, and finals, with attendance, recitation, activities, quizzes, project, and exam. Offices then review that record from the Program Chair through the College OIC, OSA, and the Registrar.

The site runs on Apache, PHP 8.2+, and MySQL/MariaDB. Locally that is XAMPP. On a host, point the domain at this folder over HTTPS.

## Install

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open phpMyAdmin at `http://localhost/phpmyadmin`.
3. In phpMyAdmin, create and open a database named `spcc_academic`, then import `database/database.sql`. The file does not create the database, because shared hosts block that.
4. Open `http://localhost/Grade%20Predict/`.
5. Professors sign in at `professor/login.php`; college offices sign in at `admin/login.php`.

## Hosting

1. Upload the project folder to the host. The web root can be this folder, or a subfolder such as `public_html/grades`.
2. In the host phpMyAdmin, open the database you were given, then import `database/database.sql`. Do not import it from the server home screen. The file loads into the database that is already open.
3. Copy `.env.example` to `.env` and set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`. Set `APP_DEBUG=false`.
4. Serve the site with HTTPS. The installable app and offline score drafts need a secure address. `localhost` is the exception used for XAMPP.
5. Open the site from the hosted address. Paths follow the folder the host uses, so the same files work at a domain root or in a subfolder.

## Offline grading

Professors can install the site from the browser (Add to Home Screen or Install app). Open a class once while online. If the connection drops, scores typed on that sheet stay on the device and are marked as drafts. When the device is online again, those drafts are sent to the database. Submit a period only after the drafts have synced. Category percents still need a connection.

## Demo accounts

Every sample account uses the password `ChangeMe!2026`. These are sample records, not official college data.

| Portal | Username | Who |
| --- | --- | --- |
| Professor | `fquiza` | Mr. Febrick Quiza, BSIT 401-C |
| Professor | `psantos` | BSHM section only |
| Administrative | `admin` | System Administrator |
| Administrative | `chair.bsit` | BSIT Program Chair |
| Administrative | `chair.bshm` | BSHM Program Chair |
| Administrative | `oic` | College Department OIC |
| Administrative | `osa` | Office of Student Affairs |
| Administrative | `registrar` | Registrar |

`fquiza` cannot open `psantos` classes. `chair.bsit` cannot review the BSHM submission.

## How a grade is computed

The sheet matches the faculty workbook:

- Columns are Attendance WK1–WK5, Recitation REC#1–REC#5, Activities ACT#1–ACT#5, Quizzes Q1–Q5, Project, and Exam, repeated for Prelim, Midterm, and Finals.
- A blank cell is not encoded yet and is left out of the total. A typed `0` is a real zero.
- A category percentage is points earned divided by points possible.
- The period percentage is the weighted sum of those categories. Demo weights are 10 / 10 / 10 / 15 / 15 / 40.
- Final percentage uses the term shares. The demo default is prelim 30, midterm 30, finals 40. That default is not a confirmed college rule.
- The equivalent table on the sheet is fixed: 98–100% = 1.00 Excellent through 75–76% = 3.00 Passed, and below 75% = 5.00 Failed.
- Remarks are PASSED, FAILED, or INC.

## Review path

Professor submits a complete class → Program Chair → College Department OIC → OSA → Registrar. A return sends the sheet back to the professor with a required note. The Registrar’s approval writes the official grade and locks the sheet.

## DL / PL and scholarships

GWA is the unweighted average of subject equivalents. Regular and irregular students use the same ranges. A subject grade of 2.00 or higher disqualifies a student. GWA 1.00–1.25 is Possible PL. GWA 1.26–1.75 is Possible DL.

Scholarship discounts are blank until someone records a confirmed percentage. A scholarship can be assigned only after the official evaluation is Possible DL, Possible PL, or Final Approved.

## What was kept from the previous predictor

Student numbers and names from the earlier system, the subject codes, the three saved prediction sessions, the 120-row training table, the grade equivalent bands, and the linear-regression coefficients.
