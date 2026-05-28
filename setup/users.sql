-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 07, 2026 at 05:31 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `wms_lsn`
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(25) NOT NULL,
  `userid` varchar(10) NOT NULL,
  `role` enum('admin','supervisor','staff','softchecker') NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `userid`, `role`, `password`, `created_at`) VALUES
(1, 'Ahmad Hariri', 'ahariri', 'admin', '$2y$12$XGlq8.eUa/F275u67z8.DOgCAS1p5ELJz4CuFciKRPyh2h6I9NbwK', '2026-05-07 10:23:22'),
(2, 'Ninditya Ayu Larasati', 'nayularas', 'supervisor', '$2y$12$EomckT8GzGHrcwvMVqUi7u6BpOWHmAYzFCuC0dg4ToCqh/nxClmMC', '2026-05-07 10:26:53'),
(3, 'Anggita Wilda Aliyani', 'awilda', 'staff', '$2y$12$CCcTyskQzwTeZUYZCMJduu5FcqtMRooetRjxaJyur87qTN66mhpz6', '2026-05-07 10:28:48'),
(4, 'Mulyo Hadi Nugroho', 'mhadinug', 'supervisor', '$2y$12$varrGhvcYbNJsI2dqEMyP.wRSN.oqX/Xna/JdUxQaCmAMlOMY7TuS', '2026-05-07 10:29:36'),
(5, 'Fandi Eriyato', 'fandieri', 'staff', '$2y$12$CjTbrFrMuXPs4pAVx40f4u5p6QCpDI8xN3uMQm6K3p.1aX2TwJWCS', '2026-05-07 10:30:14'),
(6, 'Yoyok', 'yoyok', 'softchecker', '$2y$12$IalRWEIPVbjI8pbNvg5.p.WnRilH.cjS6TpxMlH3GXcUIrBJ0571q', '2026-05-07 10:30:40');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid` (`userid`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
