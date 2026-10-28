-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: roomiesync
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

CREATE DATABASE IF NOT EXISTS `roomiesync` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `roomiesync`;

--
-- Table structure for table `announcetb`
--

DROP TABLE IF EXISTS `announcetb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcetb` (
  `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
  `household_id` int(11) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `title` varchar(100) NOT NULL,
  `content` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`announcement_id`),
  KEY `household_id` (`household_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `announcetb_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `householdtb` (`household_id`) ON DELETE CASCADE,
  CONSTRAINT `announcetb_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `usertb` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcetb`
--

LOCK TABLES `announcetb` WRITE;
/*!40000 ALTER TABLE `announcetb` DISABLE KEYS */;
INSERT INTO `announcetb` VALUES (1,2,4,'Test','Test','2026-10-05 23:38:33'),(2,3,6,'Test','Testttttt','2026-10-14 19:12:10'),(3,1,1,'Welcome to Malued! Household Guidelines & Wi-Fi Password','Welcome everyone to our shared apartment! Here are our quick house ground rules:\n1. Please clean kitchen counters and wash cookware immediately after meal prep.\n2. Quiet study & resting hours begin after 10:00 PM on weeknights.\n3. Log any shared grocery receipts in RoomieSync promptly so expenses stay balanced.\nWi-Fi SSID: Malued_Fibr_5G (Password: RoomieSync2026!). Let\'s have a great and harmonious semester!','2026-09-24 10:00:00'),(4,1,1,'Advisory: Scheduled Water Interruption Thursday Oct 22 (1 PM - 5 PM)','Manila Water has announced temporary pipeline pressure maintenance tomorrow afternoon from 1:00 PM to 5:00 PM. We have filled our blue storage containers in the laundry area for dishwashing and emergency bathroom use. Please ensure taps remain tightly closed.','2026-10-21 16:30:00'),(5,1,8,'Weekend Communal Dinner & Deep Cleaning Session this Saturday','Hey roommates! Let\'s do a collective 30-minute apartment refresh this Saturday at 5:00 PM (living room vacuum and trash purge), followed by home-cooked tacos and board games in the dining area. Everyone is welcome to join!','2026-10-19 12:00:00'),(6,1,1,'Building Admin Notice: Mandatory Fire Alarm Testing Friday (10 AM - 11 AM)','Building administration sent an advisory that technicians will be testing the corridor fire alarms and smoke detectors this Friday between 10:00 AM and 11:00 AM. Alarms will sound in short intermittent bursts. No need to evacuate unless instructed by building marshals.','2026-10-25 15:30:00'),(7,1,8,'Friendly Reminder: University Midterms Study Week - Quiet Hours After 9 PM','Hey roommates! Midterms start next Monday for most of us. Requesting everyone to observe quiet resting and study hours starting 9:00 PM on weeknights. Feel free to use the dining table as a shared study desk—just keep headphone volume on check. Good luck with exams!','2026-10-23 11:00:00'),(8,1,3,'Kitchen Pantry Update: New Spice Racks & Labeled Storage Bins Installed','I installed the two-tier acrylic spice rack beside the stovetop and labeled the pantry shelf containers (Salt, White Sugar, Ground Pepper, Pasta, Cereal). Please return all condiments and seasonings to their designated slots after meal prep so counters stay clutter-free!','2026-10-16 16:45:00'),(9,1,7,'Found Item: Black Wireless Earbuds Charging Case on Living Room Sofa','Found a matte black Bluetooth earbuds charging case tucked between the middle cushions of the grey couch. Placed it safely on top of the TV media console. Claim whenever you are in the living room!','2026-10-10 13:15:00'),(10,1,1,'Barangay Eco-Waste Advisory: Strict Segregation of Biodegradable & Recyclables','The barangay waste collector issued a reminder that mixed rubbish bags will be rejected starting this month. Blue bin = Clean paper, cardboard, plastic bottles, aluminum cans. Green bin = Food scraps, coffee grounds, biodegradable waste. Black bin = Non-recyclable sanitary items. Thank you all for cooperating!','2026-09-28 09:00:00');
/*!40000 ALTER TABLE `announcetb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `authtokenstb`
--

DROP TABLE IF EXISTS `authtokenstb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `authtokenstb` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `selector` char(24) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `idx_auth_tokens_user` (`user_id`),
  KEY `idx_auth_tokens_expiry` (`expires_at`),
  CONSTRAINT `authtokenstb_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `usertb` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `authtokenstb`
--

LOCK TABLES `authtokenstb` WRITE;
/*!40000 ALTER TABLE `authtokenstb` DISABLE KEYS */;
/*!40000 ALTER TABLE `authtokenstb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bill_categoriestb`
--

DROP TABLE IF EXISTS `bill_categoriestb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bill_categoriestb` (
  `bill_category_id` int(11) NOT NULL AUTO_INCREMENT,
  `household_id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`bill_category_id`),
  KEY `household_id` (`household_id`),
  CONSTRAINT `bill_categoriestb_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `householdtb` (`household_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bill_categoriestb`
--

LOCK TABLES `bill_categoriestb` WRITE;
/*!40000 ALTER TABLE `bill_categoriestb` DISABLE KEYS */;
INSERT INTO `bill_categoriestb` VALUES (1,1,'Electricity','Electricity and power utility'),(2,1,'Water','Water and sewer service'),(3,1,'Internet','Broadband and Wi-Fi subscription'),(4,1,'Rent','Monthly house or apartment rent'),(5,2,'Electricity','Electricity and power utility'),(6,2,'Water','Water and sewer service'),(7,2,'Internet','Broadband and Wi-Fi subscription'),(8,2,'Rent','Monthly house or apartment rent'),(9,3,'Electricity','Electricity and power utility'),(10,3,'Water','Water and sewer service'),(11,3,'Internet','Broadband and Wi-Fi subscription'),(12,3,'Rent','Monthly house or apartment rent');
/*!40000 ALTER TABLE `bill_categoriestb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bill_sharestb`
--

DROP TABLE IF EXISTS `bill_sharestb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bill_sharestb` (
  `bill_share_id` int(11) NOT NULL AUTO_INCREMENT,
  `bill_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `share_amount` decimal(10,2) NOT NULL,
  `payment_status` varchar(20) NOT NULL DEFAULT 'Unpaid',
  PRIMARY KEY (`bill_share_id`),
  KEY `bill_id` (`bill_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `bill_sharestb_ibfk_1` FOREIGN KEY (`bill_id`) REFERENCES `billstb` (`bill_id`) ON DELETE CASCADE,
  CONSTRAINT `bill_sharestb_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `usertb` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bill_sharestb`
--

LOCK TABLES `bill_sharestb` WRITE;
/*!40000 ALTER TABLE `bill_sharestb` DISABLE KEYS */;
INSERT INTO `bill_sharestb` VALUES (3,2,4,100.00,'Unpaid'),(6,4,1,4500.00,'Paid'),(7,4,3,4500.00,'Paid'),(8,4,7,4500.00,'Paid'),(9,4,8,4500.00,'Paid'),(10,5,1,424.75,'Paid'),(11,5,3,424.75,'Paid'),(12,5,7,424.75,'Paid'),(13,5,8,424.75,'Paid'),(14,6,1,235.00,'Paid'),(15,6,3,235.00,'Paid'),(16,6,7,235.00,'Paid'),(17,6,8,235.00,'Paid'),(18,7,1,1205.00,'Paid'),(19,7,3,1205.00,'Unpaid'),(20,7,7,1205.00,'Paid'),(21,7,8,1205.00,'Unpaid'),(22,8,1,100.00,'Paid'),(23,8,3,100.00,'Paid'),(24,8,7,100.00,'Paid'),(25,8,8,100.00,'Paid'),(26,9,1,4500.00,'Paid'),(27,9,3,4500.00,'Paid'),(28,9,7,4500.00,'Paid'),(29,9,8,4500.00,'Paid'),(30,10,1,1140.00,'Paid'),(31,10,3,1140.00,'Paid'),(32,10,7,1140.00,'Paid'),(33,10,8,1140.00,'Paid'),(34,11,1,424.75,'Paid'),(35,11,3,424.75,'Paid'),(36,11,7,424.75,'Paid'),(37,11,8,424.75,'Paid'),(38,12,1,220.00,'Paid'),(39,12,3,220.00,'Paid'),(40,12,7,220.00,'Paid'),(41,12,8,220.00,'Paid'),(42,13,1,4500.00,'Unpaid'),(43,13,3,4500.00,'Unpaid'),(44,13,7,4500.00,'Unpaid'),(45,13,8,4500.00,'Unpaid'),(46,14,1,4500.00,'Paid'),(47,14,3,4500.00,'Paid'),(48,14,7,4500.00,'Paid'),(49,14,8,4500.00,'Paid'),(50,15,1,1080.00,'Paid'),(51,15,3,1080.00,'Paid'),(52,15,7,1080.00,'Paid'),(53,15,8,1080.00,'Paid'),(54,16,1,424.75,'Paid'),(55,16,3,424.75,'Paid'),(56,16,7,424.75,'Paid'),(57,16,8,424.75,'Paid'),(58,17,1,197.50,'Paid'),(59,17,3,197.50,'Paid'),(60,17,7,197.50,'Paid'),(61,17,8,197.50,'Paid'),(62,18,1,300.00,'Paid'),(63,18,3,300.00,'Paid'),(64,18,7,300.00,'Paid'),(65,18,8,300.00,'Paid'),(66,19,1,150.00,'Paid'),(67,19,3,150.00,'Paid'),(68,19,7,150.00,'Paid'),(69,19,8,150.00,'Paid'),(70,20,1,424.75,'Unpaid'),(71,20,3,424.75,'Unpaid'),(72,20,7,424.75,'Unpaid'),(73,20,8,424.75,'Unpaid'),(74,21,1,230.00,'Unpaid'),(75,21,3,230.00,'Unpaid'),(76,21,7,230.00,'Unpaid'),(77,21,8,230.00,'Unpaid'),(78,22,1,1187.50,'Unpaid'),(79,22,3,1187.50,'Unpaid'),(80,22,7,1187.50,'Unpaid'),(81,22,8,1187.50,'Unpaid'),(82,23,7,250.00,'Paid'),(83,23,3,250.00,'Paid'),(84,23,1,250.00,'Paid'),(85,23,8,250.00,'Paid');
/*!40000 ALTER TABLE `bill_sharestb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `billstb`
--

DROP TABLE IF EXISTS `billstb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billstb` (
  `bill_id` int(11) NOT NULL AUTO_INCREMENT,
  `household_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `bill_name` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `due_date` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Unpaid',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`bill_id`),
  KEY `household_id` (`household_id`),
  KEY `category_id` (`category_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `billstb_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `householdtb` (`household_id`) ON DELETE CASCADE,
  CONSTRAINT `billstb_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `bill_categoriestb` (`bill_category_id`) ON DELETE SET NULL,
  CONSTRAINT `billstb_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `usertb` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billstb`
--

LOCK TABLES `billstb` WRITE;
/*!40000 ALTER TABLE `billstb` DISABLE KEYS */;
INSERT INTO `billstb` VALUES (2,2,5,'Meralco',100.00,'2026-10-27','Unpaid',4,'2026-10-04 20:45:12'),(4,1,4,'Apartment Monthly Rent (October 2026)',18000.00,'2026-10-05','Paid',1,'2026-10-01 09:00:00'),(5,1,3,'PLDT Home Fibr Fiber 100Mbps (October 2026)',1699.00,'2026-10-20','Paid',1,'2026-10-05 10:00:00'),(6,1,2,'Manila Water Utility (October 2026)',940.00,'2026-10-18','Paid',1,'2026-10-08 14:00:00'),(7,1,1,'Meralco Electric Power Bill (October 2026)',4820.00,'2026-10-28','Unpaid',1,'2026-10-15 11:30:00'),(8,1,2,'Barangay Garbage & Maintenance Dues (October)',400.00,'2026-10-10','Paid',1,'2026-10-02 08:00:00'),(9,1,4,'Apartment Monthly Rent (September 2026)',18000.00,'2026-09-05','Paid',1,'2026-09-01 09:00:00'),(10,1,1,'Meralco Electric Power Bill (September 2026)',4560.00,'2026-09-28','Paid',1,'2026-09-15 11:30:00'),(11,1,3,'PLDT Home Fibr Fiber 100Mbps (September 2026)',1699.00,'2026-09-20','Paid',1,'2026-09-05 10:00:00'),(12,1,2,'Manila Water Utility (September 2026)',880.00,'2026-09-18','Paid',1,'2026-09-08 14:00:00'),(13,1,4,'Apartment Monthly Rent (November 2026)',18000.00,'2026-11-05','Unpaid',1,'2026-10-21 15:00:00'),(14,1,4,'Apartment Monthly Rent (August 2026)',18000.00,'2026-08-05','Paid',1,'2026-08-01 09:00:00'),(15,1,1,'Meralco Electric Power Bill (August 2026)',4320.00,'2026-08-28','Paid',1,'2026-08-15 11:30:00'),(16,1,3,'PLDT Home Fibr Fiber 100Mbps (August 2026)',1699.00,'2026-08-20','Paid',1,'2026-08-05 10:00:00'),(17,1,2,'Manila Water Utility (August 2026)',790.00,'2026-08-18','Paid',1,'2026-08-08 14:00:00'),(18,1,2,'Apartment Bi-Monthly Pest Control Service (October 2026)',1200.00,'2026-10-22','Paid',1,'2026-10-15 08:30:00'),(19,1,2,'Drinking Water 5-Gallon Monthly Supply Package (October 2026)',600.00,'2026-10-12','Paid',1,'2026-10-02 10:00:00'),(20,1,3,'PLDT Home Fibr Fiber 100Mbps (November 2026)',1699.00,'2026-11-20','Unpaid',1,'2026-10-26 10:00:00'),(21,1,2,'Manila Water Utility (November 2026)',920.00,'2026-11-18','Unpaid',1,'2026-10-26 11:00:00'),(22,1,1,'Meralco Electric Power Bill (November 2026)',4750.00,'2026-11-28','Unpaid',1,'2026-10-26 14:00:00'),(23,1,4,'Rent',1000.00,'2026-10-29','Paid',1,'2026-10-28 14:50:11');
/*!40000 ALTER TABLE `billstb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chore_assignmentstb`
--

DROP TABLE IF EXISTS `chore_assignmentstb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chore_assignmentstb` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `chore_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`assignment_id`),
  KEY `chore_id` (`chore_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `chore_assignmentstb_ibfk_1` FOREIGN KEY (`chore_id`) REFERENCES `chorestb` (`chore_id`) ON DELETE CASCADE,
  CONSTRAINT `chore_assignmentstb_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `usertb` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chore_assignmentstb`
--

LOCK TABLES `chore_assignmentstb` WRITE;
/*!40000 ALTER TABLE `chore_assignmentstb` DISABLE KEYS */;
INSERT INTO `chore_assignmentstb` VALUES (4,4,1,'2026-10-02','Completed','2026-10-02 21:15:00'),(5,7,3,'2026-10-04','Completed','2026-10-04 19:30:00'),(6,5,7,'2026-10-07','Completed','2026-10-07 17:45:00'),(7,6,8,'2026-10-09','Completed','2026-10-09 16:20:00'),(8,4,3,'2026-10-11','Completed','2026-10-11 20:50:00'),(9,7,1,'2026-10-13','Completed','2026-10-13 18:40:00'),(10,8,7,'2026-10-14','Completed','2026-10-14 15:10:00'),(11,5,8,'2026-10-15','Completed','2026-10-15 17:00:00'),(13,7,7,'2026-10-19','Pending',NULL),(14,4,8,'2026-10-21','Pending',NULL),(15,5,1,'2026-10-22','Pending',NULL),(16,7,3,'2026-10-23','Pending',NULL),(18,9,1,'2026-10-26','Pending',NULL),(19,8,8,'2026-10-28','Pending',NULL),(20,4,1,'2026-09-25','Completed','2026-09-25 18:30:00'),(21,5,3,'2026-09-26','Completed','2026-09-26 19:15:00'),(22,6,7,'2026-09-27','Completed','2026-09-27 16:45:00'),(23,7,8,'2026-09-28','Completed','2026-09-28 20:00:00'),(24,8,1,'2026-09-29','Completed','2026-09-29 17:20:00'),(25,9,3,'2026-09-30','Completed','2026-09-30 21:00:00'),(26,4,7,'2026-10-01','Completed','2026-10-01 19:30:00'),(27,5,8,'2026-10-03','Completed','2026-10-03 17:00:00'),(28,6,1,'2026-10-05','Completed','2026-10-05 18:10:00'),(29,7,3,'2026-10-06','Completed','2026-10-06 19:40:00'),(30,8,7,'2026-10-08','Completed','2026-10-08 16:15:00'),(31,9,8,'2026-10-10','Completed','2026-10-10 15:30:00'),(32,10,1,'2026-10-12','Completed','2026-10-12 18:00:00'),(33,4,3,'2026-10-14','Completed','2026-10-14 20:30:00'),(34,5,7,'2026-10-16','Completed','2026-10-16 17:45:00'),(35,6,8,'2026-10-18','Completed','2026-10-18 16:50:00'),(36,7,1,'2026-10-20','Completed','2026-10-20 19:10:00'),(37,8,3,'2026-10-22','Completed','2026-10-22 17:30:00'),(38,9,7,'2026-10-24','Completed','2026-10-24 15:00:00'),(39,10,8,'2026-10-25','Completed','2026-10-25 18:40:00'),(40,4,1,'2026-10-27','Completed','2026-10-28 14:48:55'),(41,5,3,'2026-10-28','Pending',NULL),(43,7,8,'2026-10-30','Pending',NULL),(44,8,1,'2026-10-31','Pending',NULL),(45,9,3,'2026-11-01','Pending',NULL),(46,10,7,'2026-11-02','Pending',NULL),(47,10,1,'2026-10-28','Pending',NULL),(48,7,7,'2026-10-28','Pending',NULL),(49,4,3,'2026-10-28','Pending',NULL),(50,6,7,'2026-10-17','Pending',NULL),(51,13,7,'2026-10-30','Pending',NULL),(52,11,7,'2026-10-28','Pending',NULL),(53,12,1,'2026-11-04','Pending',NULL);
/*!40000 ALTER TABLE `chore_assignmentstb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chore_categoriestb`
--

DROP TABLE IF EXISTS `chore_categoriestb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chore_categoriestb` (
  `chore_category_id` int(11) NOT NULL AUTO_INCREMENT,
  `household_id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`chore_category_id`),
  KEY `household_id` (`household_id`),
  CONSTRAINT `chore_categoriestb_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `householdtb` (`household_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chore_categoriestb`
--

LOCK TABLES `chore_categoriestb` WRITE;
/*!40000 ALTER TABLE `chore_categoriestb` DISABLE KEYS */;
INSERT INTO `chore_categoriestb` VALUES (1,1,'Kitchen',NULL),(2,1,'Cleaning',NULL),(3,1,'Laundry',NULL),(4,1,'Trash',NULL),(5,2,'Kitchen',NULL),(6,2,'Cleaning',NULL),(7,2,'Laundry',NULL),(8,2,'Trash',NULL),(9,3,'Kitchen',NULL),(10,3,'Cleaning',NULL),(11,3,'Laundry',NULL),(12,3,'Trash',NULL),(13,1,'test',NULL);
/*!40000 ALTER TABLE `chore_categoriestb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chore_rotationtb`
--

DROP TABLE IF EXISTS `chore_rotationtb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chore_rotationtb` (
  `rotation_id` int(11) NOT NULL AUTO_INCREMENT,
  `chore_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rotation_order` int(11) NOT NULL,
  PRIMARY KEY (`rotation_id`),
  KEY `chore_id` (`chore_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `chore_rotationtb_ibfk_1` FOREIGN KEY (`chore_id`) REFERENCES `chorestb` (`chore_id`) ON DELETE CASCADE,
  CONSTRAINT `chore_rotationtb_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `usertb` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chore_rotationtb`
--

LOCK TABLES `chore_rotationtb` WRITE;
/*!40000 ALTER TABLE `chore_rotationtb` DISABLE KEYS */;
INSERT INTO `chore_rotationtb` VALUES (3,4,1,1),(4,4,3,2),(5,4,7,3),(6,4,8,4),(7,5,1,1),(8,5,3,2),(9,5,7,3),(10,5,8,4),(15,7,1,1),(16,7,3,2),(17,7,7,3),(18,7,8,4),(19,8,1,1),(20,8,3,2),(21,8,7,3),(22,8,8,4),(23,9,1,1),(24,9,3,2),(25,9,7,3),(26,9,8,4),(27,10,1,1),(28,10,3,2),(29,10,7,3),(30,10,8,4),(35,12,1,1),(36,12,3,2),(37,12,7,3),(38,12,8,4),(39,6,7,1),(40,6,3,2),(41,6,1,3),(42,6,8,4),(43,13,7,1),(44,13,3,2),(45,13,1,3),(46,13,8,4),(47,11,7,1),(48,11,3,2),(49,11,1,3),(50,11,8,4);
/*!40000 ALTER TABLE `chore_rotationtb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chorestb`
--

DROP TABLE IF EXISTS `chorestb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chorestb` (
  `chore_id` int(11) NOT NULL AUTO_INCREMENT,
  `household_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `chore_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `frequency` varchar(50) NOT NULL,
  `assignment_type` varchar(20) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`chore_id`),
  KEY `household_id` (`household_id`),
  KEY `category_id` (`category_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `chorestb_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `householdtb` (`household_id`) ON DELETE CASCADE,
  CONSTRAINT `chorestb_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `chore_categoriestb` (`chore_category_id`) ON DELETE SET NULL,
  CONSTRAINT `chorestb_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `usertb` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chorestb`
--

LOCK TABLES `chorestb` WRITE;
/*!40000 ALTER TABLE `chorestb` DISABLE KEYS */;
INSERT INTO `chorestb` VALUES (4,1,1,'Kitchen Countertops & Dishwashing Station','Wipe down kitchen counters, wash dishes, scrub stovetop, and empty sink strainer.','Daily','Rotation',1,'2026-09-24 10:00:00'),(5,1,2,'Living Room Floor Sweep, Vacuum & Mopping','Sweep living room tiles, vacuum under the sofa, and mop with pine disinfectant.','Weekly','Rotation',1,'2026-09-24 10:00:00'),(6,1,2,'Bathroom & Toilet Scrubbing & Disinfection','Scrub toilet bowl, wash shower glass door, disinfect sink, and clean floor tiles.','Weekly','Rotation',1,'2026-09-24 10:00:00'),(7,1,4,'Trash & Recycling Curbside Rollout','Empty all bathroom and bedroom bins, tie trash bags, and take outside for collection.','Twice a week','Rotation',1,'2026-09-24 10:00:00'),(8,1,3,'Kitchen Towels & Bath Mats Laundry','Wash common hand towels, dishcloths, and bathroom floor rugs in washing machine.','Bi-weekly','Rotation',1,'2026-09-24 10:00:00'),(9,1,1,'Refrigerator Deep Clean & Pantry Purge','Toss expired sauces, wipe interior glass shelves, and organize shared condiment bins.','Monthly','Rotation',1,'2026-09-24 10:00:00'),(10,1,2,'Balcony Plants Watering & Patio Sweep','Water potted plants, remove dry leaves, and sweep tile flooring of the balcony.','Weekly','Rotation',1,'2026-09-24 10:00:00'),(11,1,1,'Kitchen Range Hood Degreasing & Stovetop Scrub','Wash aluminum grease filters with warm soapy water and scrub burner plates.','One-time','Rotation',1,'2026-09-24 10:00:00'),(12,1,2,'Shoe Cabinet & Entryway Sanitizing','Straighten shoes on the entryway rack, disinfect door handles, and vacuum floor mat.','Weekly','Rotation',1,'2026-09-24 10:00:00'),(13,1,1,'test','test','Weekly','Rotation',1,'2026-10-28 21:21:29');
/*!40000 ALTER TABLE `chorestb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_categoriestb`
--

DROP TABLE IF EXISTS `expense_categoriestb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expense_categoriestb` (
  `expense_category_id` int(11) NOT NULL AUTO_INCREMENT,
  `household_id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`expense_category_id`),
  KEY `household_id` (`household_id`),
  CONSTRAINT `expense_categoriestb_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `householdtb` (`household_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_categoriestb`
--

LOCK TABLES `expense_categoriestb` WRITE;
/*!40000 ALTER TABLE `expense_categoriestb` DISABLE KEYS */;
INSERT INTO `expense_categoriestb` VALUES (1,1,'Grocery','Food, groceries, and daily supermarket essentials'),(2,1,'Dining Out','Restaurants, takeout, delivery, and dining meals'),(3,1,'Household Supplies','Cleaning items, toiletries, paper goods, and home supplies'),(4,1,'Transportation','Fuel, parking, public transit fares, tolls, and rideshares'),(5,1,'Entertainment','Movies, games, outings, and leisure activities'),(6,1,'Healthcare','Medications, first aid, clinic visits, and medical items'),(7,1,'Personal','Personal care, grooming, and miscellaneous individual items'),(8,1,'Other','General household and miscellaneous shared spending'),(9,2,'Grocery','Food, groceries, and daily supermarket essentials'),(10,2,'Dining Out','Restaurants, takeout, delivery, and dining meals'),(11,2,'Household Supplies','Cleaning items, toiletries, paper goods, and home supplies'),(12,2,'Transportation','Fuel, parking, public transit fares, tolls, and rideshares'),(13,2,'Entertainment','Movies, games, outings, and leisure activities'),(14,2,'Healthcare','Medications, first aid, clinic visits, and medical items'),(15,2,'Personal','Personal care, grooming, and miscellaneous individual items'),(16,2,'Other','General household and miscellaneous shared spending'),(17,3,'Grocery','Food, groceries, and daily supermarket essentials'),(18,3,'Dining Out','Restaurants, takeout, delivery, and dining meals'),(19,3,'Household Supplies','Cleaning items, toiletries, paper goods, and home supplies'),(20,3,'Transportation','Fuel, parking, public transit fares, tolls, and rideshares'),(21,3,'Entertainment','Movies, games, outings, and leisure activities'),(22,3,'Healthcare','Medications, first aid, clinic visits, and medical items'),(23,3,'Personal','Personal care, grooming, and miscellaneous individual items'),(24,3,'Other','General household and miscellaneous shared spending');
/*!40000 ALTER TABLE `expense_categoriestb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expenses_sharestb`
--

DROP TABLE IF EXISTS `expenses_sharestb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expenses_sharestb` (
  `share_id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `share_amount` decimal(10,2) NOT NULL,
  `is_paid` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`share_id`),
  KEY `expense_id` (`expense_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `expenses_sharestb_ibfk_1` FOREIGN KEY (`expense_id`) REFERENCES `expensetb` (`expense_id`) ON DELETE CASCADE,
  CONSTRAINT `expenses_sharestb_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `usertb` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=175 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses_sharestb`
--

LOCK TABLES `expenses_sharestb` WRITE;
/*!40000 ALTER TABLE `expenses_sharestb` DISABLE KEYS */;
INSERT INTO `expenses_sharestb` VALUES (7,4,1,212.50,1),(8,4,3,212.50,1),(9,4,7,212.50,1),(10,4,8,212.50,1),(11,5,1,912.50,1),(12,5,3,912.50,0),(13,5,7,912.50,0),(14,5,8,912.50,1),(15,6,1,135.00,0),(16,6,3,135.00,1),(17,6,7,135.00,0),(18,6,8,135.00,1),(19,7,1,295.00,1),(20,7,3,295.00,1),(21,7,7,295.00,1),(22,7,8,295.00,1),(23,8,1,115.00,1),(24,8,3,115.00,1),(25,8,7,115.00,1),(26,8,8,115.00,1),(27,9,1,137.25,1),(28,9,3,137.25,1),(29,9,7,137.25,1),(30,9,8,137.25,1),(31,10,1,180.00,1),(32,10,3,180.00,1),(33,10,7,180.00,1),(34,10,8,180.00,1),(35,11,1,96.25,1),(36,11,3,96.25,1),(37,11,7,96.25,1),(38,11,8,96.25,1),(39,12,1,87.50,1),(40,12,3,87.50,1),(41,12,7,87.50,1),(42,12,8,87.50,1),(43,13,1,80.00,1),(44,13,3,80.00,1),(45,13,7,80.00,1),(46,13,8,80.00,1),(47,14,1,222.50,1),(48,14,3,222.50,1),(49,14,7,222.50,1),(50,14,8,222.50,1),(51,15,1,162.50,1),(52,15,3,162.50,1),(53,15,7,162.50,1),(54,15,8,162.50,1),(55,16,1,1030.00,1),(56,16,3,1030.00,1),(57,16,7,1030.00,1),(58,16,8,1030.00,1),(59,17,1,195.00,1),(60,17,3,195.00,1),(61,17,7,195.00,1),(62,17,8,195.00,1),(63,18,1,525.00,1),(64,18,3,525.00,1),(65,18,7,525.00,1),(66,18,8,525.00,1),(67,19,1,59.75,1),(68,19,3,59.75,1),(69,19,7,59.75,1),(70,19,8,59.75,1),(71,20,1,105.00,1),(72,20,3,105.00,1),(73,20,7,105.00,1),(74,20,8,105.00,1),(75,21,1,145.00,1),(76,21,3,145.00,1),(77,21,7,145.00,1),(78,21,8,145.00,1),(79,22,1,412.50,1),(80,22,3,412.50,1),(81,22,7,412.50,1),(82,22,8,412.50,1),(83,23,1,312.50,1),(84,23,3,312.50,1),(85,23,7,312.50,1),(86,23,8,312.50,1),(87,24,1,262.50,1),(88,24,3,262.50,0),(89,24,7,262.50,0),(90,24,8,262.50,0),(91,25,1,362.50,1),(92,25,3,362.50,1),(93,25,7,362.50,0),(94,25,8,362.50,0),(95,26,1,195.00,0),(96,26,3,195.00,1),(97,26,7,195.00,1),(98,26,8,195.00,1),(99,27,1,230.00,1),(100,27,3,230.00,1),(101,27,7,230.00,1),(102,27,8,230.00,1),(103,28,1,160.00,1),(104,28,3,160.00,1),(105,28,7,160.00,1),(106,28,8,160.00,1),(107,29,1,73.75,1),(108,29,3,73.75,1),(109,29,7,73.75,1),(110,29,8,73.75,1),(111,30,1,90.00,1),(112,30,3,90.00,1),(113,30,7,90.00,1),(114,30,8,90.00,1),(115,31,1,105.00,1),(116,31,3,105.00,1),(117,31,7,105.00,1),(118,31,8,105.00,1),(119,32,1,62.50,1),(120,32,3,62.50,1),(121,32,7,62.50,1),(122,32,8,62.50,1),(123,33,1,120.00,1),(124,33,3,120.00,1),(125,33,7,120.00,1),(126,33,8,120.00,1),(127,34,1,280.00,1),(128,34,3,280.00,1),(129,34,7,280.00,1),(130,34,8,280.00,1),(131,35,1,95.00,1),(132,35,3,95.00,1),(133,35,7,95.00,1),(134,35,8,95.00,1),(135,36,1,462.50,1),(136,36,3,462.50,1),(137,36,7,462.50,1),(138,36,8,462.50,1),(139,37,1,52.50,1),(140,37,3,52.50,1),(141,37,7,52.50,1),(142,37,8,52.50,1),(143,38,1,155.00,1),(144,38,3,155.00,1),(145,38,7,155.00,1),(146,38,8,155.00,1),(147,39,1,80.00,1),(148,39,3,80.00,1),(149,39,7,80.00,1),(150,39,8,80.00,1),(151,40,1,48.75,1),(152,40,3,48.75,1),(153,40,7,48.75,1),(154,40,8,48.75,1),(155,41,1,395.00,1),(156,41,3,395.00,1),(157,41,7,395.00,1),(158,41,8,395.00,1),(159,42,1,124.75,1),(160,42,3,124.75,1),(161,42,7,124.75,1),(162,42,8,124.75,1),(163,43,1,132.50,1),(164,43,3,132.50,1),(165,43,7,132.50,1),(166,43,8,132.50,1),(167,44,1,70.00,1),(168,44,3,70.00,1),(169,44,7,70.00,1),(170,44,8,70.00,1),(171,45,1,96.25,1),(172,45,3,96.25,1),(173,45,7,96.25,1),(174,45,8,96.25,1);
/*!40000 ALTER TABLE `expenses_sharestb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expensetb`
--

DROP TABLE IF EXISTS `expensetb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expensetb` (
  `expense_id` int(11) NOT NULL AUTO_INCREMENT,
  `household_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `title` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `expense_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`expense_id`),
  KEY `household_id` (`household_id`),
  KEY `category_id` (`category_id`),
  KEY `paid_by` (`paid_by`),
  CONSTRAINT `expensetb_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `householdtb` (`household_id`) ON DELETE CASCADE,
  CONSTRAINT `expensetb_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `expense_categoriestb` (`expense_category_id`) ON DELETE SET NULL,
  CONSTRAINT `expensetb_ibfk_3` FOREIGN KEY (`paid_by`) REFERENCES `usertb` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expensetb`
--

LOCK TABLES `expensetb` WRITE;
/*!40000 ALTER TABLE `expensetb` DISABLE KEYS */;
INSERT INTO `expensetb` VALUES (4,1,1,'Weekly Fresh Market Produce & Veggies',850.00,1,'2026-10-21','Fresh vegetables, garlic, onions, tomatoes, and cooking ingredients from public market.','2026-10-21 12:00:00'),(5,1,1,'SM Hypermarket Bulk Grocery Run',3650.00,8,'2026-10-20','Monthly communal pantry restock: rice, canned goods, pasta, snacks, and condiments.','2026-10-20 12:00:00'),(6,1,3,'Living Room Floor Cleaner & Disinfectant',540.00,3,'2026-10-18','Lysol multi-surface cleaner, bleach, and microfiber mop replacement pads.','2026-10-18 12:00:00'),(7,1,2,'Friday Night Pizza & Wings Delivery',1180.00,7,'2026-10-16','Shared dinner for movie marathon night: 2 large pizzas and 12-pc buffalo wings.','2026-10-16 12:00:00'),(8,1,3,'Bathroom Tissue & Wet Wipes Bundle',460.00,1,'2026-10-15','24-roll pack of bathroom tissue and 3 refill packs of disinfectant wipes.','2026-10-15 12:00:00'),(9,1,5,'Shared Netflix Premium 4K Family Plan',549.00,8,'2026-10-14','Monthly recurring Netflix 4-screen household subscription.','2026-10-14 12:00:00'),(10,1,1,'Pantry Cooking Oil, Spices & Seasonings',720.00,3,'2026-10-12','Olive oil, canola oil, soy sauce, fish sauce, and assorted kitchen seasonings.','2026-10-12 12:00:00'),(11,1,6,'First Aid Kit Restock & Paracetamol',385.00,7,'2026-10-10','Bandages, betadine, paracetamol, antacids, and thermometer.','2026-10-10 12:00:00'),(12,1,1,'Purified Drinking Water 5-Gallon Refill',350.00,1,'2026-10-08','7 blue container refills delivered to apartment.','2026-10-08 12:00:00'),(13,1,3,'Trash Bags, Dishwashing Paste & Sponges',320.00,8,'2026-10-05','Heavy duty black garbage bags (pack of 50) and dishwashing sponges.','2026-10-05 12:00:00'),(14,1,2,'Weekend Dim Sum Takeout for Household',890.00,3,'2026-10-03','Sunday morning shared breakfast dumplings and steamed buns.','2026-10-03 12:00:00'),(15,1,8,'Living Room Extension Cord & LED Bulbs',650.00,1,'2026-10-01','Surge protector power strip for television and 3 warm white LED bulbs.','2026-10-01 12:00:00'),(16,1,1,'September Monthly Grocery & Frozen Meat',4120.00,7,'2026-09-28','Chicken breast, pork chops, ground beef, eggs, and frozen breakfast items.','2026-09-28 12:00:00'),(17,1,3,'Laundry Detergent Powder & Softener',780.00,8,'2026-09-24','Breeze laundry powder bucket and Downy fabric conditioner.','2026-09-24 12:00:00'),(18,1,2,'Samgyupsal Korean BBQ Dinner Celebration',2100.00,1,'2026-09-20','Quarterly roommate celebratory dinner out.','2026-09-20 12:00:00'),(19,1,5,'Spotify Family Plan Subscription',239.00,3,'2026-09-15','Monthly family music streaming account.','2026-09-15 12:00:00'),(20,1,1,'Purified Water Refills & Dispenser Tablets',420.00,7,'2026-09-12','6 water refills and sanitizing tablets for cold/hot water dispenser.','2026-09-12 12:00:00'),(21,1,4,'Shared GrabCar Transport to Home Depot',580.00,8,'2026-09-05','Roundtrip transport for purchasing apartment storage shelves and hooks.','2026-09-05 12:00:00'),(22,1,2,'Welcome Moving-In Potluck Dinner',1650.00,1,'2026-08-29','Roasted chicken, side salads, drinks, and welcoming snacks.','2026-08-29 12:00:00'),(23,1,3,'Apartment Deep Clean Mops & Buckets',1250.00,3,'2026-08-25','Spin mop set, squeegee, dustpans, and heavy duty scouring pads.','2026-08-25 12:00:00'),(24,1,3,'Solane LPG Cooking Gas 11kg Tank Refill',1050.00,1,'2026-10-24','Replaced empty kitchen cooking gas cylinder with safety regulator check.','2026-10-24 14:20:00'),(25,1,1,'Landers Wholesale 25kg Sinandomeng Rice Sack',1450.00,3,'2026-10-22','Shared pantry whole grain rice supply for the household.','2026-10-22 17:00:00'),(26,1,3,'Ariel Liquid Detergent & Downy Bulk Refill Pouches',780.00,8,'2026-10-19','Laundry supplies restock: antibacterial liquid detergent and fabric softener.','2026-10-19 11:30:00'),(27,1,1,'Sunday Brunch Cookout (Pancakes, Bacon, Eggs & Juice)',920.00,7,'2026-10-18','Communal weekend breakfast ingredients for all roommates.','2026-10-18 10:15:00'),(28,1,3,'Ace Hardware Multi-Bit Screwdriver & Pliers Toolset',640.00,1,'2026-10-17','Household toolset for furniture assembly and quick home repairs.','2026-10-17 15:45:00'),(29,1,3,'Joy Antibacterial Dishwashing Liquid Refill Pack (3x 800ml)',295.00,3,'2026-10-13','Kitchen dishwashing liquid triple pouch bundle.','2026-10-13 18:00:00'),(30,1,3,'Baygon Multi-Insect Killer Citrus Aerosol Twin Pack',360.00,8,'2026-10-11','Mosquito and insect barrier spray for balcony and kitchen.','2026-10-11 13:20:00'),(31,1,1,'Pantry Aromatics (Garlic, Red Onions, Ginger & Potatoes)',420.00,7,'2026-10-09','Fresh aromatics and root crops from public market vendor.','2026-10-09 09:30:00'),(32,1,1,'Drinking Water 5-Gallon Refills (5 jugs delivered)',250.00,1,'2026-10-07','Door-to-door purified water delivery for water dispenser.','2026-10-07 14:00:00'),(33,1,3,'Glade Automatic Air Freshener Lavender Refills (Pack of 2)',480.00,8,'2026-10-03','Living room and corridor automated fragrance spray refills.','2026-10-03 16:30:00'),(34,1,2,'Jollibee 8-pc Chickenjoy & Jolly Spaghetti Group Meal',1120.00,3,'2026-10-01','First of the month celebration dinner after apartment general cleaning.','2026-10-01 19:45:00'),(35,1,3,'Philips Warm White 9W LED Bulbs (Pack of 4)',380.00,1,'2026-09-29','Replaced burned out bulbs in dining fixture and hallway socket.','2026-09-29 16:00:00'),(36,1,1,'Robinson\'s Supermarket Meat & Poultry Stockup',1850.00,8,'2026-09-26','Chicken breast, lean ground pork, beef cuts, and frozen mixed veg.','2026-09-26 15:30:00'),(37,1,3,'Scotch-Brite Heavy Duty Scrub Sponges & Wipes (Pack of 6)',210.00,7,'2026-09-23','Non-scratch dish sponges and yellow microfiber kitchen wipes.','2026-09-23 11:15:00'),(38,1,1,'Communal Coffee Beans 1kg (Sagada Arabica Medium Roast)',620.00,1,'2026-09-20','Shared whole bean bag for kitchen French press and drip brewer.','2026-09-20 08:30:00'),(39,1,4,'Shared GrabCar Transport to Wholesale Club',320.00,3,'2026-09-17','Shared fare for roommates transporting bulk laundry and pantry boxes.','2026-09-17 18:20:00'),(40,1,6,'First Aid Alcohol, Cotton Balls & Band-Aids Restock',195.00,8,'2026-09-14','70% Isopropyl alcohol, sterile gauze pads, adhesive bandages.','2026-09-14 12:40:00'),(41,1,2,'Shakey\'s Monster Meal Deal Pizza & Chicken for Game Night',1580.00,7,'2026-09-08','Shared pizza, mojo potatoes, and iced tea for roommates board game night.','2026-09-08 20:00:00'),(42,1,3,'Living Room 6-Gang Surge Protector Extension Cord',499.00,1,'2026-09-03','Individual switch surge-protected extension cord for entertainment console.','2026-09-03 14:10:00'),(43,1,3,'Anti-Slip Bathroom Floor Mats & Microfiber Towels',530.00,8,'2026-08-31','Quick-dry diatomite bath mat and replacement microfiber hand towels.','2026-08-31 17:30:00'),(44,1,3,'Apartment Welcome Doormats (Main Entry & Kitchen Access)',280.00,3,'2026-08-27','Heavy duty coir welcome mat and rubber backed kitchen runner.','2026-08-27 15:00:00'),(45,1,1,'Puregold Cooking Condiments, Salt, Peppercorns & Vinegar',385.00,7,'2026-08-26','Datu Puti vinegar, soy sauce, iodized salt, whole black pepper.','2026-08-26 16:45:00');
/*!40000 ALTER TABLE `expensetb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `householdmemberstb`
--

DROP TABLE IF EXISTS `householdmemberstb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `householdmemberstb` (
  `household_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'Member',
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`household_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `householdmemberstb_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `householdtb` (`household_id`) ON DELETE CASCADE,
  CONSTRAINT `householdmemberstb_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `usertb` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `householdmemberstb`
--

LOCK TABLES `householdmemberstb` WRITE;
/*!40000 ALTER TABLE `householdmemberstb` DISABLE KEYS */;
INSERT INTO `householdmemberstb` VALUES (1,1,'Admin','2026-09-23 22:42:00'),(1,3,'Member','2026-09-23 23:37:53'),(1,7,'Member','2026-10-21 22:03:58'),(1,8,'Member','2026-10-21 22:03:58'),(2,4,'Admin','2026-10-04 20:34:33'),(2,5,'Member','2026-10-04 20:47:29'),(3,6,'Admin','2026-10-14 19:10:48');
/*!40000 ALTER TABLE `householdmemberstb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `householdtb`
--

DROP TABLE IF EXISTS `householdtb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `householdtb` (
  `household_id` int(11) NOT NULL AUTO_INCREMENT,
  `household_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `household_image` varchar(255) DEFAULT NULL,
  `join_code` varchar(32) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`household_id`),
  UNIQUE KEY `join_code` (`join_code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `householdtb`
--

LOCK TABLES `householdtb` WRITE;
/*!40000 ALTER TABLE `householdtb` DISABLE KEYS */;
INSERT INTO `householdtb` VALUES (1,'Malued','Cozy 3-bedroom shared apartment with balcony, spacious kitchen, and shared workspace.','Unit 402, Sunshine Heights Condominiums, Malued District, Dagupan City','uploads/households/household_1_daa855d183e5d0ee.png','69F0DB7A','2026-09-23 22:42:00'),(2,'Malued',NULL,'123',NULL,'2B246942','2026-10-04 20:34:33'),(3,'Gian House','','',NULL,'6FBA5224','2026-10-14 19:10:48');
/*!40000 ALTER TABLE `householdtb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `maintenancetb`
--

DROP TABLE IF EXISTS `maintenancetb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `maintenancetb` (
  `problem_id` int(11) NOT NULL AUTO_INCREMENT,
  `household_id` int(11) NOT NULL,
  `reported_by` int(11) DEFAULT NULL,
  `problem_type` varchar(50) NOT NULL,
  `problem_desc` text DEFAULT NULL,
  `reported_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) NOT NULL DEFAULT 'Open',
  PRIMARY KEY (`problem_id`),
  KEY `household_id` (`household_id`),
  KEY `reported_by` (`reported_by`),
  CONSTRAINT `maintenancetb_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `householdtb` (`household_id`) ON DELETE CASCADE,
  CONSTRAINT `maintenancetb_ibfk_2` FOREIGN KEY (`reported_by`) REFERENCES `usertb` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenancetb`
--

LOCK TABLES `maintenancetb` WRITE;
/*!40000 ALTER TABLE `maintenancetb` DISABLE KEYS */;
INSERT INTO `maintenancetb` VALUES (1,1,1,'Plumbing','P-trap piping beneath kitchen double sink has a slow drip causing minor puddling inside cabinet base.','2026-10-04 09:30:00','Resolved'),(2,1,3,'Electrical','Bathroom exhaust fan switch clicks but the fan motor hums loudly without spinning.','2026-10-17 14:15:00','In Progress'),(3,1,8,'Carpentry','Living room sliding balcony glass door bottom track has loosened rollers, making it difficult to slide shut.','2026-10-20 18:20:00','Open'),(4,1,7,'Appliance','Main living room inverter aircon unit cooling airflow is weak, needs full filter washing and coil cleaning.','2026-09-22 11:00:00','Resolved'),(5,1,1,'Security','Front entryway deadbolt lock cylinder sticks when turning key from hallway.','2026-10-21 08:45:00','Open'),(6,1,3,'Electrical','Hallway fluorescent ceiling fixture flickers intermittently, likely faulty starter or ballast.','2026-10-12 11:20:00','Resolved'),(7,1,8,'Plumbing','Shower floor drain draining slowly; hair catcher cleared but main pipe needed liquid clog remover.','2026-10-08 16:40:00','Resolved'),(8,1,7,'Appliance','Washing machine water inlet connection hose rubber gasket was seeping droplets during wash cycle.','2026-10-15 14:00:00','Resolved'),(9,1,1,'Carpentry','Kitchen upper corner cabinet door hinge screws stripped from particle board, cabinet sagging slightly.','2026-10-23 09:15:00','In Progress'),(10,1,8,'Appliance','Kitchen range hood recirculating charcoal filter needs replacement due to cooking odor buildup.','2026-10-25 18:30:00','Open'),(11,1,7,'Carpentry','Bedroom window mesh screen corner came unglued from aluminum frame during monsoon gusts.','2026-09-18 10:10:00','Resolved'),(12,1,3,'Plumbing','Bathroom shower water heater unit thermostat knob feels loose when adjusting between warm and hot.','2026-10-27 19:45:00','Open'),(13,1,1,'Other','test bro','2026-10-28 22:07:57','Open'),(14,1,3,'Appliance','test lang po','2026-10-28 22:15:00','Open');
/*!40000 ALTER TABLE `maintenancetb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `paymentstb`
--

DROP TABLE IF EXISTS `paymentstb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paymentstb` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `bill_share_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Paid',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`payment_id`),
  KEY `bill_share_id` (`bill_share_id`),
  CONSTRAINT `paymentstb_ibfk_1` FOREIGN KEY (`bill_share_id`) REFERENCES `bill_sharestb` (`bill_share_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `paymentstb`
--

LOCK TABLES `paymentstb` WRITE;
/*!40000 ALTER TABLE `paymentstb` DISABLE KEYS */;
INSERT INTO `paymentstb` VALUES (1,22,100.00,'2026-10-21','Paid',NULL),(2,23,100.00,'2026-10-21','Paid',NULL),(3,24,100.00,'2026-10-21','Paid',NULL),(4,25,100.00,'2026-10-21','Paid',NULL),(5,6,4500.00,'2026-10-02','Paid','Transferred through Maya'),(7,8,4500.00,'2026-10-03','Paid','Transferred through Maya'),(8,9,4500.00,'2026-10-05','Paid','Settled in cash with Prince'),(9,10,424.75,'2026-10-18','Paid','GCash QR payment transfer'),(10,11,424.75,'2026-10-17','Paid','Transferred through Maya'),(11,12,424.75,'2026-10-18','Paid','Bank transfer via BPI Online'),(12,13,424.75,'2026-10-17','Paid','Paid on time via mobile banking'),(13,14,235.00,'2026-10-17','Paid','GCash QR payment transfer'),(14,15,235.00,'2026-10-17','Paid','Paid via GCash app'),(15,16,235.00,'2026-10-18','Paid','Settled in cash with Prince'),(16,17,235.00,'2026-10-17','Paid','GCash QR payment transfer'),(17,18,1205.00,'2026-10-25','Paid','GCash QR payment transfer'),(18,20,1205.00,'2026-10-26','Paid','Transferred through Maya'),(19,26,4500.00,'2026-09-04','Paid','Paid via GCash app'),(20,27,4500.00,'2026-09-02','Paid','Paid on time via mobile banking'),(21,28,4500.00,'2026-09-02','Paid','GCash QR payment transfer'),(22,29,4500.00,'2026-09-03','Paid','Settled in cash with Prince'),(23,30,1140.00,'2026-09-27','Paid','Paid on time via mobile banking'),(24,31,1140.00,'2026-09-26','Paid','Paid via GCash app'),(25,32,1140.00,'2026-09-27','Paid','Settled in cash with Prince'),(26,33,1140.00,'2026-09-27','Paid','Paid via GCash app'),(27,34,424.75,'2026-09-17','Paid','Bank transfer via BPI Online'),(28,35,424.75,'2026-09-17','Paid','Paid via GCash app'),(29,36,424.75,'2026-09-19','Paid','Paid via GCash app'),(30,37,424.75,'2026-09-17','Paid','Settled in cash with Prince'),(31,38,220.00,'2026-09-18','Paid','Transferred through Maya'),(32,39,220.00,'2026-09-17','Paid','Paid on time via mobile banking'),(33,40,220.00,'2026-09-17','Paid','Paid on time via mobile banking'),(34,41,220.00,'2026-09-18','Paid','GCash QR payment transfer'),(37,48,4500.00,'2026-08-05','Paid','Paid on time via online transfer'),(38,49,4500.00,'2026-08-03','Paid','Paid on time via online transfer'),(39,50,1080.00,'2026-08-26','Paid','Paid on time via online transfer'),(40,51,1080.00,'2026-08-28','Paid','Paid on time via online transfer'),(41,52,1080.00,'2026-08-28','Paid','Paid on time via online transfer'),(42,53,1080.00,'2026-08-28','Paid','Paid on time via online transfer'),(43,54,424.75,'2026-08-20','Paid','Paid on time via online transfer'),(44,55,424.75,'2026-08-18','Paid','Paid on time via online transfer'),(45,56,424.75,'2026-08-20','Paid','Paid on time via online transfer'),(46,57,424.75,'2026-08-20','Paid','Paid on time via online transfer'),(47,58,197.50,'2026-08-16','Paid','Paid on time via online transfer'),(48,59,197.50,'2026-08-16','Paid','Paid on time via online transfer'),(49,60,197.50,'2026-08-18','Paid','Paid on time via online transfer'),(50,61,197.50,'2026-08-17','Paid','Paid on time via online transfer'),(51,62,300.00,'2026-10-21','Paid','Paid on time via online transfer'),(52,63,300.00,'2026-10-20','Paid','Paid on time via online transfer'),(53,64,300.00,'2026-10-21','Paid','Paid on time via online transfer'),(54,65,300.00,'2026-10-20','Paid','Paid on time via online transfer'),(55,66,150.00,'2026-10-12','Paid','Paid on time via online transfer'),(56,67,150.00,'2026-10-12','Paid','Paid on time via online transfer'),(57,68,150.00,'2026-10-11','Paid','Paid on time via online transfer'),(58,69,150.00,'2026-10-10','Paid','Paid on time via online transfer'),(59,82,250.00,'2026-10-28','Paid',NULL),(60,83,250.00,'2026-10-28','Paid',NULL),(61,84,250.00,'2026-10-28','Paid',NULL),(62,85,250.00,'2026-10-28','Paid',NULL),(63,6,4500.00,'2026-10-28','Paid',NULL),(64,46,4500.00,'2026-10-28','Paid',NULL),(66,47,4500.00,'2026-10-28','Paid',NULL);
/*!40000 ALTER TABLE `paymentstb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usertb`
--

DROP TABLE IF EXISTS `usertb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usertb` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `recovery_code_hash` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `uq_user_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usertb`
--

LOCK TABLES `usertb` WRITE;
/*!40000 ALTER TABLE `usertb` DISABLE KEYS */;
INSERT INTO `usertb` VALUES (1,'Prince Gian Fernandez',NULL,'uploads/profiles/user_1_383f339f56c77476.png','Prince','$2y$10$F42CB7MgzBoKkU3.Ka7K8.TYbVczDjIfyGPzdmxvYNqjcl8EZiire','$2y$10$btoahKN4wjbmJCYqbe7U1u5CBqZPUakMOJcEMvIqp1HDeSWPWhJEq','2026-09-23 21:53:05','Active'),(2,'Jonathan Villasenor','jonathanpersonalvillasenor@gmail.com','uploads/profiles/user_3_jonathan.jpg','Jonathan','$2y$10$F42CB7MgzBoKkU3.Ka7K8.TYbVczDjIfyGPzdmxvYNqjcl8EZiire','$2y$10$TkirNOEFsLl97IA1mJ7mEuJKal42sSbNvDl5wNHRrhdnG0aK0JqOS','2026-09-23 23:26:07','Active'),(3,'Jonathan Villasenor','jonathanpersonalvillasenorz@gmail.com','uploads/profiles/user_3_jonathan.jpg','Jonats','$2y$10$F42CB7MgzBoKkU3.Ka7K8.TYbVczDjIfyGPzdmxvYNqjcl8EZiire','$2y$10$uYapJJg2HQbUUDM3kusGG.Fda.M1D4pEDYuLqZM6JhWnqAtFVjo0O','2026-09-23 23:27:01','Active'),(4,'princetest','princetest@gmail.com',NULL,'princetest','$2y$10$F42CB7MgzBoKkU3.Ka7K8.TYbVczDjIfyGPzdmxvYNqjcl8EZiire','$2y$10$9ftewgckCPJqDWxBeQ0HQeyEb29xNIt15bL9GHGWcika4F4jw2DDq','2026-10-04 20:34:19','Active'),(5,'giantest','giantest@gmail.com',NULL,'giantest','$2y$10$F42CB7MgzBoKkU3.Ka7K8.TYbVczDjIfyGPzdmxvYNqjcl8EZiire','$2y$10$N57d8pqVsbbLHvt8djP3Ye7RAhr2zVkEu4yYiGwdHJMqPtBxb6bJy','2026-10-04 20:47:05','Active'),(6,'Gian','Gian@gmail.com',NULL,'Gian123','$2y$10$F42CB7MgzBoKkU3.Ka7K8.TYbVczDjIfyGPzdmxvYNqjcl8EZiire','$2y$10$U59x7Ys4zWLrBDBuqsf.duMXc0S.uT7k8LurjcjDO62YriWaeGY7.','2026-10-14 19:10:25','Active'),(7,'John Doe','john@example.com','uploads/profiles/user_7_john_doe.jpg','john_doe','$2y$10$F42CB7MgzBoKkU3.Ka7K8.TYbVczDjIfyGPzdmxvYNqjcl8EZiire','$2y$10$C9PgP2H8pgjZsI5CJ3Blbuv3RD.v67Hjqkubx9OCHJI.1HxlVXome','2026-10-21 21:21:15','Active'),(8,'Sofia Ramos','sofia.ramos@roomiesync.test','uploads/profiles/user_8_sofia_ramos.jpg','sofia_ramos','$2y$10$F42CB7MgzBoKkU3.Ka7K8.TYbVczDjIfyGPzdmxvYNqjcl8EZiire',NULL,'2026-10-21 22:03:58','Active'),(9,'Mateo Alvarez','mateo.alvarez@roomiesync.test',NULL,'mateo_alvarez','$2y$10$XTVPpgLSw7Cw9am/29wkWOXLaK1gWSwJVciG8mR87lgvKS2wWCUR2','$2y$10$VFbQbJ.pH7rAepwz.2m2duEFae2fxD7Y.tHYtUFCzlnayKUrAUhe2','2026-10-26 19:37:44','Active');
/*!40000 ALTER TABLE `usertb` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-28 22:24:01
