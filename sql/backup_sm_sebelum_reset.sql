USE bumk_umgo;
-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: bumk_umgo
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `sm_categories`
--

DROP TABLE IF EXISTS `sm_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sm_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sm_categories`
--

LOCK TABLES `sm_categories` WRITE;
/*!40000 ALTER TABLE `sm_categories` DISABLE KEYS */;
INSERT INTO `sm_categories` VALUES (1,'Kain & Bahan Seragam','Kain muhammadiyah, batik, dan bahan jahit satuan meteran.','2026-11-20 02:34:39'),(2,'Baju Kemeja','Kemeja Muhammadiyah, Batik, dan Jas Almamater.','2026-11-20 02:34:39'),(3,'Peralatan Hizbul Wathan','Seragam HW, hasduk, topi, badge, tongkat, tali.','2026-11-20 02:34:39');
/*!40000 ALTER TABLE `sm_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sm_products`
--

DROP TABLE IF EXISTS `sm_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sm_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `sku` varchar(40) NOT NULL,
  `size` varchar(15) NOT NULL DEFAULT '-',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `stock` int(11) NOT NULL DEFAULT 0,
  `min_stock` int(11) NOT NULL DEFAULT 0,
  `unit` varchar(15) NOT NULL DEFAULT 'pcs',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `sm_products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `sm_categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sm_products`
--

LOCK TABLES `sm_products` WRITE;
/*!40000 ALTER TABLE `sm_products` DISABLE KEYS */;
INSERT INTO `sm_products` VALUES (1,1,'Kain Muhammadiyah','SMC-KM01','meter',85000.00,0.00,40,10,'meter','2026-11-20 02:34:39'),(2,1,'Kain Batik Muhammadiyah','SMC-KB02','meter',95000.00,0.00,25,8,'meter','2026-11-20 02:34:39'),(3,2,'Kemeja Muhammadiyah','SMC-KM-M','M',150000.00,0.00,11,8,'pcs','2026-11-20 02:34:39'),(4,2,'Kemeja Muhammadiyah','SMC-KM-L','L',150000.00,0.00,6,8,'pcs','2026-11-20 02:34:39'),(5,2,'Batik Nasional','SMC-BN-XL','XL',175000.00,0.00,15,5,'pcs','2026-11-20 02:34:39'),(6,2,'Jas Almamater','SMC-JA-L','L',220000.00,0.00,5,5,'pcs','2026-11-20 02:34:39'),(7,3,'Seragam HW Lengkap','SMC-HW-M','M',135000.00,0.00,20,6,'set','2026-11-20 02:34:39'),(8,3,'Hasduk / Setangan Leher HW','SMC-HS-01','all',25000.00,0.00,60,15,'pcs','2026-11-20 02:34:39');
/*!40000 ALTER TABLE `sm_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sm_transactions`
--

DROP TABLE IF EXISTS `sm_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sm_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_no` varchar(40) NOT NULL,
  `transaction_date` datetime NOT NULL DEFAULT current_timestamp(),
  `customer_name` varchar(120) NOT NULL DEFAULT 'Umum',
  `payment_method` enum('tunai','qris','transfer') NOT NULL DEFAULT 'tunai',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_no` (`transaction_no`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `sm_transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sm_transactions`
--

LOCK TABLES `sm_transactions` WRITE;
/*!40000 ALTER TABLE `sm_transactions` DISABLE KEYS */;
INSERT INTO `sm_transactions` VALUES (1,'SMC-20261120-0001','2026-11-20 10:34:39','Mahasiswa','tunai',325000.00,4,'2026-11-20 02:34:39'),(2,'SMC-20261120-0002','2026-11-20 10:34:39','Dosen','qris',170000.00,4,'2026-11-20 02:34:39'),(3,'SMC-20261119-0001','2026-11-19 10:34:39','Umum','tunai',135000.00,4,'2026-11-20 02:34:39'),(4,'SMC-20261117-0001','2026-11-17 10:34:39','HW UMGO','transfer',270000.00,4,'2026-11-20 02:34:39'),(6,'SMC-20261120-0005','2026-11-20 11:07:40','Umum','qris',370000.00,4,'2026-11-20 03:07:40');
/*!40000 ALTER TABLE `sm_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sm_transaction_items`
--

DROP TABLE IF EXISTS `sm_transaction_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sm_transaction_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `sm_transaction_items_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `sm_transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sm_transaction_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `sm_products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sm_transaction_items`
--

LOCK TABLES `sm_transaction_items` WRITE;
/*!40000 ALTER TABLE `sm_transaction_items` DISABLE KEYS */;
INSERT INTO `sm_transaction_items` VALUES (1,1,3,1,150000.00,0.00,150000.00),(2,1,7,1,135000.00,0.00,135000.00),(3,1,8,4,25000.00,0.00,100000.00),(4,2,5,1,175000.00,0.00,175000.00),(5,3,7,1,135000.00,0.00,135000.00),(6,4,1,2,85000.00,0.00,170000.00),(7,4,8,4,25000.00,0.00,100000.00),(9,6,6,1,220000.00,0.00,220000.00),(10,6,3,1,150000.00,0.00,150000.00);
/*!40000 ALTER TABLE `sm_transaction_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sm_purchases`
--

DROP TABLE IF EXISTS `sm_purchases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sm_purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_no` varchar(40) NOT NULL,
  `purchase_date` datetime NOT NULL DEFAULT current_timestamp(),
  `invoice_no` varchar(60) DEFAULT NULL,
  `supplier_name` varchar(120) NOT NULL,
  `payment_status` enum('tunai','transfer','piutang') NOT NULL DEFAULT 'tunai',
  `bank` enum('BSI','MUAMALAT') DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_no` (`purchase_no`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `sm_purchases_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sm_purchases`
--

LOCK TABLES `sm_purchases` WRITE;
/*!40000 ALTER TABLE `sm_purchases` DISABLE KEYS */;
/*!40000 ALTER TABLE `sm_purchases` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sm_purchase_items`
--

DROP TABLE IF EXISTS `sm_purchase_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sm_purchase_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `sm_purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `sm_purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sm_purchase_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `sm_products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sm_purchase_items`
--

LOCK TABLES `sm_purchase_items` WRITE;
/*!40000 ALTER TABLE `sm_purchase_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `sm_purchase_items` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-20 19:33:20
