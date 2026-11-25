-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 25, 2026 at 02:24 PM
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
-- Database: `crabkidz_zim_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(120) NOT NULL,
  `user_role` varchar(20) NOT NULL DEFAULT '',
  `action` varchar(60) NOT NULL,
  `details` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`id`, `user_id`, `user_name`, `user_role`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, 2, 'Malachi Masaraure', 'Cashier', 'Signed in', 'Opened the cashier dashboard', '::1', '2026-11-23 08:27:46'),
(2, 1, 'Onwell Masaraure', 'Admin', 'Signed in', 'Opened the admin dashboard', '::1', '2026-11-23 08:28:04'),
(3, 2, 'Malachi Masaraure', 'Cashier', 'Sale completed', 'Receipt R-10497 · $12.49 · 1 line(s)', '::1', '2026-11-23 08:29:58'),
(4, 2, 'Malachi Masaraure', 'Cashier', 'Signed out', 'Signed out of the system', '::1', '2026-11-23 08:52:59'),
(5, 1, 'Onwell Masaraure', 'Admin', 'Weekly stock take run', '1 lines recorded', '::1', '2026-11-23 08:53:29'),
(6, 2, 'Malachi Masaraure', 'Cashier', 'Signed in', 'Opened the cashier dashboard', '::1', '2026-11-23 09:45:39'),
(7, 2, 'Malachi Masaraure', 'Cashier', 'Signed out', 'Signed out of the system', '::1', '2026-11-23 09:50:50'),
(8, 1, 'Onwell Masaraure', 'Admin', 'Signed in', 'Opened the admin dashboard', '::1', '2026-11-23 09:51:04'),
(9, 1, 'Onwell Masaraure', 'Admin', 'Signed in', 'Opened the admin dashboard', '::1', '2026-11-24 18:11:43'),
(10, 2, 'Malachi Masaraure', 'Cashier', 'Signed in', 'Opened the cashier dashboard', '::1', '2026-11-24 18:12:02'),
(11, 1, 'Onwell Masaraure', 'Admin', 'Weekly stock take run', '1 lines recorded', '::1', '2026-11-24 18:40:39'),
(12, 1, 'Onwell Masaraure', 'Admin', 'Stock upload', '1 × School Shoes, size 27 (item 617523)', '::1', '2026-11-24 20:52:42'),
(13, 2, 'Malachi Masaraure', 'Cashier', 'Sale completed', 'Receipt R-10498 · $22.49 · 1 line(s)', '::1', '2026-11-24 20:59:41'),
(14, 1, 'Onwell Masaraure', 'Admin', 'Stock upload', '1 × School Shoe (Black, size 28) [item 618106]', '::1', '2026-11-24 22:03:37'),
(15, 1, 'Onwell Masaraure', 'Admin', 'Stock upload', '1 × School Shoe (Black, size 29) [item 618106]', '::1', '2026-11-24 22:04:12'),
(16, 1, 'Onwell Masaraure', 'Admin', 'Stock upload', '1 × School Shoe (Black, size 32) [item 618106]', '::1', '2026-11-24 22:04:52'),
(17, 2, 'Malachi Masaraure', 'Cashier', 'Sale completed', 'Receipt R-10499 · $19.40 · 1 line(s)', '::1', '2026-11-24 22:06:43'),
(18, 1, 'Onwell Masaraure', 'Admin', 'Stock upload', '5 × School Shoe (Black, size 37) [item 618106]', '::1', '2026-11-24 22:28:24'),
(19, 2, 'Malachi Masaraure', 'Cashier', 'Sale completed', 'Receipt R-10500 · $38.97 · 1 line(s)', '::1', '2026-11-24 22:35:19'),
(20, 2, 'Malachi Masaraure', 'Cashier', 'Signed out', 'Signed out of the system', '::1', '2026-11-24 23:35:23'),
(21, 1, 'Onwell Masaraure', 'Admin', 'Signed in', 'Opened the admin dashboard', '::1', '2026-11-25 12:53:39'),
(22, 2, 'Malachi Masaraure', 'Cashier', 'Signed in', 'Opened the cashier dashboard', '::1', '2026-11-25 13:03:35'),
(23, 2, 'Malachi Masaraure', 'Cashier', 'Signed out', 'Signed out of the system', '::1', '2026-11-25 13:03:38'),
(24, 2, 'Malachi Masaraure', 'Cashier', 'Signed in', 'Opened the cashier dashboard', '::1', '2026-11-25 13:03:50'),
(25, 2, 'Malachi Masaraure', 'Cashier', 'Requested restock', 'Item 617522 (School Shool, Black, size 27) — 0 left', '::1', '2026-11-25 13:18:49');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `item_no` varchar(50) NOT NULL,
  `name` varchar(120) NOT NULL,
  `size` varchar(50) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock_qty` int(11) NOT NULL DEFAULT 0,
  `initial_qty` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `item_no`, `name`, `size`, `price`, `stock_qty`, `initial_qty`) VALUES
