# NSBM SportsHub

**Sports Club Management System - NSBM Green University**

NSBM SportsHub is a web-based sports management system designed to centralize the administration of sports clubs, competitions, training sessions and facility reservations at NSBM Green University. The platform provides a unified interface for students, sports staff and administrators to coordinate sports activities, manage participation and access institutional sports information.

## Features

| Module | Functionality |
| --- | --- |
| Sports Clubs | Club profiles, membership applications, staff assignments and club leadership management. |
| Tournaments | Competition management, registration, team formation and roster management. |
| Fixtures and Standings | Match scheduling, score recording, results and tournament standings. |
| Training | Session scheduling, venue allocation and attendance management. |
| Facility Bookings | Facility information, reservation requests, availability validation and administrative approval. |
| News | Club news publishing, image management and chronological news display. |
| Student Accounts | Registration, profile management, password changes and participation history. |
| Administration and Reporting | Centralized management, operational statistics, filtered reports and CSV exports. |

The public portal also provides global search and a responsive interface across desktop, tablet and mobile devices.

## User Roles

| Role | Responsibilities |
| --- | --- |
| Administrator | System-wide administration, club and staff management, content publishing, request approvals and reporting. |
| Member in Charge (MIC) | Management of authorized sports activities within assigned clubs. |
| Coach | Club-specific training, attendance and competition management within assigned permissions. |
| Student | Club membership applications, tournament registration, facility reservation requests and personal participation records. |

Public sports information is accessible without authentication. Protected operations are subject to role-based authorization, club assignments and record ownership.

## Technology Stack

| Component | Technology |
| --- | --- |
| Frontend | HTML5, CSS3, JavaScript |
| Backend | PHP 8.3 |
| Database | MySQL 8.4 (InnoDB) |
| Database Access | PHP Data Objects (PDO) |
| Web Server | Apache HTTP Server |
| Development Environment | Docker, Docker Compose |
| Version Control | Git, GitHub |

## System Architecture

The system follows a modular, server-rendered architecture. Application modules handle business logic and request processing, while shared components provide authentication, authorization, validation and database connectivity. A centralized relational database maintains operational records and relationships between modules.

## Repository Structure

```text
nsbm-sporthub/
├── admin/          Administrative and content management
├── assets/         Stylesheets, JavaScript and media assets
├── bookings/       Facility reservation management
├── clubs/          Sports club and membership management
├── database/       SQL schema and public sports seed
├── includes/       Shared application components
├── matches/        Fixtures, results and standings
├── reports/        Administrative reporting and exports
├── student/        Student accounts and participation history
├── tournaments/    Competitions and team management
└── training/       Training schedules and attendance
```

## Database and Security

The database uses relational constraints, unique indexes and transactional operations to maintain data integrity and support scheduling and participation workflows.

Security controls include password hashing, session-based authentication, role-based authorization, parameterized database queries, CSRF protection, server-side validation and restricted file uploads. Environment-specific credentials are maintained outside version-controlled source code.

## Local Installation

### New installation with Docker Compose

Install Git and Docker Desktop (or Docker Engine with a current Docker Compose plugin). Start Docker, then clone the repository:

```sh
git clone https://github.com/chamaracreates/nsbm-sporthub.git
cd nsbm-sporthub
```

Create a file named `.env` in the repository root with the following settings. Replace the password placeholder with a unique, strong local password (for example, 48 random hexadecimal characters). Do not commit this file.

```dotenv
DB_HOST=db
DB_PORT=3306
DB_NAME=nsbm_sports_db
DB_USER=sportshub_app
DB_PASSWORD=REPLACE_WITH_YOUR_OWN_RANDOM_PASSWORD
```

Compose resolves `DB_NAME`, `DB_USER` and `DB_PASSWORD` identically for the web and MySQL services. Exported shell variables with those names take precedence over `.env`. If you have previously exported database settings, clear them in the current terminal before starting either installation so `.env` supplies the intended values.

On macOS/Linux or Git Bash:

```sh
unset DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD
```

On PowerShell:

```powershell
Remove-Item Env:DB_HOST, Env:DB_PORT, Env:DB_NAME, Env:DB_USER, Env:DB_PASSWORD -ErrorAction SilentlyContinue
```

Start the application and its optional local MySQL service:

```sh
docker compose --profile local-db up -d --build --wait
```

Open [http://localhost:8086](http://localhost:8086). On the first start with an empty database volume, MySQL imports `database/nsbm_sports_db.sql` followed by `database/seed.sql`. The seed includes clubs, facilities, historical tournaments, match results and published news. It does not include existing user accounts or private student activity. The local database uses a separate named volume, is not exposed on a host port and generates its own root password. The local application account has database-scoped privileges provided by the MySQL image; this setup is for development and demonstration, not production hosting.

To stop the project while preserving the database, use `docker compose --profile local-db stop`. Subsequent starts retain your data. Initialization scripts do not run again on an existing volume; do not delete volumes to update an existing installation. Changing `.env` does not change the credentials of an already initialized MySQL account; coordinate password changes separately.

### Using an existing MySQL server

The original external-database workflow remains available. Do not enable the `local-db` profile for an existing external-database installation. For a new external database, create an empty MySQL 8.4 database named `nsbm_sports_db`, import the schema followed by the seed, and create a dedicated application account with SELECT, INSERT, UPDATE and DELETE privileges on that database. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASSWORD` in `.env` to match the server. Clear previously exported database settings as described above before creating or recreating the web container. The hostname must be reachable from the web container (for example, `host.docker.internal` for a database on the host using Docker Desktop).

```sh
docker compose up -d --build
```

Never import the schema or seed over an existing populated database. Existing team members should preserve their databases and apply only the required schema upgrades separately.

### News image uploads

The tracked `assets/uploads/club-news` directory must be writable by the Apache user. Check permissions after starting the web service:

```sh
docker compose exec -T --user www-data web php -r 'exit(is_writable("/var/www/html/assets/uploads/club-news") ? 0 : 1);'
```

If the check fails on a Linux Docker host, grant the Apache user ownership of that directory only, then repeat the check:

```sh
docker compose exec -T --user root web chown www-data:www-data /var/www/html/assets/uploads/club-news
```

This changes the bind-mounted directory ownership on the host. Do not grant world-write permissions or recursively change ownership of the repository. On Docker Desktop, review host folder permissions if ownership changes are not supported. Uploaded news images remain excluded from Git.

### Local accounts

Students can register through the website. To create an administrator on a new installation, run the command below from the repository root after the web service and database are running. Replace the example email with your own local administrator email. The command generates and prints a unique password; keep it private. An existing account with that email will not be overwritten.

```sh
docker compose exec -T web php -r '
require "/var/www/html/includes/db.php";
$email = "admin@example.invalid";
$password = bin2hex(random_bytes(16));
$stmt = db()->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)");
$stmt->execute(["Local Administrator",$email,password_hash($password,PASSWORD_DEFAULT),"admin"]);
echo "Email: $email\nPassword: $password\n";
'
```

The seed-author account is not a login account; its invalid password hash preserves the foreign-key relationships without providing shared credentials. Do not use it as an administrator login.

The seed is a snapshot of public-facing sports content. Training schedules, team rosters, bookings and registrations may be empty until local users create them. External news imagery, where used by the application, requires internet access.