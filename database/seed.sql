-- 仅供本地演示；在全新 schema.sql 导入后执行一次。
-- 示例账号的密码哈希是不可登录占位符；用注册功能创建可登录账号。
SET NAMES utf8mb4;
INSERT INTO users (user_id, username, email, password_hash) VALUES
(1, 'demo_seller', 'seller@example.test', '!demo-no-login'),
(2, 'demo_buyer', 'buyer@example.test', '!demo-no-login');
INSERT INTO categories (category_id, category_name) VALUES (1,'卡片'),(2,'手办'),(3,'周边');
INSERT INTO items (item_id,seller_id,category_id,title,description,item_condition) VALUES
(1,1,1,'示例收藏卡','用于测试搜索和竞拍的示例商品。','近全新'),
(2,1,2,'示例手办','用于测试拍卖结果的示例商品。','二手');
INSERT INTO auctions (auction_id,item_id,starting_price,reserve_price,minimum_increment,start_time,end_time,status) VALUES
(1,1,10.00,20.00,2.00,DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY),'active'),
(2,2,20.00,NULL,5.00,DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY),DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY),'completed');
INSERT INTO bids (bid_id,auction_id,bidder_id,amount,placed_at) VALUES
(1,1,2,12.00,UTC_TIMESTAMP()),
(2,2,2,25.00,DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY));
INSERT INTO auction_results (auction_id,winning_bid_id,decided_at) VALUES (2,2,DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY));
INSERT INTO watches (user_id,auction_id) VALUES (2,1);
INSERT INTO user_preferences (user_id,category_id) VALUES (2,1);
INSERT INTO comments (auction_id,user_id,content) VALUES (1,2,'这是一条用于测试的评论。');
