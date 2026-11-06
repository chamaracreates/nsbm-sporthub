-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: db:3306
-- Generation Time: Nov 06, 2026 at 05:57 AM
-- Server version: 8.4.11
-- PHP Version: 8.3.35

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `nsbm_sports_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `booking_request`
--

CREATE TABLE `booking_request` (
  `b_id` int NOT NULL,
  `res_id` int NOT NULL,
  `user_id` int NOT NULL,
  `date` date NOT NULL,
  `starts_at` time NOT NULL,
  `ends_at` time NOT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `booking_status` varchar(100) NOT NULL DEFAULT 'pending',
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `club_membership`
--

CREATE TABLE `club_membership` (
  `mem_id` int NOT NULL,
  `user_id` int NOT NULL,
  `club_id` int NOT NULL,
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `joined_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `matches`
--

CREATE TABLE `matches` (
  `m_id` int NOT NULL,
  `t_id` int DEFAULT NULL,
  `title` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `home_team_id` int NOT NULL,
  `away_team_id` int NOT NULL,
  `home_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `away_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `home_u_id` int NOT NULL,
  `away_u_id` int NOT NULL,
  `sport` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `date` date NOT NULL,
  `venue` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `match_status` varchar(100) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'scheduled',
  `home_score` int NOT NULL DEFAULT '0',
  `away_score` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `resources`
--

CREATE TABLE `resources` (
  `res_id` int NOT NULL,
  `res_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `res_type` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `location` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `res_status` varchar(100) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_clubs`
--

CREATE TABLE `sports_clubs` (
  `club_id` int NOT NULL,
  `club_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `sport` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `club_email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sport_staff`
--

CREATE TABLE `sport_staff` (
  `staff_id` int NOT NULL,
  `user_id` int NOT NULL,
  `club_id` int NOT NULL,
  `staff_role` enum('mic','coach') COLLATE utf8mb4_general_ci NOT NULL,
  `specialization` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teams`
--

CREATE TABLE `teams` (
  `team_id` int NOT NULL,
  `t_id` int NOT NULL,
  `team_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `club_id` int NOT NULL,
  `created_by` int NOT NULL,
  `created_at` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team_members`
--

CREATE TABLE `team_members` (
  `team_mem_id` int NOT NULL,
  `team_id` int NOT NULL,
  `user_id` int NOT NULL,
  `role` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tournaments`
--

CREATE TABLE `tournaments` (
  `t_id` int NOT NULL,
  `club_id` int NOT NULL,
  `t_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `sport` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `des` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `reg_open_at` date NOT NULL,
  `reg_end_at` date NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `venue` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `status` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `created_by` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tournament_registration`
--

CREATE TABLE `tournament_registration` (
  `reg_id` int NOT NULL,
  `t_id` int NOT NULL,
  `user_id` int NOT NULL,
  `reg_date` date NOT NULL,
  `reviewed_by` int DEFAULT NULL,
  `registration_status` enum('pending','approved','rejected','withdrawn') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `reviewed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tournament_results`
--

CREATE TABLE `tournament_results` (
  `result_id` int NOT NULL,
  `m_id` int NOT NULL,
  `t_id` int NOT NULL,
  `score_details` int NOT NULL,
  `winner_id` int NOT NULL,
  `team_id` int NOT NULL,
  `user_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `training_attendance`
--

CREATE TABLE `training_attendance` (
  `user_id` int NOT NULL,
  `session_id` int NOT NULL,
  `att_status` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `recorded_by` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `training_sessions`
--

CREATE TABLE `training_sessions` (
  `session_id` int NOT NULL,
  `club_id` int NOT NULL,
  `title` varchar(50) NOT NULL,
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `location` int NOT NULL,
  `description` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `role` enum('admin','student','coach','mic') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'student'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_student_participation_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_student_participation_summary` (
`user_id` int
,`student_name` varchar(100)
,`email` varchar(255)
,`total_clubs_joined` bigint
,`training_sessions_attended` bigint
,`tournaments_registered` bigint
,`facility_bookings_made` bigint
);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `booking_request`
--
ALTER TABLE `booking_request`
  ADD PRIMARY KEY (`b_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `res_id` (`res_id`),
  ADD KEY `fk_booking_reviewer` (`reviewed_by`);

--
-- Indexes for table `club_membership`
--
ALTER TABLE `club_membership`
  ADD PRIMARY KEY (`mem_id`),
  ADD UNIQUE KEY `uq_user_club` (`user_id`,`club_id`),
  ADD KEY `fk_membership_club` (`club_id`);

--
-- Indexes for table `matches`
--
ALTER TABLE `matches`
  ADD PRIMARY KEY (`m_id`),
  ADD KEY `away_team_id` (`away_team_id`),
  ADD KEY `home_team_id` (`home_team_id`),
  ADD KEY `t_id` (`t_id`),
  ADD KEY `away_u_id` (`away_u_id`),
  ADD KEY `home_u_id` (`home_u_id`);

--
-- Indexes for table `resources`
--
ALTER TABLE `resources`
  ADD PRIMARY KEY (`res_id`);

--
-- Indexes for table `sports_clubs`
--
ALTER TABLE `sports_clubs`
  ADD PRIMARY KEY (`club_id`),
  ADD UNIQUE KEY `sport` (`sport`);

--
-- Indexes for table `sport_staff`
--
ALTER TABLE `sport_staff`
  ADD PRIMARY KEY (`staff_id`),
  ADD UNIQUE KEY `uq_staff_club_user_role` (`club_id`,`user_id`,`staff_role`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `teams`
--
ALTER TABLE `teams`
  ADD PRIMARY KEY (`team_id`),
  ADD KEY `club-id` (`club_id`),
  ADD KEY `t_id` (`t_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `team_members`
--
ALTER TABLE `team_members`
  ADD PRIMARY KEY (`team_mem_id`),
  ADD KEY `team_id` (`team_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `tournaments`
--
ALTER TABLE `tournaments`
  ADD PRIMARY KEY (`t_id`),
  ADD KEY `club_id` (`club_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `tournament_registration`
--
ALTER TABLE `tournament_registration`
  ADD PRIMARY KEY (`reg_id`),
  ADD UNIQUE KEY `uq_tournament_user` (`t_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `tournament_results`
--
ALTER TABLE `tournament_results`
  ADD PRIMARY KEY (`result_id`),
  ADD KEY `fk_result_match` (`m_id`);

--
-- Indexes for table `training_attendance`
--
ALTER TABLE `training_attendance`
  ADD PRIMARY KEY (`user_id`,`session_id`),
  ADD KEY `session_id` (`session_id`),
  ADD KEY `fk_attendance_recorder` (`recorded_by`);

--
-- Indexes for table `training_sessions`
--
ALTER TABLE `training_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `club_id` (`club_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `booking_request`
--
ALTER TABLE `booking_request`
  MODIFY `b_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `club_membership`
--
ALTER TABLE `club_membership`
  MODIFY `mem_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `matches`
--
ALTER TABLE `matches`
  MODIFY `m_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `resources`
--
ALTER TABLE `resources`
  MODIFY `res_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sports_clubs`
--
ALTER TABLE `sports_clubs`
  MODIFY `club_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sport_staff`
--
ALTER TABLE `sport_staff`
  MODIFY `staff_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teams`
--
ALTER TABLE `teams`
  MODIFY `team_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team_members`
--
ALTER TABLE `team_members`
  MODIFY `team_mem_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tournaments`
--
ALTER TABLE `tournaments`
  MODIFY `t_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tournament_registration`
--
ALTER TABLE `tournament_registration`
  MODIFY `reg_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tournament_results`
--
ALTER TABLE `tournament_results`
  MODIFY `result_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `training_sessions`
--
ALTER TABLE `training_sessions`
  MODIFY `session_id` int NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `v_student_participation_summary`
--
DROP TABLE IF EXISTS `v_student_participation_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY INVOKER VIEW `v_student_participation_summary`  AS SELECT `u`.`user_id` AS `user_id`, `u`.`name` AS `student_name`, `u`.`email` AS `email`, count(distinct `cm`.`club_id`) AS `total_clubs_joined`, count(distinct `ta`.`session_id`) AS `training_sessions_attended`, count(distinct `tr`.`t_id`) AS `tournaments_registered`, count(distinct `br`.`b_id`) AS `facility_bookings_made` FROM ((((`users` `u` left join `club_membership` `cm` on(((`u`.`user_id` = `cm`.`user_id`) and (`cm`.`status` = 'approved')))) left join `training_attendance` `ta` on((`u`.`user_id` = `ta`.`user_id`))) left join `tournament_registration` `tr` on((`u`.`user_id` = `tr`.`user_id`))) left join `booking_request` `br` on((`u`.`user_id` = `br`.`user_id`))) WHERE (`u`.`role` = 'student') GROUP BY `u`.`user_id`, `u`.`name`, `u`.`email` ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `booking_request`
--
ALTER TABLE `booking_request`
  ADD CONSTRAINT `booking_request_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `booking_request_ibfk_2` FOREIGN KEY (`res_id`) REFERENCES `resources` (`res_id`),
  ADD CONSTRAINT `fk_booking_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `club_membership`
--
ALTER TABLE `club_membership`
  ADD CONSTRAINT `club_membership_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_membership_club` FOREIGN KEY (`club_id`) REFERENCES `sports_clubs` (`club_id`) ON DELETE CASCADE;

--
-- Constraints for table `matches`
--
ALTER TABLE `matches`
  ADD CONSTRAINT `matches_ibfk_1` FOREIGN KEY (`away_team_id`) REFERENCES `teams` (`team_id`),
  ADD CONSTRAINT `matches_ibfk_2` FOREIGN KEY (`home_team_id`) REFERENCES `teams` (`team_id`),
  ADD CONSTRAINT `matches_ibfk_3` FOREIGN KEY (`t_id`) REFERENCES `tournaments` (`t_id`),
  ADD CONSTRAINT `matches_ibfk_4` FOREIGN KEY (`away_u_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `matches_ibfk_5` FOREIGN KEY (`home_u_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `sport_staff`
--
ALTER TABLE `sport_staff`
  ADD CONSTRAINT `sport_staff_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `sport_staff_ibfk_2` FOREIGN KEY (`club_id`) REFERENCES `sports_clubs` (`club_id`) ON DELETE CASCADE;

--
-- Constraints for table `teams`
--
ALTER TABLE `teams`
  ADD CONSTRAINT `teams_ibfk_1` FOREIGN KEY (`club_id`) REFERENCES `sports_clubs` (`club_id`),
  ADD CONSTRAINT `teams_ibfk_2` FOREIGN KEY (`t_id`) REFERENCES `tournaments` (`t_id`),
  ADD CONSTRAINT `teams_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `team_members`
--
ALTER TABLE `team_members`
  ADD CONSTRAINT `team_members_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`),
  ADD CONSTRAINT `team_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `tournaments`
--
ALTER TABLE `tournaments`
  ADD CONSTRAINT `tournaments_ibfk_1` FOREIGN KEY (`club_id`) REFERENCES `sports_clubs` (`club_id`),
  ADD CONSTRAINT `tournaments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `tournament_registration`
--
ALTER TABLE `tournament_registration`
  ADD CONSTRAINT `tournament_registration_ibfk_1` FOREIGN KEY (`t_id`) REFERENCES `tournaments` (`t_id`),
  ADD CONSTRAINT `tournament_registration_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `tournament_registration_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `tournament_results`
--
ALTER TABLE `tournament_results`
  ADD CONSTRAINT `fk_result_match` FOREIGN KEY (`m_id`) REFERENCES `matches` (`m_id`) ON DELETE CASCADE;

--
-- Constraints for table `training_attendance`
--
ALTER TABLE `training_attendance`
  ADD CONSTRAINT `fk_attendance_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `training_attendance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `training_attendance_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `training_sessions` (`session_id`);

--
-- Constraints for table `training_sessions`
--
ALTER TABLE `training_sessions`
  ADD CONSTRAINT `training_sessions_ibfk_1` FOREIGN KEY (`club_id`) REFERENCES `sports_clubs` (`club_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
