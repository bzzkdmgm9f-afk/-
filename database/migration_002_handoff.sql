SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS transaction_handoffs (
 transaction_id INT UNSIGNED NOT NULL,
 delivery_method_id INT UNSIGNED NOT NULL,
 recipient_name VARCHAR(120) NULL,
 delivery_address VARCHAR(1000) NULL,
 collection_request VARCHAR(500) NULL,
 carrier VARCHAR(120) NULL,
 tracking_number VARCHAR(160) NULL,
 collection_place VARCHAR(250) NULL,
 collection_time VARCHAR(180) NULL,
 PRIMARY KEY (transaction_id, delivery_method_id),
 FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id),
 FOREIGN KEY (delivery_method_id) REFERENCES delivery_methods(delivery_method_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transaction_messages (
 message_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 transaction_id INT UNSIGNED NOT NULL,
 sender_id INT UNSIGNED NOT NULL,
 submission_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 body VARCHAR(2000) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_transaction_submission (transaction_id, sender_id, submission_key),
 INDEX idx_transaction_chat (transaction_id, message_id),
 FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id),
 FOREIGN KEY (sender_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
