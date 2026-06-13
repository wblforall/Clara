<?php
// Penawaran mengadopsi mesin pricing & kontrol recurring dari input transaksi:
//  - override_amount    : harga nego final (menimpa hasil kalkulasi), nullable
//  - billing_method     : spread | anchor_cycle (ditentukan sales saat menawarkan)
//  - recurring_flag     : tandai kontrak berulang (diteruskan ke transaksi)
//  - cycle_recognition  : cycle_start | cycle_end (saat spread)
//  - referrer_name      : referral (komisi 1%), diteruskan ke transaksi
// Nilai-nilai ini dipakai _skp_create_transaction() saat konfirmasi di-approve,
// menggantikan tebakan lama. Lihat [[project-offer-pipeline]] & [[project-recurring-standard]].

$cols = array_column($pdo->query('SHOW COLUMNS FROM offers')->fetchAll(), 'Field');

if (!in_array('override_amount', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN override_amount DECIMAL(18,2) NULL AFTER total_calculated");
}
if (!in_array('billing_method', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN billing_method VARCHAR(20) NULL AFTER override_amount");
}
if (!in_array('recurring_flag', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN recurring_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_method");
}
if (!in_array('cycle_recognition', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN cycle_recognition VARCHAR(20) NULL AFTER recurring_flag");
}
if (!in_array('referrer_name', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN referrer_name VARCHAR(120) NULL AFTER pic_name");
}
