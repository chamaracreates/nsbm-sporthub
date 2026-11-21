-- NSBM SportsHub Phase 12 — independent fixtures (one-time migration)
-- IMPORTANT: BACK UP YOUR MYSQL DATABASE BEFORE EXECUTING THIS FILE.
-- Run only after checking SHOW CREATE TABLE matches\G and confirming the
-- five existing FK constraint names match those in database/nsbm_sports_db.sql.
-- Existing rows and tournament-linked fixtures remain unchanged.
-- The matches.t_id column is already NULLABLE; only four FK columns change.
-- DDL auto-commits in MySQL/MariaDB; do not assume ROLLBACK will undo this.

ALTER TABLE `matches`
  DROP FOREIGN KEY `matches_ibfk_1`,
  DROP FOREIGN KEY `matches_ibfk_2`,
  DROP FOREIGN KEY `matches_ibfk_4`,
  DROP FOREIGN KEY `matches_ibfk_5`,
  MODIFY COLUMN `away_team_id` INT NULL,
  MODIFY COLUMN `home_team_id` INT NULL,
  MODIFY COLUMN `away_u_id` INT NULL,
  MODIFY COLUMN `home_u_id` INT NULL,
  ADD CONSTRAINT `fk_matches_away_team_ref` FOREIGN KEY (`away_team_id`) REFERENCES `teams` (`team_id`),
  ADD CONSTRAINT `fk_matches_home_team_ref` FOREIGN KEY (`home_team_id`) REFERENCES `teams` (`team_id`),
  ADD CONSTRAINT `fk_matches_away_user_ref` FOREIGN KEY (`away_u_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_matches_home_user_ref` FOREIGN KEY (`home_u_id`) REFERENCES `users` (`user_id`);

-- Confirm after migration:
-- SHOW COLUMNS FROM matches;
-- SHOW CREATE TABLE matches;
-- Standalone matches: t_id and all four FK columns NULL, with team names in
-- home_name / away_name. Tournament fixtures retain their original FK checks.
