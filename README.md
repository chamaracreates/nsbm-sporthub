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
├── database/       Consolidated SQL schema
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