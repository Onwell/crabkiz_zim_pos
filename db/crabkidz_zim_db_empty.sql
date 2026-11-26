-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 26, 2026 at 10:27 AM
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
(1, 1, 'Onwell Masaraure', 'Admin', 'Signed out', 'Signed out of the system', '::1', '2026-11-26 08:54:45');

-- --------------------------------------------------------

--
-- Table structure for table `admin_notification_reads`
--

CREATE TABLE `admin_notification_reads` (
  `user_id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- --------------------------------------------------------

--
-- Table structure for table `stock_notification_reads`
--

CREATE TABLE `stock_notification_reads` (
  `user_id` int(11) NOT NULL,
  `notification_id` int(11) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
-- Indexes for table `admin_notification_reads`
--
ALTER TABLE `admin_notification_reads`
  ADD PRIMARY KEY (`user_id`,`request_id`),
  ADD KEY `idx_req` (`request_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `item_size` (`item_no`,`size`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `restock_requests`
--
ALTER TABLE `restock_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_variants`
--
ALTER TABLE `sale_variants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_items`
--
ALTER TABLE `stock_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_notifications`
--
ALTER TABLE `stock_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
