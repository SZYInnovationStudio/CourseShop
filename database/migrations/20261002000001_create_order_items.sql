-- ============================================================
-- 新增 order_items 表：订单商品快照
--
-- 记录下单那一刻订单覆盖的课程清单（课程订单 1 行；套餐订单每个课程 1 行），
-- 使付款开通与退款撤销都以「下单时的快照」为依据，而不是实时读取当前套餐内容：
--   1) 下单后再编辑套餐，不再改变该订单的开通 / 撤销课程范围；
--   2) 退款时可据此判断用户是否还有其它已付款来源，避免误撤销仍应保留的授权。
-- 幂等：使用 CREATE TABLE IF NOT EXISTS。
-- ============================================================

CREATE TABLE IF NOT EXISTS `order_items` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   BIGINT UNSIGNED NOT NULL COMMENT '所属订单',
  `item_type`  VARCHAR(10)     NOT NULL DEFAULT 'course' COMMENT 'course 课程 / package 套餐',
  `course_id`  BIGINT UNSIGNED DEFAULT NULL COMMENT '课程 ID（快照）',
  `package_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '套餐 ID（快照，课程订单为 NULL）',
  `title`      VARCHAR(150)    NOT NULL DEFAULT '' COMMENT '课程 / 套餐名快照',
  `price`      INT             NOT NULL DEFAULT 0 COMMENT '单价快照（分）',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_items_order` (`order_id`),
  KEY `idx_order_items_course` (`course_id`),
  KEY `idx_order_items_package` (`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单商品快照';
