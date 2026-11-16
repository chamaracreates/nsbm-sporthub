# NSBM SportsHub
A web-based sports club management system for NSBM Green University.

# Technologies
- HTML, CSS, JavaScript
- PHP
- MySQL

## Database Setup

NSBM SportsHub requires MySQL 8.4 and PHP with PDO MySQL enabled.

### Fresh installation

1. Create an empty MySQL database.
2. Import `database/nsbm_sports_db.sql` into that database.
3. Configure the application database environment variables:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASSWORD`
4. Start the PHP web server and verify the database connection.

The main SQL file already provides:

- `AUTO_INCREMENT` for `users.user_id`.
- A unique constraint on `student_profiles.student_number`.
- The student participation view without a hardcoded root definer.

Do not apply `002_student_accounts_constraints.sql` again
after importing the current main schema.

### Existing installations

For databases created using an older schema:

1. Back up the existing database.
2. Inspect the current account ID and student number constraints.
3. Apply only the missing changes from
   `database/migrations/002_student_accounts_constraints.sql`.
4. Verify the affected tables and indexes before continuing.

Do not blindly rerun migrations or import the full schema
over a database containing existing records.

### Database verification

After installation, verify the database structure:

```sql
SHOW CREATE TABLE users;
SHOW INDEX FROM student_profiles;
SHOW FULL TABLES;
```

Ensure that the application database account has the
required permissions for the tables and views.
