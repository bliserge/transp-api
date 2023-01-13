-- phpMyAdmin SQL Dump
-- version 4.6.6deb5ubuntu0.5
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 16, 2022 at 07:47 AM
-- Server version: 5.7.38-0ubuntu0.18.04.1
-- PHP Version: 7.2.24-0ubuntu0.18.04.11

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cfms_ug`
--

-- --------------------------------------------------------

--
-- Table structure for table `locales`
--

CREATE TABLE `locales` (
  `id` int(11) NOT NULL,
  `language_id` int(11) NOT NULL,
  `key` varchar(100) NOT NULL,
  `content` longtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `locales`
--

INSERT INTO `locales` (`id`, `language_id`, `key`, `content`, `created_at`, `updated_at`) VALUES
(1, 1, 'continue', 'Continue', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(2, 1, 'input_phone', 'Enter your phone number', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(3, 1, 'input_email', 'Enter your Email', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(4, 1, 'church', 'Church', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(5, 1, 'input_church', 'Enter church code', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(6, 1, 'next', 'Next', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(7, 1, 'welcome', 'Welcome', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(8, 1, 'give', 'Give', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(9, 1, 'history', 'History', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(10, 1, 'c_receipt', 'Cash Receipting', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(11, 1, 'c_action', 'Choose action', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(12, 1, 'tithe', 'Tithe', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(13, 1, 'amount', 'Amount', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(14, 1, 'review', 'Review', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(15, 1, 'input_paying', 'Enter paying phone number', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(16, 1, 'confirm', 'Confirm', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(17, 1, 'payment', 'Payment', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(18, 1, 'finish', 'Finish', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(19, 1, 'back', 'Back', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(20, 1, 'input_names', 'Enter your names', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(21, 1, 'finish_line', 'Approve the payment on your mobile phone ,', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(22, 1, 'thank', 'Thank you', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(23, 1, 'register', 'Register', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(24, 1, 'err_chuch', 'Church not found', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(25, 1, 'login_label', 'Login as church personnel', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(26, 1, 'login2_label', 'Login as Member', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(27, 1, 'signin_label', 'Login', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(28, 1, 'input_password', 'Enter password', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(29, 1, 'choose_church', 'Choose  church', '2022-05-16 05:43:54', '2022-05-16 05:43:54'),
(30, 1, 'choose_tithe', 'Choose  types of tithe', '2022-05-16 05:43:54', '2022-05-16 05:43:54');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `locales`
--
ALTER TABLE `locales`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `locales`
--
ALTER TABLE `locales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
