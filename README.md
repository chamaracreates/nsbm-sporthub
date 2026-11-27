# NSBM SportsHub

Sports Club Management System

NSBM SportsHub is a web application for managing sports activities at NSBM Green University. Students can join sports clubs, register for competitions, book facilities and track their participation. Administrators and sports staff manage club operations, schedules, results and attendance.

Developed as a group project for the Web and Mobile Application Development module, Faculty of Computing, NSBM Green University (Batch 26.1).

**Live application:** [nsbmsportshub.rf.gd](https://nsbmsportshub.rf.gd/)

## Features

- Sports club membership applications, staff assignments and club leadership
- Tournament applications, registration review, team formation and rosters
- Match registration, fixtures, scores, results and standings
- Training schedules and attendance records
- Facility booking requests and approvals
- Sports news, global search and student participation history
- Administrative dashboard, filtered reports and CSV exports

The application supports administrator, student, coach and Member in Charge (MIC) accounts. Coach and MIC access is restricted to assigned clubs and permitted operations. Public sports information is available without an account.

## Technology stack

- HTML, CSS and JavaScript
- PHP 8.3, Apache and PDO
- MySQL 8.4 (local development); MariaDB (shared hosting)
- Docker Compose, Git and GitHub

## Project structure

```text
admin/          Administration and news
assets/         CSS, JavaScript, images and uploads
bookings/       Facility reservations
clubs/          Clubs, memberships and staff
database/      Database schema and seed data
includes/       Shared PHP components
matches/        Fixtures and results
reports/        Reports and CSV exports
student/        Student accounts and history
tournaments/    Tournaments, registrations and teams
training/       Training and attendance
```

The application uses server-rendered PHP pages and a relational database. Shared authentication and database functions are located in `includes/`.

## Local installation

Requires Git and Docker Desktop or Docker Engine with Docker Compose.

```sh
git clone https://github.com/chamaracreates/nsbm-sporthub.git
cd nsbm-sporthub
```

Create `.env` in the repository root with the following settings. Replace the password placeholder with a unique local password.

```dotenv
DB_HOST=db
DB_PORT=3306
DB_NAME=nsbm_sports_db
DB_USER=sportshub_app
DB_PASSWORD=YOUR_LOCAL_PASSWORD
```

Clear any previously exported `DB_*` environment variables if they conflict with these settings. Do not commit `.env`.

```sh
docker compose --profile local-db up -d --build --wait
```

The application is available at [http://localhost:8086](http://localhost:8086/). The database is initialized automatically on first startup and stored in a persistent Docker volume.

Stop the services with `docker compose --profile local-db stop` to retain the database.

### Existing MySQL server

For a new external database, import `database/nsbm_sports_db.sql` followed by `database/seed.sql` into an empty MySQL database. Configure a dedicated application user with SELECT, INSERT, UPDATE and DELETE privileges on that database.

Set the connection details in `.env` using `.env.example` as a reference. On Docker Desktop, use `host.docker.internal` for a database running on the host, with its actual port. Start only the web service:

```sh
docker compose up -d --build web
```

Do not enable the `local-db` profile when using an external database.

### Local administrator

Student accounts are created through the registration page. To create a local administrator, replace the example email below with your own and run the command from the repository root with the web service running.

```sh
docker compose exec -T web php -r '
require "/var/www/html/includes/db.php";
$email = "admin@example.invalid";
if ($email === "admin@example.invalid" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Set a valid administrator email.\n");
}
$password = bin2hex(random_bytes(16));
$stmt = db()->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)");
$stmt->execute(["Local Administrator",$email,password_hash($password,PASSWORD_DEFAULT),"admin"]);
echo "Email: $email\nPassword: $password\n";
'
```

Keep the generated password private. The seed content-author record is not a login account.

## Database

- `database/nsbm_sports_db.sql` — schema, keys, constraints and participation view.
- `database/seed.sql` — initial public sports content, without private student records or usable login credentials.

The schema contains 18 base tables. Existing installations retain their data; initialization scripts run only on an empty database volume. Changing `.env` does not update an existing MySQL account password.

### Schema updates

Fresh installations do not require the following SQL. For earlier installations, back up the database and inspect the relevant columns using `SHOW COLUMNS FROM table_name;` before applying changes. Run only the missing `ADD COLUMN` clauses using a database administrator account; omit statements with no missing columns.

```sql
ALTER TABLE club_membership
    ADD COLUMN experience_level VARCHAR(20) DEFAULT NULL,
    ADD COLUMN application_reason VARCHAR(500) DEFAULT NULL;

ALTER TABLE matches
    ADD COLUMN registration_open TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE tournament_registration
    ADD COLUMN experience_level VARCHAR(20) DEFAULT NULL,
    ADD COLUMN participation_notes VARCHAR(500) DEFAULT NULL,
    ADD COLUMN experience_duration VARCHAR(30) DEFAULT NULL,
    ADD COLUMN competition_level VARCHAR(40) DEFAULT NULL,
    ADD COLUMN preferred_event VARCHAR(100) DEFAULT NULL,
    ADD COLUMN sporting_achievements VARCHAR(500) DEFAULT NULL,
    ADD COLUMN declaration_accepted_at DATETIME DEFAULT NULL;
```

Do not reimport the schema or seed into a populated database or remove database volumes to apply updates.

## Deployment

The live application is hosted on InfinityFree using PHP, MariaDB and HTTPS. Docker is not required on the hosting server.

For a fresh shared-hosting database, import a compatible copy of the schema followed by the seed. Replace `utf8mb4_0900_ai_ci` with `utf8mb4_general_ci` in the copy and remove the stand-in table and view definition for `v_student_participation_summary`, including its associated `DROP TABLE` statement. Preserve all base tables and foreign keys. The application queries the base tables directly for student participation history.

Upload the PHP entry points and application directories to the hosting document root via FTPS. Exclude the SQL files, Git metadata, Docker files, environment files and backups. Preserve `includes/.htaccess` and `assets/uploads/club-news/.htaccess` and ensure the news upload directory is writable by the web server.

Configure all five database environment variables or, when none is defined, use a protected `includes/db.local.php` returning an array with the same five `DB_*` keys. Enable HTTPS and provision a separate administrator with a PHP-generated password hash. Verify internal files are inaccessible over HTTP before storing credentials.

## Security

The application uses PHP password hashing, session authentication, role and ownership checks, PDO prepared statements, CSRF protection and server-side validation. Private configuration files and uploaded news images are excluded from Git. Never publish real credentials or database backups.

This project is intended for academic demonstration and evaluation; it is not an official university service.
