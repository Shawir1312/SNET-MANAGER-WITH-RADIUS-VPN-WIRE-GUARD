<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

function add_index_safe($table, $indexName, $columns) {
    try {
        $check = db_fetch_one("SHOW INDEX FROM {$table} WHERE Key_name = ?", 's', [$indexName]);
        if (!$check) {
            db_execute("ALTER TABLE {$table} ADD INDEX {$indexName} ({$columns})");
            echo "✓ Index {$indexName} ({$columns}) pada tabel {$table} berhasil ditambahkan.\n";
        } else {
            echo "- Index {$indexName} pada tabel {$table} sudah ada.\n";
        }
    } catch (Throwable $e) {
        echo "✗ Gagal menambahkan {$indexName} pada {$table}: " . $e->getMessage() . "\n";
    }
}

echo "=== Memulai Optimasi Indeks Database (Mencegah Overload & I/O Wait) ===\n";

// Tabel vouchers
add_index_safe('vouchers', 'idx_status', 'status');
add_index_safe('vouchers', 'idx_profile', 'profile_id');
add_index_safe('vouchers', 'idx_batch', 'batch_id');
add_index_safe('vouchers', 'idx_router', 'router_id');
add_index_safe('vouchers', 'idx_expired_at', 'expired_at');

// Tabel pppoe_payments
add_index_safe('pppoe_payments', 'idx_pay_cust_period', 'customer_id, period_year, period_month');
add_index_safe('pppoe_payments', 'idx_pay_status', 'midtrans_status, payment_method');
add_index_safe('pppoe_payments', 'idx_pay_order_id', 'midtrans_order_id');

// Tabel pppoe_customers
add_index_safe('pppoe_customers', 'idx_cust_router_status', 'router_id, status');
add_index_safe('pppoe_customers', 'idx_cust_username', 'pppoe_username');
add_index_safe('pppoe_customers', 'idx_cust_phone', 'phone');

// Tabel sales_log
add_index_safe('sales_log', 'idx_sales_sold_at', 'sold_at');
add_index_safe('sales_log', 'idx_sales_router', 'router_id');

// Tabel radacct
add_index_safe('radacct', 'idx_radacct_user_stop', 'username, acctstoptime');

echo "=== Selesai! Seluruh Indeks Database Berhasil Dioptimasi ===\n";
