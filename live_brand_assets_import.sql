-- Brand Assets sync: brand_identity + vehicle_models + vehicle_model_images.
-- Deliberately EXCLUDES post_submissions (12 rows, all dealership_id=12,
-- clearly local feature-testing data, not real dealer content).
--
-- Replaces live's existing single "Swift" vehicle model with the complete
-- local set (5 models, full reference photo sets) per your choice.

INSERT INTO brand_identity (id, logo_light_path, logo_dark_path, logo_white_bg_path, tagline, primary_color, secondary_color, website_url)
VALUES (1, 'assets/uploads/logos/logos_6a63b02c7ba55.png', 'assets/uploads/logos/logos_6a63b04323793.png', 'assets/uploads/logos/logos_6a63aa1f70551.jpeg', NULL, NULL, NULL, NULL)
ON DUPLICATE KEY UPDATE
  logo_light_path = VALUES(logo_light_path),
  logo_dark_path = VALUES(logo_dark_path),
  logo_white_bg_path = VALUES(logo_white_bg_path);

DELETE FROM vehicle_models;

INSERT INTO vehicle_models (id, name, color, reference_image) VALUES
(5, 'Suzuki Alto', 'White, Silky Silver, Minral Grey, Black', 'assets/uploads/vehicles/vehicles_6a63a4e2d1637.jpg'),
(7, 'Suzuki Fronx', 'Solid White, Solid White with Black Roof, Ice Grey', 'assets/uploads/vehicles/vehicles_6a63a7352cea7.jpg'),
(8, 'Suzuki Swift', 'White, Silky Silver, Minral Grey, Phoenix Red', 'assets/uploads/vehicles/vehicles_6a63a78c71420.jpg'),
(9, 'Suzuki Every', 'White, Silky Silver, Minral Grey , Black', 'assets/uploads/vehicles/vehicles_6a63a7db3dc87.jpg'),
(10, 'Suzuki Cultus', 'White, Silky Silver, Minral Grey , Black', 'assets/uploads/vehicles/vehicles_6a63a83c6b15d.jpg');

INSERT INTO vehicle_model_images (id, vehicle_model_id, image_path) VALUES
(13, 5, 'assets/uploads/vehicles/vehicles_6a63a4e2d1637.jpg'),
(14, 5, 'assets/uploads/vehicles/vehicles_6a63a4e2e75d3.jpg'),
(15, 5, 'assets/uploads/vehicles/vehicles_6a63a4e368f16.jpg'),
(16, 5, 'assets/uploads/vehicles/vehicles_6a63a4e3dc2ce.png'),
(32, 7, 'assets/uploads/vehicles/vehicles_6a63a7352cea7.jpg'),
(33, 7, 'assets/uploads/vehicles/vehicles_6a63a735be988.jpg'),
(34, 7, 'assets/uploads/vehicles/vehicles_6a63a73659321.jpg'),
(35, 7, 'assets/uploads/vehicles/vehicles_6a63a736d2255.jpg'),
(36, 7, 'assets/uploads/vehicles/vehicles_6a63a7377eb5b.jpg'),
(37, 8, 'assets/uploads/vehicles/vehicles_6a63a78c71420.jpg'),
(38, 8, 'assets/uploads/vehicles/vehicles_6a63a78d120f4.jpg'),
(39, 8, 'assets/uploads/vehicles/vehicles_6a63a78d1ea0e.png'),
(40, 8, 'assets/uploads/vehicles/vehicles_6a63a78f1ef13.png'),
(41, 8, 'assets/uploads/vehicles/vehicles_6a63a790cdd84.png'),
(42, 8, 'assets/uploads/vehicles/vehicles_6a63a793e4e2e.png'),
(43, 9, 'assets/uploads/vehicles/vehicles_6a63a7db3dc87.jpg'),
(44, 9, 'assets/uploads/vehicles/vehicles_6a63a7db4a2e6.jpg'),
(45, 9, 'assets/uploads/vehicles/vehicles_6a63a7db5722d.png'),
(46, 10, 'assets/uploads/vehicles/vehicles_6a63a83c6b15d.jpg'),
(47, 10, 'assets/uploads/vehicles/vehicles_6a63a83c77e9a.jpg'),
(48, 10, 'assets/uploads/vehicles/vehicles_6a63a83e8df65.jpg'),
(49, 10, 'assets/uploads/vehicles/vehicles_6a63a84088958.jpg');