(1, '617522', 'School Shool', '27', 12.49, 0, 1),
(2, '617523', 'School Shoes', '27', 22.49, 0, 1),
(3, '618106', 'School Shoe', '37', 12.99, 4, 8);

-- --------------------------------------------------------

--
-- Table structure for table `restock_requests`
--

CREATE TABLE `restock_requests` (
  `id` int(11) NOT NULL,
  `item_no` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `color` varchar(40) NOT NULL,
  `size` varchar(20) NOT NULL,
  `current_qty` int(11) NOT NULL DEFAULT 0,
  `requested_by_id` int(11) DEFAULT NULL,
  `requested_by_name` varchar(120) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `restock_requests`
--

INSERT INTO `restock_requests` (`id`, `item_no`, `name`, `color`, `size`, `current_qty`, `requested_by_id`, `requested_by_name`, `status`, `created_at`) VALUES
(1, '617522', 'School Shool', 'Black', '27', 0, 2, 'Malachi Masaraure', 'pending', '2026-11-25 13:18:49');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `receipt_no` varchar(50) NOT NULL,
  `cashier_name` varchar(120) NOT NULL,
  `customer` varchar(120) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `cash_received` decimal(10,2) DEFAULT NULL,
  `change_given` decimal(10,2) DEFAULT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'Cash',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `receipt_no`, `cashier_name`, `customer`, `total_amount`, `cash_received`, `change_given`, `payment_method`, `created_at`) VALUES
(1, 'R-10497', 'Malachi Masaraure', 'Walk-in customer', 12.49, 15.00, 2.51, 'Cash', '2026-11-23 08:29:58'),
(2, 'R-10498', 'Malachi Masaraure', 'Walk-in customer', 22.49, 30.00, 7.51, 'Cash', '2026-11-24 20:59:41'),
(3, 'R-10499', 'Malachi Masaraure', 'Walk-in customer', 19.40, 50.00, 30.60, 'Cash', '2026-11-24 22:06:43'),
(4, 'R-10500', 'Malachi Masaraure', 'Walk-in customer', 38.97, 500.00, 461.03, 'Cash', '2026-11-24 22:35:19');

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`id`, `sale_id`, `product_id`, `qty`, `price`) VALUES
(1, 1, 1, 1, 12.49),
(2, 2, 2, 1, 22.49),
(3, 3, 3, 1, 19.40),
(4, 4, 3, 3, 12.99);

-- --------------------------------------------------------

--
-- Table structure for table `sale_variants`
--

