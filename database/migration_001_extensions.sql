-- Apply once after schema.sql. Existing baseline tables and records are preserved.
SET NAMES utf8mb4;

CREATE TABLE notification_settings (
 user_id INT UNSIGNED PRIMARY KEY,
 outbid_enabled BOOLEAN NOT NULL DEFAULT TRUE,
 watch_enabled BOOLEAN NOT NULL DEFAULT TRUE,
 seller_frequency VARCHAR(20) NOT NULL DEFAULT 'immediate',
 FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
 notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 auction_id INT UNSIGNED NOT NULL,
 type VARCHAR(30) NOT NULL,
 subject VARCHAR(200) NOT NULL,
 content TEXT NOT NULL,
 event_key VARCHAR(160) NOT NULL UNIQUE,
 status VARCHAR(20) NOT NULL DEFAULT 'queued',
 is_read BOOLEAN NOT NULL DEFAULT FALSE,
 available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 sent_at DATETIME NULL,
 FOREIGN KEY (user_id) REFERENCES users(user_id),
 FOREIGN KEY (auction_id) REFERENCES auctions(auction_id),
 INDEX idx_notification_user (user_id, created_at),
 INDEX idx_notification_queue (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delivery_methods (
 delivery_method_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 method_name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO delivery_methods (method_name) VALUES ('Tracked delivery'), ('Collection in person');

CREATE TABLE auction_delivery (
 auction_id INT UNSIGNED NOT NULL,
 delivery_method_id INT UNSIGNED NOT NULL,
 PRIMARY KEY (auction_id, delivery_method_id),
 FOREIGN KEY (auction_id) REFERENCES auctions(auction_id),
 FOREIGN KEY (delivery_method_id) REFERENCES delivery_methods(delivery_method_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transactions (
 transaction_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 result_id INT UNSIGNED NOT NULL UNIQUE,
 delivery_method_id INT UNSIGNED NULL,
 payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
 delivery_status VARCHAR(20) NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (result_id) REFERENCES auction_results(result_id),
 FOREIGN KEY (delivery_method_id) REFERENCES delivery_methods(delivery_method_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE reviews (
 review_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 transaction_id INT UNSIGNED NOT NULL,
 reviewer_id INT UNSIGNED NOT NULL,
 reviewee_id INT UNSIGNED NOT NULL,
 rating TINYINT UNSIGNED NOT NULL,
 comment TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id),
 FOREIGN KEY (reviewer_id) REFERENCES users(user_id),
 FOREIGN KEY (reviewee_id) REFERENCES users(user_id),
 UNIQUE KEY uq_review_once (transaction_id, reviewer_id),
 INDEX idx_review_recipient (reviewee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE after_sales_requests (
 request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 transaction_id INT UNSIGNED NOT NULL,
 requester_id INT UNSIGNED NOT NULL,
 description TEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'open',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id),
 FOREIGN KEY (requester_id) REFERENCES users(user_id),
 INDEX idx_request_transaction (transaction_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Carry existing successful results into the new transaction workflow.
INSERT INTO transactions (result_id) SELECT result_id FROM auction_results;

-- Imported baseline auctions predate handover choices; offer the two initial methods.
INSERT INTO auction_delivery (auction_id, delivery_method_id)
 SELECT a.auction_id, d.delivery_method_id FROM auctions a CROSS JOIN delivery_methods d;
