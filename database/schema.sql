-- 收藏品拍卖系统 v1：导入前在 phpMyAdmin 选中一个全新的 auction_db 数据库。
-- 本文件不删除现有数据；如果表已存在，导入会报错，请使用新的空数据库。
SET NAMES utf8mb4;
CREATE TABLE users (
 user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(50) NOT NULL UNIQUE,
 email VARCHAR(255) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE categories (
 category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 category_name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE items (
 item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 seller_id INT UNSIGNED NOT NULL,
 category_id INT UNSIGNED NOT NULL,
 title VARCHAR(200) NOT NULL,
 description TEXT NOT NULL,
 item_condition VARCHAR(100) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (seller_id) REFERENCES users(user_id),
 FOREIGN KEY (category_id) REFERENCES categories(category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE item_images (
 image_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 item_id INT UNSIGNED NOT NULL,
 image_path VARCHAR(500) NOT NULL,
 display_order INT UNSIGNED NOT NULL DEFAULT 0,
 FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE auctions (
 auction_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 item_id INT UNSIGNED NOT NULL,
 starting_price DECIMAL(10,2) NOT NULL,
 reserve_price DECIMAL(10,2) NULL,
 minimum_increment DECIMAL(10,2) NOT NULL,
 start_time DATETIME NOT NULL,
 end_time DATETIME NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (item_id) REFERENCES items(item_id),
 INDEX idx_auction_status_end (status, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE bids (
 bid_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 auction_id INT UNSIGNED NOT NULL,
 bidder_id INT UNSIGNED NOT NULL,
 amount DECIMAL(10,2) NOT NULL,
 placed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (auction_id) REFERENCES auctions(auction_id),
 FOREIGN KEY (bidder_id) REFERENCES users(user_id),
 UNIQUE KEY uq_bid_auction (bid_id, auction_id),
 INDEX idx_bid_auction_amount (auction_id, amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE auction_results (
 result_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 auction_id INT UNSIGNED NOT NULL UNIQUE,
 winning_bid_id INT UNSIGNED NOT NULL UNIQUE,
 decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (auction_id) REFERENCES auctions(auction_id),
 FOREIGN KEY (winning_bid_id, auction_id) REFERENCES bids(bid_id, auction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE watches (
 user_id INT UNSIGNED NOT NULL,
 auction_id INT UNSIGNED NOT NULL,
 watched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (user_id, auction_id),
 FOREIGN KEY (user_id) REFERENCES users(user_id),
 FOREIGN KEY (auction_id) REFERENCES auctions(auction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE user_preferences (
 user_id INT UNSIGNED NOT NULL,
 category_id INT UNSIGNED NOT NULL,
 selected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (user_id, category_id),
 FOREIGN KEY (user_id) REFERENCES users(user_id),
 FOREIGN KEY (category_id) REFERENCES categories(category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE comments (
 comment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 auction_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 content TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (auction_id) REFERENCES auctions(auction_id),
 FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
