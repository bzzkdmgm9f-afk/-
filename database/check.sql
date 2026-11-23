-- 导入后在选中的 auction_db 中运行：应显示 10 张表以及示例数据。
SHOW TABLES;
SELECT a.auction_id, i.title, c.category_name, a.status, a.starting_price,
       MAX(b.amount) AS highest_bid, COUNT(DISTINCT b.bidder_id) AS bidder_count
FROM auctions a JOIN items i ON i.item_id = a.item_id
JOIN categories c ON c.category_id = i.category_id
LEFT JOIN bids b ON b.auction_id = a.auction_id
GROUP BY a.auction_id, i.title, c.category_name, a.status, a.starting_price
ORDER BY a.auction_id;
SELECT r.auction_id, b.amount, u.username AS winner
FROM auction_results r JOIN bids b ON b.bid_id = r.winning_bid_id
JOIN users u ON u.user_id = b.bidder_id;
