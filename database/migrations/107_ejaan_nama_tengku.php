<?php
/**
 * Ralat ejaan nama lengkap: "Tengku Fadjirina" → "Tengku Fadjrina".
 *
 * Migrasi 097 mengisikan nama itu dengan satu huruf i berlebih, dan nama
 * tersebut ikut tercetak di blok tanda tangan SETIAP surat (SKP, SKS, Form
 * Utilities) pada kolom "Mengetahui/Menyetujui". Ejaan yang benar dipastikan
 * dari alamat surel pemiliknya sendiri (tengkufadjrina@gmail.com).
 *
 * Hanya baris yang masih salah eja yang disentuh — nama yang sudah diperbaiki
 * lewat Master PIC tidak diubah.
 */

$st = $pdo->prepare("UPDATE master_pic SET full_name = 'Tengku Fadjrina'
                      WHERE full_name = 'Tengku Fadjirina'");
$st->execute();
echo '     ' . $st->rowCount() . " nama PIC diralat ejaannya\n";