CREATE TABLE `sale_variants` (
  `id` int(11) NOT NULL,
  `receipt_no` varchar(40) NOT NULL,
  `item_no` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `color` varchar(40) NOT NULL,
  `size` varchar(10) NOT NULL,
  `qty` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sale_variants`
--

INSERT INTO `sale_variants` (`id`, `receipt_no`, `item_no`, `name`, `color`, `size`, `qty`, `price`) VALUES
(1, 'R-10500', '618106', 'School Shoe', 'Black', '37', 3, 12.99);

-- --------------------------------------------------------

--
-- Table structure for table `stock_items`
--

CREATE TABLE `stock_items` (
  `id` int(11) NOT NULL,
  `sku` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `gender` varchar(20) NOT NULL DEFAULT 'Unisex',
  `color` varchar(40) NOT NULL DEFAULT 'Black',
  `size` varchar(10) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_by_name` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_items`
--

INSERT INTO `stock_items` (`id`, `sku`, `name`, `gender`, `color`, `size`, `qty`, `cost`, `price`, `uploaded_by`, `uploaded_by_name`, `created_at`, `updated_at`) VALUES
(1, '617522', 'School Shool', 'Boy', 'Black', '27', 1, 10.00, 12.49, 1, 'Onwell Masaraure', '2026-11-23 08:20:00', '2026-11-23 08:20:00'),
(2, '617523', 'School Shoes', 'Boy', 'Black', '27', 1, 20.00, 22.49, 1, 'Onwell Masaraure', '2026-11-24 20:52:42', '2026-11-24 20:52:42'),
(3, '618106', 'School Shoe', 'Boy', 'Black', '28', 1, 20.00, 25.00, 1, 'Onwell Masaraure', '2026-11-24 22:03:37', '2026-11-24 22:03:37'),
(4, '618106', 'School Shoe', 'Boy', 'Black', '29', 1, 19.00, 21.00, 1, 'Onwell Masaraure', '2026-11-24 22:04:12', '2026-11-24 22:04:12'),
(5, '618106', 'School Shoe', 'Boy', 'Black', '32', 1, 17.00, 19.40, 1, 'Onwell Masaraure', '2026-11-24 22:04:52', '2026-11-24 22:04:52'),
(6, '618106', 'School Shoe', 'Boy', 'Black', '37', 2, 10.00, 12.99, 1, 'Onwell Masaraure', '2026-11-24 22:28:24', '2026-11-24 22:35:19');

-- --------------------------------------------------------

--
-- Table structure for table `stock_notifications`
--

CREATE TABLE `stock_notifications` (
  `id` int(11) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_by_name` varchar(120) NOT NULL,
  `pairs` int(11) NOT NULL DEFAULT 0,
  `summary` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_notifications`
--

INSERT INTO `stock_notifications` (`id`, `uploaded_by`, `uploaded_by_name`, `pairs`, `summary`, `created_at`) VALUES
(1, 1, 'Onwell Masaraure', 1, '1 × School Shool, size 27 (item 617522)', '2026-11-23 08:20:00'),
(2, 1, 'Onwell Masaraure', 1, '1 × School Shoes, size 27 (item 617523)', '2026-11-24 20:52:42'),
(3, 1, 'Onwell Masaraure', 1, '1 × School Shoe (Black, size 28) [item 618106]', '2026-11-24 22:03:37'),
(4, 1, 'Onwell Masaraure', 1, '1 × School Shoe (Black, size 29) [item 618106]', '2026-11-24 22:04:12'),
(5, 1, 'Onwell Masaraure', 1, '1 × School Shoe (Black, size 32) [item 618106]', '2026-11-24 22:04:52'),
(6, 1, 'Onwell Masaraure', 5, '5 × School Shoe (Black, size 37) [item 618106]', '2026-11-24 22:28:24');

-- --------------------------------------------------------

--
-- Table structure for table `stock_notification_reads`
--

CREATE TABLE `stock_notification_reads` (
  `user_id` int(11) NOT NULL,
  `notification_id` int(11) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_notification_reads`
--

INSERT INTO `stock_notification_reads` (`user_id`, `notification_id`, `read_at`) VALUES
(2, 1, '2026-11-23 08:21:24'),
(2, 2, '2026-11-24 20:57:05'),
(2, 3, '2026-11-24 22:05:44'),
(2, 4, '2026-11-24 22:05:44'),
(2, 5, '2026-11-24 22:05:44'),
(2, 6, '2026-11-24 22:28:46');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Admin','Cashier') NOT NULL DEFAULT 'Cashier',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `role`, `created_at`) VALUES
(1, 'Onwell Masaraure', 'o.masaraure@gmail.com', '$2y$10$/Dg9hCBk05sOoqpphixBv.kgmIBmr0UGTX4dUilx/RdkMVVG4t2Um', 'Admin', '2026-11-22 21:59:31'),
(2, 'Malachi Masaraure', 'malachi@gmail.com', '$2y$10$MY7W5/3iuNq7wyIBDZSYSe5yRvSlNoMImFMkmNvuFjmJIzC9yF5/a', 'Cashier', '2026-11-23 08:16:54');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_at` (`created_at`,`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `item_no` (`item_no`);

--
-- Indexes for table `restock_requests`
--
ALTER TABLE `restock_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_no` (`receipt_no`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `sale_variants`
--
ALTER TABLE `sale_variants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_receipt` (`receipt_no`);

--
-- Indexes for table `stock_items`
--
ALTER TABLE `stock_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku_variant` (`sku`,`color`,`size`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `stock_notifications`
--
ALTER TABLE `stock_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `stock_notification_reads`
--
ALTER TABLE `stock_notification_reads`
  ADD PRIMARY KEY (`user_id`,`notification_id`),
  ADD KEY `notification_id` (`notification_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `restock_requests`
--
ALTER TABLE `restock_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sale_variants`
--
ALTER TABLE `sale_variants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `stock_items`
--
ALTER TABLE `stock_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `stock_notifications`
--
ALTER TABLE `stock_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `stock_items`
--
ALTER TABLE `stock_items`
  ADD CONSTRAINT `stock_items_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `stock_notifications`
--
ALTER TABLE `stock_notifications`
  ADD CONSTRAINT `stock_notifications_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `stock_notification_reads`
--
ALTER TABLE `stock_notification_reads`
  ADD CONSTRAINT `stock_notification_reads_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `stock_notification_reads_ibfk_2` FOREIGN KEY (`notification_id`) REFERENCES `stock_notifications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
