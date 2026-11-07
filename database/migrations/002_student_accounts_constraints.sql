
-- NSBM SportsHub
-- Member 02: Student Accounts
-- Apply once after importing the base schema,
-- only when these changes are not already present.

-- Automatically generate internal account IDs.
ALTER TABLE users
MODIFY COLUMN user_id INT NOT NULL AUTO_INCREMENT;

-- Prevent duplicate official NSBM student numbers.
ALTER TABLE student_profiles
ADD CONSTRAINT uq_student_number UNIQUE (student_number);
