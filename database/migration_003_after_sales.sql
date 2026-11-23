-- Append-only after-sales history. Original request rows and their IDs are preserved.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS after_sales_events (
 event_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_id INT UNSIGNED NOT NULL,
 actor_id INT UNSIGNED NOT NULL,
 event_type VARCHAR(20) NOT NULL,
 message TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (request_id) REFERENCES after_sales_requests(request_id),
 FOREIGN KEY (actor_id) REFERENCES users(user_id),
 INDEX idx_after_sales_history (request_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
