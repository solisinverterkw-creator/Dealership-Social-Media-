-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: dealership_dashboard
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
-- Table structure for table `app_settings`
--

DROP TABLE IF EXISTS `app_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_settings`
--

LOCK TABLES `app_settings` WRITE;
/*!40000 ALTER TABLE `app_settings` DISABLE KEYS */;
INSERT INTO `app_settings` VALUES ('source_page_id','100069181887026'),('source_page_name','Suzuki Pakistan | Karachi '),('source_page_url','https://www.facebook.com/SuzukiPakistan'),('zapier_connected_pages_count','2'),('zapier_webhook_url','https://hooks.zapier.com/hooks/catch/28220162/4ugr700/');
/*!40000 ALTER TABLE `app_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `brand_identity`
--

DROP TABLE IF EXISTS `brand_identity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `brand_identity` (
  `id` int(11) NOT NULL DEFAULT 1,
  `logo_light_path` varchar(255) DEFAULT NULL,
  `logo_dark_path` varchar(255) DEFAULT NULL,
  `tagline` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `brand_identity`
--

LOCK TABLES `brand_identity` WRITE;
/*!40000 ALTER TABLE `brand_identity` DISABLE KEYS */;
/*!40000 ALTER TABLE `brand_identity` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dealerships`
--

DROP TABLE IF EXISTS `dealerships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dealerships` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `fb_input` varchar(255) DEFAULT NULL,
  `yt_search` varchar(255) DEFAULT NULL,
  `google_search` varchar(255) DEFAULT NULL,
  `ig_search` varchar(255) DEFAULT NULL,
  `fb_followers` int(11) DEFAULT 0,
  `fb_target` int(11) DEFAULT 0,
  `ig_followers` int(11) DEFAULT 0,
  `ig_target` int(11) DEFAULT 0,
  `ig_updated_at` datetime DEFAULT NULL,
  `yt_subscribers` int(11) DEFAULT 0,
  `yt_target` int(11) DEFAULT 0,
  `yt_videos` int(11) DEFAULT 0,
  `yt_views` bigint(20) DEFAULT 0,
  `google_review_count` int(11) DEFAULT 0,
  `google_review_target` int(11) DEFAULT 0,
  `google_rating` decimal(2,1) DEFAULT 0.0,
  `last_refreshed` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fb_posts_week` int(11) DEFAULT 0,
  `fb_posts_checked_at` datetime DEFAULT NULL,
  `ig_posts_week` int(11) DEFAULT 0,
  `ig_posts_checked_at` datetime DEFAULT NULL,
  `yt_videos_month` int(11) DEFAULT 0,
  `yt_videos_checked_at` datetime DEFAULT NULL,
  `yt_channel_id` varchar(50) DEFAULT NULL,
  `fb_engagement_avg` decimal(10,2) DEFAULT 0.00,
  `ig_engagement_avg` decimal(10,2) DEFAULT 0.00,
  `fb_page_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dealerships`
--

LOCK TABLES `dealerships` WRITE;
/*!40000 ALTER TABLE `dealerships` DISABLE KEYS */;
INSERT INTO `dealerships` VALUES (1,'SUZUKI UNITED MOTORS','https://www.facebook.com/suzukiunitedMotors','Suzuki United Motors','Suzuki United Motors Vehari','https://www.instagram.com/suzukiunitedmotors/',107000,18000,5137,1800,NULL,2320,1851,159,0,1527,2101,4.7,'2026-07-12 16:35:16','2026-07-10 09:44:44',6,'2026-07-11 21:26:31',7,'2026-07-11 21:26:38',0,'2026-07-10 21:28:07','UCWZBq2jihtnQmHXG_P6Gwkg',10.17,1.00,'100064372222141'),(2,'SUZUKI PIONEER MOTORS','https://www.facebook.com/suzukipioneer/','suzuki pioneer motors','Suzuki Pioneer Motors Multan','https://www.instagram.com/suzukipioneer/',15000,18000,1393,1800,NULL,1130,1851,75,31246,1433,2101,4.6,'2026-07-11 20:38:15','2026-07-10 11:55:30',6,'2026-07-11 21:26:52',6,'2026-07-11 21:26:57',2,'2026-07-10 18:05:19','UCxLtVZQZIpr_tE8Ljrmx4tg',273.33,1.00,'100063784461029'),(3,'SUZUKI SOUTH PUNJAB','https://www.facebook.com/suzukisouthpunjab','Suzuki South Punjab','Suzuki South Punjab Multan','https://www.instagram.com/suzukisouthpunjabssp/',21000,18000,3277,1800,NULL,1000,1851,104,69553,1341,2101,4.3,'2026-07-11 20:38:31','2026-07-10 13:27:37',6,'2026-07-11 21:27:11',9,'2026-07-11 21:27:16',0,NULL,'UCb_5XY57OH-GlNFBEVc-B9w',10.50,3.11,'100063863265127'),(4,'SUZUKI PAKPATTAN MOTORS','https://www.facebook.com/suzukipakpattan','Suzuki Pakpattan Motors','Suzuki Pakpattan Motors','https://www.instagram.com/suzukimotors_pakpattan/',18000,18000,3628,1800,NULL,1600,1851,75,162949,2034,2101,4.8,'2026-07-11 20:38:41','2026-07-10 13:31:50',6,'2026-07-11 21:27:34',10,'2026-07-11 21:27:40',0,NULL,'UC3OGH4FrSLNK_JcabzHvPbA',4.00,4.20,'100083064144263'),(5,'SUZUKI KHANEWAL MOTORS','https://www.facebook.com/profile.php?id=100063543177270','Suzuki Khanewal Motors','Suzuki Khanewal Motors','https://www.instagram.com/suzukikhanewal/',16000,18000,3096,1800,NULL,1300,1851,255,47571,1385,2101,4.8,'2026-07-11 20:38:55','2026-07-10 13:35:27',6,'2026-07-11 21:27:49',12,'2026-07-11 21:27:55',0,NULL,'UC6pjuxLBGweXwfzDjooBbrw',10.50,2.00,'100063543177270'),(6,'SUZUKI RAHIM YAR KHAN MOTORS','https://www.facebook.com/SuzukiRahimYarKhanMotors','Suzuki Rahim Yar Khan Motors','Suzuki Rahim Yar Khan Motors RAHIM YAR KHAN','https://www.instagram.com/suzukirykmotors/',48000,18000,1374,1800,NULL,1420,1851,151,104050,1337,2101,4.6,'2026-07-11 20:39:05','2026-07-10 13:38:58',6,'2026-07-11 21:28:08',11,'2026-07-11 21:28:16',0,NULL,'UCqZ_ORNkKf25sQCEvHj2xTg',20.50,2.27,'100064627333865'),(7,'SUZUKI SADIQABAD MOTORS','https://www.facebook.com/suzukisadiqabad','Suzuki Sadiqabad Motors','Suzuki Sadiqabad Motors SADIQABAD','https://www.instagram.com/suzukisadiqabad/',3300,18000,285,1800,NULL,2000,1851,85,110449,1246,2101,4.9,'2026-07-11 20:39:18','2026-07-10 13:44:07',6,'2026-07-11 21:28:30',9,'2026-07-11 21:28:36',0,NULL,'UC76Gv8nGWN6fKh9q7a6rgww',14.33,11.78,'61568789715850'),(8,'SUZUKI GATEWAY MOTORS','https://www.facebook.com/suzukigatewaymotors','Suzuki Gateway Motors','Suzuki Gateway Motors DG KHAN','https://www.instagram.com/suzukigatewaymotors/',1400,18000,104,1800,NULL,137,1851,3,75,185,2101,4.6,'2026-07-11 20:39:35','2026-07-10 13:47:12',6,'2026-07-11 21:28:45',6,'2026-07-11 21:28:50',0,NULL,'UCu9T9SFXcREOGYwlzfztZZw',6.83,1.33,'61582844234315'),(9,'SUZUKI SHORKOT MOTORS','https://www.facebook.com/suzukishorkot','Suzuki Shorkot Motors','Suzuki Shorkot Motors SHORKOT','https://www.instagram.com/suzukishorkot/',24000,18000,1768,1800,NULL,1200,1851,127,18190,1276,2101,4.8,'2026-07-11 20:39:45','2026-07-10 13:50:21',6,'2026-07-11 21:29:05',9,'2026-07-11 21:29:10',0,NULL,'UCMuTORxsKZCjAvTJarttPRQ',5.17,1.00,'100063836116004'),(10,'SUZUKI CHICHAWATNI MOTORS','https://www.facebook.com/suzukichichawatni','Suzuki Chichawatni Motors','Suzuki Chichawatni Motors CHICHAWATNI','https://www.instagram.com/suzukichichawatni/',54000,18000,3677,1800,NULL,1790,1851,138,197297,1339,2101,4.7,'2026-07-11 20:39:57','2026-07-10 13:54:33',6,'2026-07-11 21:29:20',12,'2026-07-11 21:29:25',0,NULL,'UCazgrNNA7KkAXvYNQyPQ02w',11.00,0.42,'100057387192155'),(11,'SUZUKI SAHIWAL MOTORS','https://www.facebook.com/Suzukisahiwalmotors','Suzuki Sahiwal Motors','Suzuki Sahiwal Motors SAHIWAL MOTORS','https://www.instagram.com/suzukisahiwalmotors/',15000,18000,1477,1800,NULL,1130,1851,110,97396,1086,2101,4.7,'2026-07-11 20:40:09','2026-07-10 13:58:29',6,'2026-07-11 21:29:38',5,'2026-07-11 21:29:43',0,NULL,'UCw7zIuSa7euHpcCzhXf5w2A',3.33,1.60,'100064109281999'),(12,'SUZUKI BAHAWALNAGAR MOTORS','https://www.facebook.com/Suzukibahawalnagar','Suzuki Bahawalnagar Motors','Suzuki Bahawalnagar Motors BAHAWALNAGAR','https://www.instagram.com/suzukibahawalnagarmotors/',17000,18000,2268,1800,NULL,1420,1851,146,24489,1331,2101,4.7,'2026-07-11 20:40:23','2026-07-10 14:02:28',8,'2026-07-12 19:57:12',7,'2026-07-12 19:57:18',0,NULL,'UCKj6aLiDHtDPrnZmgUfXmAA',32.13,0.71,'100063955213535'),(13,'SUZUKI BAHAWALPUR MOTORS','https://www.facebook.com/suzuki.bms','Suzuki Bahawalpur Motors','Suzuki Bahawalpur Motors BAHAWALPUR','https://www.instagram.com/suzukibahawalpur/',15000,18000,1518,1800,NULL,1410,1851,58,5223,1369,2101,4.4,'2026-07-11 20:40:36','2026-07-10 14:07:51',6,'2026-07-12 16:56:26',5,'2026-07-12 16:56:31',0,NULL,'UCkQQ4DtEZSRNJyTkLQDnFlw',153.67,8.60,'100064000421523'),(14,'SUZUKI DERAWAR MOTORS','https://www.facebook.com/Derawarmotor','Suzuki Derawar Motors','Suzuki Derawar Motors Ahmedpur East','https://www.instagram.com/suzukiderawarmotors/',15000,18000,1422,1800,NULL,1680,1851,20,2620,1351,2101,4.8,'2026-07-11 20:40:47','2026-07-10 14:10:35',6,'2026-07-11 21:30:34',6,'2026-07-11 21:30:39',0,NULL,'UCeKhpV_Qp4tE7m1PTJ2QWlA',11.83,0.33,'100064152274677'),(15,'SUZUKI FORT MOTORS','https://www.facebook.com/suzukifortmotors','Suzuki Fort Motors','Suzuki Fort Motors','https://www.instagram.com/suzukifortmotors/',56000,18000,2131,1800,NULL,1890,1851,175,130872,1497,2101,4.7,'2026-07-11 20:41:01','2026-07-10 14:14:03',12,'2026-07-14 17:19:38',8,'2026-07-14 17:20:53',0,NULL,'UC2E6jnvjJ1n7ReVkJsu653A',66.08,14.25,'100064617614874'),(16,'SUZUKI UNIQUE MOTORS','https://www.facebook.com/SuzukiUniqueMotorsMultan','Suzuki Unique Motors','Suzuki Unique Motors MULTAN','https://www.instagram.com/suzukiuniquemotors/',15000,18000,1902,1800,NULL,1090,1851,156,15862,1451,2101,4.7,'2026-07-11 20:41:17','2026-07-10 14:17:55',6,'2026-07-11 21:31:06',10,'2026-07-11 21:31:13',0,NULL,'UCJv2VyQhaPvHhxS9OJM4Efw',15.83,0.20,'100088144298128'),(17,'SUZUKI MULTAN CITY MOTORS','https://www.facebook.com/SuzukiMultanCityMotors','Suzuki Multan City Motors','Suzuki Multan City Motors MULTAN','https://www.instagram.com/suzukimultancitymotors/',18000,18000,2009,1800,NULL,2130,1851,51,8427,1484,2101,4.7,'2026-07-11 20:41:33','2026-07-10 14:21:00',6,'2026-07-11 21:31:23',9,'2026-07-11 21:31:27',0,NULL,'UCn2S44ufD_ocskcwXvuQD6Q',111.33,1.22,'61580219707138'),(18,'SUZUKI MIANCHANNU MOTORS','https://www.facebook.com/suzukimianchannumotors','Suzuki Mian Channu Motors','Suzuki Mian Channu Motors MIANCHANNUE','https://www.instagram.com/suzukimianchannumotors/',16000,18000,1985,1800,NULL,1170,1851,72,11526,1028,2101,4.8,'2026-07-11 20:41:49','2026-07-10 14:24:08',6,'2026-07-11 21:31:37',7,'2026-07-11 21:31:42',0,NULL,'UCrYc41DaGYT0-x8vdC91taw',2.17,0.00,'100063690348321'),(19,'SUZUKI MUZAFFARGARH MOTORS','https://www.facebook.com/suzukimuzaffargarh','Suzuki Muzaffargarh Motors','Suzuki Muzaffargarh Motors MUZAFFARGARH','https://www.instagram.com/suzukimuzaffargarhmotors/',0,18000,4484,1800,NULL,1180,1851,154,37868,1563,2101,4.8,'2026-07-11 20:41:59','2026-07-10 14:27:58',0,'2026-07-10 21:50:07',4,'2026-07-11 21:31:54',0,NULL,'UC_RZIuvqiXzgh-ap7Ec3A1w',0.00,1.75,NULL),(20,'SUZUKI RAJANPUR MOTORS','https://www.facebook.com/SuzukiRajanpur','Suzuki Rajanpur Motors','Suzuki Rajanpur Motors RAJANPUR','https://www.instagram.com/suzukirajanpurmotors/',15000,18000,10276,1800,NULL,1540,1851,47,3492,206,2101,4.3,'2026-07-11 20:42:18','2026-07-10 14:33:31',4,'2026-07-11 21:32:03',2,'2026-07-11 21:32:07',0,NULL,'UCJnZ7qVGJ0RE2jQEEIOvKlA',7.25,0.00,'61565640848819'),(21,'SUZUKI DEPALPUR MOTORS','https://www.facebook.com/suzukidepalpurmotors','SUZUKI DEPALPUR MOTOR','Suzuki Depalpur Motors','https://www.instagram.com/suzuki.depalpurmotors/',15000,18000,1563,1800,NULL,1370,1851,69,67958,1360,2101,4.9,'2026-07-11 20:42:35','2026-07-10 15:46:46',6,'2026-07-11 21:32:17',8,'2026-07-11 21:32:22',0,NULL,'UC9ltU0yWX1zvqL7nDdP--gw',2.67,2.25,'100085564661350');
/*!40000 ALTER TABLE `dealerships` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `post_log`
--

DROP TABLE IF EXISTS `post_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `post_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_post_id` varchar(50) DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `dealership_name` varchar(150) NOT NULL,
  `target_page_id` varchar(50) NOT NULL,
  `fb_post_id` varchar(100) DEFAULT NULL,
  `status` enum('success','failed') NOT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `posted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `post_log`
--

LOCK TABLES `post_log` WRITE;
/*!40000 ALTER TABLE `post_log` DISABLE KEYS */;
INSERT INTO `post_log` VALUES (1,'1351987407117309','https://www.facebook.com/reel/2489055948225132/','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','Rospdealerships','61591770663883',NULL,'failed','Error validating access token: Session has expired on Saturday, 11-Jul-26 06:00:00 PDT. The current time is Saturday, 11-Jul-26 10:00:18 PDT.','2026-07-11 17:00:18'),(2,'1351213943861322','https://www.facebook.com/SuzukiPakistan/posts/pfbid02C5xLNdhQ9ZyMKnhQU9gsRifY4Vm7Fw3YPgPVqr7rTZruNMPfGFKkA1dgA6bBNVU2l','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','Rospdealerships','61591770663883',NULL,'failed','(#200) This app is not allowed to publish to other users\' timelines.','2026-07-11 17:03:35'),(3,'1349888973993819','https://www.facebook.com/SuzukiPakistan/posts/pfbid0ei5Rt99uxjEDrkRMo9xWqB3Fb2mjZaaNCfqkrJpc8QaeqJdmS3SY31tPwCaLPmzxl','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','Rospdealerships','1254369481090130','1254369481090130_122100457731392355','success',NULL,'2026-07-11 17:57:31'),(4,'1349888973993819','https://www.facebook.com/SuzukiPakistan/posts/pfbid0ei5Rt99uxjEDrkRMo9xWqB3Fb2mjZaaNCfqkrJpc8QaeqJdmS3SY31tPwCaLPmzxl','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','Rospdealerships','1254369481090130','1254369481090130_122100464367392355','success',NULL,'2026-07-11 18:05:52'),(5,'1351987407117309','https://www.facebook.com/reel/2489055948225132/','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','Rospdealerships','1254369481090130','1054984160533389','success',NULL,'2026-07-11 18:27:12'),(6,'1351213943861322','https://www.facebook.com/SuzukiPakistan/posts/pfbid02C5xLNdhQ9ZyMKnhQU9gsRifY4Vm7Fw3YPgPVqr7rTZruNMPfGFKkA1dgA6bBNVU2l','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','Rospdealerships','1254369481090130','1254369481090130_122100483135392355','success',NULL,'2026-07-11 18:27:29'),(7,'1351213943861322','https://www.facebook.com/SuzukiPakistan/posts/pfbid02C5xLNdhQ9ZyMKnhQU9gsRifY4Vm7Fw3YPgPVqr7rTZruNMPfGFKkA1dgA6bBNVU2l','Check Suzuki Pakistan | Karachi\n\nAnother step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','Rospdealerships','1254369481090130','1254369481090130_122100514845392355','success',NULL,'2026-07-11 19:19:56'),(8,'1349888973993819','https://www.facebook.com/SuzukiPakistan/posts/pfbid02iZX94p5jp3qttJBiouXSDYpkVecCq7L6wc9Y39a2PMS7nwxvNcvsx7aG4rYjsEE2l','Check Suzuki Pakistan | Karachi\n\nEvery extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','CH SWEET & BAKER','100063808013503',NULL,'failed','(#100) The global id 100063808013503 is not allowed for this call','2026-07-11 20:20:03'),(9,'1351213943861322','https://www.facebook.com/SuzukiPakistan/posts/pfbid02C5xLNdhQ9ZyMKnhQU9gsRifY4Vm7Fw3YPgPVqr7rTZruNMPfGFKkA1dgA6bBNVU2l','Check Suzuki Pakistan | Karachi\n\nAnother step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','CH SWEET & BAKER','114347427357774','114347427357774_1570541701749415','success',NULL,'2026-07-11 20:28:39'),(10,'1351213943861322','https://www.facebook.com/SuzukiPakistan/posts/pfbid02C5xLNdhQ9ZyMKnhQU9gsRifY4Vm7Fw3YPgPVqr7rTZruNMPfGFKkA1dgA6bBNVU2l','Check Suzuki Pakistan | Karachi\n\nAnother step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','Rospdealerships','1254369481090130','1254369481090130_122100633171392355','success',NULL,'2026-07-11 23:17:32'),(11,'1351213943861322','https://www.facebook.com/SuzukiPakistan/posts/pfbid02C5xLNdhQ9ZyMKnhQU9gsRifY4Vm7Fw3YPgPVqr7rTZruNMPfGFKkA1dgA6bBNVU2l','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','Zapier (connected pages)','zapier',NULL,'success',NULL,'2026-07-11 23:17:34'),(12,'1351987407117309','https://www.facebook.com/reel/2489055948225132/','Check Suzuki Pakistan | Karachi\n\nExchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','Rospdealerships','1254369481090130','1003758198931747','success',NULL,'2026-07-12 08:36:58'),(13,'1351987407117309','https://www.facebook.com/reel/2489055948225132/','Source: Shared By Pak Suzuki Official Page\n\nExchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','Zapier (connected pages)','zapier',NULL,'success',NULL,'2026-07-12 08:37:01'),(14,'1351987407117309','https://www.facebook.com/reel/2489055948225132/','Check Suzuki Pakistan | Karachi\n\nExchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','Rospdealerships','1254369481090130','1020202337473070','success',NULL,'2026-07-12 09:39:42'),(15,'1351987407117309','https://www.facebook.com/reel/2489055948225132/','Source: Shared By Pak Suzuki Official Page\n\nExchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','Zapier (connected pages)','zapier',NULL,'success',NULL,'2026-07-12 09:39:43'),(16,'1351987407117309','https://www.facebook.com/reel/2489055948225132/','Check Suzuki Pakistan | Karachi\n\nExchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','Rospdealerships','1254369481090130','1582333763325816','success',NULL,'2026-07-12 13:21:21'),(17,'1351987407117309','https://www.facebook.com/reel/2489055948225132/','Source: Shared By Pak Suzuki Official Page\n\nExchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','Zapier (connected pages)','zapier',NULL,'success',NULL,'2026-07-12 13:21:22');
/*!40000 ALTER TABLE `post_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `post_submissions`
--

DROP TABLE IF EXISTS `post_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `post_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dealership_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `caption` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reasons` text DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `dealership_id` (`dealership_id`),
  CONSTRAINT `post_submissions_ibfk_1` FOREIGN KEY (`dealership_id`) REFERENCES `dealerships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `post_submissions`
--

LOCK TABLES `post_submissions` WRITE;
/*!40000 ALTER TABLE `post_submissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `post_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `processed_source_posts`
--

DROP TABLE IF EXISTS `processed_source_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `processed_source_posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_post_id` varchar(50) NOT NULL,
  `message_snippet` varchar(255) DEFAULT NULL,
  `processed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_post_id` (`source_post_id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `processed_source_posts`
--

LOCK TABLES `processed_source_posts` WRITE;
/*!40000 ALTER TABLE `processed_source_posts` DISABLE KEYS */;
INSERT INTO `processed_source_posts` VALUES (8,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx','2026-07-11 20:20:04','2026-07-07 20:45:26'),(10,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad','2026-07-11 23:17:34','2026-07-09 11:36:56'),(13,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange','2026-07-12 13:21:22','2026-07-10 10:52:29'),(17,'1355137433468973','Designed to stand out and built to move forward, the Suzuki Fronx redefines every journey with style and confidence.\n\n#SuzukiPakistan #SuzukiFronx','2026-07-14 11:13:59','2026-07-14 11:32:48'),(27,'1346265334356183','Another step closer to you.\n\nSuzuki Service is now available in Chiniot through Suzuki Falcon Motors.\n\n#SuzukiPakistan #SuzukiMotors #Chiniot','2026-07-14 16:49:13','2026-07-03 17:32:06'),(28,'1344541977861852','When confidence meets bold design, every drive becomes unforgettable. Make every road your own with the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx #YourNextMove','2026-07-14 16:49:13','2026-07-01 16:55:19'),(29,'1344426797873370','Important announcement for our valued customers.\n\n#Suzuki #SuzukiPakistan','2026-07-14 16:49:13','2026-07-01 13:46:57'),(30,'1338743958441654','Important announcement for our valued customers.\n\n#Suzuki #SuzukiPakistan','2026-07-14 16:49:13','2026-06-24 19:21:16'),(31,'1338503585132358','For those who believe every drive is an opportunity to make an impression.\n\n#SuzukiPakistan #SuzukiFronx #YourNextMove','2026-07-14 16:49:13','2026-06-24 12:48:14'),(32,'1337849638531086','Experience the elevation firsthand.\n\nVisit your nearest Suzuki dealership and take the Suzuki Fronx for a test drive today.\n\n#SuzukiPakistan #SuzukiFronx #TestDrive','2026-07-14 16:49:13','2026-06-23 17:04:25'),(33,'1334450242204359','To the one who has always stood beside us through every milestone, every challenge, and every achievement.\n\nHappy Father’s Day.\n\n#SuzukiPakistan #FathersDay','2026-07-14 16:49:13','2026-06-21 00:00:31'),(34,'1333786008937449','Every move says something about you. Make yours extraordinary with the bold design, dynamic performance, and confidence of the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx #YourNextMove','2026-07-14 16:49:13','2026-06-18 19:07:01'),(35,'1332809519035098','Confidence comes naturally when everything you need is right where it should be. Experience the comfort, convenience, and control of the Suzuki Fronx.\n\n#Suzuki #SuzukiPakistan #SuzukiFronx','2026-07-14 16:49:13','2026-06-17 14:56:30');
/*!40000 ALTER TABLE `processed_source_posts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reshare_checks`
--

DROP TABLE IF EXISTS `reshare_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reshare_checks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dealership_id` int(11) NOT NULL,
  `source_post_id` varchar(50) NOT NULL,
  `message_snippet` varchar(255) DEFAULT NULL,
  `first_seen_at` datetime NOT NULL,
  `reshared` tinyint(1) NOT NULL DEFAULT 0,
  `reshared_detected_at` datetime DEFAULT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dealership_post` (`dealership_id`,`source_post_id`),
  CONSTRAINT `reshare_checks_ibfk_1` FOREIGN KEY (`dealership_id`) REFERENCES `dealerships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=91 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reshare_checks`
--

LOCK TABLES `reshare_checks` WRITE;
/*!40000 ALTER TABLE `reshare_checks` DISABLE KEYS */;
INSERT INTO `reshare_checks` VALUES (1,1,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:17:46',1,'2026-07-14 00:17:46','2026-07-14 22:48:08','2026-07-10 10:52:29'),(2,1,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:17:46',1,'2026-07-14 22:30:11','2026-07-14 22:48:08','2026-07-09 11:36:56'),(3,1,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:17:46',1,'2026-07-14 22:30:10','2026-07-14 22:48:08','2026-07-07 20:45:26'),(4,2,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:17:51',0,NULL,'2026-07-14 00:17:51',NULL),(5,2,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:17:51',0,NULL,'2026-07-14 00:17:51',NULL),(6,2,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:17:51',0,NULL,'2026-07-14 00:17:51',NULL),(7,3,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:17:58',1,'2026-07-14 00:17:58','2026-07-14 00:17:58',NULL),(8,3,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:17:58',0,NULL,'2026-07-14 00:17:58',NULL),(9,3,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:17:58',0,NULL,'2026-07-14 00:17:58',NULL),(10,4,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:18:04',0,NULL,'2026-07-14 00:18:04',NULL),(11,4,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:18:04',0,NULL,'2026-07-14 00:18:04',NULL),(12,4,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:18:04',0,NULL,'2026-07-14 00:18:04',NULL),(13,5,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:18:11',0,NULL,'2026-07-14 00:18:11',NULL),(14,5,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:18:11',0,NULL,'2026-07-14 00:18:11',NULL),(15,5,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:18:11',0,NULL,'2026-07-14 00:18:11',NULL),(16,6,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:18:15',0,NULL,'2026-07-14 00:18:15',NULL),(17,6,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:18:15',0,NULL,'2026-07-14 00:18:15',NULL),(18,6,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:18:15',0,NULL,'2026-07-14 00:18:15',NULL),(19,7,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:18:19',0,NULL,'2026-07-14 00:18:19',NULL),(20,7,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:18:19',0,NULL,'2026-07-14 00:18:19',NULL),(21,7,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:18:19',0,NULL,'2026-07-14 00:18:19',NULL),(22,8,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:18:24',0,NULL,'2026-07-14 00:18:24',NULL),(23,8,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:18:24',0,NULL,'2026-07-14 00:18:24',NULL),(24,8,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:18:24',0,NULL,'2026-07-14 00:18:24',NULL),(25,9,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:18:31',0,NULL,'2026-07-14 00:18:31',NULL),(26,9,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:18:31',0,NULL,'2026-07-14 00:18:31',NULL),(27,9,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:18:31',0,NULL,'2026-07-14 00:18:31',NULL),(31,11,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:19:01',0,NULL,'2026-07-14 00:19:01',NULL),(32,11,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:19:01',0,NULL,'2026-07-14 00:19:01',NULL),(33,11,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:19:01',0,NULL,'2026-07-14 00:19:01',NULL),(34,12,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:19:06',0,NULL,'2026-07-14 09:12:17',NULL),(35,12,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:19:06',0,NULL,'2026-07-14 09:12:17',NULL),(36,12,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:19:06',0,NULL,'2026-07-14 09:12:16',NULL),(37,13,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:19:22',1,'2026-07-14 00:19:22','2026-07-14 16:14:13',NULL),(38,13,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:19:22',1,'2026-07-14 00:19:22','2026-07-14 16:14:13',NULL),(39,13,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:19:22',0,NULL,'2026-07-14 16:14:13',NULL),(40,15,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange\"','2026-07-14 00:19:58',0,NULL,'2026-07-14 17:05:38',NULL),(41,15,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad\"','2026-07-14 00:19:58',0,NULL,'2026-07-14 17:05:38',NULL),(42,15,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx\"','2026-07-14 00:19:58',0,NULL,'2026-07-14 17:05:38',NULL),(43,13,'1355137433468973','Designed to stand out and built to move forward, the Suzuki Fronx redefines every journey with style and confidence.\n\n#SuzukiPakistan #SuzukiFronx','2026-07-14 16:14:13',0,NULL,'2026-07-14 16:14:13',NULL),(45,15,'1355137433468973','Designed to stand out and built to move forward, the Suzuki Fronx redefines every journey with style and confidence.\n\n#SuzukiPakistan #SuzukiFronx','2026-07-14 17:04:06',0,NULL,'2026-07-14 17:05:38',NULL),(68,10,'1349888973993819','Every extraordinary journey starts with one move.\nMake yours in the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx','2026-07-14 22:07:55',1,'2026-07-14 22:07:55','2026-07-14 22:07:55','2026-07-07 20:45:26'),(69,10,'1351213943861322','Another step closer to you.\n\nSuzuki Service is now available in Usta Muhammad through Suzuki Jacobabad Motors.\n\n#SuzukiPakistan #SuzukiMotors #UstaMuhammad','2026-07-14 22:07:55',1,'2026-07-14 22:07:55','2026-07-14 22:07:55','2026-07-09 11:36:56'),(70,10,'1351987407117309','Exchange your old Suzuki in just a few easy steps. With a quick, convenient, and secure process, upgrading to a new Suzuki has never been simpler.\n\nVisit your nearest authorized Suzuki dealership to get started.\n\n#SuzukiPakistan #SuzukiExchange','2026-07-14 22:07:55',1,'2026-07-14 22:07:55','2026-07-14 22:07:55','2026-07-10 10:52:29'),(71,10,'1355137433468973','Designed to stand out and built to move forward, the Suzuki Fronx redefines every journey with style and confidence.\n\n#SuzukiPakistan #SuzukiFronx','2026-07-14 22:07:55',1,'2026-07-14 22:07:55','2026-07-14 22:07:55','2026-07-14 11:32:48'),(72,10,'1346265334356183','Another step closer to you.\n\nSuzuki Service is now available in Chiniot through Suzuki Falcon Motors.\n\n#SuzukiPakistan #SuzukiMotors #Chiniot','2026-07-14 22:07:55',1,'2026-07-14 22:07:55','2026-07-14 22:07:55','2026-07-03 17:32:06'),(73,10,'1344541977861852','When confidence meets bold design, every drive becomes unforgettable. Make every road your own with the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx #YourNextMove','2026-07-14 22:07:55',1,'2026-07-14 22:07:55','2026-07-14 22:07:55','2026-07-01 16:55:19'),(74,10,'1344426797873370','Important announcement for our valued customers.\n\n#Suzuki #SuzukiPakistan','2026-07-14 22:07:55',1,'2026-07-14 22:07:55','2026-07-14 22:07:55','2026-07-01 13:46:57'),(75,10,'1338743958441654','Important announcement for our valued customers.\n\n#Suzuki #SuzukiPakistan','2026-07-14 22:07:55',0,NULL,'2026-07-14 22:07:55','2026-06-24 19:21:16'),(76,10,'1338503585132358','For those who believe every drive is an opportunity to make an impression.\n\n#SuzukiPakistan #SuzukiFronx #YourNextMove','2026-07-14 22:07:55',0,NULL,'2026-07-14 22:07:55','2026-06-24 12:48:14'),(77,10,'1337849638531086','Experience the elevation firsthand.\n\nVisit your nearest Suzuki dealership and take the Suzuki Fronx for a test drive today.\n\n#SuzukiPakistan #SuzukiFronx #TestDrive','2026-07-14 22:07:55',0,NULL,'2026-07-14 22:07:55','2026-06-23 17:04:25'),(78,10,'1334450242204359','To the one who has always stood beside us through every milestone, every challenge, and every achievement.\n\nHappy Father’s Day.\n\n#SuzukiPakistan #FathersDay','2026-07-14 22:07:55',0,NULL,'2026-07-14 22:07:55','2026-06-21 00:00:31'),(79,10,'1333786008937449','Every move says something about you. Make yours extraordinary with the bold design, dynamic performance, and confidence of the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx #YourNextMove','2026-07-14 22:07:55',0,NULL,'2026-07-14 22:07:55','2026-06-18 19:07:01'),(80,10,'1332809519035098','Confidence comes naturally when everything you need is right where it should be. Experience the comfort, convenience, and control of the Suzuki Fronx.\n\n#Suzuki #SuzukiPakistan #SuzukiFronx','2026-07-14 22:07:55',0,NULL,'2026-07-14 22:07:55','2026-06-17 14:56:30'),(81,1,'1355137433468973','Designed to stand out and built to move forward, the Suzuki Fronx redefines every journey with style and confidence.\n\n#SuzukiPakistan #SuzukiFronx','2026-07-14 22:30:11',1,'2026-07-14 22:30:11','2026-07-14 22:48:08','2026-07-14 11:32:48'),(82,1,'1346265334356183','Another step closer to you.\n\nSuzuki Service is now available in Chiniot through Suzuki Falcon Motors.\n\n#SuzukiPakistan #SuzukiMotors #Chiniot','2026-07-14 22:30:11',0,NULL,'2026-07-14 22:30:11','2026-07-03 17:32:06'),(83,1,'1344541977861852','When confidence meets bold design, every drive becomes unforgettable. Make every road your own with the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx #YourNextMove','2026-07-14 22:30:11',1,'2026-07-14 22:30:11','2026-07-14 22:30:11','2026-07-01 16:55:19'),(84,1,'1344426797873370','Important announcement for our valued customers.\n\n#Suzuki #SuzukiPakistan','2026-07-14 22:30:11',1,'2026-07-14 22:30:11','2026-07-14 22:30:11','2026-07-01 13:46:57'),(85,1,'1338743958441654','Important announcement for our valued customers.\n\n#Suzuki #SuzukiPakistan','2026-07-14 22:30:11',1,'2026-07-14 22:30:11','2026-07-14 22:30:11','2026-06-24 19:21:16'),(86,1,'1338503585132358','For those who believe every drive is an opportunity to make an impression.\n\n#SuzukiPakistan #SuzukiFronx #YourNextMove','2026-07-14 22:30:11',1,'2026-07-14 22:30:11','2026-07-14 22:30:11','2026-06-24 12:48:14'),(87,1,'1337849638531086','Experience the elevation firsthand.\n\nVisit your nearest Suzuki dealership and take the Suzuki Fronx for a test drive today.\n\n#SuzukiPakistan #SuzukiFronx #TestDrive','2026-07-14 22:30:11',1,'2026-07-14 22:30:11','2026-07-14 22:30:11','2026-06-23 17:04:25'),(88,1,'1334450242204359','To the one who has always stood beside us through every milestone, every challenge, and every achievement.\n\nHappy Father’s Day.\n\n#SuzukiPakistan #FathersDay','2026-07-14 22:30:11',0,NULL,'2026-07-14 22:30:11','2026-06-21 00:00:31'),(89,1,'1333786008937449','Every move says something about you. Make yours extraordinary with the bold design, dynamic performance, and confidence of the Suzuki Fronx.\n\n#SuzukiPakistan #SuzukiFronx #YourNextMove','2026-07-14 22:30:11',0,NULL,'2026-07-14 22:30:11','2026-06-18 19:07:01'),(90,1,'1332809519035098','Confidence comes naturally when everything you need is right where it should be. Experience the comfort, convenience, and control of the Suzuki Fronx.\n\n#Suzuki #SuzukiPakistan #SuzukiFronx','2026-07-14 22:30:11',0,NULL,'2026-07-14 22:30:11','2026-06-17 14:56:30');
/*!40000 ALTER TABLE `reshare_checks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reshare_own_post_stats`
--

DROP TABLE IF EXISTS `reshare_own_post_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reshare_own_post_stats` (
  `dealership_id` int(11) NOT NULL,
  `range_from` date NOT NULL,
  `range_to` date NOT NULL,
  `own_post_count` int(11) NOT NULL DEFAULT 0,
  `reshare_post_count` int(11) NOT NULL DEFAULT 0,
  `checked_at` datetime NOT NULL,
  PRIMARY KEY (`dealership_id`),
  CONSTRAINT `reshare_own_post_stats_ibfk_1` FOREIGN KEY (`dealership_id`) REFERENCES `dealerships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reshare_own_post_stats`
--

LOCK TABLES `reshare_own_post_stats` WRITE;
/*!40000 ALTER TABLE `reshare_own_post_stats` DISABLE KEYS */;
INSERT INTO `reshare_own_post_stats` VALUES (1,'2026-07-06','2026-07-14',7,6,'2026-07-14 22:48:08'),(10,'2026-06-14','2026-07-14',17,13,'2026-07-14 22:07:55');
/*!40000 ALTER TABLE `reshare_own_post_stats` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `target_pages`
--

DROP TABLE IF EXISTS `target_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `target_pages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `page_id` varchar(50) NOT NULL,
  `page_access_token` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `dealership_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `target_pages`
--

LOCK TABLES `target_pages` WRITE;
/*!40000 ALTER TABLE `target_pages` DISABLE KEYS */;
INSERT INTO `target_pages` VALUES (5,'Rospdealerships','1254369481090130','EAAOkXMltQTcBR09vAa3Djq0UZBWEBAKIwqNLW6teZBLkTALWTZC1QSDdLi3ZAbbWhamUffDDkS4w0qAcuEOCeubh6hfaOo4n1gv0Bq5Bum24YVy5O1SxBEbUpn2OH3tUBppqKL98WwxyDjrZA4IryZAV364J0LkJnbupplfDwr82UN5p8k4upMFMaoSPcrffMFNaoz',1,NULL,'2026-07-11 17:03:01'),(6,'CH SWEET & BAKER','114347427357774','EAAWOYJhnZBI8BRZChggSxfCyl3NdLdkkxQLkyqYE6mhroRvWRpnKvQL0n6hqZAL1m0L0ffxMGy6vjZCZBvaMJIp773PkdPrOyTuoTKXOldXuWZBbAajPqCD6d8SZBDZCbeWb11XcUNthIDrau5yUbpbnRM9nd4PMU1BkYOM2FKyVtR0TdAwcU0FmOAFjkOCSYFh23ZBTZCT0BZB26jO5g3GUuYVpyoYa2lucsmmlRwAqCEZBLAZDZD',0,NULL,'2026-07-11 20:19:33');
/*!40000 ALTER TABLE `target_pages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_dealerships`
--

DROP TABLE IF EXISTS `user_dealerships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_dealerships` (
  `user_id` int(11) NOT NULL,
  `dealership_id` int(11) NOT NULL,
  PRIMARY KEY (`user_id`,`dealership_id`),
  KEY `dealership_id` (`dealership_id`),
  CONSTRAINT `user_dealerships_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_dealerships_ibfk_2` FOREIGN KEY (`dealership_id`) REFERENCES `dealerships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_dealerships`
--

LOCK TABLES `user_dealerships` WRITE;
/*!40000 ALTER TABLE `user_dealerships` DISABLE KEYS */;
INSERT INTO `user_dealerships` VALUES (6,1),(6,2),(6,3),(6,4),(6,5),(6,6),(6,7),(6,8),(6,9),(6,10),(6,11),(6,12),(6,13),(6,14),(6,15),(6,16),(6,17),(6,18),(6,19),(6,20),(6,21);
/*!40000 ALTER TABLE `user_dealerships` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  `can_refresh` tinyint(1) NOT NULL DEFAULT 1,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','$2y$10$QC9MHcUAw67y/AZAruvaROSms9Sm/EUpDzaobF6LH2qvhIv/kQgXC','2026-07-10 12:27:00',1,1,0,0),(6,'Abaidullah','$2y$10$391l7Bxh8dFk32LncnFCC.kTzfiszNxhprTvSRVLIE27GRH.6PA2a','2026-07-13 21:27:54',0,0,0,0);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vehicle_models`
--

DROP TABLE IF EXISTS `vehicle_models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicle_models` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `color` varchar(50) NOT NULL,
  `reference_image` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vehicle_models`
--

LOCK TABLES `vehicle_models` WRITE;
/*!40000 ALTER TABLE `vehicle_models` DISABLE KEYS */;
/*!40000 ALTER TABLE `vehicle_models` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `yt_monthly_stats`
--

DROP TABLE IF EXISTS `yt_monthly_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `yt_monthly_stats` (
  `dealership_id` int(11) NOT NULL,
  `month` char(7) NOT NULL,
  `video_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`dealership_id`,`month`),
  CONSTRAINT `yt_monthly_stats_ibfk_1` FOREIGN KEY (`dealership_id`) REFERENCES `dealerships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `yt_monthly_stats`
--

LOCK TABLES `yt_monthly_stats` WRITE;
/*!40000 ALTER TABLE `yt_monthly_stats` DISABLE KEYS */;
INSERT INTO `yt_monthly_stats` VALUES (1,'2026-01',2),(1,'2026-02',3),(1,'2026-03',3),(1,'2026-04',5),(1,'2026-05',6),(1,'2026-06',2),(1,'2026-07',0),(2,'2026-01',3),(2,'2026-02',4),(2,'2026-03',4),(2,'2026-04',3),(2,'2026-05',7),(2,'2026-06',3),(2,'2026-07',0),(3,'2026-01',2),(3,'2026-02',4),(3,'2026-03',2),(3,'2026-04',3),(3,'2026-05',6),(3,'2026-06',7),(3,'2026-07',2),(4,'2026-01',2),(4,'2026-02',2),(4,'2026-03',2),(4,'2026-04',2),(4,'2026-05',12),(4,'2026-06',3),(4,'2026-07',0),(5,'2026-01',5),(5,'2026-02',7),(5,'2026-03',5),(5,'2026-04',7),(5,'2026-05',12),(5,'2026-06',13),(5,'2026-07',5),(6,'2026-01',7),(6,'2026-02',5),(6,'2026-03',5),(6,'2026-04',4),(6,'2026-05',8),(6,'2026-06',3),(6,'2026-07',0),(7,'2026-01',3),(7,'2026-02',3),(7,'2026-03',2),(7,'2026-04',3),(7,'2026-05',4),(7,'2026-06',3),(7,'2026-07',0),(8,'2026-01',0),(8,'2026-02',0),(8,'2026-03',1),(8,'2026-04',0),(8,'2026-05',2),(8,'2026-06',0),(8,'2026-07',0),(9,'2026-01',3),(9,'2026-02',3),(9,'2026-03',5),(9,'2026-04',2),(9,'2026-05',4),(9,'2026-06',10),(9,'2026-07',2),(10,'2026-01',2),(10,'2026-02',3),(10,'2026-03',9),(10,'2026-04',10),(10,'2026-05',14),(10,'2026-06',11),(10,'2026-07',6),(11,'2026-01',2),(11,'2026-02',2),(11,'2026-03',3),(11,'2026-04',2),(11,'2026-05',1),(11,'2026-06',3),(11,'2026-07',0),(12,'2026-01',5),(12,'2026-02',2),(12,'2026-03',3),(12,'2026-04',2),(12,'2026-05',4),(12,'2026-06',2),(12,'2026-07',1),(13,'2026-01',2),(13,'2026-02',2),(13,'2026-03',2),(13,'2026-04',2),(13,'2026-05',3),(13,'2026-06',2),(13,'2026-07',0),(14,'2026-01',3),(14,'2026-02',2),(14,'2026-03',2),(14,'2026-04',2),(14,'2026-05',2),(14,'2026-06',2),(14,'2026-07',1),(15,'2026-01',9),(15,'2026-02',3),(15,'2026-03',6),(15,'2026-04',7),(15,'2026-05',12),(15,'2026-06',7),(15,'2026-07',3),(16,'2026-01',4),(16,'2026-02',4),(16,'2026-03',4),(16,'2026-04',5),(16,'2026-05',6),(16,'2026-06',6),(16,'2026-07',1),(17,'2026-01',5),(17,'2026-02',4),(17,'2026-03',4),(17,'2026-04',5),(17,'2026-05',6),(17,'2026-06',12),(17,'2026-07',3),(18,'2026-01',2),(18,'2026-02',2),(18,'2026-03',2),(18,'2026-04',2),(18,'2026-05',3),(18,'2026-06',8),(18,'2026-07',0),(19,'2026-01',4),(19,'2026-02',2),(19,'2026-03',2),(19,'2026-04',2),(19,'2026-05',3),(19,'2026-06',2),(19,'2026-07',4),(20,'2026-01',0),(20,'2026-02',0),(20,'2026-03',0),(20,'2026-04',4),(20,'2026-05',3),(20,'2026-06',2),(20,'2026-07',0),(21,'2026-01',2),(21,'2026-02',3),(21,'2026-03',3),(21,'2026-04',4),(21,'2026-05',5),(21,'2026-06',8),(21,'2026-07',4);
/*!40000 ALTER TABLE `yt_monthly_stats` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'dealership_dashboard'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-15 18:40:23
